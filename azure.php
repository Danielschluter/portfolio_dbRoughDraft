
<?php
// PostgreSQL connection using environment variables
try {
    // Use Replit's PostgreSQL environment variables if available
    $database_url = getenv('DATABASE_URL');
    
    if ($database_url) {
        // Use the DATABASE_URL environment variable
        $conn = new PDO($database_url);
    } else {
        // Fallback to manual connection (same as used in other files)
        $conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
        $conn = new PDO($conn_string);
    }
    
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Successfully connected to PostgreSQL!";
}
catch (PDOException $e) {
    print("Error connecting to PostgreSQL: " . $e->getMessage());
    die();
}

// Alternative connection using pg_connect (similar to performance_query.php)
$conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
$pg_conn = pg_connect($conn_string);

if (!$pg_conn) {
    echo "Failed to connect using pg_connect";
} else {
    echo "Successfully connected using pg_connect!";
    pg_close($pg_conn);
}

?>
