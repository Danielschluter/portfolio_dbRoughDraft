
<?php

$conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
$con = pg_connect($conn_string);

// Get parameters from query
$acct_num = $_GET['acct_num'];
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

if (!$acct_num) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Account number is required']);
    exit;
}

// Build the SQL query
$sql = "WITH transactions_filtered AS (
    SELECT * FROM transactions_temp 
    WHERE acct_num = $1
    " . ($start_date ? "AND transaction_date >= '$start_date'" : "") . "
    " . ($end_date ? "AND transaction_date <= '$end_date'" : "") . "
),

price_data AS (
    SELECT 
        ticker,
        date,
        close,
        LAG(close) OVER (PARTITION BY ticker ORDER BY date) as prev_close
    FROM prices_stocks
    WHERE ticker IN (SELECT DISTINCT ticker FROM transactions_filtered)
    " . ($start_date ? "AND date >= '$start_date'" : "") . "
    " . ($end_date ? "AND date <= '$end_date'" : "") . "
),

holdings_timeline AS (
    SELECT 
        p.ticker,
        p.date,
        p.close,
        p.prev_close,
        COALESCE(SUM(t.shares) OVER (
            PARTITION BY p.ticker 
            ORDER BY p.date 
            ROWS UNBOUNDED PRECEDING
        ), 0) as cumulative_shares,
        CASE WHEN p.prev_close IS NOT NULL AND p.prev_close > 0 
             THEN (p.close - p.prev_close) / p.prev_close 
             ELSE 0 END as daily_return
    FROM price_data p
    LEFT JOIN transactions_filtered t ON p.ticker = t.ticker AND p.date = t.transaction_date
    WHERE p.ticker != 'CASHX'
),

daily_contributions AS (
    SELECT 
        ticker,
        date,
        cumulative_shares,
        close,
        daily_return,
        cumulative_shares * close as market_value,
        -- Previous day market value for contribution calculation
        LAG(cumulative_shares * close) OVER (PARTITION BY ticker ORDER BY date) as prev_market_value,
        -- Daily contribution = previous market value * daily return
        LAG(cumulative_shares * close) OVER (PARTITION BY ticker ORDER BY date) * daily_return as daily_contribution
    FROM holdings_timeline
    WHERE cumulative_shares > 0
),

portfolio_daily_totals AS (
    SELECT 
        date,
        SUM(market_value) as total_portfolio_value,
        SUM(daily_contribution) as total_daily_contribution
    FROM daily_contributions
    GROUP BY date
),

ticker_summary AS (
    SELECT 
        dc.ticker,
        -- Average weight over the period
        AVG(dc.market_value / pdt.total_portfolio_value) as avg_weight,
        -- Total return calculation
        (MAX(dc.close) / MIN(dc.close) - 1) * 100 as total_return,
        -- Final market value
        MAX(dc.market_value) as final_market_value,
        -- Cost basis (sum of purchase amounts)
        COALESCE(SUM(CASE WHEN tf.shares > 0 THEN tf.shares * tf.price ELSE 0 END), 0) as cost_basis,
        -- Total contribution over the period
        SUM(dc.daily_contribution) as total_contribution_dollar,
        -- Contribution in basis points (relative to total portfolio)
        (SUM(dc.daily_contribution) / AVG(pdt.total_portfolio_value)) * 10000 as contribution_bps,
        -- Contribution as percentage of total return
        SUM(dc.daily_contribution) / NULLIF(SUM(pdt.total_daily_contribution), 0) as contribution_percent
    FROM daily_contributions dc
    JOIN portfolio_daily_totals pdt ON dc.date = pdt.date
    LEFT JOIN transactions_filtered tf ON dc.ticker = tf.ticker
    WHERE dc.cumulative_shares > 0
    GROUP BY dc.ticker
    HAVING MAX(dc.market_value) > 0
)

SELECT 
    ticker,
    ROUND(avg_weight * 100, 2) as weight,
    ROUND(total_return, 2) as total_return,
    ROUND(final_market_value, 2) as market_value,
    ROUND(cost_basis, 2) as cost_basis,
    ROUND(total_contribution_dollar, 2) as contribution_dollar,
    ROUND(contribution_bps, 2) as contribution_bps,
    ROUND(contribution_percent * 100, 2) as contribution_percent
FROM ticker_summary
ORDER BY contribution_bps DESC";

$result = pg_query_params($con, $sql, array($acct_num));

if (!$result) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database query failed: ' . pg_last_error($con)]);
    exit;
}

$returnArr = [];

while ($row = pg_fetch_assoc($result)) {
    $returnArr[] = $row;
}

header('Content-Type: application/json');
echo json_encode($returnArr, JSON_NUMERIC_CHECK);

pg_close($con);
?>
