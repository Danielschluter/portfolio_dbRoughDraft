<?php

$conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
$con = pg_connect($conn_string);

// Get acct_num from query parameter
$acct_num = $_GET['acct_num'];
$start_date = $_GET['start_date'];
$end_date = $_GET['end_date'];
//$acct_num = 800;

$sql = "WITH tr AS ( 
    SELECT * FROM transactions_temp WHERE acct_num = ".$acct_num."
),
pr AS (
    SELECT date, ticker, close 
    FROM prices_stocks
    WHERE ticker IN (SELECT DISTINCT ticker FROM transactions_temp WHERE acct_num = ".$acct_num.")
    AND date >= (SELECT MIN(transaction_date) FROM transactions_temp WHERE acct_num = ".$acct_num.")
),
mktvalues AS (
    SELECT
        date,
        pr.ticker,
        shares,
        close,
        SUM(tr.shares) OVER (PARTITION BY pr.ticker ORDER BY pr.date) AS totalshares,
        pr.close * SUM(tr.shares) OVER (PARTITION BY pr.ticker ORDER BY pr.date) AS marketvalue,
        SUM(amount) OVER (PARTITION BY pr.date ORDER BY pr.date) AS cf
    FROM pr
    LEFT JOIN tr
    ON pr.date = tr.transaction_date
    AND pr.ticker = tr.ticker
    ORDER BY date, ticker
),
cash_flows AS (
    SELECT
        transaction_date,
        SUM(amount) AS total_cf,
        SUM(CASE WHEN transaction_type = 'MoneyLink Transfer' THEN amount ELSE 0 END) AS contr_distr
    FROM transactions_temp WHERE acct_num = ".$acct_num."
    GROUP BY transaction_date
    ORDER BY transaction_date
), twr AS (
SELECT
    mktvalues.date,
    SUM(DISTINCT marketvalue) AS dailyval,
    total_cf,
    SUM(total_cf) OVER (ORDER BY mktvalues.date) AS cash_balance,
    COALESCE(contr_distr, 0) AS external_cf
FROM mktvalues
LEFT JOIN cash_flows
ON mktvalues.date = cash_flows.transaction_date
WHERE totalshares IS NOT NULL
GROUP BY mktvalues.date, total_cf, contr_distr
ORDER BY mktvalues.date
)
SELECT twr.date, dailyval, total_cf, cash_balance, (dailyval + cash_balance) AS total_market_value, external_cf,
/*( (dailyval + cash_balance) / ( LAG(dailyval, 1) OVER (ORDER BY twr.date) + LAG(cash_balance, 1) OVER (ORDER BY twr.date) + external_cf) ) AS factor,*/
( (dailyval + cash_balance) / ( LAG(dailyval, 1) OVER (ORDER BY twr.date) + LAG(cash_balance, 1) OVER (ORDER BY twr.date) + external_cf) ) -1 AS pct_return, (return_factor - 1) AS spy_pct_return
FROM twr
INNER JOIN spy_daily_return
ON twr.date = spy_daily_return.date
WHERE twr.date >= '".$start_date."' AND twr.date <= '".$end_date."
'";

$result = pg_query($con, $sql);

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

