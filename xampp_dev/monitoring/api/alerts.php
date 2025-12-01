<?php
// API для получения алертов
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
        $action = $_GET['action'] ?? 'active';
        $limit = (int)($_GET['limit'] ?? 10);
        
        $sql = "SELECT a.*, n.name as node_name FROM alerts a LEFT JOIN nodes n ON a.node_id = n.id WHERE 1=1";
        $params = [];
        
        if ($action === 'active') {
            $sql .= " AND a.resolved = FALSE";
        }
        
        $sql .= " ORDER BY a.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Форматируем для совместимости с фронтендом
        $formattedAlerts = array_map(function($alert) {
            return [
                'id' => $alert['id'],
                'level' => $alert['level'],
                'title' => $alert['title'],
                'node' => $alert['node_name'] ?? 'N/A',
                'node_id' => $alert['node_id'],
                'timestamp' => $alert['created_at']
            ];
        }, $alerts);
        
        echo json_encode(['alerts' => $formattedAlerts]);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

