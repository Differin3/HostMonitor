<?php
declare(strict_types=1);

// Каталог monitoring/includes. Модули лежат уровнем ниже, поэтому их
// собственный __DIR__ на уровень глубже; пути конфигурации и данных
// должны считаться от каталога, где лежит db_config.php.
if (!defined('DB_INCLUDES_DIR')) {
    define('DB_INCLUDES_DIR', dirname(__DIR__));
}

/**
 * Настройки панели.
 *
 * Отдельный конфиг интерфейса, значения по умолчанию, нормализация типов, маскирование секретов.
 *
 * Часть monitoring/includes/db_config.php; подключается оттуда.
 * Напрямую этот файл не включают.
 */

function web_config_path(): string
{
    $dataDir = dirname(DB_INCLUDES_DIR) . DIRECTORY_SEPARATOR . 'data';
    return $dataDir . DIRECTORY_SEPARATOR . 'web.local.php';
}

function web_config_load(): array
{
    $file = [];
    $path = web_config_path();
    if (is_file($path)) {
        $loaded = include $path;
        if (is_array($loaded)) {
            $file = $loaded;
        }
    }
    return [
        'host' => (string)(getenv('WEB_HOST') ?: ($file['host'] ?? '0.0.0.0')),
        'port' => (string)(getenv('WEB_PORT') ?: ($file['port'] ?? '8080')),
        'public_url' => (string)(getenv('MASTER_URL') ?: ($file['public_url'] ?? '')),
        'from_file' => is_file($path),
        'from_env' => (bool)(getenv('WEB_HOST') || getenv('WEB_PORT') || getenv('MASTER_URL')),
    ];
}

function web_config_save(array $cfg): void
{
    $dir = dirname(web_config_path());
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог data/ для конфига веб-сервера');
    }
    $prev = [];
    $path = web_config_path();
    if (is_file($path)) {
        $loaded = include $path;
        if (is_array($loaded)) {
            $prev = $loaded;
        }
    }
    $export = var_export([
        'host' => (string)($cfg['host'] ?? $prev['host'] ?? '0.0.0.0'),
        'port' => (string)($cfg['port'] ?? $prev['port'] ?? '8080'),
        'public_url' => (string)($cfg['public_url'] ?? $prev['public_url'] ?? ''),
    ], true);
    $php = "<?php\n// Сгенерировано панелью. Не коммить.\nreturn {$export};\n";
    if (file_put_contents($path, $php, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать data/web.local.php — проверьте права');
    }
    @chmod($path, 0600);
}

function settings_defaults(): array
{
    $web = web_config_load();
    return [
        'system_name' => 'HostMonitor',
        'web_host' => $web['host'],
        'web_port' => $web['port'],
        'public_url' => $web['public_url'],
        'timezone' => 'Europe/Moscow',
        'language' => 'ru',
        'collect_interval' => '60',
        'log_retention_days' => '30',
        'metrics_retention_days' => '14',
        'alerts_retention_days' => '30',
        'update_history_days' => '90',
        'logs_per_page' => '100',
        'log_max_rows' => '1000000',
        'log_max_rows_per_node' => '100000',
        'upnp_enabled' => 'true',
        'upnp_interval_cycles' => '2',
        'upnp_mx' => '3',
        'upnp_timeout' => '8',
        'upnp_gena_port' => '0',
        'notify_email_enabled' => '1',
        'notify_telegram_enabled' => '0',
        'notify_email' => '',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_user' => '',
        'smtp_password' => '',
        'smtp_from' => '',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'min_password_length' => '8',
        'session_timeout_minutes' => '60',
        'api_rate_limit' => '100',
    ];
}

function settings_secret_keys(): array
{
    return ['smtp_password', 'telegram_bot_token'];
}

function settings_load_map(bool $reload = false): array
{
    static $map = null;
    if ($reload) {
        $map = null;
    }
    if ($map !== null) {
        return $map;
    }
    $map = settings_defaults();
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
    } catch (Throwable $e) {
        // таблица ещё не создана — оставляем значения по умолчанию
    }
    return $map;
}

function setting_get(string $key, ?string $default = null): string
{
    $map = settings_load_map();
    if (array_key_exists($key, $map) && $map[$key] !== '') {
        return (string)$map[$key];
    }
    if ($default !== null) {
        return $default;
    }
    $defaults = settings_defaults();
    return (string)($defaults[$key] ?? '');
}

function settings_public(): array
{
    $map = settings_load_map();
    $out = [];
    foreach (settings_defaults() as $key => $def) {
        $val = (string)($map[$key] ?? $def);
        if (in_array($key, settings_secret_keys(), true)) {
            $out['has_' . $key] = $val !== '';
            $out[$key] = '';
        } else {
            $out[$key] = $val;
        }
    }
    return $out;
}

function settings_normalize(string $key, $value): string
{
    $raw = is_bool($value) ? ($value ? '1' : '0') : trim((string)$value);
    switch ($key) {
        case 'system_name':
            if ($raw === '') {
                return 'HostMonitor';
            }
            return function_exists('mb_substr') ? mb_substr($raw, 0, 80) : substr($raw, 0, 80);
        case 'web_host':
            if ($raw === '') {
                return '0.0.0.0';
            }
            if ($raw !== '0.0.0.0' && $raw !== '127.0.0.1' && !filter_var($raw, FILTER_VALIDATE_IP)) {
                throw new InvalidArgumentException('web_host: должен быть 0.0.0.0, 127.0.0.1 или корректный IP');
            }
            return $raw;
        case 'web_port':
            $n = (int)$raw;
            if ($n < 1 || $n > 65535) {
                throw new InvalidArgumentException('web_port: 1–65535');
            }
            return (string)$n;
        case 'public_url':
            if ($raw !== '' && !filter_var($raw, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('public_url: некорректный URL (оставьте пустым для авто)');
            }
            return rtrim($raw, '/');
            break;
        case 'timezone':
            return $raw !== '' ? $raw : 'Europe/Moscow';
        case 'language':
            return in_array($raw, ['ru', 'en'], true) ? $raw : 'ru';
        case 'collect_interval':
            $n = (int)$raw;
            if ($n < 10 || $n > 300) {
                throw new InvalidArgumentException('collect_interval: 10–300 секунд');
            }
            return (string)$n;
        case 'log_retention_days':
            $n = (int)$raw;
            if ($n < 1 || $n > 365) {
                throw new InvalidArgumentException('Глубина логов: 1–365 дней');
            }
            return (string)$n;
        case 'metrics_retention_days':
            $n = (int)$raw;
            if ($n < 2 || $n > 90) {
                throw new InvalidArgumentException('Глубина метрик: 2–90 дней');
            }
            return (string)$n;
        case 'alerts_retention_days':
            $n = (int)$raw;
            if ($n < 7 || $n > 365) {
                throw new InvalidArgumentException('Глубина закрытых алертов: 7–365 дней');
            }
            return (string)$n;
        case 'update_history_days':
            $n = (int)$raw;
            if ($n < 14 || $n > 365) {
                throw new InvalidArgumentException('Глубина истории обновлений: 14–365 дней');
            }
            return (string)$n;
        case 'logs_per_page':
            $n = (int)$raw;
            if ($n < 10 || $n > 2000) {
                throw new InvalidArgumentException('Записей на страницу: 10–2000');
            }
            return (string)$n;
        case 'log_max_rows':
            $n = (int)$raw;
            if ($n < 10000 || $n > 10000000) {
                throw new InvalidArgumentException('Максимум записей логов: 10 000–10 000 000');
            }
            return (string)$n;
        case 'log_max_rows_per_node':
            $n = (int)$raw;
            if ($n < 0 || $n > 1000000) {
                throw new InvalidArgumentException('Максимум на ноду: 0–1 000 000');
            }
            return (string)$n;
        case 'upnp_enabled':
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true) ? 'true' : 'false';
        case 'upnp_interval_cycles':
            $n = (int)$raw;
            if ($n < 1 || $n > 60) {
                throw new InvalidArgumentException('UPNP_INTERVAL_CYCLES: 1–60');
            }
            return (string)$n;
        case 'upnp_mx':
            $n = (int)$raw;
            if ($n < 1 || $n > 15) {
                throw new InvalidArgumentException('UPNP_MX: 1–15');
            }
            return (string)$n;
        case 'upnp_timeout':
            $n = (int)$raw;
            if ($n < 3 || $n > 60) {
                throw new InvalidArgumentException('UPNP_TIMEOUT: 3–60 секунд');
            }
            return (string)$n;
        case 'upnp_gena_port':
            $n = (int)$raw;
            if ($n < 0 || $n > 65535) {
                throw new InvalidArgumentException('UPNP_GENA_PORT: 0–65535');
            }
            return (string)$n;
        case 'notify_email_enabled':
        case 'notify_telegram_enabled':
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
        case 'notify_email':
        case 'smtp_from':
            return $raw;
        case 'smtp_host':
        case 'smtp_user':
        case 'telegram_chat_id':
            return $raw;
        case 'smtp_port':
            $n = (int)$raw;
            if ($n < 1 || $n > 65535) {
                throw new InvalidArgumentException('SMTP-порт: 1–65535');
            }
            return (string)$n;
        case 'smtp_password':
        case 'telegram_bot_token':
            return (string)$value;
        case 'min_password_length':
            $n = (int)$raw;
            if ($n < 6 || $n > 32) {
                throw new InvalidArgumentException('Минимальная длина пароля: 6–32');
            }
            return (string)$n;
        case 'session_timeout_minutes':
            $n = (int)$raw;
            if ($n < 5 || $n > 1440) {
                throw new InvalidArgumentException('Таймаут сессии: 5–1440 минут');
            }
            return (string)$n;
        case 'api_rate_limit':
            $n = (int)$raw;
            if ($n < 10 || $n > 10000) {
                throw new InvalidArgumentException('Лимит запросов: 10–10 000 в минуту');
            }
            return (string)$n;
        default:
            throw new InvalidArgumentException('Неизвестный ключ настройки');
    }
}

function settings_upsert(PDO $pdo, array $pairs): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $saved = [];
    $current = settings_load_map();
    $webKeys = ['web_host', 'web_port', 'public_url'];
    $webToUpdate = [];
    foreach ($pairs as $key => $value) {
        if (!array_key_exists($key, settings_defaults())) {
            throw new InvalidArgumentException('Неизвестный ключ настройки: ' . $key);
        }
        if (in_array($key, settings_secret_keys(), true) && trim((string)$value) === '') {
            continue;
        }
        $norm = settings_normalize((string)$key, $value);
        $stmt->execute([(string)$key, $norm]);
        $saved[$key] = in_array($key, settings_secret_keys(), true) ? '' : $norm;
        $current[$key] = $norm;
        if (in_array($key, $webKeys, true)) {
            $webToUpdate[$key] = $norm;
        }
    }
    if ($webToUpdate !== []) {
        $existing = web_config_load();
        $merged = [
            'host' => $webToUpdate['web_host'] ?? $existing['host'],
            'port' => $webToUpdate['web_port'] ?? $existing['port'],
            'public_url' => $webToUpdate['public_url'] ?? $existing['public_url'],
        ];
        try {
            web_config_save($merged);
        } catch (Throwable $e) {
            error_log('Failed to save web.local.php: ' . $e->getMessage());
        }
    }
    settings_load_map(true);
    return $saved;
}
