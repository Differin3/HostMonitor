<?php
declare(strict_types=1);

/**
 * Профиль пользователя: смена собственного пароля.
 *
 * Раньше поля «Новый пароль» в профиле были фронтовым TODO — на сервере
 * не было ни одного endpoint, обновлявшего users.password_hash. Здесь это
 * закрывается: проверяем текущий пароль, применяем политику панели
 * (min_password_length) и пишем событие в auth_logs.
 *
 * Часть monitoring. Путь: POST /api/profile.php?action=password
 */

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

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    json_error('Method not allowed', 405);
}
require_csrf();

$action = $_GET['action'] ?? 'password';
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    json_error('Invalid JSON', 400);
}

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    json_error('Database unavailable', 503);
}

if ($action === 'password') {
    $current = (string)($data['current_password'] ?? '');
    $new = (string)($data['new_password'] ?? '');
    $confirm = (string)($data['new_password2'] ?? ($data['confirm_password'] ?? ''));

    if ($current === '' || $new === '') {
        json_error('Укажите текущий и новый пароль', 400);
    }
    if ($confirm !== '' && $new !== $confirm) {
        json_error('Пароли не совпадают', 400);
    }
    if (($policyError = password_policy_error($new)) !== null) {
        json_error($policyError, 400);
    }

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $hash = (string)$stmt->fetchColumn();
    if ($hash === '' || !password_verify($current, $hash)) {
        log_auth_event($pdo, $userId, $username, 'password_change', false, 'Wrong current password');
        json_error('Неверный текущий пароль', 403);
    }
    if (password_verify($new, $hash)) {
        json_error('Новый пароль совпадает с текущим', 400);
    }

    $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $upd->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);

    // Пароль сменили — завершаем остальные сессии, чтобы старый вход нигде
    // не остался. Текущую сессию не трогаем.
    try {
        if (function_exists('session_revoke_others')) {
            session_revoke_others($pdo, $userId);
        }
    } catch (Throwable $e) {
        app_log_warning('profile.php revoke-others: ' . $e->getMessage());
    }

    log_auth_event($pdo, $userId, $username, 'password_change', true, 'Password changed');
    echo json_encode(['ok' => true, 'success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

json_error('Unknown action', 400);
