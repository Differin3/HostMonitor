<?php
declare(strict_types=1);

if (!function_exists('json_error')) {
    function json_error(string $message, int $status = 400): void
    {
        http_response_code($status);
        echo json_encode(['error' => $message]);
        exit;
    }
}

if (!function_exists('json_exception')) {
    function json_exception(Throwable $e, bool $expose = false): void
    {
        error_log('[monitoring-api] ' . $e->getMessage());
        $msg = $expose ? $e->getMessage() : 'Internal server error';
        json_error($msg, 500);
    }
}

if (!function_exists('log_auth_event')) {
    function log_auth_event(PDO $pdo, $userId, $username, $eventType, $success, $message = null) {
        try {
            // Проверяем наличие таблицы auth_logs
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                username VARCHAR(100),
                ip_address VARCHAR(45),
                event_type VARCHAR(20) NOT NULL,
                success BOOLEAN DEFAULT FALSE,
                message TEXT,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_event_type (event_type),
                INDEX idx_timestamp (timestamp),
                INDEX idx_ip_address (ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $stmt = $pdo->prepare("INSERT INTO auth_logs (user_id, username, ip_address, event_type, success, message) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $username, $ipAddress, $eventType, $success ? 1 : 0, $message]);
        } catch (Exception $e) {
            error_log("Error logging auth event: " . $e->getMessage());
        }
    }
}

if (!function_exists('require_api_auth')) {
    function require_api_auth(PDO $pdo): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $nodeInfo = null;
        if (isset($_SESSION['user_id'])) {
            return ['user' => (int)$_SESSION['user_id'], 'node' => null];
        }

        // Пробуем получить заголовок Authorization разными способами
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (empty($authHeader) && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        // Также пробуем через REDIRECT_HTTP_AUTHORIZATION (для некоторых конфигураций)
        if (empty($authHeader)) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }
        
        if ($authHeader && preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
            $token = trim($m[1]);
            // Логируем для отладки (первые и последние символы токена)
            $tokenPreview = strlen($token) > 8 ? substr($token, 0, 4) . '...' . substr($token, -4) : '***';
            error_log("[require_api_auth] Token received, length: " . strlen($token) . ", preview: " . $tokenPreview);
            
            $stmt = $pdo->prepare("SELECT id, name FROM nodes WHERE node_token = ?");
            $stmt->execute([$token]);
            $nodeInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($nodeInfo) {
                error_log("[require_api_auth] Node found: id=" . $nodeInfo['id'] . ", name=" . $nodeInfo['name']);
                return ['user' => null, 'node' => $nodeInfo];
            } else {
                // Проверяем, есть ли вообще ноды с токенами
                $checkStmt = $pdo->query("SELECT COUNT(*) as cnt FROM nodes WHERE node_token IS NOT NULL AND node_token != ''");
                $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
                error_log("[require_api_auth] Node not found. Total nodes with tokens: " . ($checkResult['cnt'] ?? 0));
                // Пробуем найти по первым символам для отладки
                $tokenStart = substr($token, 0, 8);
                $debugStmt = $pdo->prepare("SELECT id, name, LEFT(node_token, 8) as token_start FROM nodes WHERE LEFT(node_token, 8) = ? LIMIT 1");
                $debugStmt->execute([$tokenStart]);
                $debugNode = $debugStmt->fetch(PDO::FETCH_ASSOC);
                if ($debugNode) {
                    error_log("[require_api_auth] Found node with matching token start: id=" . $debugNode['id'] . ", name=" . $debugNode['name']);
                }
            }
        } else {
            error_log("[require_api_auth] No Authorization header found. HTTP_AUTHORIZATION: " . ($_SERVER['HTTP_AUTHORIZATION'] ?? 'NOT SET'));
        }

        json_error('Unauthorized', 401);
    }
}

if (!function_exists('monitoring_base_path')) {
    function monitoring_base_path(): string
    {
        // Проверяем конфигурацию
        $config = require __DIR__ . '/config.php';
        if (isset($config['base_path']) && !empty($config['base_path'])) {
            return rtrim($config['base_path'], '/');
        }
        
        // Автоопределение из SCRIPT_NAME
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        return $dir === '/' ? '' : $dir;
    }
}

if (!function_exists('monitoring_asset')) {
    function monitoring_asset(string $path): string
    {
        // Если путь начинается с /frontend, используем абсолютный путь от корня
        // Это нужно для XAMPP, где frontend находится в htdocs/frontend
        if (strpos($path, '/frontend') === 0) {
            return $path; // Абсолютный путь от корня сайта
        }
        
        $base = monitoring_base_path();
        return $base . $path;
    }
}

if (!function_exists('monitoring_url')) {
    function monitoring_url(string $path): string
    {
        $base = monitoring_base_path();
        return $base . '/' . ltrim($path, '/');
    }
}

