<?php
header('Content-Type: application/json');

$conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
$con = pg_connect($conn_string);

if (!$con) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$acct_num = $_GET['acct_num'] ?? null;
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

if (!$acct_num) {
    echo json_encode(['error' => 'Account number is required']);
    exit;
}

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

position_metrics AS (
    SELECT 
        ticker,
        COUNT(*) as days_held,
        MIN(date) as first_date,
        MAX(date) as last_date,
        AVG(cumulative_shares * close) as avg_position_value,
        MAX(cumulative_shares * close) as max_position_value,
        MIN(CASE WHEN cumulative_shares > 0 THEN cumulative_shares * close END) as min_position_value,
        STDDEV(cumulative_shares * close) as position_volatility,
        SUM(CASE WHEN cumulative_shares > 0 THEN 1 ELSE 0 END) as days_with_position,
        FIRST_VALUE(close) OVER (PARTITION BY ticker ORDER BY date) as start_price,
        LAST_VALUE(close) OVER (PARTITION BY ticker ORDER BY date ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING) as end_price
    FROM holdings_timeline
    WHERE cumulative_shares > 0
    GROUP BY ticker
),

performance_stats AS (
    SELECT 
        ticker,
        AVG(daily_return) * 252 as annualized_return,
        STDDEV(daily_return) * SQRT(252) as annualized_volatility,
        (MAX(close) - MIN(close)) / MIN(close) as total_return,
        COUNT(CASE WHEN daily_return > 0 THEN 1 END) * 1.0 / COUNT(*) as win_rate,
        MAX(daily_return) as best_day,
        MIN(daily_return) as worst_day
    FROM holdings_timeline
    WHERE cumulative_shares > 0
    GROUP BY ticker
),

transaction_analysis AS (
    SELECT 
        ticker,
        COUNT(*) as total_transactions,
        SUM(CASE WHEN shares > 0 THEN shares ELSE 0 END) as total_bought,
        SUM(CASE WHEN shares < 0 THEN ABS(shares) ELSE 0 END) as total_sold,
        SUM(CASE WHEN shares > 0 THEN amount ELSE 0 END) as total_invested,
        SUM(CASE WHEN shares < 0 THEN ABS(amount) ELSE 0 END) as total_proceeds,
        AVG(CASE WHEN shares > 0 THEN price END) as avg_buy_price,
        AVG(CASE WHEN shares < 0 THEN price END) as avg_sell_price
    FROM transactions_filtered
    WHERE ticker != 'CASHX'
    GROUP BY ticker
)

SELECT 
    pm.ticker,
    pm.days_held,
    pm.first_date,
    pm.last_date,
    pm.avg_position_value,
    pm.max_position_value,
    pm.min_position_value,
    pm.position_volatility,
    pm.start_price,
    pm.end_price,
    (pm.end_price / pm.start_price - 1) * 100 as price_return_pct,
    ps.annualized_return * 100 as annualized_return_pct,
    ps.annualized_volatility * 100 as annualized_volatility_pct,
    ps.total_return * 100 as total_return_pct,
    ps.win_rate * 100 as win_rate_pct,
    ps.best_day * 100 as best_day_pct,
    ps.worst_day * 100 as worst_day_pct,
    CASE WHEN ps.annualized_volatility > 0 
         THEN ps.annualized_return / ps.annualized_volatility 
         ELSE 0 END as sharpe_ratio,
    ta.total_transactions,
    ta.total_bought,
    ta.total_sold,
    ta.total_invested,
    ta.total_proceeds,
    ta.avg_buy_price,
    ta.avg_sell_price,
    CASE WHEN ta.avg_buy_price > 0 AND ta.avg_sell_price > 0
         THEN (ta.avg_sell_price / ta.avg_buy_price - 1) * 100
         ELSE NULL END as trading_profit_pct,
    ta.total_proceeds - ta.total_invested as realized_pnl
FROM position_metrics pm
LEFT JOIN performance_stats ps ON pm.ticker = ps.ticker
LEFT JOIN transaction_analysis ta ON pm.ticker = ta.ticker
WHERE pm.days_with_position > 0
ORDER BY pm.avg_position_value DESC";

$result = pg_query_params($con, $sql, array($acct_num));

if (!$result) {
    echo json_encode(['error' => 'Database query failed: ' . pg_last_error($con)]);
    exit;
}

$holdings = [];
while ($row = pg_fetch_assoc($result)) {
    $holdings[] = $row;
}

echo json_encode($holdings);
pg_close($con);
?>
