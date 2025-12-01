<?php
// API для получения алертов
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDbConnection();
require_api_auth($pdo);

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'active';
        $limit = (int)($_GET['limit'] ?? 10);
        
        $sql = "SELECT a.*, COALESCE(n.name, CONCAT('Node ', a.node_id)) as node_name 
                FROM alerts a 
                LEFT JOIN nodes n ON a.node_id = n.id 
                WHERE 1=1";
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
        json_error('Method not allowed', 405);
    }
} catch (Throwable $e) {
    json_exception($e, false);
}
