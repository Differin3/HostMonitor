<?php
// API для получения портов
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDbConnection();
$auth = require_api_auth($pdo);
$nodeInfo = $auth['node'];

try {
    if ($method === 'GET') {
        $nodeId = $_GET['node_id'] ?? null;
        
        $sql = "SELECT p.*, COALESCE(n.name, CONCAT('Node ', p.node_id)) as node_name 
                FROM ports p 
                LEFT JOIN nodes n ON p.node_id = n.id 
                WHERE 1=1";
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
    } elseif ($method === 'POST') {
        // Если есть action в GET, это управление firewall
        if (isset($_GET['node_id']) && isset($_GET['action'])) {
            $nodeId = $_GET['node_id'];
            
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON']);
                exit;
            }
            
            $port = $data['port'] ?? null;
            $proto = $data['proto'] ?? 'tcp';
            $action = $data['action'] ?? $_GET['action'];
            
            if (!$port || !$action) {
                http_response_code(400);
                echo json_encode(['error' => 'port and action are required']);
                exit;
            }
            
            if (!in_array($action, ['allow', 'deny'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action. Use allow or deny']);
                exit;
            }
            
            // Получаем информацию о ноде
            $nodeStmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
            $nodeStmt->execute([$nodeId]);
            $node = $nodeStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$node) {
                http_response_code(404);
                echo json_encode(['error' => 'Node not found']);
                exit;
            }
            
            // Сохраняем команду в БД для выполнения агентом
            $command = "firewall {$action} {$port}/{$proto}";
            $stmt = $pdo->prepare("UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW() WHERE id = ?");
            $stmt->execute([$command, $nodeId]);
            
            echo json_encode([
                'success' => true,
                'message' => "Firewall rule '{$action}' queued for port {$port}/{$proto}",
                'node_id' => $nodeId,
                'port' => $port,
                'proto' => $proto,
                'action' => $action
            ]);
            exit;
        }
        
        // Прием данных о портах от агента
        global $nodeInfo;
        $raw = file_get_contents('php://input');
        $data = $raw !== '' ? json_decode($raw, true) : null;
        
        if ($data === null && $raw !== '') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }
        
        // Если запрос от агента, используем node_id из токена
        $nodeId = $nodeInfo ? $nodeInfo['id'] : ($data['node_id'] ?? null);
        
        if (!$nodeId) {
            http_response_code(400);
            echo json_encode(['error' => 'node_id is required']);
            exit;
        }
        
        // Поддержка формата от агента (массив портов)
        $ports = null;
        if (is_array($data) && isset($data[0]) && isset($data[0]['port'])) {
            $ports = $data;
        } elseif (isset($data['ports']) && is_array($data['ports'])) {
            $ports = $data['ports'];
        }
        
        // Если агент прислал пустой массив портов — очищаем и выходим без ошибки
        if (is_array($data) && isset($data[0]) === false && $ports === null) {
            $deleteStmt = $pdo->prepare("DELETE FROM ports WHERE node_id = ?");
            $deleteStmt->execute([$nodeId]);
            
            echo json_encode(['message' => 'No ports reported', 'count' => 0]);
            exit;
        }
        
        if ($ports && count($ports) > 0) {
            // Удаляем старые порты для этой ноды
            $deleteStmt = $pdo->prepare("DELETE FROM ports WHERE node_id = ?");
            $deleteStmt->execute([$nodeId]);
            
            // Вставляем новые порты
            $stmt = $pdo->prepare("INSERT INTO ports (node_id, port, type, status, process_name, pid) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($ports as $port) {
                $stmt->execute([
                    $nodeId,
                    $port['port'] ?? 0,
                    $port['type'] ?? 'tcp',
                    $port['status'] ?? 'open',
                    $port['process_name'] ?? null,
                    $port['pid'] ?? null
                ]);
            }
            
            echo json_encode(['message' => 'Ports updated', 'count' => count($ports)]);
            exit;
        }
        
        // Старый формат (один порт)
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data format']);
    } else {
        json_error('Method not allowed', 405);
    }
} catch (Throwable $e) {
    json_exception($e, false);
}
