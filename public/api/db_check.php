<?php
require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $ok = db_healthcheck();
    echo json_encode(['ok' => $ok, 'db' => DB_NAME], JSON_UNESCAPED_UNICODE);
} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $t->getMessage()]);
}