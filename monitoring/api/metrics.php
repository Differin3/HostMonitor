<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDbConnection();

// Для GET запросов (дашборд) проверяем только сессию пользователя
// Для POST запросов (от агента) проверяем токен
$nodeInfo = null;
if ($method === 'GET') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
} else {
    $auth = require_api_auth($pdo);
    $nodeInfo = $auth['node'];
}

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
    $from = isset($_GET['from']) ? (int)$_GET['from'] : null;
    $to = isset($_GET['to']) ? (int)$_GET['to'] : null;

    // Вычисляем временной диапазон
    if ($from || $to) {
        // Приоритет явно заданным датам (from/to в unix)
        if (!$from) {
            // если не задан from — берём "час назад" от to или сейчас
            $base = $to ?: time();
            $from = $base - 3600;
        }
        if (!$to) {
            $to = time();
        }
    } else {
        // Старый режим с range
        $timeRange = strtotime("-{$range}");
        if (!$timeRange) {
            $timeRange = strtotime('-1 hour');
        }
        $from = $timeRange;
        $to = null;
    }
    
    if ($nodeId) {
        if ($to) {
            $stmt = $pdo->prepare("SELECT timestamp as ts, cpu_percent as cpu, memory_percent as ram, disk_percent as disk, network_in, network_out 
                                   FROM metrics 
                                   WHERE node_id = ? 
                                     AND timestamp BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?) 
                                   ORDER BY timestamp ASC 
                                   LIMIT ?");
            $stmt->execute([$nodeId, $from, $to, $limit]);
        } else {
            $stmt = $pdo->prepare("SELECT timestamp as ts, cpu_percent as cpu, memory_percent as ram, disk_percent as disk, network_in, network_out 
                                   FROM metrics 
                                   WHERE node_id = ? 
                                     AND timestamp >= FROM_UNIXTIME(?) 
                                   ORDER BY timestamp ASC 
                                   LIMIT ?");
            $stmt->execute([$nodeId, $from, $limit]);
        }
        $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Агрегированные метрики для всех нод
        if ($to) {
            $stmt = $pdo->prepare("SELECT timestamp as ts, AVG(cpu_percent) as cpu, AVG(memory_percent) as ram, AVG(disk_percent) as disk, 
                                          SUM(network_in) as network_in, SUM(network_out) as network_out
                                   FROM metrics 
                                   WHERE timestamp BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?) 
                                   GROUP BY timestamp 
                                   ORDER BY timestamp ASC 
                                   LIMIT ?");
            $stmt->execute([$from, $to, $limit]);
        } else {
            $stmt = $pdo->prepare("SELECT timestamp as ts, AVG(cpu_percent) as cpu, AVG(memory_percent) as ram, AVG(disk_percent) as disk, 
                                          SUM(network_in) as network_in, SUM(network_out) as network_out
                                   FROM metrics 
                                   WHERE timestamp >= FROM_UNIXTIME(?) 
                                   GROUP BY timestamp 
                                   ORDER BY timestamp ASC 
                                   LIMIT ?");
            $stmt->execute([$from, $limit]);
        }
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
        
        // Если node_id не установлен из токена, пытаемся найти по имени
        if (!$nodeId && $nodeName) {
            $stmt = $pdo->prepare("SELECT id FROM nodes WHERE name = ?");
            $stmt->execute([$nodeName]);
            $node = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($node) {
                $nodeId = $node['id'];
            } else {
                error_log("[metrics.php] Node not found by name: {$nodeName}");
            }
        }
        
        $cpuPercent = $metrics['cpu_percent'] ?? null;
        $memoryPercent = $metrics['memory_percent'] ?? null;
        $diskPercent = $metrics['disk_percent'] ?? null;
        $networkIn = $metrics['network_in'] ?? null;
        $networkOut = $metrics['network_out'] ?? null;
        $gpuInfo = $metrics['gpu'] ?? null;
    } else {
        // Старый формат
        $cpuPercent = $data['cpu_percent'] ?? null;
        $memoryPercent = $data['memory_percent'] ?? null;
        $diskPercent = $data['disk_percent'] ?? null;
        $networkIn = $data['network_in'] ?? null;
        $networkOut = $data['network_out'] ?? null;
        $gpuInfo = $data['gpu'] ?? null;
    }
    
    if (!$nodeId) {
        error_log("[metrics.php] node_id is required. nodeInfo: " . json_encode($nodeInfo) . ", node_name: " . ($nodeName ?? 'null'));
        http_response_code(400);
        echo json_encode(['error' => 'node_id is required. Check node_token or provide node_name in metrics.']);
        return;
    }
    
    // Обновляем статус ноды и last_seen
    $updateStmt = $pdo->prepare("UPDATE nodes SET status = 'online', last_seen = NOW() WHERE id = ?");
    $updateStmt->execute([$nodeId]);
    
    // Сохраняем основные метрики
    $stmt = $pdo->prepare("INSERT INTO metrics (node_id, cpu_percent, memory_percent, disk_percent, network_in, network_out) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$nodeId, $cpuPercent, $memoryPercent, $diskPercent, $networkIn, $networkOut]);
    $id = $pdo->lastInsertId();
    
    // Сохраняем GPU метрики если есть
    if ($gpuInfo && is_array($gpuInfo)) {
        // Проверяем наличие таблицы gpu_metrics
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS gpu_metrics (
                id INT AUTO_INCREMENT PRIMARY KEY,
                node_id INT,
                gpu_index INT,
                gpu_name VARCHAR(255),
                vendor VARCHAR(20),
                utilization FLOAT,
                memory_used BIGINT,
                memory_total BIGINT,
                temperature FLOAT,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_node_id (node_id),
                INDEX idx_timestamp (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Exception $e) {
            error_log("Error creating gpu_metrics table: " . $e->getMessage());
        }
        
        // Удаляем старые GPU метрики для этой ноды
        $deleteGpuStmt = $pdo->prepare("DELETE FROM gpu_metrics WHERE node_id = ?");
        $deleteGpuStmt->execute([$nodeId]);
        
        // Вставляем новые GPU метрики
        $gpuStmt = $pdo->prepare("INSERT INTO gpu_metrics (node_id, gpu_index, gpu_name, vendor, utilization, memory_used, memory_total, temperature) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($gpuInfo as $gpu) {
            $gpuStmt->execute([
                $nodeId,
                $gpu['index'] ?? 0,
                $gpu['name'] ?? 'Unknown',
                $gpu['vendor'] ?? 'unknown',
                $gpu['utilization'] ?? 0,
                $gpu['memory_used'] ?? 0,
                $gpu['memory_total'] ?? 0,
                $gpu['temperature'] ?? 0
            ]);
        }
    }
    
    http_response_code(201);
    echo json_encode(['id' => $id, 'message' => 'Metric created']);
}

