
<?php
header('Content-Type: application/json');

$conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
$con = pg_connect($conn_string);

if (!$con) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get acct_num from query parameter
$acct_num = $_GET['acct_num'] ?? null;

if (!$acct_num) {
    http_response_code(400);
    echo json_encode(['error' => 'Account number is required']);
    exit;
}

// Query to get the earliest transaction date for the account
$sql = "SELECT MIN(transaction_date) AS inception_date FROM transactions_temp WHERE acct_num = $1";
$result = pg_query_params($con, $sql, array($acct_num));

if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed: ' . pg_last_error($con)]);
    exit;
}

$row = pg_fetch_assoc($result);

if ($row && $row['inception_date']) {
    echo json_encode(['inception_date' => $row['inception_date']]);
} else {
    echo json_encode(['error' => 'No inception date found for account ' . $acct_num]);
}

pg_close($con);
?>
