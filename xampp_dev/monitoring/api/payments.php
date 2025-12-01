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

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDbConnection();

try {
    switch ($method) {
        case 'GET':
            handleGet($pdo);
            break;
        case 'POST':
            handlePost($pdo);
            break;
        case 'DELETE':
            handleDelete($pdo);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleGet($pdo) {
    $id = $_GET['id'] ?? null;
    $nodeId = $_GET['node_id'] ?? null;
    
    if ($id) {
        $stmt = $pdo->prepare("SELECT p.*, n.name as node_name FROM payments p LEFT JOIN nodes n ON p.node_id = n.id WHERE p.id = ?");
        $stmt->execute([$id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            http_response_code(404);
            echo json_encode(['error' => 'Payment not found']);
            return;
        }
        
        echo json_encode(['payment' => $payment]);
    } else {
        $sql = "SELECT p.*, n.name as node_name FROM payments p LEFT JOIN nodes n ON p.node_id = n.id";
        $params = [];
        
        if ($nodeId) {
            $sql .= " WHERE p.node_id = ?";
            $params[] = $nodeId;
        }
        
        $sql .= " ORDER BY p.payment_date DESC, p.id DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['payments' => $payments]);
    }
}

function handlePost($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data) || !isset($data['node_id']) || !isset($data['amount']) || !isset($data['payment_date'])) {
        http_response_code(400);
        echo json_encode(['error' => 'node_id, amount and payment_date required']);
        return;
    }
    
    $nodeId = $data['node_id'];
    $amount = $data['amount'];
    $paymentDate = $data['payment_date'];
    $currency = $data['currency'] ?? 'RUB';
    
    // Проверяем существование ноды
    $stmt = $pdo->prepare("SELECT id FROM nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Node not found']);
        return;
    }
    
    $stmt = $pdo->prepare("INSERT INTO payments (node_id, amount, currency, payment_date) VALUES (?, ?, ?, ?)");
    $stmt->execute([$nodeId, $amount, $currency, $paymentDate]);
    
    $id = $pdo->lastInsertId();
    
    // Обновляем даты в ноде
    $updateStmt = $pdo->prepare("UPDATE nodes SET last_payment_date = ?, next_payment_date = ? WHERE id = ?");
    $lastPayment = new DateTime($paymentDate);
    $billingPeriod = $pdo->prepare("SELECT billing_period FROM nodes WHERE id = ?");
    $billingPeriod->execute([$nodeId]);
    $period = $billingPeriod->fetchColumn() ?: 30;
    $nextPayment = clone $lastPayment;
    $nextPayment->modify("+{$period} days");
    
    $updateStmt->execute([
        $lastPayment->format('Y-m-d'),
        $nextPayment->format('Y-m-d'),
        $nodeId
    ]);
    
    echo json_encode(['success' => true, 'id' => $id]);
}

function handleDelete($pdo) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
        return;
    }
    
    $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true]);
}

