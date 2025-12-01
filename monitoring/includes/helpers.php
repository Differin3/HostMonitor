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

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader && preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
            $token = $m[1];
            $stmt = $pdo->prepare("SELECT id, name FROM nodes WHERE node_token = ?");
            $stmt->execute([$token]);
            $nodeInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($nodeInfo) {
                return ['user' => null, 'node' => $nodeInfo];
            }
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

