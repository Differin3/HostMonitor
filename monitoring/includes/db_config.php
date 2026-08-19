<?php
declare(strict_types=1);

function db_config_path(): string
{
    $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    return $dataDir . DIRECTORY_SEPARATOR . 'db.local.php';
}

function db_ha_state_path(): string
{
    $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
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
    if (is_file($path)) {
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

    return [
        'host' => (string)(getenv('DB_HOST') ?: ($file['host'] ?? 'localhost')),
        'port' => (string)(getenv('DB_PORT') ?: ($file['port'] ?? '3306')),
        'name' => (string)(getenv('DB_NAME') ?: ($file['name'] ?? 'monitoring')),
        'user' => (string)(getenv('DB_USER') ?: ($file['user'] ?? 'root')),
        'password' => (string)(getenv('DB_PASSWORD') !== false && getenv('DB_PASSWORD') !== ''
            ? getenv('DB_PASSWORD')
            : ($file['password'] ?? '')),
        'replica_enabled' => db_env_flag(
            getenv('DB_REPLICA_ENABLED') !== false ? (string)getenv('DB_REPLICA_ENABLED') : null,
            $replicaEnabledDefault
        ),
        'replica_failback' => db_env_flag(
            getenv('DB_REPLICA_FAILBACK') !== false ? (string)getenv('DB_REPLICA_FAILBACK') : null,
            (bool)($file['replica_failback'] ?? true)
        ),
        'replica' => [
            'host' => (string)($envReplicaHost !== false && $envReplicaHost !== ''
                ? $envReplicaHost
                : ($replicaFile['host'] ?? '')),
            'port' => (string)(getenv('DB_REPLICA_PORT') ?: ($replicaFile['port'] ?? '3306')),
            'name' => (string)(getenv('DB_REPLICA_NAME') ?: ($replicaFile['name'] ?? '')),
            'user' => (string)(getenv('DB_REPLICA_USER') ?: ($replicaFile['user'] ?? '')),
            'password' => (string)(getenv('DB_REPLICA_PASSWORD') !== false && getenv('DB_REPLICA_PASSWORD') !== ''
                ? getenv('DB_REPLICA_PASSWORD')
                : ($replicaFile['password'] ?? '')),
        ],
        'from_file' => is_file($path),
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
        ],
    ], true);
    $php = "<?php\n// Сгенерировано панелью. Не коммить.\nreturn {$export};\n";
    if (file_put_contents($path, $php, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать data/db.local.php — проверьте права www-data');
    }
    @chmod($path, 0600);
}

function db_reset_pdo(): void
{
    getDbConnection(true);
}

function db_pdo(array $cfg, ?string $dbname = null, int $timeout = 8): PDO
{
    $host = $cfg['host'] ?: 'localhost';
    $port = $cfg['port'] ?: '3306';
    $dsn = $dbname
        ? "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4"
        : "mysql:host={$host};port={$port};charset=utf8mb4";
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => $timeout,
    ];
    if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
        $opts[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = $timeout;
    }
    return new PDO($dsn, (string)$cfg['user'], (string)$cfg['password'], $opts);
}

function db_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('Недопустимое имя базы данных');
    }
    return $name;
}

function db_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function db_schema_path(): string
{
    $name = 'schema_mysql.sql';
    $here = dirname(__DIR__);
    $candidates = [
        dirname($here) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . $name,
        $here . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . $name,
        $here . DIRECTORY_SEPARATOR . $name,
        $here . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $name,
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return $candidates[0];
}

function db_apply_schema(PDO $pdo): void
{
    $path = db_schema_path();
    if (!is_file($path)) {
        throw new RuntimeException('Не найден database/schema_mysql.sql');
    }
    $sql = (string)file_get_contents($path);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $chunks = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($chunks as $chunk) {
        $stmt = trim($chunk);
        if ($stmt === '') {
            continue;
        }
        $head = strtoupper(ltrim($stmt));
        if (strpos($head, 'CREATE DATABASE') === 0 || strpos($head, 'USE ') === 0) {
            continue;
        }
        $isDdl = strpos($head, 'CREATE') === 0 || strpos($head, 'ALTER') === 0;
        $isSeed = (bool)preg_match('/^INSERT\s+(IGNORE\s+)?INTO\s+(settings|providers)\b/i', $stmt);
        if (!$isDdl && !$isSeed) {
            continue;
        }
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if ($isSeed || in_array($code, [1050, 1060, 1061, 1062], true)) {
                continue;
            }
            throw $e;
        }
    }
}

function db_has_users(PDO $pdo): bool
{
    try {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        return $n > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function db_needs_setup(): bool
{
    try {
        $pdo = getDbConnection();
        $pdo->query('SELECT 1');
        try {
            return !db_has_users($pdo);
        } catch (Throwable $e) {
            return true;
        }
    } catch (Throwable $e) {
        return true;
    }
}

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
    return [
        'host' => (string)($ep['host'] ?? ''),
        'port' => (string)($ep['port'] ?? ''),
        'name' => (string)($ep['name'] ?? ''),
        'user' => (string)($ep['user'] ?? ''),
        'has_password' => ($ep['password'] ?? '') !== '',
    ];
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

function db_try_connect(array $ep, int $timeout = 3): PDO
{
    $name = (string)($ep['name'] ?? '');
    if ($name === '') {
        throw new InvalidArgumentException('Не указано имя базы');
    }
    $pdo = db_pdo($ep, $name, $timeout);
    $pdo->query('SELECT 1');
    return $pdo;
}

function db_ping_endpoint(array $ep, int $timeout = 3): array
{
    $started = microtime(true);
    try {
        db_try_connect($ep, $timeout);
        return [
            'ok' => true,
            'ms' => (int)round((microtime(true) - $started) * 1000),
            'error' => null,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'ms' => (int)round((microtime(true) - $started) * 1000),
            'error' => $e->getMessage(),
        ];
    }
}

function db_list_base_tables(PDO $pdo): array
{
    $stmt = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
    $tables = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = (string)$row[0];
    }
    return $tables;
}

function db_strip_foreign_keys(string $ddl): string
{
    $ddl = preg_replace('/,?\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN KEY\s*\([^)]+\)\s*REFERENCES\s*`[^`]+`\s*\([^)]+\)(?:\s+ON DELETE (?:SET NULL|CASCADE|RESTRICT|NO ACTION))?(?:\s+ON UPDATE (?:SET NULL|CASCADE|RESTRICT|NO ACTION))?/i', '', $ddl) ?? $ddl;
    $ddl = preg_replace('/,?\s*FOREIGN KEY\s*\([^)]+\)\s*REFERENCES\s*`[^`]+`\s*\([^)]+\)(?:\s+ON DELETE (?:SET NULL|CASCADE|RESTRICT|NO ACTION))?(?:\s+ON UPDATE (?:SET NULL|CASCADE|RESTRICT|NO ACTION))?/i', '', $ddl) ?? $ddl;
    return preg_replace('/,\s*\)/', ')', $ddl) ?? $ddl;
}

function db_insert_batch(PDO $pdo, string $table, array $cols, array $rows): void
{
    if ($rows === [] || $cols === []) {
        return;
    }
    $colSql = implode(',', array_map('db_quote_ident', $cols));
    $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $sql = 'INSERT INTO ' . db_quote_ident($table) . " ({$colSql}) VALUES "
        . implode(',', array_fill(0, count($rows), $placeholders));
    $params = [];
    foreach ($rows as $row) {
        foreach ($cols as $col) {
            $params[] = $row[$col] ?? null;
        }
    }
    $pdo->prepare($sql)->execute($params);
}

function db_copy_database(PDO $src, PDO $dst): array
{
    @set_time_limit(0);
    $dst->exec('SET FOREIGN_KEY_CHECKS=0');
    $dst->exec('SET UNIQUE_CHECKS=0');
    $tables = db_list_base_tables($src);
    $copied = [];
    $unbuffered = defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    foreach ($tables as $table) {
        $quoted = db_quote_ident($table);
        $createRow = $src->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_ASSOC) ?: [];
        $ddl = (string)($createRow['Create Table'] ?? $createRow['Create View'] ?? '');
        if ($ddl === '') {
            $vals = array_values($createRow);
            $ddl = (string)($vals[1] ?? '');
        }
        if ($ddl === '') {
            throw new RuntimeException('Не удалось прочитать схему таблицы ' . $table);
        }
        $dst->exec('DROP TABLE IF EXISTS ' . $quoted);
        $dst->exec(db_strip_foreign_keys($ddl));
        $count = 0;
        if ($unbuffered) {
            $src->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        try {
            $q = $src->query('SELECT * FROM ' . $quoted);
            $batch = [];
            $cols = null;
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) {
                    $cols = array_keys($row);
                }
                $batch[] = $row;
                if (count($batch) >= 100) {
                    db_insert_batch($dst, $table, $cols, $batch);
                    $count += count($batch);
                    $batch = [];
                }
            }
            if ($batch !== [] && $cols !== null) {
                db_insert_batch($dst, $table, $cols, $batch);
                $count += count($batch);
            }
            $q->closeCursor();
        } finally {
            if ($unbuffered) {
                $src->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
        $copied[$table] = $count;
    }
    $dst->exec('SET UNIQUE_CHECKS=1');
    $dst->exec('SET FOREIGN_KEY_CHECKS=1');
    return ['tables' => $copied, 'table_count' => count($copied), 'row_count' => array_sum($copied)];
}

function settings_defaults(): array
{
    return [
        'system_name' => 'HostMonitor',
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
    }
    settings_load_map(true);
    return $saved;
}
