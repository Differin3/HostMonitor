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
require_once __DIR__ . '/../includes/helpers.php';
require_csrf();
session_write_close();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/dashboard_snapshot.php';

$pdo = getDbConnection();

try {
    $action = $_GET['action'] ?? '';
    if ($action === 'network_per_node') {
        $range = (string)($_GET['range'] ?? '1h');
        $from = isset($_GET['from']) ? (int)$_GET['from'] : null;
        $to = isset($_GET['to']) ? (int)$_GET['to'] : null;
        echo json_encode(dashboard_network_per_node($pdo, $range, 5, $from, $to));
    } else {
        echo json_encode(dashboard_summary($pdo));
    }
} catch (Exception $e) {
    app_log_error('summary.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
