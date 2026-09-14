<?php
// Двухфакторная аутентификация (TOTP / Google Authenticator) — настройка и управление
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
users_ensure_totp_columns($pdo);

$action = $_GET['action'] ?? '';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET' && $action === 'status') {
    $stmt = $pdo->prepare("SELECT totp_enabled FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    echo json_encode(['enabled' => (int)$stmt->fetchColumn() === 1]);
    exit;
}

if ($method === 'GET' && $action === 'recovery-status') {
    echo json_encode(['remaining' => recovery_codes_remaining($pdo, $userId)]);
    exit;
}

if ($method === 'POST' && $action === 'setup') {
    $stmt = $pdo->prepare("SELECT username, totp_enabled FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Пользователь не найден', 404);
    }
    if ((int)$row['totp_enabled'] === 1) {
        json_error('2FA уже включена', 400);
    }
    $secret = totp_secret_generate();
    $_SESSION['pending_totp_secret'] = $secret;
    session_write_close();
    echo json_encode([
        'secret' => $secret,
        'otpauth' => totp_otpauth_uri((string)($row['username'] ?: 'admin'), $secret),
    ]);
    exit;
}

if ($method === 'POST' && $action === 'confirm') {
    $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $code = trim((string)($data['code'] ?? ''));
    $secret = (string)($_SESSION['pending_totp_secret'] ?? '');
    if ($secret === '' || !totp_verify($secret, $code)) {
        json_error('Неверный код', 400);
    }
    $stmt = $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
    $stmt->execute([$secret, $userId]);
    $codes = recovery_codes_generate(10);
    recovery_codes_store($pdo, $userId, $codes);
    unset($_SESSION['pending_totp_secret']);
    session_write_close();
    log_auth_event($pdo, $userId, $username, 'totp_enable', true, '2FA enabled');
    echo json_encode(['success' => true, 'recovery_codes' => $codes]);
    exit;
}

if ($method === 'POST' && $action === 'regenerate-recovery') {
    $stmt = $pdo->prepare("SELECT totp_enabled FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    if ((int)$stmt->fetchColumn() !== 1) {
        json_error('2FA не включена', 400);
    }
    $codes = recovery_codes_generate(10);
    recovery_codes_store($pdo, $userId, $codes);
    session_write_close();
    log_auth_event($pdo, $userId, $username, 'totp_recovery_regen', true, 'Recovery codes regenerated');
    echo json_encode(['success' => true, 'recovery_codes' => $codes]);
    exit;
}

if ($method === 'POST' && $action === 'disable') {
    $stmt = $pdo->prepare("UPDATE users SET totp_secret = '', totp_enabled = 0 WHERE id = ?");
    $stmt->execute([$userId]);
    trusted_devices_revoke_all($pdo, $userId);
    unset($_SESSION['pending_totp_secret']);
    session_write_close();
    log_auth_event($pdo, $userId, $username, 'totp_disable', true, '2FA disabled');
    echo json_encode(['success' => true]);
    exit;
}

json_error('Unknown action', 400);
