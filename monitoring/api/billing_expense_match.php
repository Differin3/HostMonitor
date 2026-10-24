<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_csrf();
session_write_close();

header('Content-Type: application/json; charset=utf-8');
$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';

try {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $expenseId = (int)($data['expense_id'] ?? 0);
    $nodeId = isset($data['node_id']) && $data['node_id'] !== '' ? (int)$data['node_id'] : null;
    $domainId = isset($data['domain_id']) && $data['domain_id'] !== '' ? (int)$data['domain_id'] : null;
    if ($expenseId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'expense_id required']);
        return;
    }
    if (!$nodeId && !$domainId) {
        http_response_code(400);
        echo json_encode(['error' => 'node_id or domain_id required']);
        return;
    }
    $stmt = $pdo->prepare("UPDATE billing_expenses SET matched_node_id=?, matched_domain_id=?, match_type='manual', updated_at=NOW() WHERE id=?");
    $stmt->execute([$nodeId, $domainId, $expenseId]);
    echo json_encode(['success'=>true]);
} catch (Exception $e) {
    app_log_error('billing_expense_match.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
