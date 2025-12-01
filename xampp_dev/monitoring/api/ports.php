<?php
// API для получения портов
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
        
        $sql = "SELECT p.*, n.name as node_name FROM ports p JOIN nodes n ON p.node_id = n.id WHERE 1=1";
        $params = [];
        
        if ($nodeId) {
            $sql .= " AND p.node_id = ?";
            $params[] = $nodeId;
        }
        
        $sql .= " ORDER BY p.port ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Форматируем для совместимости с фронтендом
        $formattedPorts = array_map(function($port) {
            return [
                'id' => $port['id'],
                'node_id' => $port['node_id'],
                'port' => $port['port'],
                'type' => $port['type'],
                'process' => $port['process_name'],
                'process_name' => $port['process_name'],
                'pid' => $port['pid'],
                'status' => $port['status'],
                'node_name' => $port['node_name']
            ];
        }, $ports);
        
        echo json_encode(['ports' => $formattedPorts]);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

