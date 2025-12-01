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

function generateFaviconUrl($url) {
    if (!$url) return null;
    $trimmed = trim($url);
    if (!$trimmed) return null;
    
    // Если это уже полный URL с протоколом
    if (preg_match('/^https?:\/\//i', $trimmed)) {
        try {
            $parsed = parse_url($trimmed);
            $domain = $parsed['host'] ?? null;
            if ($domain) {
                return "https://www.google.com/s2/favicons?sz=64&domain=" . urlencode($domain);
            }
        } catch (Exception $e) {
            // Ignore
        }
        return $trimmed;
    }
    
    // Если это домен без протокола
    $domain = preg_split('/[\/?#]/', $trimmed)[0];
    return "https://www.google.com/s2/favicons?sz=64&domain=" . urlencode($domain);
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
    
    // Автоматически генерируем favicon_url, если не указан, но есть url
    if (!$favicon_url && $url) {
        $favicon_url = generateFaviconUrl($url);
    }
    
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
    
    // Автоматически генерируем favicon_url, если не указан, но есть url
    if (!$favicon_url && $url) {
        $favicon_url = generateFaviconUrl($url);
    }
    
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

