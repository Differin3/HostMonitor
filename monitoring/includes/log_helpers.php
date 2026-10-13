<?php
/**
 * Единый формат логов панели: [php] <ISO> <LEVEL> req=<id> <message>
 *
 * php.ini на панели: error_log пуст, log_errors=On, display_errors=Off →
 * error_log() пишет в stderr php-cgi, который python-веб-сервер перекладывает
 * в journald (см. docs/LOGGING.md). app_log() добавляет уровень и request-id,
 * чтобы строку можно было связать с access-log и stderr того же запроса.
 *
 * Уровень фильтруется через LOG_LEVEL (env): debug < info < warning < error.
 */

if (!function_exists('app_log_levels')) {
    function app_log_levels(): array
    {
        return ['debug' => 10, 'info' => 20, 'warning' => 30, 'warn' => 30, 'error' => 40];
    }
}

if (!function_exists('app_log')) {
    /**
     * @param string $message Текст (без префикса [php] и времени).
     * @param string $level   debug|info|warning|error (по умолчанию info).
     * @param array  $context Доп. поля — сериализуются в JSON после текста.
     */
    function app_log(string $message, string $level = 'info', array $context = []): void
    {
        $levels = app_log_levels();
        $lvl = strtolower(trim($level));
        if (!isset($levels[$lvl])) {
            $lvl = 'info';
        }
        $min = strtolower(trim((string)(getenv('LOG_LEVEL') ?: 'info')));
        $min_rank = $levels[$min] ?? 20;
        if ($levels[$lvl] < $min_rank) {
            return;
        }

        $rid = '';
        $raw_rid = (string)($_SERVER['HTTP_X_HM_REQUEST_ID'] ?? '');
        if ($raw_rid !== '') {
            $rid = ' req=' . preg_replace('/[^A-Za-z0-9_-]/', '', $raw_rid);
        }

        if (!empty($context)) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $message .= ' ' . $json;
            }
        }

        error_log(sprintf('[php] %s %-7s%s %s', date('c'), strtoupper($lvl), $rid, $message));
    }
}

if (!function_exists('app_log_debug')) {
    function app_log_debug(string $message, array $context = []): void
    {
        app_log($message, 'debug', $context);
    }
}

if (!function_exists('app_log_warning')) {
    function app_log_warning(string $message, array $context = []): void
    {
        app_log($message, 'warning', $context);
    }
}

if (!function_exists('app_log_error')) {
    function app_log_error(string $message, array $context = []): void
    {
        app_log($message, 'error', $context);
    }
}
