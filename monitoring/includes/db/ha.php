<?php
declare(strict_types=1);

// Каталог monitoring/includes. Модули лежат уровнем ниже, поэтому их
// собственный __DIR__ на уровень глубже; пути конфигурации и данных
// должны считаться от каталога, где лежит db_config.php.
if (!defined('DB_INCLUDES_DIR')) {
    define('DB_INCLUDES_DIR', dirname(__DIR__));
}

/**
 * Роли primary и replica.
 *
 * Описание endpoint по роли, отсечение секретов для показа, журнал смен ролей, синхронизация часового пояса.
 *
 * Часть monitoring/includes/db_config.php; подключается оттуда.
 * Напрямую этот файл не включают.
 */

function db_replica_enabled(array $cfg): bool
{
    if (empty($cfg['replica_enabled'])) {
        return false;
    }
    return trim((string)($cfg['replica']['host'] ?? '')) !== '';
}

function db_endpoint(array $cfg, string $role): array
{
    if ($role === 'replica') {
        $replica = is_array($cfg['replica'] ?? null) ? $cfg['replica'] : [];
        return [
            'host' => (string)($replica['host'] ?? ''),
            'port' => (string)($replica['port'] ?? '3306') ?: '3306',
            'name' => (string)(($replica['name'] ?? '') !== '' ? $replica['name'] : ($cfg['name'] ?? 'monitoring')),
            'user' => (string)(($replica['user'] ?? '') !== '' ? $replica['user'] : ($cfg['user'] ?? 'root')),
            'password' => (string)(($replica['password'] ?? '') !== '' ? $replica['password'] : ($cfg['password'] ?? '')),
            'ssl' => (bool)($replica['ssl'] ?? false),
            'ssl_verify' => (bool)($replica['ssl_verify'] ?? false),
            'ssl_ca' => (string)($replica['ssl_ca'] ?? ''),
        ];
    }
    return [
        'host' => (string)($cfg['host'] ?? 'localhost'),
        'port' => (string)($cfg['port'] ?? '3306') ?: '3306',
        'name' => (string)($cfg['name'] ?? 'monitoring'),
        'user' => (string)($cfg['user'] ?? 'root'),
        'password' => (string)($cfg['password'] ?? ''),
    ];
}

function db_endpoint_same(array $a, array $b): bool
{
    return strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '')
        && (string)($a['port'] ?? '') === (string)($b['port'] ?? '')
        && (string)($a['name'] ?? '') === (string)($b['name'] ?? '');
}

function db_public_endpoint(array $ep): array
{
    $out = [
        'host' => (string)($ep['host'] ?? ''),
        'port' => (string)($ep['port'] ?? ''),
        'name' => (string)($ep['name'] ?? ''),
        'user' => (string)($ep['user'] ?? ''),
        'has_password' => ($ep['password'] ?? '') !== '',
    ];
    if (array_key_exists('ssl', $ep)) {
        $out['ssl'] = !empty($ep['ssl']);
    }
    if (array_key_exists('ssl_verify', $ep)) {
        $out['ssl_verify'] = !empty($ep['ssl_verify']);
    }
    $caInfo = db_ssl_ca_info((string)($ep['ssl_ca'] ?? ''));
    $out['has_ssl_ca'] = !empty($caInfo['installed']);
    $out['ssl_ca'] = $out['has_ssl_ca'] ? (string)($caInfo['relative'] ?? '') : '';
    if (!empty($caInfo['subject'])) {
        $out['ssl_ca_subject'] = (string)$caInfo['subject'];
    }
    if (!empty($caInfo['cert_count'])) {
        $out['ssl_ca_cert_count'] = (int)$caInfo['cert_count'];
    }
    return $out;
}

function db_ha_state_load(): array
{
    $path = db_ha_state_path();
    if (!is_file($path)) {
        return ['role' => 'primary', 'reason' => '', 'since' => 0, 'last_primary_try' => 0];
    }
    $loaded = include $path;
    if (!is_array($loaded)) {
        return ['role' => 'primary', 'reason' => '', 'since' => 0, 'last_primary_try' => 0];
    }
    return [
        'role' => (($loaded['role'] ?? '') === 'replica') ? 'replica' : 'primary',
        'reason' => (string)($loaded['reason'] ?? ''),
        'since' => (int)($loaded['since'] ?? 0),
        'last_primary_try' => (int)($loaded['last_primary_try'] ?? 0),
    ];
}

function db_ha_state_save(string $role, string $reason, ?int $lastPrimaryTry = null): void
{
    $dir = dirname(db_ha_state_path());
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return;
    }
    $prev = db_ha_state_load();
    $now = time();
    $export = var_export([
        'role' => $role === 'replica' ? 'replica' : 'primary',
        'reason' => $reason,
        'since' => ($role === ($prev['role'] ?? '') && (int)($prev['since'] ?? 0) > 0) ? (int)$prev['since'] : $now,
        'last_primary_try' => $lastPrimaryTry ?? $now,
    ], true);
    @file_put_contents(db_ha_state_path(), "<?php\nreturn {$export};\n", LOCK_EX);
    @chmod(db_ha_state_path(), 0600);
}

function db_active_role(): string
{
    $cfg = db_config_load();
    if (!db_replica_enabled($cfg)) {
        return 'primary';
    }
    $state = db_ha_state_load();
    return ($state['role'] ?? 'primary') === 'replica' ? 'replica' : 'primary';
}

function db_ensure_database(array $ep): void
{
    $name = db_ident((string)($ep['name'] ?? ''));
    $server = db_pdo($ep, null, 3);
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

/**
 * Синхронизирует таймзону MySQL-сессии с PHP-таймзоной.
 * Без этого MySQL NOW() и PHP time()/strtotime() считают в разных поясах,
 * и node_presence_from_last_seen() завышает возраст ноды на разницу часовых поясов
 * (нода уходит в offline хотя heartbeat пришёл секунду назад).
 */
function db_sync_timezone(PDO $pdo): void
{
    try {
        $tzName = date_default_timezone_get();
        if (!$tzName || $tzName === 'UTC') {
            $pdo->exec("SET time_zone = '+00:00'");
            return;
        }
        $tz = new DateTimeZone($tzName);
        $now = new DateTime('now', $tz);
        $offset = $tz->getOffset($now);
        $sign = $offset >= 0 ? '+' : '-';
        $offset = abs($offset);
        $h = (int)($offset / 3600);
        $m = (int)(($offset % 3600) / 60);
        $pdo->exec("SET time_zone = '" . $sign . sprintf('%d:%02d', $h, $m) . "'");
    } catch (Throwable $e) {
        // Fallback: если таймзона не поддерживается MySQL — ставим UTC
        try {
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (Throwable $e2) {
            // ignore — MySQL может быть без поддержки таймзон
        }
    }
}
