
<?php
// Resource monitoring script to track server performance
function getSystemResources() {
    $resources = [];
    
    // Memory usage
    $resources['memory'] = [
        'current_usage_mb' => memory_get_usage(true) / 1024 / 1024,
        'peak_usage_mb' => memory_get_peak_usage(true) / 1024 / 1024,
        'limit_mb' => ini_get('memory_limit')
    ];
    
    // CPU load (Linux/Unix only)
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $resources['cpu_load'] = [
            '1min' => $load[0],
            '5min' => $load[1],
            '15min' => $load[2]
        ];
    }
    
    // Database connections (if using PostgreSQL)
    try {
        $conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
        $con = pg_connect($conn_string);
        
        if ($con) {
            $result = pg_query($con, "SELECT count(*) as active_connections FROM pg_stat_activity WHERE state = 'active'");
            if ($result) {
                $row = pg_fetch_assoc($result);
                $resources['database']['active_connections'] = $row['active_connections'];
            }
            pg_close($con);
        }
    } catch (Exception $e) {
        $resources['database']['error'] = $e->getMessage();
    }
    
    // Disk usage
    $resources['disk'] = [
        'free_space_mb' => disk_free_space('.') / 1024 / 1024,
        'total_space_mb' => disk_total_space('.') / 1024 / 1024
    ];
    
    $resources['timestamp'] = date('Y-m-d H:i:s');
    
    return $resources;
}

// If called directly, output current resources
if (php_sapi_name() === 'cli') {
    echo json_encode(getSystemResources(), JSON_PRETTY_PRINT);
}
?>
