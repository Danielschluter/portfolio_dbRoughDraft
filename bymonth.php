
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
    SELECT 
        TO_CHAR(DATE_TRUNC('month', month_start), 'YYYY-MM') as month_year,
        DATE_TRUNC('month', month_start) as month_start_date,
        (DATE_TRUNC('month', month_start) + INTERVAL '1 month' - INTERVAL '1 day') as month_end_date
    FROM (
        SELECT generate_series(
            DATE_TRUNC('month', ('" . $start_year . "-01-01')::DATE),
            DATE_TRUNC('month', ('" . $end_year . "-12-31')::DATE),
            '1 month'::INTERVAL
        ) as month_start
    ) months
),
-- Find the closest available price dates for each month
date_mappings AS (
    SELECT 
        mp.month_year,
        mp.month_start_date,
        mp.month_end_date,
        -- For beginning date, use the last day of the previous month
        (SELECT MAX(date) 
         FROM prices_stocks ps 
         WHERE ps.date <= (mp.month_start_date - INTERVAL '1 day')
        ) AS beg_price_date,
        -- For ending date, find the most recent price date on or before month end
        (SELECT MAX(date) 
         FROM prices_stocks ps 
         WHERE ps.date <= mp.month_end_date
        ) AS end_price_date
    FROM monthly_periods mp
),
monthly_performance AS (
    SELECT 
        dm.month_year,
        dm.month_start_date,
        dm.month_end_date,
        dm.beg_price_date,
        dm.end_price_date,
        -- Beginning portfolio value using closest available price date
        COALESCE(
            (
                SELECT SUM(
                    shares_held * price
                )
                FROM (
                    SELECT 
                        t.ticker,
                        SUM(CASE WHEN t.transaction_date <= dm.beg_price_date THEN t.shares ELSE 0 END) as shares_held,
                        (SELECT p.close 
                         FROM prices_stocks p 
                         WHERE p.ticker = t.ticker 
                         AND p.date <= dm.beg_price_date 
                         ORDER BY p.date DESC 
                         LIMIT 1) as price
                    FROM transactions_temp t
                    WHERE t.acct_num = " . $q . "
                    GROUP BY t.ticker
                    HAVING SUM(CASE WHEN t.transaction_date <= dm.beg_price_date THEN t.shares ELSE 0 END) > 0
                ) ticker_values
                WHERE price IS NOT NULL
            ), 0
        ) AS beginning_portfolio_value,
        
        -- Ending portfolio value using closest available price date
        COALESCE(
            (
                SELECT SUM(
                    shares_held * price
                )
                FROM (
                    SELECT 
                        t.ticker,
                        SUM(CASE WHEN t.transaction_date <= dm.month_end_date THEN t.shares ELSE 0 END) as shares_held,
                        (SELECT p.close 
                         FROM prices_stocks p 
                         WHERE p.ticker = t.ticker 
                         AND p.date <= dm.end_price_date 
                         ORDER BY p.date DESC 
                         LIMIT 1) as price
                    FROM transactions_temp t
                    WHERE t.acct_num = " . $q . "
                    GROUP BY t.ticker
                    HAVING SUM(CASE WHEN t.transaction_date <= dm.month_end_date THEN t.shares ELSE 0 END) > 0
                ) ticker_values
                WHERE price IS NOT NULL
            ), 0
        ) AS ending_portfolio_value,
        
        -- Cash flows during the month
        COALESCE(
            (SELECT SUM(amount) 
             FROM transactions_temp tr 
             WHERE tr.acct_num = " . $q . "
               AND tr.transaction_date >= dm.month_start_date 
               AND tr.transaction_date <= dm.month_end_date), 0
        ) AS net_cash_flow
        
    FROM date_mappings dm
)
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
