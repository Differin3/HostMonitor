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
        case 'PUT':
            handlePut($pdo);
            break;
        case 'DELETE':
            handleDelete($pdo);
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
    $id = $_GET['id'] ?? null;
    
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM providers WHERE id = ?");
        $stmt->execute([$id]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$provider) {
            http_response_code(404);
            echo json_encode(['error' => 'Provider not found']);
            return;
        }
        
        echo json_encode(['provider' => $provider]);
    } else {
        $stmt = $pdo->query("SELECT * FROM providers ORDER BY name ASC");
        $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['providers' => $providers]);
    }
}

function handlePost($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data) || !isset($data['name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Name required']);
        return;
    }
    
    $name = $data['name'];
    $url = $data['url'] ?? null;
    $favicon_url = $data['favicon_url'] ?? null;
    
    $stmt = $pdo->prepare("INSERT INTO providers (name, url, favicon_url) VALUES (?, ?, ?)");
    $stmt->execute([$name, $url, $favicon_url]);
    
    $id = $pdo->lastInsertId();
    echo json_encode(['success' => true, 'id' => $id]);
}

function handlePut($pdo) {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data']);
        return;
    }
    
    $name = $data['name'] ?? null;
    $url = $data['url'] ?? null;
    $favicon_url = $data['favicon_url'] ?? null;
    
    $stmt = $pdo->prepare("UPDATE providers SET name = ?, url = ?, favicon_url = ? WHERE id = ?");
    $stmt->execute([$name, $url, $favicon_url, $id]);
    
    echo json_encode(['success' => true]);
}

function handleDelete($pdo) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
        return;
    }
    
    $stmt = $pdo->prepare("DELETE FROM providers WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true]);
}

