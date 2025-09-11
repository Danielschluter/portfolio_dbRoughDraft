
<?php

$conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
$con = pg_connect($conn_string);

$q = $_GET['q'];
$start_year = $_GET['start_year'];
$end_year = $_GET['end_year'];

if (!$q || !$start_year || !$end_year) {
    echo json_encode(['error' => 'Account number, start year, and end year are required']);
    exit;
}

// Generate monthly performance data
$sql = "
WITH monthly_periods AS (
    -- This CTE is unchanged. It generates the series of months to analyze.
    SELECT
        TO_CHAR(month_start, 'YYYY-MM') AS month_year,
        DATE_TRUNC('month', month_start)::DATE AS month_start_date,
        (DATE_TRUNC('month', month_start) + INTERVAL '1 month' - INTERVAL '1 day')::DATE AS month_end_date
    FROM generate_series(
        DATE_TRUNC('month', ('" . $start_year . "-01-01')::DATE),
        DATE_TRUNC('month', ('" . $end_year . "-12-31')::DATE),
        '1 month'::interval
    ) AS month_start
),
account_tickers AS (
    -- Get a distinct list of tickers for the specified account.
    -- This prevents us from repeatedly scanning the entire transactions table.
    SELECT DISTINCT ticker
    FROM transactions_temp
    WHERE acct_num = " . $q . "
),
monthly_ticker_data AS (
    -- For each ticker in the account and for each month, calculate the
    -- shares and price at the beginning and end of the month.
    SELECT
        mp.month_year,
        t.ticker,
        -- Shares held just BEFORE the month starts
        (SELECT COALESCE(SUM(shares), 0)
         FROM transactions_temp
         WHERE acct_num = " . $q . " AND ticker = t.ticker AND transaction_date < mp.month_start_date
        ) AS beg_shares,
        -- Price on the last available day BEFORE the month starts
        (SELECT close
         FROM prices_stocks
         WHERE ticker = t.ticker AND date < mp.month_start_date
         ORDER BY date DESC LIMIT 1
        ) AS beg_price,
        -- Shares held as of the END of the month
        (SELECT COALESCE(SUM(shares), 0)
         FROM transactions_temp
         WHERE acct_num = " . $q . " AND ticker = t.ticker AND transaction_date <= mp.month_end_date
        ) AS end_shares,
        -- Price on the last available day ON or BEFORE the month ends
        (SELECT close
         FROM prices_stocks
         WHERE ticker = t.ticker AND date <= mp.month_end_date
         ORDER BY date DESC LIMIT 1
        ) AS end_price
    FROM monthly_periods mp
    CROSS JOIN account_tickers t
),
monthly_performance AS (
    -- Calculate the total portfolio value and cash flow for each month.
    SELECT
        month_year,
        -- Sum the value of all tickers to get the total beginning portfolio value.
        COALESCE(SUM(beg_shares * beg_price), 0) AS beginning_portfolio_value,
        -- Sum the value of all tickers to get the total ending portfolio value.
        COALESCE(SUM(end_shares * end_price), 0) AS ending_portfolio_value,
        -- Calculate the net cash flow during the month.
        (SELECT COALESCE(SUM(amount), 0)
         FROM transactions_temp tr
         JOIN monthly_periods mp ON mp.month_year = mtd.month_year
         WHERE tr.acct_num = " . $q . "
           AND tr.transaction_date BETWEEN mp.month_start_date AND mp.month_end_date
        ) AS net_cash_flow
    FROM monthly_ticker_data mtd
    GROUP BY month_year
)
-- Final calculation and formatting, same as your original query.
SELECT
    month_year,
    beginning_portfolio_value,
    ending_portfolio_value,
    net_cash_flow,
    (ending_portfolio_value - beginning_portfolio_value + net_cash_flow) AS total_gain_loss,
    CASE
        WHEN beginning_portfolio_value = 0 THEN 0
        ELSE ((ending_portfolio_value - beginning_portfolio_value + net_cash_flow) / beginning_portfolio_value) * 100
    END AS total_gain_loss_pct
FROM monthly_performance
WHERE beginning_portfolio_value > 0 OR ending_portfolio_value > 0 OR net_cash_flow != 0
ORDER BY month_year;
";

$result = pg_query($con, $sql);
if (!$result) {
    echo json_encode(['error' => 'Database query failed: ' . pg_last_error($con)]);
    exit;
}

$all = pg_fetch_all($result);

if (!$all) {
    echo json_encode([]);
} else {
    echo json_encode($all, JSON_NUMERIC_CHECK);
}

pg_close($con);

?>
