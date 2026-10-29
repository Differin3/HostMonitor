<?php
/**
 * api/billing_expense_match.php
 *
 * Ручное сопоставление расхода: нода, домен и/или платёж.
 * Обновляются только переданные поля — остальные связи не трогаются.
 */
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
    if (!is_array($data)) $data = [];
    $expenseId = (int)($data['expense_id'] ?? 0);
    if ($expenseId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'expense_id required']);
        return;
    }

    $toId = function ($v) {
        if ($v === null || $v === '' || $v === 0 || $v === '0') return null;
        $n = (int)$v;
        return $n > 0 ? $n : null;
    };

    $sets = []; $params = [];
    $map = ['node_id' => 'matched_node_id', 'domain_id' => 'matched_domain_id', 'payment_id' => 'matched_payment_id'];
    foreach ($map as $field => $column) {
        if (array_key_exists($field, $data)) {
            $sets[] = "$column = ?";
            $params[] = $toId($data[$field]);
        }
    }
    if (!$sets) {
        http_response_code(400);
        echo json_encode(['error' => 'node_id, domain_id or payment_id required']);
        return;
    }

    $sets[] = "match_type = 'manual'";
    $sets[] = "updated_at = NOW()";
    $params[] = $expenseId;

    $stmt = $pdo->prepare("UPDATE billing_expenses SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    app_log_error('billing_expense_match.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
