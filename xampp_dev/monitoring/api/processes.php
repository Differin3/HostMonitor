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
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    $nodeId = $data['node_id'] ?? null;
    $pid = $data['pid'] ?? null;
    $name = $data['name'] ?? null;
    $cpuPercent = $data['cpu_percent'] ?? null;
    $memoryPercent = $data['memory_percent'] ?? null;
    $status = $data['status'] ?? 'running';
    
    if (!$nodeId || !$pid || !$name) {
        http_response_code(400);
        echo json_encode(['error' => 'node_id, pid, and name are required']);
        return;
    }
    
    // Удаляем старые процессы для этой ноды
    $deleteStmt = $pdo->prepare("DELETE FROM processes WHERE node_id = ?");
    $deleteStmt->execute([$nodeId]);
    
    // Вставляем новые процессы
    if (is_array($data) && isset($data[0])) {
        // Массив процессов
        $stmt = $pdo->prepare("INSERT INTO processes (node_id, pid, name, cpu_percent, memory_percent, status) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($data as $process) {
            $stmt->execute([
                $process['node_id'] ?? $nodeId,
                $process['pid'],
                $process['name'],
                $process['cpu_percent'] ?? 0,
                $process['memory_percent'] ?? 0,
                $process['status'] ?? 'running'
            ]);
        }
    } else {
        // Один процесс
        $stmt = $pdo->prepare("INSERT INTO processes (node_id, pid, name, cpu_percent, memory_percent, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nodeId, $pid, $name, $cpuPercent, $memoryPercent, $status]);
    }
    
    http_response_code(201);
    echo json_encode(['message' => 'Processes updated']);
}

