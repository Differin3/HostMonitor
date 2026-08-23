<?php
declare(strict_types=1);

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('json_error')) {
    function json_error(string $message, int $status = 400): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
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

if (!function_exists('node_heartbeat_timeout_sec')) {
    /**
     * Через сколько секунд без last_seen нода считается offline.
     * Агент шлёт метрики ~раз в 60с + время сбора; 180с ≈ 3 цикла с запасом.
     */
    function node_heartbeat_timeout_sec(): int
    {
        return 180;
    }
}

if (!function_exists('nodes_ensure_agent_columns')) {
    /**
     * Добавляет agent_* колонки без долгих блокировок:
     * сначала INFORMATION_SCHEMA, ALTER только если колонки нет.
     * Без ADD COLUMN IF NOT EXISTS — в MySQL этого синтаксиса нет.
     */
    function nodes_ensure_agent_columns(PDO $pdo): void
    {
        static $done = [];

        $needed = [
            'agent_version' => 'VARCHAR(32) NULL',
            'agent_commit' => 'VARCHAR(64) NULL',
            'agent_remote_commit' => 'VARCHAR(64) NULL',
            'agent_branch' => 'VARCHAR(64) NULL',
            'agent_update_available' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'agent_updated_at' => 'TIMESTAMP NULL',
            'command_result' => 'TEXT NULL',
        ];

        try {
            $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($dbName === '') {
                return;
            }
            $cacheKey = $dbName . '@' . spl_object_id($pdo);
            if (isset($done[$cacheKey])) {
                return;
            }
            $done[$cacheKey] = true;

            $existing = [];
            $stmt = $pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            $stmt->execute([$dbName, 'nodes']);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
                $existing[(string)$col] = true;
            }

            $missing = [];
            foreach ($needed as $col => $ddl) {
                if (!isset($existing[$col])) {
                    $missing[$col] = $ddl;
                }
            }
            if (!$missing) {
                return;
            }

            try {
                $pdo->exec('SET SESSION lock_wait_timeout = 5');
            } catch (Throwable $e) {
                // ignore
            }
            foreach ($missing as $col => $ddl) {
                try {
                    $pdo->exec("ALTER TABLE nodes ADD COLUMN `{$col}` {$ddl}");
                } catch (Throwable $e) {
                    // race / already exists
                }
            }
        } catch (Throwable $e) {
            error_log('[nodes_ensure_agent_columns] ' . $e->getMessage());
        }
    }
}

if (!function_exists('nodes_ensure_agent_columns_all')) {
    /**
     * Миграция agent_* на активной и (если есть) резервной БД.
     * @return list<array{role:string,ok:bool,error?:string}>
     */
    function nodes_ensure_agent_columns_all(?PDO $active = null): array
    {
        $results = [];
        if ($active instanceof PDO) {
            nodes_ensure_agent_columns($active);
            $results[] = ['role' => 'active', 'ok' => true];
        }

        if (!function_exists('db_config_load') || !function_exists('db_replica_enabled')) {
            return $results;
        }

        try {
            $cfg = db_config_load();
            $roles = ['primary'];
            if (db_replica_enabled($cfg)) {
                $roles[] = 'replica';
            }
            foreach ($roles as $role) {
                try {
                    $ep = db_endpoint($cfg, $role);
                    if (($ep['host'] ?? '') === '' || ($ep['name'] ?? '') === '') {
                        continue;
                    }
                    $pdo = db_try_connect($ep, 8);
                    nodes_ensure_agent_columns($pdo);
                    $results[] = ['role' => $role, 'ok' => true];
                } catch (Throwable $e) {
                    $results[] = ['role' => $role, 'ok' => false, 'error' => $e->getMessage()];
                    error_log('[nodes_ensure_agent_columns_all:' . $role . '] ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[nodes_ensure_agent_columns_all] ' . $e->getMessage());
        }

        return $results;
    }
}

