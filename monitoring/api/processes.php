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

// Обработка действий с процессами: POST /api/processes.php?node_id={id}&pid={pid}&action=kill
if ($method === 'POST' && isset($_GET['node_id']) && isset($_GET['pid']) && isset($_GET['action'])) {
    $nodeId = $_GET['node_id'];
    $pid = $_GET['pid'];
    $action = $_GET['action'];
    
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $action;
    
    if (!in_array($action, ['kill', 'restart'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use kill or restart']);
        exit;
    }
    
    // Проверяем существование процесса
    $stmt = $pdo->prepare("SELECT * FROM processes WHERE node_id = ? AND pid = ?");
    $stmt->execute([$nodeId, $pid]);
    $process = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$process) {
        http_response_code(404);
        echo json_encode(['error' => 'Process not found']);
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
    $command = $action === 'kill' ? "kill {$pid}" : "restart {$pid}";
    $stmt = $pdo->prepare("UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW() WHERE id = ?");
    $stmt->execute([$command, $nodeId]);
    
    // TODO: Реальная реализация через SSH или API агента
    echo json_encode([
        'success' => true,
        'message' => "Command '{$action}' queued for process {$pid}",
        'node_id' => $nodeId,
        'pid' => $pid,
        'action' => $action
    ]);
    exit;
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
    
    if ($nodeId) {
        $stmt = $pdo->prepare("SELECT * FROM processes WHERE node_id = ? ORDER BY cpu_percent DESC");
        $stmt->execute([$nodeId]);
        $processes = $stmt->fetchAll();
        echo json_encode(['processes' => $processes]);
    } else {
        $stmt = $pdo->query("SELECT * FROM processes ORDER BY timestamp DESC");
        $processes = $stmt->fetchAll();
        echo json_encode(['processes' => $processes]);
    }
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
    
    // Поддержка формата от агента (processes - массив)
    $processes = null;
    if (isset($data['processes']) && is_array($data['processes'])) {
        $processes = $data['processes'];
    } elseif (is_array($data) && isset($data[0])) {
        $processes = $data;
    }
    
    if (!$nodeId) {
        http_response_code(400);
        echo json_encode(['error' => 'node_id is required']);
        return;
    }
    
    // Удаляем старые процессы для этой ноды
    $deleteStmt = $pdo->prepare("DELETE FROM processes WHERE node_id = ?");
    $deleteStmt->execute([$nodeId]);
    
    if ($processes && count($processes) > 0) {
        // Вставляем новые процессы
        $stmt = $pdo->prepare("INSERT INTO processes (node_id, pid, name, cpu_percent, memory_percent, status) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($processes as $process) {
            $stmt->execute([
                $nodeId,
                $process['pid'] ?? 0,
                $process['name'] ?? 'unknown',
                $process['cpu_percent'] ?? 0,
                $process['memory_percent'] ?? 0,
                $process['status'] ?? 'running'
            ]);
        }
    } else {
        // Один процесс (старый формат)
        $pid = $data['pid'] ?? null;
        $name = $data['name'] ?? null;
        $cpuPercent = $data['cpu_percent'] ?? null;
        $memoryPercent = $data['memory_percent'] ?? null;
        $status = $data['status'] ?? 'running';
        
        if (!$pid || !$name) {
            http_response_code(400);
            echo json_encode(['error' => 'pid and name are required']);
            return;
        }
        
        $stmt = $pdo->prepare("INSERT INTO processes (node_id, pid, name, cpu_percent, memory_percent, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nodeId, $pid, $name, $cpuPercent, $memoryPercent, $status]);
    }
    
    http_response_code(201);
    echo json_encode(['message' => 'Processes updated']);
}

// Обработка действий с процессами: POST /api/processes.php?node_id={id}&pid={pid}&action=kill
if ($method === 'POST' && isset($_GET['node_id']) && isset($_GET['pid']) && isset($_GET['action'])) {
    $nodeId = $_GET['node_id'];
    $pid = $_GET['pid'];
    $action = $_GET['action'];
    
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $action;
    
    if (!in_array($action, ['kill', 'restart'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use kill or restart']);
        exit;
    }
    
    // Проверяем существование процесса
    $stmt = $pdo->prepare("SELECT * FROM processes WHERE node_id = ? AND pid = ?");
    $stmt->execute([$nodeId, $pid]);
    $process = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$process) {
        http_response_code(404);
        echo json_encode(['error' => 'Process not found']);
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
    $command = $action === 'kill' ? "kill {$pid}" : "restart {$pid}";
    $stmt = $pdo->prepare("UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW() WHERE id = ?");
    $stmt->execute([$command, $nodeId]);
    
    // TODO: Реальная реализация через SSH или API агента
    echo json_encode([
        'success' => true,
        'message' => "Command '{$action}' queued for process {$pid}",
        'node_id' => $nodeId,
        'pid' => $pid,
        'action' => $action
    ]);
    exit;
}

