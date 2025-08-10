<?php
// PHP Data Objects(PDO) Sample Code:
try {
    $conn = new PDO("sqlsrv:server = tcp:ddstest.database.windows.net,1433; Database = my_db", "daniel.ds93@live.com", "Lnitromonkee1");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}
catch (PDOException $e) {
    print("Error connecting to SQL Server.");
    die(print_r($e));
}

// SQL Server Extension Sample Code:
$connectionInfo = array("UID" => "daniel.ds93@live.com", "pwd" => "Lnitromonkee1", "Database" => "my_db", "LoginTimeout" => 30, "Encrypt" => 1, "TrustServerCertificate" => 0);
$serverName = "tcp:ddstest.database.windows.net,1433";
$conn = sqlsrv_connect($serverName, $connectionInfo);


?>