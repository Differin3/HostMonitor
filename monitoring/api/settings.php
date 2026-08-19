<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    json_error('Unauthorized', 401);
}

try {
    $pdo = getDbConnection();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $key = isset($_GET['key']) ? (string)$_GET['key'] : '';
        $public = settings_public();
        if ($key !== '') {
            if (!array_key_exists($key, settings_defaults())) {
                json_error('Unknown setting', 404);
            }
            echo json_encode(['key' => $key, 'value' => $public[$key] ?? null], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true, 'settings' => $public], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        json_error('Method not allowed', 405);
    }
    if (($_SESSION['role'] ?? 'admin') !== 'admin') {
        json_error('Forbidden', 403);
    }

    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) {
        json_error('Invalid JSON', 400);
    }

    if (($data['action'] ?? '') === 'cleanup_history') {
        require_once __DIR__ . '/../includes/retention.php';
        $result = retention_run($pdo, true);
        echo json_encode(['ok' => true, 'success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pairs = [];
    if (isset($data['settings']) && is_array($data['settings'])) {
        $pairs = $data['settings'];
    } elseif (isset($data['key'])) {
        $pairs[(string)$data['key']] = $data['value'] ?? '';
    } else {
        json_error('settings или key+value обязательны');
    }

    $saved = settings_upsert($pdo, $pairs);
    echo json_encode([
        'ok' => true,
        'success' => true,
        'saved' => $saved,
        'settings' => settings_public(),
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage(), 400);
} catch (Throwable $e) {
    json_exception($e, true);
}
