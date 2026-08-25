<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
session_write_close();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/dashboard_snapshot.php';

$pdo = getDbConnection();

try {
    echo json_encode(dashboard_summary($pdo));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
