<?php
// authenticate.php

// Use Composer to install Google's PHP Client for verifying tokens:
// Run:
// composer require google/apiclient

require_once __DIR__ . '/vendor/autoload.php'; // Google Client library

// Database connection parameters
$dbHost = 'localhost';
$dbPort = '5432';
$dbName = 'your_db_name';
$dbUser = 'your_db_user';
$dbPassword = 'your_db_password';

// Fetch POST data (raw JSON)
$postData = json_decode(file_get_contents('php://input'), true);

if (!isset($postData['id_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID token missing']);
    exit;
}

$idToken = $postData['id_token'];

// Configure Google Client
$client = new Google_Client(['client_id' => 'YOUR_GOOGLE_CLIENT_ID']);

try {
    $payload = $client->verifyIdToken($idToken);
    if ($payload) {
        // Token is valid
        $googleUserId = $payload['sub'];         // Unique Google user ID
        $email = $payload['email'] ?? null;
        $name = $payload['name'] ?? null;

        // Connect to PostgreSQL
        $dsn = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName;";
        $pdo = new PDO($dsn, $dbUser, $dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // Check if user exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE google_user_id = :google_user_id");
        $stmt->execute(['google_user_id' => $googleUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // User does not exist, insert
            $insert = $pdo->prepare("INSERT INTO users (google_user_id, email, name) VALUES (:google_user_id, :email, :name)");
            $insert->execute([
                'google_user_id' => $googleUserId,
                'email' => $email,
                'name' => $name
            ]);
            $userId = $pdo->lastInsertId();
        } else {
            $userId = $user['id'];
        }

        // Set session or return success
        session_start();
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $name;

        echo json_encode([
            'success' => true,
            'name' => $name
        ]);
    } else {
        // Invalid ID token
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid ID token']);
    }
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}