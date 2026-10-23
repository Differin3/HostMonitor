<?php
/**
 * api/providers.php — CRUD провайдеров
 *
 * Исправления:
 *  - catch (Throwable) вместо catch (Exception) — ловит Error/TypeError
 *  - require_csrf()/getDbConnection() внутри try — их падение отдаёт JSON
 *  - Content-Type выставлен до возможных ошибок
 *  - валидация url/favicon_url по длине и FILTER_VALIDATE_URL
 *  - мёртвый try/catch в generateFaviconUrl убран
 *  - app_log_error() через function_exists
 *  - в 500 временно добавлен debug (УБРАТЬ ПОСЛЕ ОТЛАДКИ)
 */

header('Content-Type: application/json; charset=utf-8');

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    require_once __DIR__ . '/../includes/database.php';
    require_once __DIR__ . '/../includes/helpers.php';

    require_csrf();
    session_write_close();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $pdo = getDbConnection();

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
} catch (Throwable $e) {
    error_log('[providers] ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (function_exists('app_log_error')) {
        app_log_error('[providers] ' . get_class($e) . ': ' . $e->getMessage());
    }
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        // ===== ВРЕМЕННО, для отладки. УБРАТЬ ПОСЛЕ ФИКСА =====
        'debug' => $e->getMessage(),
        'type'  => get_class($e),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        // ====================================================
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ---------------------------- handlers ---------------------------- */

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
    $trimmed = trim((string)$url);
    if ($trimmed === '') return null;

    if (preg_match('/^https?:\/\//i', $trimmed)) {
        $parsed = parse_url($trimmed);
        $domain = $parsed['host'] ?? null;
        if ($domain) {
            return "https://www.google.com/s2/favicons?sz=64&domain=" . urlencode($domain);
        }
        return $trimmed;
    }

    $parts = preg_split('/[\/?#]/', $trimmed);
    $domain = trim($parts[0] ?? '');
    if ($domain === '') return null;
    return "https://www.google.com/s2/favicons?sz=64&domain=" . urlencode($domain);
}

function handlePost($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data) || empty($data['name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Name required']);
        return;
    }

    $name = trim((string)$data['name']);
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Name required']);
        return;
    }
    if (mb_strlen($name, 'UTF-8') > 255) {
        http_response_code(400);
        echo json_encode(['error' => 'Name too long (max 255)']);
        return;
    }

    $url = isset($data['url']) && $data['url'] !== '' ? trim((string)$data['url']) : null;
    if ($url !== null) {
        if (strlen($url) > 255) {
            http_response_code(400);
            echo json_encode(['error' => 'url too long (max 255)']);
            return;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid url']);
            return;
        }
    }

    $favicon_url = isset($data['favicon_url']) && $data['favicon_url'] !== ''
        ? trim((string)$data['favicon_url'])
        : null;

    if (!$favicon_url && $url) {
        $favicon_url = generateFaviconUrl($url);
    }

    if ($favicon_url !== null && strlen($favicon_url) > 255) {
        http_response_code(400);
        echo json_encode([
            'error' => 'favicon_url too long (max 255)',
            'length' => strlen($favicon_url),
        ]);
        return;
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
    if (!is_array($data) || empty($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data']);
        return;
    }

    $check = $pdo->prepare("SELECT id FROM providers WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Provider not found']);
        return;
    }

    $name = isset($data['name']) ? trim((string)$data['name']) : null;
    if ($name !== null && $name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Name empty']);
        return;
    }
    if ($name !== null && mb_strlen($name, 'UTF-8') > 255) {
        http_response_code(400);
        echo json_encode(['error' => 'Name too long (max 255)']);
        return;
    }

    $url = isset($data['url']) && $data['url'] !== '' ? trim((string)$data['url']) : null;
    if ($url !== null) {
        if (strlen($url) > 255) {
            http_response_code(400);
            echo json_encode(['error' => 'url too long (max 255)']);
            return;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid url']);
            return;
        }
    }

    $favicon_url = isset($data['favicon_url']) && $data['favicon_url'] !== ''
        ? trim((string)$data['favicon_url'])
        : null;

    if (!$favicon_url && $url) {
        $favicon_url = generateFaviconUrl($url);
    }
    if ($favicon_url !== null && strlen($favicon_url) > 255) {
        http_response_code(400);
        echo json_encode([
            'error' => 'favicon_url too long (max 255)',
            'length' => strlen($favicon_url),
        ]);
        return;
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