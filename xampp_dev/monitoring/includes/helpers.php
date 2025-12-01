<?php
declare(strict_types=1);

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

