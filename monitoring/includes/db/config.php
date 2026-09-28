<?php
declare(strict_types=1);

// Каталог monitoring/includes. Модули лежат уровнем ниже, поэтому их
// собственный __DIR__ на уровень глубже; пути конфигурации и данных
// должны считаться от каталога, где лежит db_config.php.
if (!defined('DB_INCLUDES_DIR')) {
    define('DB_INCLUDES_DIR', dirname(__DIR__));
}

/**
 * Хранение конфигурации подключения.
 *
 * Пути конфигов, env-флаги, загрузка и атомарное сохранение. Файл на диске — источник правды, DB_* из окружения — только начальные значения при первом запуске.
 *
 * Часть monitoring/includes/db_config.php; подключается оттуда.
 * Напрямую этот файл не включают.
 */

function db_config_path(): string
{
    $dataDir = dirname(DB_INCLUDES_DIR) . DIRECTORY_SEPARATOR . 'data';
    return $dataDir . DIRECTORY_SEPARATOR . 'db.local.php';
}

function db_ha_state_path(): string
{
    $dataDir = dirname(DB_INCLUDES_DIR) . DIRECTORY_SEPARATOR . 'data';
    return $dataDir . DIRECTORY_SEPARATOR . 'db.active.php';
}

function db_env_flag(?string $value, bool $default): bool
{
    if ($value === null || $value === false || $value === '') {
        return $default;
    }
    return !in_array(strtolower((string)$value), ['0', 'false', 'no', 'off'], true);
}

function db_config_load(): array
{
    $file = [];
    $path = db_config_path();
    $fromFile = is_file($path);
    if ($fromFile) {
        $loaded = include $path;
        if (is_array($loaded)) {
            $file = $loaded;
        }
    }
    $replicaFile = is_array($file['replica'] ?? null) ? $file['replica'] : [];
    $envReplicaHost = getenv('DB_REPLICA_HOST');
    $replicaEnabledDefault = (bool)($file['replica_enabled'] ?? false);
    if ($envReplicaHost !== false && $envReplicaHost !== '' && getenv('DB_REPLICA_ENABLED') === false) {
        $replicaEnabledDefault = true;
    }

    // Основной источник параметров — monitoring/data/db.local.php: его пишет сама панель
    // (Настройки → База данных, db_config_save()). DB_* переменные окружения
    // (systemd/web-сервер) НЕ должны перекрывать сохранённые в панели значения —
    // иначе изменения в настройках не применяются, а форма откатывается на env-значения.
    // Env остаётся только как fallback, когда файл ещё не создан (первый запуск).
    if ($fromFile) {
        return [
            'host' => (string)($file['host'] ?? 'localhost'),
            'port' => (string)($file['port'] ?? '3306'),
            'name' => (string)($file['name'] ?? 'monitoring'),
            'user' => (string)($file['user'] ?? 'root'),
            'password' => (string)($file['password'] ?? ''),
            'replica_enabled' => (bool)($file['replica_enabled'] ?? $replicaEnabledDefault),
            'replica_failback' => (bool)($file['replica_failback'] ?? true),
            'replica' => [
                'host' => (string)($replicaFile['host'] ?? ''),
                'port' => (string)($replicaFile['port'] ?? '3306'),
                'name' => (string)($replicaFile['name'] ?? ''),
                'user' => (string)($replicaFile['user'] ?? ''),
                'password' => (string)($replicaFile['password'] ?? ''),
                'ssl' => (bool)($replicaFile['ssl'] ?? false),
                'ssl_verify' => (bool)($replicaFile['ssl_verify'] ?? false),
                'ssl_ca' => (string)($replicaFile['ssl_ca'] ?? ''),
            ],
            'from_file' => true,
            'from_env' => false,
        ];
    }

    // Файл отсутствует — берём параметры из окружения (docker/systemd/k8s) или дефолты.
    return [
        'host' => (string)(getenv('DB_HOST') ?: 'localhost'),
        'port' => (string)(getenv('DB_PORT') ?: '3306'),
        'name' => (string)(getenv('DB_NAME') ?: 'monitoring'),
        'user' => (string)(getenv('DB_USER') ?: 'root'),
        'password' => (string)(getenv('DB_PASSWORD') !== false && getenv('DB_PASSWORD') !== ''
            ? getenv('DB_PASSWORD')
            : ''),
        'replica_enabled' => db_env_flag(
            getenv('DB_REPLICA_ENABLED') !== false ? (string)getenv('DB_REPLICA_ENABLED') : null,
            $replicaEnabledDefault
        ),
        'replica_failback' => db_env_flag(
            getenv('DB_REPLICA_FAILBACK') !== false ? (string)getenv('DB_REPLICA_FAILBACK') : null,
            true
        ),
        'replica' => [
            'host' => (string)($envReplicaHost !== false && $envReplicaHost !== '' ? $envReplicaHost : ''),
            'port' => (string)(getenv('DB_REPLICA_PORT') ?: '3306'),
            'name' => (string)(getenv('DB_REPLICA_NAME') ?: ''),
            'user' => (string)(getenv('DB_REPLICA_USER') ?: ''),
            'password' => (string)(getenv('DB_REPLICA_PASSWORD') !== false && getenv('DB_REPLICA_PASSWORD') !== ''
                ? getenv('DB_REPLICA_PASSWORD')
                : ''),
            'ssl' => false,
            'ssl_verify' => false,
            'ssl_ca' => '',
        ],
        'from_file' => false,
        'from_env' => (bool)(getenv('DB_NAME') || getenv('DB_USER') || getenv('DB_HOST')),
    ];
}

function db_config_save(array $cfg): void
{
    $dir = dirname(db_config_path());
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог data/ для конфига БД');
    }

    $prev = [];
    $path = db_config_path();
    if (is_file($path)) {
        $loaded = include $path;
        if (is_array($loaded)) {
            $prev = $loaded;
        }
    }
    $prevReplica = is_array($prev['replica'] ?? null) ? $prev['replica'] : [];
    $replicaIn = is_array($cfg['replica'] ?? null) ? $cfg['replica'] : [];

    $password = array_key_exists('password', $cfg) ? (string)$cfg['password'] : '';
    if ($password === '' && ($prev['password'] ?? '') !== '') {
        $password = (string)$prev['password'];
    }
    $replicaPassword = array_key_exists('password', $replicaIn) ? (string)$replicaIn['password'] : '';
    if ($replicaPassword === '' && ($prevReplica['password'] ?? '') !== '') {
        $replicaPassword = (string)$prevReplica['password'];
    }

    $export = var_export([
        'host' => (string)($cfg['host'] ?? $prev['host'] ?? 'localhost'),
        'port' => (string)($cfg['port'] ?? $prev['port'] ?? '3306'),
        'name' => (string)($cfg['name'] ?? $prev['name'] ?? 'monitoring'),
        'user' => (string)($cfg['user'] ?? $prev['user'] ?? 'root'),
        'password' => $password,
        'replica_enabled' => (bool)($cfg['replica_enabled'] ?? $prev['replica_enabled'] ?? false),
        'replica_failback' => (bool)($cfg['replica_failback'] ?? $prev['replica_failback'] ?? true),
        'replica' => [
            'host' => (string)($replicaIn['host'] ?? $prevReplica['host'] ?? ''),
            'port' => (string)($replicaIn['port'] ?? $prevReplica['port'] ?? '3306'),
            'name' => (string)($replicaIn['name'] ?? $prevReplica['name'] ?? ''),
            'user' => (string)($replicaIn['user'] ?? $prevReplica['user'] ?? ''),
            'password' => $replicaPassword,
            'ssl' => (bool)($replicaIn['ssl'] ?? $prevReplica['ssl'] ?? false),
            'ssl_verify' => (bool)($replicaIn['ssl_verify'] ?? $prevReplica['ssl_verify'] ?? false),
            'ssl_ca' => (string)($replicaIn['ssl_ca'] ?? $prevReplica['ssl_ca'] ?? ''),
        ],
    ], true);
    $php = "<?php\n// Сгенерировано панелью. Не коммить.\nreturn {$export};\n";
    if (file_put_contents($path, $php, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать data/db.local.php — проверьте права www-data');
    }
    @chmod($path, 0600);
}
