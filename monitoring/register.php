<?php
session_start();
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/database.php';

$error = '';

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

try {
    $pdo = getDbConnection();
    $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count > 0) {
        header('Location: login.php');
        exit;
    }
} catch (Exception $e) {
    if (db_is_configured()) {
        $status = db_connection_status();
        db_render_error_page($status);
        exit;
    }
    header('Location: setup.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    
    if ($username === '' || $password === '') {
        $error = 'Логин и пароль обязательны';
    } elseif ($password !== $password2) {
        $error = 'Пароли не совпадают';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
            $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT)]);
            header('Location: login.php');
            exit;
        } catch (PDOException $e) {
            $error = 'Не удалось создать пользователя (возможно, логин уже существует)';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация администратора · HostMonitor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/nexus.css')) ?>">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="dark">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <i data-lucide="user-plus"></i>
                    <span>HostMonitor</span>
                </div>
                <h1>Создание администратора</h1>
                <p class="login-subtitle">Первичная настройка панели</p>
            </div>
            
            <?php if ($error): ?>
                <div class="login-error">
                    <i data-lucide="alert-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="login-form">
                <div class="form-field">
                    <label class="form-label">Логин администратора</label>
                    <div class="input-with-icon">
                        <i data-lucide="user" class="input-icon"></i>
                        <input type="text" name="username" placeholder="admin" required autofocus>
                    </div>
                </div>
                
                <div class="form-field">
                    <label class="form-label">Пароль</label>
                    <div class="input-with-icon">
                        <i data-lucide="lock" class="input-icon"></i>
                        <input type="password" name="password" placeholder="Введите пароль" required>
                    </div>
                </div>
                
                <div class="form-field">
                    <label class="form-label">Повтор пароля</label>
                    <div class="input-with-icon">
                        <i data-lucide="lock" class="input-icon"></i>
                        <input type="password" name="password2" placeholder="Повторите пароль" required>
                    </div>
                </div>
                
                <button type="submit" class="primary" style="width: 100%; margin-top: 8px;">
                    <i data-lucide="check"></i>
                    <span>Создать администратора</span>
                </button>
            </form>
        </div>
    </div>
    <script>
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    </script>
</body>
</html>


