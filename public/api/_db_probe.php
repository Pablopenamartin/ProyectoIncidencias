<?php
header('Content-Type: application/json; charset=utf-8');
$host = '127.0.0.1';
$port = 3306; // cambia a 3306 si corresponde
$db   = 'jira_sync';
$user = 'root';
$pass = '';

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    $pdo->query('SELECT 1');
    echo json_encode(['ok' => true, 'dsn' => $dsn]);
} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'dsn' => $dsn, 'error' => $t->getMessage()]);
}