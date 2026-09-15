<?php
// Сброс двухфакторной аутентификации для пользователя (только CLI).
// Использование: php reset_2fa.php <username>
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/helpers.php';

$username = $argv[1] ?? '';
if ($username === '') {
    fwrite(STDERR, "Использование: php reset_2fa.php <username>\n");
    exit(1);
}

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "Нет подключения к БД: " . $e->getMessage() . "\n");
    exit(1);
}

users_ensure_totp_columns($pdo);

$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$username]);
$id = $stmt->fetchColumn();
if (!$id) {
    fwrite(STDERR, "Пользователь не найден: {$username}\n");
    exit(1);
}

$pdo->prepare("UPDATE users SET totp_secret = '', totp_enabled = 0 WHERE id = ?")->execute([(int)$id]);
try {
    totp_recovery_ensure_table($pdo);
    $pdo->prepare("DELETE FROM totp_recovery_codes WHERE user_id = ?")->execute([(int)$id]);
} catch (Throwable $e) {
    // ignore
}

echo "2FA сброшена для пользователя {$username}. Вход снова по паролю.\n";
