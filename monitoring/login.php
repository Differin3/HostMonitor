<?php
// Настройка сессий для PHP-CGI
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/database.php';

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
        // Не вызывать db_connection_status() (2×12с ping) — страница логина и так без БД
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>БД недоступна</title></head><body>';
        echo '<h1>База данных недоступна</h1><p>Проверьте подключение и настройки в monitoring/data/db.local.php</p>';
        echo '</body></html>';
        exit;
    }
}

$error = '';
if (isset($_GET['setup'])) {
    $error = '';
}
$notice = isset($_GET['setup']) ? 'База создана. Войдите учётной записью администратора.' : '';
if (isset($_GET['expired'])) {
    $notice = 'Сессия истекла. Войдите снова.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ——— Шаг 2: подтверждение кода двухфакторной аутентификации ———
    if (!empty($_SESSION['pending_2fa']) && isset($_POST['totp_code'])) {
        $pending = $_SESSION['pending_2fa'];
        $code = trim((string)($_POST['totp_code'] ?? ''));
        try {
            $pdo = getDbConnection();
            users_ensure_totp_columns($pdo);
            $stmt = $pdo->prepare("SELECT totp_secret FROM users WHERE id = ?");
            $stmt->execute([(int)$pending['id']]);
            $secret = (string)$stmt->fetchColumn();
            if ($secret !== '' && totp_verify($secret, $code)) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $pending['id'];
                $_SESSION['username'] = $pending['username'];
                $_SESSION['role'] = $pending['role'];
                $_SESSION['last_activity'] = time();
                unset($_SESSION['pending_2fa']);
                log_auth_event($pdo, (int)$pending['id'], $pending['username'], 'login_2fa', true, '2FA verified');
                session_write_close();
                header('Location: index.php');
                exit;
            }
            $error = 'Неверный код подтверждения';
            log_auth_event($pdo, (int)$pending['id'], $pending['username'], 'login_2fa', false, 'Invalid 2FA code');
        } catch (Exception $e) {
            $error = 'Ошибка подключения к базе данных';
        }
    } else {
        // ——— Шаг 1: логин + пароль ———
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Brute-force rate limiting: max 5 attempts per 15 minutes per IP
        $rlFile = __DIR__ . '/data/login_attempts.json';
        $rlMax = 5;
        $rlWindow = 900; // 15 min
        $attempts = [];
        if (file_exists($rlFile)) {
            $attempts = json_decode(file_get_contents($rlFile), true) ?: [];
        }
        $now = time();
        $ipAttempts = $attempts[$clientIp] ?? [];
        $ipAttempts = array_filter($ipAttempts, fn($ts) => $ts > $now - $rlWindow);
        if (count($ipAttempts) >= $rlMax) {
            $error = 'Слишком много неудачных попыток. Попробуйте через 15 минут.';
        } else {
            try {
                $pdo = getDbConnection();
                users_ensure_totp_columns($pdo);
                $stmt = $pdo->prepare("SELECT id, username, password_hash, role, totp_enabled FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    // Если включена 2FA — переходим к вводу кода
                    if (!empty($user['totp_enabled'])) {
                        $_SESSION['pending_2fa'] = [
                            'id' => (int)$user['id'],
                            'username' => $user['username'],
                            'role' => $user['role'],
                            'ts' => time(),
                        ];
                        session_write_close();
                        header('Location: login.php?totp=1');
                        exit;
                    }

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['last_activity'] = time();

                    // Clear attempts on success
                    unset($attempts[$clientIp]);
                    file_put_contents($rlFile, json_encode($attempts), LOCK_EX);

                    log_auth_event($pdo, $user['id'], $user['username'], 'login', true, 'Successful login');

                    session_write_close();
                    header('Location: index.php');
                    exit;
                } else {
                    $error = 'Неверный логин или пароль';

                    // Record failed attempt
                    $ipAttempts[] = $now;
                    $attempts[$clientIp] = $ipAttempts;
                    file_put_contents($rlFile, json_encode($attempts), LOCK_EX);

                    log_auth_event($pdo, null, $username, 'failed', false, 'Invalid credentials');
                }
            } catch (Exception $e) {
                $error = 'Ошибка подключения к базе данных';
            }
        }
    }
}

if (isset($_GET['cancel'])) {
    unset($_SESSION['pending_2fa']);
    header('Location: login.php');
    exit;
}

$totpStep = !empty($_SESSION['pending_2fa']) || isset($_GET['totp']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход · HostMonitor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/nexus.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/mobile.css')) ?>">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="dark">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <i data-lucide="activity"></i>
                    <span>HostMonitor</span>
                </div>
                <h1><?= $totpStep ? 'Двухфакторная аутентификация' : 'Вход в систему' ?></h1>
                <p class="login-subtitle"><?= $totpStep ? 'Введите код из приложения' : 'Панель управления' ?></p>
            </div>
            
            <?php if ($error): ?>
                <div class="login-error">
                    <i data-lucide="alert-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php elseif (!empty($notice)): ?>
                <div class="login-error <?= isset($_GET['expired']) ? '' : 'login-ok' ?>">
                    <i data-lucide="<?= isset($_GET['expired']) ? 'clock' : 'check-circle' ?>"></i>
                    <span><?= htmlspecialchars($notice) ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($totpStep): ?>
                <form method="POST" class="login-form">
                    <div class="form-field">
                        <label class="form-label">Код из приложения аутентификации</label>
                        <div class="input-with-icon">
                            <i data-lucide="shield-check" class="input-icon"></i>
                            <input type="text" name="totp_code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="6 цифр" required autofocus autocomplete="one-time-code">
                        </div>
                    </div>
                    <button type="submit" class="primary" style="width: 100%; margin-top: 8px;">
                        <i data-lucide="log-in"></i>
                        <span>Подтвердить</span>
                    </button>
                    <a href="login.php?cancel=1" class="btn-outline" style="width:100%; text-align:center; margin-top:10px; text-decoration:none;">
                        <i data-lucide="arrow-left"></i>
                        <span>Другой аккаунт</span>
                    </a>
                </form>
            <?php else: ?>
                <form method="POST" class="login-form">
                    <div class="form-field">
                        <label class="form-label">Логин</label>
                        <div class="input-with-icon">
                            <i data-lucide="user" class="input-icon"></i>
                            <input type="text" name="username" placeholder="Введите логин" required autofocus>
                        </div>
                    </div>

                    <div class="form-field">
                        <label class="form-label">Пароль</label>
                        <div class="input-with-icon">
                            <i data-lucide="lock" class="input-icon"></i>
                            <input type="password" name="password" placeholder="Введите пароль" required>
                        </div>
                    </div>

                    <button type="submit" class="primary" style="width: 100%; margin-top: 8px;">
                        <i data-lucide="log-in"></i>
                        <span>Войти</span>
                    </button>
                </form>
            <?php endif; ?>

            <?php
            // Показываем ссылку на регистрацию, только если в БД нет ни одного пользователя
            try {
                $pdo = getDbConnection();
                $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            } catch (Exception $e) {
                $count = 1; // на всякий случай скрываем регистрацию при ошибке БД
            }
            if ($count === 0): ?>
                <div style="margin-top: 16px; text-align: center; font-size: 13px; color: var(--text-muted);">
                    <a href="register.php" style="color: var(--accent); text-decoration: none;">Создать первый аккаунт администратора</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    </script>
</body>
</html>

