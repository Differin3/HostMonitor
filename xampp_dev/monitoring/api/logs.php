<?php
// API для получения логов
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
    if ($method === 'GET') {
        $nodeId = $_GET['node_id'] ?? null;
        $level = $_GET['level'] ?? null;
        $limit = (int)($_GET['limit'] ?? 100);
        
        $sql = "SELECT * FROM logs WHERE 1=1";
        $params = [];
        
        if ($nodeId) {
            $sql .= " AND node_id = ?";
            $params[] = $nodeId;
        }
        
        if ($level) {
            $sql .= " AND level = ?";
            $params[] = $level;
        }
        
        $sql .= " ORDER BY timestamp DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['logs' => $logs]);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

