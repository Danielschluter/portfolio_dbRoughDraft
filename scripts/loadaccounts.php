<?php
header('Content-Type: application/json');
$conn_string = "host=pg-28325ccc-daniel-0eca.a.aivencloud.com port=26974 dbname=defaultdb user=avnadmin password=AVNS_3hYJYnbM0v0az16FLB0";
$con = pg_connect($conn_string);

if (!$con) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Load all accounts without user filtering
$query = "SELECT DISTINCT acct_num FROM transactions_temp ORDER BY acct_num";
$result = pg_query($con, $query);

$accounts = array();
while ($row = pg_fetch_row($result)) {
    $accounts[] = array('acct_num' => $row[0]);
}

pg_close($con);

//header('Content-Type: application/json');
echo json_encode($accounts);
?>
