<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Lax');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/database.php';

if (!db_needs_setup()) {
    try {
        $pdo = getDbConnection();
        if ($pdo) {
            $pdo->query('SELECT 1');
            if (db_has_users($pdo)) {
                header('Location: login.php');
                exit;
            }
        }
    } catch (Throwable $e) {
        // БД сконфигурирована но упала - оставляем пользователя на setup.php
        // (вдруг он хочет перенастроить конфиг)
    }
}

$cfg = db_config_load();
$webCfg = web_config_load();
$error = '';

$oldReplica = is_array($cfg['replica'] ?? null) ? $cfg['replica'] : [];
$old = [
    'host' => $_POST['host'] ?? $cfg['host'] ?? 'localhost',
    'port' => $_POST['port'] ?? $cfg['port'] ?? '3306',
    'name' => $_POST['name'] ?? $cfg['name'] ?? 'monitoring',
    'user' => $_POST['user'] ?? $cfg['user'] ?? 'root',
    'username' => $_POST['username'] ?? 'admin',
    'web_host' => $_POST['web_host'] ?? $webCfg['host'] ?? '0.0.0.0',
    'web_port' => $_POST['web_port'] ?? $webCfg['port'] ?? '8080',
    'public_url' => $_POST['public_url'] ?? $webCfg['public_url'] ?? '',
    'replica_enabled' => ($_SERVER['REQUEST_METHOD'] === 'POST')
        ? isset($_POST['replica_enabled'])
        : !empty($cfg['replica_enabled']),
    'replica_host' => $_POST['replica_host'] ?? ($oldReplica['host'] ?? ''),
    'replica_port' => $_POST['replica_port'] ?? ($oldReplica['port'] ?? '3306'),
    'replica_name' => $_POST['replica_name'] ?? ($oldReplica['name'] ?? ''),
    'replica_user' => $_POST['replica_user'] ?? ($oldReplica['user'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim((string)($_POST['host'] ?? ''));
    $port = trim((string)($_POST['port'] ?? '3306'));
    $name = trim((string)($_POST['name'] ?? ''));
    $user = trim((string)($_POST['user'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($password === '' && ($cfg['password'] ?? '') !== '') {
            $password = (string)$cfg['password'];
        }
    $admin = trim((string)($_POST['username'] ?? ''));
    $adminPass = (string)($_POST['admin_password'] ?? '');
    $adminPass2 = (string)($_POST['admin_password2'] ?? '');

    try {
        if ($host === '' || $name === '' || $user === '') {
            throw new InvalidArgumentException('Хост, имя базы и пользователь MySQL обязательны');
        }
        db_ident($name);
        if (!preg_match('/^\d+$/', $port) || (int)$port < 1 || (int)$port > 65535) {
            throw new InvalidArgumentException('Некорректный порт MySQL');
        }
        if ($admin === '' || $adminPass === '') {
            throw new InvalidArgumentException('Логин и пароль администратора обязательны');
        }
        if (strlen($adminPass) < 6) {
            throw new InvalidArgumentException('Пароль администратора — минимум 6 символов');
        }
        if ($adminPass !== $adminPass2) {
            throw new InvalidArgumentException('Пароли администратора не совпадают');
        }

        $replicaEnabled = isset($_POST['replica_enabled']);
        $replicaHost = trim((string)($_POST['replica_host'] ?? ''));
        $replicaPort = trim((string)($_POST['replica_port'] ?? '3306'));
        $replicaName = trim((string)($_POST['replica_name'] ?? ''));
        $replicaUser = trim((string)($_POST['replica_user'] ?? ''));
        $replicaPassword = (string)($_POST['replica_password'] ?? '');
        if ($replicaEnabled) {
            if ($replicaHost === '') {
                throw new InvalidArgumentException('Укажите хост резервной MySQL или снимите галочку');
            }
            if (!preg_match('/^\d+$/', $replicaPort) || (int)$replicaPort < 1 || (int)$replicaPort > 65535) {
                throw new InvalidArgumentException('Некорректный порт резервной MySQL');
            }
            db_ident($replicaName !== '' ? $replicaName : $name);
        }

        $next = [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'password' => $password,
            'replica_enabled' => $replicaEnabled,
            'replica_failback' => true,
            'replica' => [
                'host' => $replicaHost,
                'port' => $replicaPort ?: '3306',
                'name' => $replicaName,
                'user' => $replicaUser,
                'password' => $replicaPassword,
            ],
        ];

        $server = db_pdo($next, null);
        try {
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . db_ident($name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $e) {
            try {
                db_pdo($next, $name);
            } catch (PDOException $inner) {
                throw new RuntimeException('Нет прав создать базу «' . $name . '». Создайте её вручную или дайте GRANT. ' . $e->getMessage());
            }
        }

        db_config_save($next);

        $webHost = trim((string)($_POST['web_host'] ?? '0.0.0.0'));
        $webPort = trim((string)($_POST['web_port'] ?? '8080'));
        $publicUrl = trim((string)($_POST['public_url'] ?? ''));
        if ($webHost === '') $webHost = '0.0.0.0';
        if ($webPort === '' || !preg_match('/^\d+$/', $webPort) || (int)$webPort < 1 || (int)$webPort > 65535) $webPort = '8080';
        if ($publicUrl !== '' && substr($publicUrl, -1) === '/') $publicUrl = substr($publicUrl, 0, -1);
        web_config_save([
            'host' => $webHost,
            'port' => $webPort,
            'public_url' => $publicUrl,
        ]);

        getDbConnection(true);
        $pdo = db_pdo($next, $name);
        db_apply_schema($pdo);

        if (!db_has_users($pdo)) {
            $ins = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
            $ins->execute([$admin, password_hash($adminPass, PASSWORD_DEFAULT)]);
        }

        if ($replicaEnabled) {
            try {
                $rep = db_endpoint($next, 'replica');
                db_ensure_database($rep);
                $replicaPdo = db_try_connect($rep, 8);
                db_copy_database($pdo, $replicaPdo);
            } catch (Throwable $e) {
                error_log('Replica initial copy skipped: ' . $e->getMessage());
            }
        }

        getDbConnection(true);
        header('Location: login.php?setup=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Первый запуск · HostMonitor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/nexus.css')) ?>">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="dark">
    <div class="login-container">
        <div class="login-card setup-card">
            <div class="login-header">
                <div class="login-logo">
                    <i data-lucide="activity"></i>
                    <span>HostMonitor</span>
                </div>
                <h1>Первый запуск</h1>
                <p class="login-subtitle">База данных ещё не настроена. Укажите MySQL и создайте администратора.</p>
            </div>

            <?php if ($error): ?>
                <div class="login-error">
                    <i data-lucide="alert-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="login-form" autocomplete="off">
                <div class="setup-section">
                    <h2><i data-lucide="database"></i> База данных</h2>
                    <div class="setup-grid">
                        <div class="form-field">
                            <label class="form-label">Хост</label>
                            <input type="text" name="host" value="<?= htmlspecialchars((string)$old['host']) ?>" placeholder="localhost" required>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Порт</label>
                            <input type="number" name="port" value="<?= htmlspecialchars((string)$old['port']) ?>" min="1" max="65535" required>
                        </div>
                        <div class="form-field span-2">
                            <label class="form-label">Имя базы</label>
                            <input type="text" name="name" value="<?= htmlspecialchars((string)$old['name']) ?>" placeholder="monitoring" pattern="[A-Za-z0-9_]+" required>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Пользователь MySQL</label>
                            <input type="text" name="user" value="<?= htmlspecialchars((string)$old['user']) ?>" placeholder="root" required>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Пароль MySQL</label>
                            <input type="password" name="password" value="" placeholder="Пароль к MySQL" autocomplete="new-password">
                        </div>
                    </div>
                    <p class="setup-hint">Если базы нет — панель попробует создать её. Нужны права CREATE DATABASE либо уже существующая пустая база.</p>
                </div>

                <details class="setup-section setup-optional" <?= !empty($old['replica_enabled']) ? 'open' : '' ?>>
                    <summary>
                        <i data-lucide="hard-drive"></i>
                        Резервная база (необязательно)
                    </summary>
                    <p class="setup-hint">Если основная MySQL упадёт, панель переключится на эту. Постоянную синхронизацию лучше настроить репликацией MySQL; в настройках есть кнопка разового копирования.</p>
                    <label class="setup-check">
                        <input type="checkbox" name="replica_enabled" id="replica_enabled" value="1" <?= !empty($old['replica_enabled']) ? 'checked' : '' ?>>
                        Включить резерв
                    </label>
                    <div class="setup-grid" id="replica-fields" <?= empty($old['replica_enabled']) ? 'hidden' : '' ?>>
                        <div class="form-field">
                            <label class="form-label">Хост резерва</label>
                            <input type="text" name="replica_host" value="<?= htmlspecialchars((string)$old['replica_host']) ?>" placeholder="db-standby">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Порт</label>
                            <input type="number" name="replica_port" value="<?= htmlspecialchars((string)$old['replica_port']) ?>" min="1" max="65535">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Имя базы</label>
                            <input type="text" name="replica_name" value="<?= htmlspecialchars((string)$old['replica_name']) ?>" placeholder="как у основной" pattern="[A-Za-z0-9_]*">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Пользователь</label>
                            <input type="text" name="replica_user" value="<?= htmlspecialchars((string)$old['replica_user']) ?>" placeholder="как у основной">
                        </div>
                        <div class="form-field span-2">
                            <label class="form-label">Пароль резерва</label>
                            <input type="password" name="replica_password" value="" placeholder="Пусто — как у основной" autocomplete="new-password">
                        </div>
                    </div>
                </details>

                <div class="setup-section">
                    <h2><i data-lucide="server"></i> Веб-сервер панели</h2>
                    <div class="setup-grid">
                        <div class="form-field span-2">
                            <label class="form-label">Адрес прослушивания</label>
                            <select name="web_host" id="web_host">
                                <option value="0.0.0.0" <?= $old['web_host'] === '0.0.0.0' ? 'selected' : '' ?>>0.0.0.0 — все интерфейсы (локальный + публичный)</option>
                                <option value="127.0.0.1" <?= $old['web_host'] === '127.0.0.1' ? 'selected' : '' ?>>127.0.0.1 — только локально</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Порт веб-интерфейса</label>
                            <input type="number" name="web_port" value="<?= htmlspecialchars((string)$old['web_port']) ?>" min="1" max="65535" required>
                        </div>
                        <div class="form-field span-2">
                            <label class="form-label">Публичный URL (необязательно)</label>
                            <input type="text" name="public_url" value="<?= htmlspecialchars((string)$old['public_url']) ?>" placeholder="https://monitoring.example.com">
                            <p class="setup-hint">Если панель доступна по домену или внешнему IP — укажите URL. Используется для ссылок в уведомлениях.</p>
                        </div>
                    </div>
                </div>

                <div class="setup-section">
                    <h2><i data-lucide="shield"></i> Администратор панели</h2>
                    <div class="setup-grid">
                        <div class="form-field span-2">
                            <label class="form-label">Логин</label>
                            <input type="text" name="username" value="<?= htmlspecialchars((string)$old['username']) ?>" placeholder="admin" required>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Пароль</label>
                            <input type="password" name="admin_password" placeholder="Минимум 6 символов" required autocomplete="new-password">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Повтор пароля</label>
                            <input type="password" name="admin_password2" placeholder="Повторите пароль" required autocomplete="new-password">
                        </div>
                    </div>
                </div>

                <button type="submit" class="primary" style="width:100%;margin-top:4px">
                    <i data-lucide="check"></i>
                    <span>Создать базу и войти</span>
                </button>
            </form>
        </div>
    </div>
    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();
        const replicaOn = document.getElementById('replica_enabled');
        const replicaFields = document.getElementById('replica-fields');
        if (replicaOn && replicaFields) {
            replicaOn.addEventListener('change', () => {
                replicaFields.hidden = !replicaOn.checked;
            });
        }
    </script>
</body>
</html>
