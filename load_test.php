
<?php
// Load testing script for simulating multiple users
ini_set('max_execution_time', 300); // 5 minutes
ini_set('memory_limit', '512M');

// Configuration
$baseUrl = 'http://localhost:8000'; // Change to your deployed URL when testing
$endpoints = [
    '/index.php',
    '/byasset.php?q=592&beg=2024-01-01&end=2024-12-31',
    '/bymonth.php?q=592&start_year=2024&end_year=2024',
    '/performance_query.php?acct_num=592&start_date=2024-01-01&end_date=2024-12-31'
];

$concurrentUsers = 10; // Number of concurrent users to simulate
$requestsPerUser = 5;   // Number of requests each user makes
$delayBetweenRequests = 1; // Seconds between requests

function makeRequest($url) {
    $startTime = microtime(true);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $responseTime = microtime(true) - $startTime;
    
    curl_close($ch);
    
    return [
        'url' => $url,
        'status' => $httpCode,
        'response_time' => $responseTime,
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'response_size' => strlen($response)
    ];
}

function simulateUser($userId, $endpoints, $requestsPerUser, $delay) {
    $results = [];
    
    for ($i = 0; $i < $requestsPerUser; $i++) {
        $endpoint = $endpoints[array_rand($endpoints)];
        $result = makeRequest($endpoint);
        $result['user_id'] = $userId;
        $result['request_number'] = $i + 1;
        $result['timestamp'] = date('Y-m-d H:i:s');
        
        $results[] = $result;
        
        echo "User {$userId} - Request " . ($i + 1) . ": {$result['url']} - {$result['status']} - " . 
             number_format($result['response_time'], 3) . "s\n";
        
        if ($i < $requestsPerUser - 1) {
            sleep($delay);
        }
    }
    
    return $results;
}

echo "Starting load test with {$concurrentUsers} concurrent users...\n";
echo "Each user will make {$requestsPerUser} requests with {$delayBetweenRequests}s delay\n";
echo "Testing endpoints: " . implode(', ', $endpoints) . "\n\n";

$testStartTime = microtime(true);
$allResults = [];

// Simulate concurrent users using curl_multi for better concurrency
$multiHandle = curl_multi_init();
$curlHandles = [];

// Prepare all requests
for ($user = 1; $user <= $concurrentUsers; $user++) {
    for ($req = 0; $req < $requestsPerUser; $req++) {
        $endpoint = $endpoints[array_rand($endpoints)];
        $url = $baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        curl_multi_add_handle($multiHandle, $ch);
        $curlHandles[] = [
            'handle' => $ch,
            'user_id' => $user,
            'request_number' => $req + 1,
            'url' => $url,
            'start_time' => microtime(true)
        ];
    }
}

// Execute all requests
$running = null;
do {
    curl_multi_exec($multiHandle, $running);
    curl_multi_select($multiHandle);
} while ($running > 0);

// Collect results
foreach ($curlHandles as $handleInfo) {
    $response = curl_multi_getcontent($handleInfo['handle']);
    $httpCode = curl_getinfo($handleInfo['handle'], CURLINFO_HTTP_CODE);
    $responseTime = microtime(true) - $handleInfo['start_time'];
    
    $result = [
        'user_id' => $handleInfo['user_id'],
        'request_number' => $handleInfo['request_number'],
        'url' => $handleInfo['url'],
        'status' => $httpCode,
        'response_time' => $responseTime,
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'response_size' => strlen($response),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    $allResults[] = $result;
    
    curl_multi_remove_handle($multiHandle, $handleInfo['handle']);
    curl_close($handleInfo['handle']);
}

curl_multi_close($multiHandle);

$testEndTime = microtime(true);
$totalTestTime = $testEndTime - $testStartTime;

// Calculate statistics
$successfulRequests = array_filter($allResults, function($r) { return $r['success']; });
$failedRequests = array_filter($allResults, function($r) { return !$r['success']; });

$responseTimes = array_column($allResults, 'response_time');
$avgResponseTime = array_sum($responseTimes) / count($responseTimes);
$minResponseTime = min($responseTimes);
$maxResponseTime = max($responseTimes);

sort($responseTimes);
$percentile95 = $responseTimes[floor(count($responseTimes) * 0.95)];

$totalRequests = count($allResults);
$requestsPerSecond = $totalRequests / $totalTestTime;

// Display results
echo "\n" . str_repeat("=", 60) . "\n";
echo "LOAD TEST RESULTS\n";
echo str_repeat("=", 60) . "\n";
echo "Total test time: " . number_format($totalTestTime, 2) . " seconds\n";
echo "Total requests: {$totalRequests}\n";
echo "Successful requests: " . count($successfulRequests) . "\n";
echo "Failed requests: " . count($failedRequests) . "\n";
echo "Success rate: " . number_format((count($successfulRequests) / $totalRequests) * 100, 2) . "%\n";
echo "Requests per second: " . number_format($requestsPerSecond, 2) . "\n\n";

echo "RESPONSE TIME STATISTICS:\n";
echo "Average: " . number_format($avgResponseTime, 3) . "s\n";
echo "Minimum: " . number_format($minResponseTime, 3) . "s\n";
echo "Maximum: " . number_format($maxResponseTime, 3) . "s\n";
echo "95th percentile: " . number_format($percentile95, 3) . "s\n\n";

// Memory usage estimation
$peakMemory = memory_get_peak_usage(true);
echo "Peak memory usage: " . number_format($peakMemory / 1024 / 1024, 2) . " MB\n\n";

// Failed requests details
if (count($failedRequests) > 0) {
    echo "FAILED REQUESTS:\n";
    foreach ($failedRequests as $failed) {
        echo "User {$failed['user_id']}: {$failed['url']} - Status: {$failed['status']}\n";
    }
    echo "\n";
}

// Resource recommendations
echo "RESOURCE RECOMMENDATIONS:\n";
echo str_repeat("-", 40) . "\n";

if ($avgResponseTime > 2.0) {
    echo "⚠️ Average response time is high (>{$avgResponseTime}s). Consider upgrading CPU.\n";
}

if ($percentile95 > 5.0) {
    echo "⚠️ 95th percentile response time is very high. Database optimization needed.\n";
}

if ($requestsPerSecond < 10) {
    echo "ℹ️ Low throughput detected. Current setup handles ~{$requestsPerSecond} req/s.\n";
}

if (count($failedRequests) > 0) {
    echo "❌ Request failures detected. Check server configuration.\n";
}

echo "\nFor Replit Autoscale Deployments:\n";
echo "- Current performance suggests you can handle ~" . number_format($requestsPerSecond * 0.8, 0) . " concurrent users\n";
echo "- Consider 'Basic' machine power for light load, 'Boost' for heavier load\n";
echo "- Set max instances to " . max(1, ceil($concurrentUsers / 10)) . "-" . ceil($concurrentUsers / 5) . " based on your traffic patterns\n";

// Save detailed results to file
file_put_contents('load_test_results.json', json_encode($allResults, JSON_PRETTY_PRINT));
echo "\nDetailed results saved to load_test_results.json\n";
?>
