
<?php
header('Content-Type: application/json');
$conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
$con = pg_connect($conn_string);

if (!$con) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Only POST method allowed']);
    exit;
}

if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['csv'];
$filePath = $file['tmp_name'];

// Validate file type
$fileInfo = pathinfo($file['name']);
if (strtolower($fileInfo['extension']) !== 'csv') {
    echo json_encode(['error' => 'Only CSV files are allowed']);
    exit;
}

// Open and read CSV file
$handle = fopen($filePath, 'r');
if ($handle === false) {
    echo json_encode(['error' => 'Unable to read the uploaded file']);
    exit;
}

// Read header row
$header = fgetcsv($handle);
if ($header === false) {
    echo json_encode(['error' => 'Unable to read CSV header']);
    fclose($handle);
    exit;
}

// Expected columns
$expectedColumns = ['acct_num', 'transaction_date', 'ticker', 'transaction_type', 'shares', 'price', 'amount'];

// Map header to expected columns (case insensitive)
$columnMap = array();
foreach ($expectedColumns as $expected) {
    $found = false;
    foreach ($header as $index => $col) {
        if (strtolower(trim($col)) === strtolower($expected)) {
            $columnMap[$expected] = $index;
            $found = true;
            break;
        }
    }
    if (!$found) {
        echo json_encode(['error' => "Required column '$expected' not found in CSV"]);
        fclose($handle);
        exit;
    }
}

// Prepare insert statement
$query = "INSERT INTO transactions_temp (acct_num, transaction_date, ticker, transaction_type, shares, price, amount) 
          VALUES ($1, $2, $3, $4, $5, $6, $7)";

$successCount = 0;
$errorCount = 0;
$errors = array();
$rowNumber = 1; // Start from 1 (header is row 0)

// Begin transaction
pg_query($con, "BEGIN");

// Process each row
while (($data = fgetcsv($handle)) !== false) {
    $rowNumber++;
    
    try {
        // Extract data using column mapping
        $transactionData = array();
        foreach ($expectedColumns as $column) {
            $value = isset($data[$columnMap[$column]]) ? trim($data[$columnMap[$column]]) : '';
            
            // Validate required fields
            if (empty($value)) {
                throw new Exception("Missing value for '$column'");
            }
            
            $transactionData[$column] = $value;
        }

        // Validate and format data
        if (!is_numeric($transactionData['acct_num'])) {
            throw new Exception("Invalid account number");
        }
        
        // Validate date format
        $date = DateTime::createFromFormat('Y-m-d', $transactionData['transaction_date']);
        if (!$date || $date->format('Y-m-d') !== $transactionData['transaction_date']) {
            throw new Exception("Invalid date format. Use YYYY-MM-DD");
        }
        
        // Validate numeric fields
        if (!is_numeric($transactionData['shares']) || 
            !is_numeric($transactionData['price']) || 
            !is_numeric($transactionData['amount'])) {
            throw new Exception("Shares, price, and amount must be numeric");
        }

        // Insert the transaction
        $params = [
            intval($transactionData['acct_num']),
            $transactionData['transaction_date'],
            strtoupper($transactionData['ticker']),
            $transactionData['transaction_type'],
            floatval($transactionData['shares']),
            floatval($transactionData['price']),
            floatval($transactionData['amount'])
        ];

        $result = pg_query_params($con, $query, $params);
        
        if ($result) {
            $successCount++;
        } else {
            throw new Exception("Database error: " . pg_last_error($con));
        }
        
    } catch (Exception $e) {
        $errorCount++;
        $errors[] = "Row $rowNumber: " . $e->getMessage();
        
        // Limit error messages to prevent overwhelming response
        if (count($errors) >= 10) {
            $errors[] = "... and more errors (showing first 10)";
            break;
        }
    }
}

fclose($handle);

// Commit or rollback transaction
if ($errorCount > 0 && $successCount === 0) {
    // If all rows failed, rollback
    pg_query($con, "ROLLBACK");
    echo json_encode([
        'error' => 'All transactions failed',
        'errors' => $errors,
        'success_count' => $successCount,
        'error_count' => $errorCount
    ]);
} else {
    // Commit successful transactions
    pg_query($con, "COMMIT");
    
    $response = [
        'success' => true,
        'count' => $successCount
    ];
    
    if ($errorCount > 0) {
        $response['warnings'] = $errors;
        $response['error_count'] = $errorCount;
    }
    
    echo json_encode($response);
}

pg_close($con);
?>
