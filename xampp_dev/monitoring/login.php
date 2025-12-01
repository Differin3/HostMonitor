<?php
session_start();
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            header('Location: index.php');
            exit;
        } else {
            $error = 'Неверный логин или пароль';
        }
    } catch (Exception $e) {
        $error = 'Ошибка подключения к базе данных';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход · Мониторинг</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/style.css')) ?>">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="dark">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <i data-lucide="activity"></i>
                    <span>Мониторинг</span>
                </div>
                <h1>Вход в систему</h1>
                <p class="login-subtitle">Панель управления</p>
            </div>
            
            <?php if ($error): ?>
                <div class="login-error">
                    <i data-lucide="alert-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            
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
        </div>
    </div>
    <script>
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    </script>
</body>
</html>

