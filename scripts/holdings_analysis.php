
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
),

-- Get price data for the full range
price_data AS (
    SELECT 
        ticker,
        date,
        close
    FROM prices_stocks
    WHERE ticker IN (SELECT DISTINCT ticker FROM transactions_filtered)
    AND ticker != 'CASHX'
),

-- Calculate cumulative shares over time
holdings_timeline AS (
    SELECT 
        p.ticker,
        p.date,
        p.close,
        COALESCE(SUM(t.shares) OVER (
            PARTITION BY p.ticker 
            ORDER BY p.date 
            ROWS UNBOUNDED PRECEDING
        ), 0) as cumulative_shares
    FROM price_data p
    LEFT JOIN transactions_filtered t ON p.ticker = t.ticker AND p.date = t.transaction_date
),

-- Find holdings within the specified period
period_holdings AS (
    SELECT 
        ticker,
        date,
        close,
        cumulative_shares,
        cumulative_shares * close as market_value
    FROM holdings_timeline
    WHERE date >= COALESCE('$start_date', '1900-01-01')::date
    AND date <= COALESCE('$end_date', '2099-12-31')::date
    AND cumulative_shares > 0
),

-- Get first and last dates for each ticker in the period
holding_dates AS (
    SELECT 
        ticker,
        MIN(date) as first_date,
        MAX(date) as last_date
    FROM period_holdings
    GROUP BY ticker
),

-- Get starting positions and prices
starting_positions AS (
    SELECT DISTINCT
        h.ticker,
        hd.first_date,
        FIRST_VALUE(h.cumulative_shares) OVER (PARTITION BY h.ticker ORDER BY h.date) as starting_shares,
        FIRST_VALUE(h.close) OVER (PARTITION BY h.ticker ORDER BY h.date) as starting_price,
        FIRST_VALUE(h.market_value) OVER (PARTITION BY h.ticker ORDER BY h.date) as starting_value
    FROM period_holdings h
    JOIN holding_dates hd ON h.ticker = hd.ticker AND h.date = hd.first_date
),

-- Get ending positions and prices  
ending_positions AS (
    SELECT DISTINCT
        h.ticker,
        hd.last_date,
        FIRST_VALUE(h.cumulative_shares) OVER (PARTITION BY h.ticker ORDER BY h.date DESC) as ending_shares,
        FIRST_VALUE(h.close) OVER (PARTITION BY h.ticker ORDER BY h.date DESC) as ending_price,
        FIRST_VALUE(h.market_value) OVER (PARTITION BY h.ticker ORDER BY h.date DESC) as ending_value
    FROM period_holdings h
    JOIN holding_dates hd ON h.ticker = hd.ticker AND h.date = hd.last_date
),

-- Calculate daily returns for volatility
daily_returns AS (
    SELECT 
        ticker,
        date,
        close,
        LAG(close) OVER (PARTITION BY ticker ORDER BY date) as prev_close,
        CASE WHEN LAG(close) OVER (PARTITION BY ticker ORDER BY date) IS NOT NULL
             THEN (close / LAG(close) OVER (PARTITION BY ticker ORDER BY date)) - 1
             ELSE 0 END as daily_return
    FROM period_holdings
),

-- Calculate volatility
volatility_stats AS (
    SELECT 
        ticker,
        STDDEV(daily_return) * SQRT(252) * 100 as annualized_volatility_pct
    FROM daily_returns
    WHERE daily_return IS NOT NULL
    GROUP BY ticker
),

-- Calculate transactions within period
period_transactions AS (
    SELECT 
        ticker,
        COUNT(*) as total_transactions,
        SUM(CASE WHEN shares > 0 THEN shares ELSE 0 END) as shares_bought,
        SUM(CASE WHEN shares < 0 THEN ABS(shares) ELSE 0 END) as shares_sold,
        SUM(CASE WHEN shares > 0 THEN amount ELSE 0 END) as amount_invested,
        SUM(CASE WHEN shares < 0 THEN ABS(amount) ELSE 0 END) as amount_received,
        AVG(CASE WHEN shares > 0 THEN price END) as avg_buy_price,
        AVG(CASE WHEN shares < 0 THEN price END) as avg_sell_price,
        COUNT(CASE WHEN amount > 0 THEN 1 END) as winning_trades,
        COUNT(*) as total_trades
    FROM transactions_filtered
    WHERE ticker != 'CASHX'
    AND transaction_date >= COALESCE('$start_date', '1900-01-01')::date
    AND transaction_date <= COALESCE('$end_date', '2099-12-31')::date
    GROUP BY ticker
),

-- Get total days held in period
days_held AS (
    SELECT 
        ticker,
        COUNT(DISTINCT date) as days_held
    FROM period_holdings
    GROUP BY ticker
)

SELECT 
    sp.ticker,
    sp.first_date,
    ep.last_date,
    dh.days_held,
    
    -- Average position value (average of starting and ending values)
    (sp.starting_value + ep.ending_value) / 2 as avg_position_value,
    
    -- Total return calculation including cash flows
    CASE WHEN sp.starting_value > 0 
         THEN ((ep.ending_value - sp.starting_value + COALESCE(pt.amount_received - pt.amount_invested, 0)) / sp.starting_value) * 100
         ELSE 0 END as total_return_pct,
    
    -- Annualized return
    CASE WHEN sp.starting_value > 0 AND dh.days_held > 0
         THEN (POWER((ep.ending_value + COALESCE(pt.amount_received - pt.amount_invested, 0)) / sp.starting_value, 365.0 / dh.days_held) - 1) * 100
         ELSE 0 END as annualized_return_pct,
    
    -- Volatility
    COALESCE(vs.annualized_volatility_pct, 0) as annualized_volatility_pct,
    
    -- Sharpe ratio (assuming 0% risk-free rate)
    CASE WHEN COALESCE(vs.annualized_volatility_pct, 0) > 0
         THEN (CASE WHEN sp.starting_value > 0 AND dh.days_held > 0
                    THEN (POWER((ep.ending_value + COALESCE(pt.amount_received - pt.amount_invested, 0)) / sp.starting_value, 365.0 / dh.days_held) - 1) * 100
                    ELSE 0 END) / vs.annualized_volatility_pct
         ELSE 0 END as sharpe_ratio,
    
    -- Win rate
    CASE WHEN COALESCE(pt.total_trades, 0) > 0
         THEN (COALESCE(pt.winning_trades, 0) * 100.0 / pt.total_trades)
         ELSE 0 END as win_rate_pct,
    
    -- Transaction data
    COALESCE(pt.total_transactions, 0) as total_transactions,
    
    -- Realized P&L
    COALESCE(pt.amount_received - pt.amount_invested, 0) as realized_pnl

FROM starting_positions sp
JOIN ending_positions ep ON sp.ticker = ep.ticker
JOIN days_held dh ON sp.ticker = dh.ticker
LEFT JOIN period_transactions pt ON sp.ticker = pt.ticker
LEFT JOIN volatility_stats vs ON sp.ticker = vs.ticker
WHERE sp.starting_value > 0
ORDER BY avg_position_value DESC";

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
