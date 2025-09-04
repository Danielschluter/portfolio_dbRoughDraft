
<?php
header('Content-Type: application/json');
$conn_string = 'postgres://avnadmin:AVNS_3hYJYnbM0v0az16FLB0@pg-28325ccc-daniel-0eca.a.aivencloud.com:26974/defaultdb?sslmode=require';
$con = pg_connect($conn_string);

if (!$con) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet($con);
        break;
    case 'POST':
        handlePost($con);
        break;
    case 'PUT':
        handlePut($con);
        break;
    case 'DELETE':
        handleDelete($con);
        break;
    default:
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function handleGet($con) {
    if (isset($_GET['id'])) {
        // Get single transaction
        $id = $_GET['id'];
        $query = "SELECT * FROM transactions_temp WHERE id = $1";
        $result = pg_query_params($con, $query, array($id));
        
        if ($row = pg_fetch_assoc($result)) {
            echo json_encode(['transaction' => $row]);
        } else {
            echo json_encode(['error' => 'Transaction not found']);
        }
        return;
    }

    // Get transactions with filters and pagination
    $whereClause = "WHERE 1=1";
    $params = array();
    $paramIndex = 1;

    if (!empty($_GET['account'])) {
        $whereClause .= " AND acct_num = $" . $paramIndex++;
        $params[] = $_GET['account'];
    }

    if (!empty($_GET['ticker'])) {
        $whereClause .= " AND UPPER(ticker) LIKE UPPER($" . $paramIndex++ . ")";
        $params[] = '%' . $_GET['ticker'] . '%';
    }

    if (!empty($_GET['type'])) {
        $whereClause .= " AND transaction_type = $" . $paramIndex++;
        $params[] = $_GET['type'];
    }

    if (!empty($_GET['dateFrom'])) {
        $whereClause .= " AND transaction_date >= $" . $paramIndex++;
        $params[] = $_GET['dateFrom'];
    }

    if (!empty($_GET['dateTo'])) {
        $whereClause .= " AND transaction_date <= $" . $paramIndex++;
        $params[] = $_GET['dateTo'];
    }

    // Get total count
    $countQuery = "SELECT COUNT(*) as total FROM transactions_temp $whereClause";
    $countResult = pg_query_params($con, $countQuery, $params);
    
    if (!$countResult) {
        echo json_encode(['error' => 'Database query failed: ' . pg_last_error($con)]);
        return;
    }
    
    $totalRow = pg_fetch_assoc($countResult);
    $total = $totalRow['total'];

    // Add pagination
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = isset($_GET['pageSize']) ? min(100, max(10, intval($_GET['pageSize']))) : 50;
    $offset = ($page - 1) * $pageSize;

    $query = "SELECT id, acct_num, transaction_date, ticker, transaction_type, shares, price, amount 
              FROM transactions_temp 
              $whereClause 
              ORDER BY transaction_date DESC, id DESC 
              LIMIT $" . $paramIndex++ . " OFFSET $" . $paramIndex;
    $params[] = $pageSize;
    $params[] = $offset;

    $result = pg_query_params($con, $query, $params);
    
    if (!$result) {
        echo json_encode(['error' => 'Database query failed: ' . pg_last_error($con)]);
        return;
    }
    
    $transactions = array();
    
    while ($row = pg_fetch_assoc($result)) {
        $transactions[] = $row;
    }

    echo json_encode([
        'transactions' => $transactions,
        'total' => intval($total),
        'page' => $page,
        'pageSize' => $pageSize
    ]);
}

function handlePost($con) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['error' => 'Invalid JSON data']);
        return;
    }

    // Validate required fields
    $required = ['acct_num', 'transaction_date', 'ticker', 'transaction_type', 'shares', 'price', 'amount'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            echo json_encode(['error' => "Field '$field' is required"]);
            return;
        }
    }

    $query = "INSERT INTO transactions_temp (acct_num, transaction_date, ticker, transaction_type, shares, price, amount) 
              VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id";
    
    $params = [
        $input['acct_num'],
        $input['transaction_date'],
        strtoupper($input['ticker']),
        $input['transaction_type'],
        $input['shares'],
        $input['price'],
        $input['amount']
    ];

    $result = pg_query_params($con, $query, $params);
    
    if ($result && $row = pg_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'id' => $row['id']]);
    } else {
        echo json_encode(['error' => 'Failed to add transaction: ' . pg_last_error($con)]);
    }
}

function handlePut($con) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        echo json_encode(['error' => 'Transaction ID is required']);
        return;
    }

    // Validate required fields
    $required = ['acct_num', 'transaction_date', 'ticker', 'transaction_type', 'shares', 'price', 'amount'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            echo json_encode(['error' => "Field '$field' is required"]);
            return;
        }
    }

    $query = "UPDATE transactions_temp 
              SET acct_num = $1, transaction_date = $2, ticker = $3, transaction_type = $4, 
                  shares = $5, price = $6, amount = $7 
              WHERE id = $8";
    
    $params = [
        $input['acct_num'],
        $input['transaction_date'],
        strtoupper($input['ticker']),
        $input['transaction_type'],
        $input['shares'],
        $input['price'],
        $input['amount'],
        $input['id']
    ];

    $result = pg_query_params($con, $query, $params);
    
    if ($result && pg_affected_rows($result) > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Failed to update transaction or transaction not found']);
    }
}

function handleDelete($con) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        echo json_encode(['error' => 'Transaction ID is required']);
        return;
    }

    $query = "DELETE FROM transactions_temp WHERE id = $1";
    $result = pg_query_params($con, $query, array($input['id']));
    
    if ($result && pg_affected_rows($result) > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Failed to delete transaction or transaction not found']);
    }
}

pg_close($con);
?>
