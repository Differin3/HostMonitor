<?php
// Проверка сессии и авторизации
// Настройка сессий для PHP-CGI
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Lax');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';

if (db_needs_setup()) {
    header('Location: setup.php');
    exit;
}

if (db_is_configured()) {
    $dbOk = false;
    try {
        $pdo = getDbConnection();
        if ($pdo) {
            $pdo->query('SELECT 1');
            $dbOk = true;
        }
    } catch (Throwable $e) {
        $dbOk = false;
    }
    if (!$dbOk) {
        $status = db_connection_status();
        db_render_error_page($status);
        exit;
    }
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$timeoutMin = (int)setting_get('session_timeout_minutes', '60');
if ($timeoutMin < 5) {
    $timeoutMin = 5;
}
if ($timeoutMin > 1440) {
    $timeoutMin = 1440;
}
$last = (int)($_SESSION['last_activity'] ?? time());
if ((time() - $last) > ($timeoutMin * 60)) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = time();

