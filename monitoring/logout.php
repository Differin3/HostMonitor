<?php
// Выход из системы
session_start();
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/helpers.php';

// Логируем выход если пользователь был авторизован
if (isset($_SESSION['user_id'])) {
    try {
        $pdo = getDbConnection();
        log_auth_event($pdo, $_SESSION['user_id'], $_SESSION['username'] ?? 'unknown', 'logout', true, 'User logged out');
    } catch (Exception $e) {
        error_log("Error logging logout: " . $e->getMessage());
    }
}

session_destroy();
header('Location: login.php');
exit;

