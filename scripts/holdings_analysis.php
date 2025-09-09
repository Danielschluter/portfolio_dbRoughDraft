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
        h.first_date,
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
        h.last_date,
        FIRST_VALUE(h.cumulative_shares) OVER (PARTITION BY h.ticker ORDER BY h.date DESC) as ending_shares,
        FIRST_VALUE(h.close) OVER (PARTITION BY h.ticker ORDER BY h.date DESC) as ending_price,
        FIRST_VALUE(h.market_value) OVER (PARTITION BY h.ticker ORDER BY h.date DESC) as ending_value
    FROM period_holdings h
    JOIN holding_dates hd ON h.ticker = hd.ticker AND h.date = hd.last_date
),

-- Calculate transactions within period
period_transactions AS (
    SELECT 
        ticker,
        COUNT(*) as transactions_in_period,
        SUM(CASE WHEN shares > 0 THEN shares ELSE 0 END) as shares_bought,
        SUM(CASE WHEN shares < 0 THEN ABS(shares) ELSE 0 END) as shares_sold,
        SUM(CASE WHEN shares > 0 THEN amount ELSE 0 END) as amount_invested,
        SUM(CASE WHEN shares < 0 THEN ABS(amount) ELSE 0 END) as amount_received,
        AVG(CASE WHEN shares > 0 THEN price END) as avg_buy_price,
        AVG(CASE WHEN shares < 0 THEN price END) as avg_sell_price
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
        COUNT(DISTINCT date) as days_held_in_period
    FROM period_holdings
    GROUP BY ticker
)

SELECT 
    sp.ticker,
    sp.first_date,
    ep.last_date,
    dh.days_held_in_period,
    sp.starting_shares,
    sp.starting_price,
    sp.starting_value,
    ep.ending_shares,
    ep.ending_price,
    ep.ending_value,
    
    -- Change calculations
    (ep.ending_shares - sp.starting_shares) as shares_change,
    (ep.ending_value - sp.starting_value) as market_value_change,
    CASE WHEN sp.starting_value > 0 
         THEN ((ep.ending_value - sp.starting_value) / sp.starting_value) * 100
         ELSE 0 END as market_value_change_pct,
    
    -- Price performance
    (ep.ending_price - sp.starting_price) as price_change,
    CASE WHEN sp.starting_price > 0 
         THEN ((ep.ending_price - sp.starting_price) / sp.starting_price) * 100
         ELSE 0 END as price_change_pct,
    
    -- Transaction data
    COALESCE(pt.transactions_in_period, 0) as transactions_in_period,
    COALESCE(pt.shares_bought, 0) as shares_bought,
    COALESCE(pt.shares_sold, 0) as shares_sold,
    COALESCE(pt.amount_invested, 0) as amount_invested,
    COALESCE(pt.amount_received, 0) as amount_received,
    COALESCE(pt.avg_buy_price, 0) as avg_buy_price,
    COALESCE(pt.avg_sell_price, 0) as avg_sell_price,
    
    -- Net cash flow and realized P&L
    COALESCE(pt.amount_received - pt.amount_invested, 0) as net_cash_flow,
    
    -- Total return including cash flows
    (ep.ending_value - sp.starting_value + COALESCE(pt.amount_received - pt.amount_invested, 0)) as total_return,
    CASE WHEN sp.starting_value > 0 
         THEN ((ep.ending_value - sp.starting_value + COALESCE(pt.amount_received - pt.amount_invested, 0)) / sp.starting_value) * 100
         ELSE 0 END as total_return_pct

FROM starting_positions sp
JOIN ending_positions ep ON sp.ticker = ep.ticker
JOIN days_held dh ON sp.ticker = dh.ticker
LEFT JOIN period_transactions pt ON sp.ticker = pt.ticker
ORDER BY sp.starting_value DESC";

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
