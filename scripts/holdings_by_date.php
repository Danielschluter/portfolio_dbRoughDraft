
<?php
$conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
$con = pg_connect($conn_string);

// Get parameters from query
$acct_num = $_GET['acct_num'] ?? null;
$date = $_GET['date'] ?? null;

if (!$acct_num || !$date) {
    echo json_encode(['error' => 'Account number and date are required']);
    exit;
}

// Validate account number is numeric
if (!is_numeric($acct_num)) {
    echo json_encode(['error' => 'Invalid account number']);
    exit;
}

$sql = "WITH transactions_trig2 AS (
    SELECT * FROM transactions_temp WHERE acct_num = $1
),
holdings_on_date AS (
    SELECT 
        p.ticker,
        p.close as price,
        SUM(COALESCE(t.shares, 0)) OVER (
            PARTITION BY p.ticker 
            ORDER BY p.date 
            ROWS UNBOUNDED PRECEDING
        ) AS shares
    FROM prices_stocks p
    LEFT JOIN transactions_trig2 t 
        ON p.ticker = t.ticker 
        AND p.date = t.transaction_date
    WHERE p.ticker IN (SELECT DISTINCT ticker FROM transactions_trig2)
    AND p.date <= $2
    ORDER BY p.ticker, p.date DESC
),
latest_holdings AS (
    SELECT DISTINCT ON (ticker)
        ticker,
        shares,
        price,
        (shares * price) as market_value
    FROM holdings_on_date
    WHERE shares > 0
    ORDER BY ticker, shares DESC
)
SELECT 
    ticker,
    shares,
    price,
    market_value
FROM latest_holdings
WHERE ticker != 'CASHX'
ORDER BY market_value DESC";

$result = pg_query_params($con, $sql, array($acct_num, $date));

if (!$result) {
    echo json_encode(['error' => 'Database query failed: ' . pg_last_error($con)]);
    exit;
}

$returnArr = [];

while ($row = pg_fetch_assoc($result)) {
    $returnArr[] = $row;
}

echo json_encode($returnArr);

pg_close($con);
?>
