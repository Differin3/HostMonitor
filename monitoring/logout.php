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
        // Убираем запись текущей сессии из реестра, иначе она остаётся
        // висеть в «Активных сессиях» до ручного отзыва.
        if (function_exists('session_revoke_current')) {
            session_revoke_current($pdo, (int)$_SESSION['user_id']);
        }
    } catch (Exception $e) {
        app_log_warning("Error logging logout: " . $e->getMessage());
    }
}

session_destroy();
header('Location: login.php');
exit;

