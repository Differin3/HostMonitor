<?php
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
    switch ($method) {
        case 'GET':
            handleGet($pdo);
            break;
        case 'POST':
            handlePost($pdo);
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
    $nodeId = $_GET['node_id'] ?? null;
    $range = $_GET['range'] ?? '1h';
    $limit = (int)($_GET['limit'] ?? 100);
    
    // Вычисляем временной диапазон
    $timeRange = strtotime("-{$range}");
    if (!$timeRange) {
        $timeRange = strtotime('-1 hour');
    }
    
    if ($nodeId) {
        $stmt = $pdo->prepare("SELECT timestamp as ts, cpu_percent as cpu, memory_percent as ram, disk_percent as disk, network_in, network_out 
                              FROM metrics 
                              WHERE node_id = ? AND timestamp >= FROM_UNIXTIME(?) 
                              ORDER BY timestamp ASC 
                              LIMIT ?");
        $stmt->execute([$nodeId, $timeRange, $limit]);
        $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Агрегированные метрики для всех нод
        $stmt = $pdo->prepare("SELECT timestamp as ts, AVG(cpu_percent) as cpu, AVG(memory_percent) as ram, AVG(disk_percent) as disk, 
                              SUM(network_in) as network_in, SUM(network_out) as network_out
                              FROM metrics 
                              WHERE timestamp >= FROM_UNIXTIME(?) 
                              GROUP BY timestamp 
                              ORDER BY timestamp ASC 
                              LIMIT ?");
        $stmt->execute([$timeRange, $limit]);
        $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Форматируем для dashboard
    $data = array_map(function($m) {
        return [
            'ts' => $m['ts'],
            'cpu' => (float)($m['cpu'] ?? 0),
            'ram' => (float)($m['ram'] ?? 0),
            'memory' => (float)($m['ram'] ?? 0),
            'disk' => (float)($m['disk'] ?? 0),
            'network_in' => (float)($m['network_in'] ?? 0),
            'network_out' => (float)($m['network_out'] ?? 0)
        ];
    }, $metrics);
    
    echo json_encode(['data' => $data]);
}

function handlePost($pdo) {
    global $nodeInfo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    // Если запрос от агента, используем node_id из токена
    $nodeId = $nodeInfo ? $nodeInfo['id'] : ($data['node_id'] ?? null);
    
    // Поддержка формата от агента (metrics содержит данные)
    if (isset($data['metrics'])) {
        $metrics = $data['metrics'];
        $nodeName = $metrics['node_name'] ?? null;
        
        // Если node_name указан, находим ноду по имени
        if ($nodeName && !$nodeId) {
            $stmt = $pdo->prepare("SELECT id FROM nodes WHERE name = ?");
            $stmt->execute([$nodeName]);
            $node = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($node) {
                $nodeId = $node['id'];
            }
        }
        
        $cpuPercent = $metrics['cpu_percent'] ?? null;
        $memoryPercent = $metrics['memory_percent'] ?? null;
        $diskPercent = $metrics['disk_percent'] ?? null;
        $networkIn = $metrics['network_in'] ?? null;
        $networkOut = $metrics['network_out'] ?? null;
    } else {
        // Старый формат
        $cpuPercent = $data['cpu_percent'] ?? null;
        $memoryPercent = $data['memory_percent'] ?? null;
        $diskPercent = $data['disk_percent'] ?? null;
        $networkIn = $data['network_in'] ?? null;
        $networkOut = $data['network_out'] ?? null;
    }
    
    if (!$nodeId) {
        http_response_code(400);
        echo json_encode(['error' => 'node_id is required']);
        return;
    }
    
    // Обновляем статус ноды и last_seen
    $updateStmt = $pdo->prepare("UPDATE nodes SET status = 'online', last_seen = NOW() WHERE id = ?");
    $updateStmt->execute([$nodeId]);
    
    $stmt = $pdo->prepare("INSERT INTO metrics (node_id, cpu_percent, memory_percent, disk_percent, network_in, network_out) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$nodeId, $cpuPercent, $memoryPercent, $diskPercent, $networkIn, $networkOut]);
    
    $id = $pdo->lastInsertId();
    http_response_code(201);
    echo json_encode(['id' => $id, 'message' => 'Metric created']);
}

