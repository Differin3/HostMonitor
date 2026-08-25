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
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Обработка запроса логов процесса напрямую с ноды: GET /api/processes.php?node_id=X&pid=Y&logs=1
if ($method === 'GET' && isset($_GET['logs']) && $_GET['logs'] == '1' && isset($_GET['node_id']) && isset($_GET['pid'])) {
    $nodeId = (int)$_GET['node_id'];
    $pid = (int)$_GET['pid'];
    $fromTime = $_GET['from'] ?? null;
    $toTime = $_GET['to'] ?? null;
    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 10000)) : 1000;
    
    // Получаем информацию о ноде
    $nodeStmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
    $nodeStmt->execute([$nodeId]);
    $node = $nodeStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$node) {
        http_response_code(404);
        echo json_encode(['error' => 'Node not found']);
        exit;
    }
    
    // Формируем команду для агента (from/to — unix timestamp, без shell-кавычек)
    $command = "get-process-logs {$pid}";
    if ($fromTime !== null && $fromTime !== '' && ctype_digit((string)$fromTime)) {
        $command .= ' --from ' . (int)$fromTime;
    }
    if ($toTime !== null && $toTime !== '' && ctype_digit((string)$toTime)) {
        $command .= ' --to ' . (int)$toTime;
    }
    $command .= " --limit {$limit}";

    // Очищаем предыдущий результат и ставим pending (force — логи не ждут чужой команды)
    $stmt = $pdo->prepare(
        "UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW(), command_result = NULL WHERE id = ?"
    );
    $stmt->execute([$command, $nodeId]);
    
    // Логируем для отладки
    error_log("Process logs command queued: node_id={$nodeId}, node_name={$node['name']}, pid={$pid}, command={$command}");
    
    // Проверяем что команда действительно сохранена (по id и по name для агента)
    $checkStmt = $pdo->prepare("SELECT id, name, last_command, command_status FROM nodes WHERE id = ? OR name = ?");
    $checkStmt->execute([$nodeId, $node['name']]);
    $check = $checkStmt->fetch(PDO::FETCH_ASSOC);
    error_log("Command saved check: id={$check['id']}, name={$check['name']}, command=" . ($check['last_command'] ?? 'NULL') . ", status=" . ($check['command_status'] ?? 'NULL'));
    
    // Возвращаем статус что команда поставлена, фронтенд будет делать polling
    echo json_encode([
        'status' => 'pending',
        'message' => 'Command queued, use polling to get results',
        'command' => $command,
        'node_id' => $nodeId,
        'node_name' => $node['name'] ?? null
    ]);
    exit;
}

// Получение результата команды: GET /api/processes.php?node_id=X&pid=Y&get-result=1
if ($method === 'GET' && isset($_GET['get-result']) && $_GET['get-result'] == '1' && isset($_GET['node_id']) && isset($_GET['pid'])) {
    $nodeId = (int)$_GET['node_id'];
    $pid = (int)$_GET['pid'];
    
    // Проверяем статус команды и результат
    $statusStmt = $pdo->prepare("SELECT command_status, command_result FROM nodes WHERE id = ?");
    $statusStmt->execute([$nodeId]);
    $status = $statusStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$status) {
        http_response_code(404);
        echo json_encode(['error' => 'Node not found']);
        exit;
    }
    
    if ($status['command_status'] === 'completed' && $status['command_result'] !== null && $status['command_result'] !== '') {
        // Команда выполнена (в т.ч. пустой массив логов "[]")
        $logs = json_decode($status['command_result'], true);
        if (!is_array($logs)) {
            $logs = [];
        }
        echo json_encode([
            'status' => 'completed',
            'logs' => $logs,
            'count' => count($logs),
            'source' => 'node'
        ]);
    } elseif ($status['command_status'] === 'failed') {
        echo json_encode([
            'status' => 'failed',
            'error' => 'Failed to get logs from node'
        ]);
    } else {
        // Команда еще выполняется
        echo json_encode([
            'status' => 'pending',
            'message' => 'Command is still executing'
        ]);
    }
    exit;
}

// Прием результата команды от агента: POST /api/processes.php?action=command-result
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'command-result') {
    global $nodeInfo;
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$nodeInfo) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $nodeId = $nodeInfo['id'];
    $command = $data['command'] ?? '';
    $logs = $data['logs'] ?? [];
    
    error_log("Received command result from node_id={$nodeId}, command={$command}, logs_count=" . count($logs));
    
    // Сохраняем результат команды
    $resultJson = json_encode($logs, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("UPDATE nodes SET command_result = ?, command_status = 'completed' WHERE id = ?");
    $stmt->execute([$resultJson, $nodeId]);
    
    // Проверяем что результат сохранен
    $checkStmt = $pdo->prepare("SELECT command_result, command_status FROM nodes WHERE id = ?");
    $checkStmt->execute([$nodeId]);
    $check = $checkStmt->fetch(PDO::FETCH_ASSOC);
    error_log("Command result saved: status=" . ($check['command_status'] ?? 'NULL') . ", result_length=" . (strlen($check['command_result'] ?? '')));
    
    echo json_encode([
        'success' => true,
        'message' => 'Command result saved',
        'logs_count' => count($logs)
    ]);
    exit;
}

// Обработка действий с процессами: POST /api/processes.php?node_id={id}&pid={pid}&action=kill
if ($method === 'POST' && isset($_GET['node_id']) && isset($_GET['pid']) && isset($_GET['action'])) {
    $nodeId = (int)$_GET['node_id'];
    $pid = (int)$_GET['pid'];
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
    error_log('processes.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
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

