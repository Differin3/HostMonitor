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
        $stmt = $pdo->prepare("SELECT * FROM containers WHERE node_id = ? ORDER BY name");
        $stmt->execute([$nodeId]);
        $containers = $stmt->fetchAll();
        echo json_encode(['containers' => $containers]);
    } else {
        $stmt = $pdo->query("SELECT * FROM containers ORDER BY timestamp DESC");
        $containers = $stmt->fetchAll();
        echo json_encode(['containers' => $containers]);
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
    $containerId = $data['container_id'] ?? null;
    $name = $data['name'] ?? null;
    $image = $data['image'] ?? null;
    $status = $data['status'] ?? 'stopped';
    $cpuPercent = $data['cpu_percent'] ?? 0;
    $memoryPercent = $data['memory_percent'] ?? 0;
    
    if (!$nodeId || !$containerId || !$name) {
        http_response_code(400);
        echo json_encode(['error' => 'node_id, container_id, and name are required']);
        return;
    }
    
    // Обновляем или вставляем контейнер
    $stmt = $pdo->prepare("INSERT INTO containers (node_id, container_id, name, image, status, cpu_percent, memory_percent) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE 
                          name = VALUES(name), 
                          image = VALUES(image), 
                          status = VALUES(status), 
                          cpu_percent = VALUES(cpu_percent), 
                          memory_percent = VALUES(memory_percent)");
    
    // Если нет уникального ключа на container_id, используем обычный INSERT
    $checkStmt = $pdo->prepare("SELECT id FROM containers WHERE node_id = ? AND container_id = ?");
    $checkStmt->execute([$nodeId, $containerId]);
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        $updateStmt = $pdo->prepare("UPDATE containers SET name = ?, image = ?, status = ?, cpu_percent = ?, memory_percent = ? WHERE id = ?");
        $updateStmt->execute([$name, $image, $status, $cpuPercent, $memoryPercent, $existing['id']]);
    } else {
        $insertStmt = $pdo->prepare("INSERT INTO containers (node_id, container_id, name, image, status, cpu_percent, memory_percent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->execute([$nodeId, $containerId, $name, $image, $status, $cpuPercent, $memoryPercent]);
    }
    
    http_response_code(201);
    echo json_encode(['message' => 'Container updated']);
}

