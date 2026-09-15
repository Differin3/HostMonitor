<?php
// Управление активными сессиями пользователя
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    json_error('Unauthorized', 401);
}
$userId = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? '');

$pdo = getDbConnection();
require_csrf();

$action = $_GET['action'] ?? '';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET' && $action === 'list') {
    $cur = current_session_hash();
    $sessions = session_list($pdo, $userId);
    foreach ($sessions as &$s) {
        $s['current'] = hash_equals($cur, (string)$s['session_hash']);
        unset($s['session_hash']);
    }
    unset($s);
    echo json_encode(['sessions' => $sessions]);
    exit;
}

if ($method === 'POST') {
    $data = json_decode((string)file_get_contents('php://input'), true) ?: [];

    if ($action === 'revoke') {
        session_revoke($pdo, $userId, (int)($data['id'] ?? 0));
        session_write_close();
        log_auth_event($pdo, $userId, $username, 'session_revoke', true, 'Session terminated');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'revoke-others') {
        session_revoke_others($pdo, $userId);
        session_write_close();
        log_auth_event($pdo, $userId, $username, 'session_revoke_others', true, 'Other sessions terminated');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'revoke-all') {
        session_revoke_all($pdo, $userId);
        log_auth_event($pdo, $userId, $username, 'session_revoke_all', true, 'All sessions terminated');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
        echo json_encode(['success' => true, 'logout' => true]);
        exit;
    }
}

json_error('Unknown action', 400);
