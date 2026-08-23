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
            'ssl' => (bool)($replicaFile['ssl'] ?? false),
            'ssl_verify' => (bool)($replicaFile['ssl_verify'] ?? false),
            'ssl_ca' => (string)($replicaFile['ssl_ca'] ?? ''),
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

function db_reset_pdo(): void
{
    getDbConnection(true);
}

function db_ssl_ca_relative(): string
{
    return 'ssl/replica-ca.pem';
}

function db_ssl_data_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ssl';
}

function db_ssl_ca_absolute(?string $relative = null): string
{
    $rel = $relative !== null && $relative !== '' ? $relative : db_ssl_ca_relative();
    $rel = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rel);
    if (str_contains($rel, '..')) {
        throw new InvalidArgumentException('Недопустимый путь к CA-сертификату');
    }
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $rel;
}

function db_ssl_validate_pem(string $pem): bool
{
    $pem = trim($pem);
    if ($pem === '') {
        return false;
    }
    return str_contains($pem, '-----BEGIN CERTIFICATE-----')
        && str_contains($pem, '-----END CERTIFICATE-----');
}

function db_ssl_ca_resolve(?string $configured, bool $allowReplicaDefault = true): ?string
{
    if ($configured !== null && $configured !== '') {
        $path = db_ssl_ca_absolute($configured);
        return is_readable($path) ? $path : null;
    }
    if ($configured === '' && !$allowReplicaDefault) {
        return null;
    }
    if (!$allowReplicaDefault) {
        return null;
    }
    $default = db_ssl_ca_absolute(db_ssl_ca_relative());
    return is_readable($default) ? $default : null;
}

function db_ssl_ca_save(string $pem, ?string $relative = null): string
{
    if (!db_ssl_validate_pem($pem)) {
        throw new InvalidArgumentException('Файл должен содержать PEM-сертификат (-----BEGIN CERTIFICATE-----)');
    }
    $rel = $relative !== null && $relative !== '' ? $relative : db_ssl_ca_relative();
    $dir = db_ssl_data_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог data/ssl/');
    }
    $path = db_ssl_ca_absolute($rel);
    if (file_put_contents($path, trim($pem) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить CA-сертификат — проверьте права data/ssl/');
    }
    @chmod($path, 0640);
    return $rel;
}

function db_ssl_ca_remove(?string $relative = null): void
{
    $path = db_ssl_ca_absolute($relative);
    if (is_file($path)) {
        @unlink($path);
    }
}

function db_ssl_ca_info(?string $configured = null, bool $allowReplicaDefault = true): array
{
    $path = db_ssl_ca_resolve($configured, $allowReplicaDefault);
    if ($path === null) {
        return ['installed' => false, 'path' => '', 'relative' => ''];
    }
    $info = [
        'installed' => true,
        'path' => $path,
        'relative' => $configured !== null && $configured !== '' ? $configured : db_ssl_ca_relative(),
        'size' => (int)@filesize($path),
        'modified' => (int)@filemtime($path),
    ];
    $pem = @file_get_contents($path);
    if (is_string($pem) && db_ssl_validate_pem($pem) && function_exists('openssl_x509_parse')) {
        $blocks = preg_split('/(?=-----BEGIN CERTIFICATE-----)/', $pem) ?: [];
        $info['cert_count'] = 0;
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $x509 = @openssl_x509_parse($block);
            if (is_array($x509)) {
                $info['cert_count']++;
                if (empty($info['subject'])) {
                    $info['subject'] = (string)($x509['name'] ?? '');
                }
            }
        }
    }
    return $info;
}

function db_pdo_ssl_opts(array $ep): array
{
    $opts = [];
    if (empty($ep['ssl'])) {
        return $opts;
    }
    $verify = !empty($ep['ssl_verify']);
    $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $verify;

    $allowDefault = ($ep['ssl_ca_default'] ?? true);
    $configured = array_key_exists('ssl_ca', $ep) ? (string)$ep['ssl_ca'] : null;
    $caPath = db_ssl_ca_resolve($configured, $allowDefault);
    if ($caPath !== null) {
        $opts[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
    } elseif ($verify) {
        foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'] as $sysCa) {
            if (is_readable($sysCa)) {
                $opts[PDO::MYSQL_ATTR_SSL_CA] = $sysCa;
                break;
            }
        }
    }
    return $opts;
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
    $opts = array_replace($opts, db_pdo_ssl_opts($cfg));
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

function db_is_configured(): bool
{
    $cfg = db_config_load();
    if (!empty($cfg['from_file'])) {
        return true;
    }
    if (!empty($cfg['from_env'])) {
        $name = trim((string)($cfg['name'] ?? ''));
        $user = trim((string)($cfg['user'] ?? ''));
        return $name !== '' && $user !== '';
    }
    return false;
}

function db_needs_setup(): bool
{
    if (!db_is_configured()) {
        return true;
    }
    try {
        $pdo = getDbConnection();
        $pdo->query('SELECT 1');
        try {
            return !db_has_users($pdo);
        } catch (Throwable $e) {
            return true;
        }
    } catch (Throwable $e) {
        return false;
    }
}

function db_connection_status(): array
{
    $cfg = db_config_load();
    $status = [
        'configured' => db_is_configured(),
        'replica_enabled' => db_replica_enabled($cfg),
        'primary' => null,
        'replica' => null,
        'active_role' => null,
        'last_error' => null,
    ];
    if (!$status['configured']) {
        return $status;
    }
    $primaryEp = db_endpoint($cfg, 'primary');
    $GLOBALS['_db_ep_primary_host'] = $primaryEp['host'];
    $GLOBALS['_db_ep_primary_name'] = $primaryEp['name'];
    try {
        $status['primary'] = db_ping_endpoint($primaryEp, 3);
    } catch (Throwable $e) {
        $status['primary'] = ['ok' => false, 'ms' => 0, 'error' => $e->getMessage()];
    }
    $status['primary']['host'] = $primaryEp['host'];
    $status['primary']['port'] = $primaryEp['port'];
    $status['primary']['name'] = $primaryEp['name'];
    $status['primary']['user'] = $primaryEp['user'];

    if ($status['replica_enabled']) {
        $replicaEp = db_endpoint($cfg, 'replica');
        $GLOBALS['_db_ep_replica_host'] = $replicaEp['host'];
        $GLOBALS['_db_ep_replica_name'] = $replicaEp['name'];
        try {
            $status['replica'] = db_ping_endpoint($replicaEp, 3);
        } catch (Throwable $e) {
            $status['replica'] = ['ok' => false, 'ms' => 0, 'error' => $e->getMessage()];
        }
        $status['replica']['host'] = $replicaEp['host'];
        $status['replica']['port'] = $replicaEp['port'];
        $status['replica']['name'] = $replicaEp['name'];
        $status['replica']['user'] = $replicaEp['user'];
    }
    try {
        $status['active_role'] = db_active_role();
    } catch (Throwable $e) {
    }
    $anyOk = ($status['primary']['ok'] ?? false) || ($status['replica']['ok'] ?? false);
    if (!$anyOk) {
        $errors = [];
        if (!empty($status['primary']['error'])) $errors[] = 'Основная: ' . $status['primary']['error'];
        if (!empty($status['replica']['error'])) $errors[] = 'Резервная: ' . $status['replica']['error'];
        $status['last_error'] = implode('; ', $errors) ?: 'Не удалось подключиться к базам данных';
    }
    return $status;
}

function db_connection_editable(array $status): bool
{
    if (empty($status['configured'])) {
        return true;
    }
    $primaryOk = !empty($status['primary']['ok']);
    if (empty($status['replica_enabled'])) {
        return $primaryOk;
    }
    return $primaryOk || !empty($status['replica']['ok']);
}

function db_render_error_page(array $status): void
{
    $title = 'Ошибка подключения к базе данных';
    $brandName = 'HostMonitor';
    $primary = $status['primary'];
    $replica = $status['replica'];
    $replicaEnabled = !empty($status['replica_enabled']);
    $lastError = $status['last_error'] ?? 'Неизвестная ошибка';
    $bothDown = !db_connection_editable($status);
    header('HTTP/1.1 503 Service Unavailable');
    header('Retry-After: 60');
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> · <?= htmlspecialchars($brandName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Inter', system-ui, sans-serif; background: #0b1220; color: #e2e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { background: #111827; border: 1px solid rgba(239,68,68,0.25); border-radius: 14px; max-width: 640px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.35); overflow: hidden; }
        .card-head { background: linear-gradient(135deg, rgba(239,68,68,0.12), rgba(234,179,8,0.08)); padding: 28px 28px 12px; border-bottom: 1px solid rgba(148,163,184,0.08); display: flex; align-items: flex-start; gap: 16px; }
        .icon { flex: 0 0 auto; width: 48px; height: 48px; border-radius: 12px; background: rgba(239,68,68,0.15); display: flex; align-items: center; justify-content: center; color: #f87171; }
        .icon svg { width: 26px; height: 26px; stroke-width: 2; stroke: currentColor; fill: none; }
        .card-title { margin: 0 0 6px; font-size: 20px; font-weight: 700; color: #fecaca; }
        .card-sub { margin: 0; font-size: 14px; color: #cbd5e1; line-height: 1.5; }
        .card-body { padding: 24px 28px; }
        .section-title { font-size: 12px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: #94a3b8; margin: 0 0 12px; }
        .endpoints { display: grid; gap: 12px; margin-bottom: 22px; }
        .endpoint { background: rgba(15,23,42,0.6); border: 1px solid rgba(148,163,184,0.08); border-radius: 10px; padding: 14px 16px; }
        .ep-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .ep-name { display: flex; align-items: center; gap: 10px; font-weight: 600; color: #f1f5f9; }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge.ok { background: rgba(16,185,129,0.15); color: #34d399; }
        .badge.err { background: rgba(239,68,68,0.15); color: #f87171; }
        .dot { width: 8px; height: 8px; border-radius: 50%; }
        .badge.ok .dot { background: #34d399; box-shadow: 0 0 0 4px rgba(16,185,129,0.12); }
        .badge.err .dot { background: #f87171; box-shadow: 0 0 0 4px rgba(239,68,68,0.12); }
        .ep-meta { font-size: 13px; color: #94a3b8; display: flex; flex-wrap: wrap; gap: 6px 14px; }
        .ep-meta span strong { color: #e2e8f0; font-weight: 500; }
        .ep-error { margin-top: 10px; font-size: 13px; color: #fca5a5; background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.15); border-radius: 8px; padding: 10px 12px; line-height: 1.5; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; word-break: break-word; }
        .hint-box { background: rgba(59,130,246,0.08); border: 1px solid rgba(59,130,246,0.18); border-radius: 10px; padding: 14px 16px; color: #bfdbfe; font-size: 13.5px; line-height: 1.6; }
        .hint-box strong { color: #dbeafe; }
        .actions { margin-top: 22px; display: flex; flex-wrap: wrap; gap: 10px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; font-weight: 600; font-size: 14px; text-decoration: none; border: none; cursor: pointer; transition: 150ms; }
        .btn-primary { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,0.25); }
        .btn-primary:hover { filter: brightness(1.07); }
        .btn-secondary { background: rgba(148,163,184,0.08); color: #e2e8f0; border: 1px solid rgba(148,163,184,0.15); }
        .btn-secondary:hover { background: rgba(148,163,184,0.14); }
        .foot { margin-top: 18px; text-align: center; font-size: 12px; color: #64748b; }
        .retry-tip { display: inline-block; margin-left: 8px; color: #64748b; font-size: 12px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-head">
            <div class="icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div>
                <h1 class="card-title"><?= htmlspecialchars($title) ?></h1>
                <p class="card-sub">Панель временно не может работать с базой данных. Уже настроенная конфигурация сохранена — пожалуйста, проверьте сервер MySQL/MariaDB.</p>
            </div>
        </div>
        <div class="card-body">
            <div class="section-title">Состояние подключений</div>
            <div class="endpoints">
                <div class="endpoint">
                    <div class="ep-head">
                        <div class="ep-name">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#60a5fa"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
                            Основная база
                        </div>
                        <?php if (($primary['ok'] ?? false) === true): ?>
                            <span class="badge ok"><span class="dot"></span>ОК (<?= (int)($primary['ms'] ?? 0) ?> мс)</span>
                        <?php else: ?>
                            <span class="badge err"><span class="dot"></span>Недоступна</span>
                        <?php endif; ?>
                    </div>
                    <div class="ep-meta">
                        <span>Хост: <strong><?= htmlspecialchars((string)($primary['host'] ?? $GLOBALS['_db_ep_primary_host'] ?? '—')) ?></strong></span>
                        <span>База: <strong><?= htmlspecialchars((string)($primary['name'] ?? $GLOBALS['_db_ep_primary_name'] ?? '—')) ?></strong></span>
                    </div>
                    <?php if (!empty($primary['error'])): ?>
                        <div class="ep-error"><?= htmlspecialchars((string)$primary['error']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($replicaEnabled): ?>
                <div class="endpoint">
                    <div class="ep-head">
                        <div class="ep-name">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#34d399"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                            Резервная база
                        </div>
                        <?php if (($replica['ok'] ?? false) === true): ?>
                            <span class="badge ok"><span class="dot"></span>ОК (<?= (int)($replica['ms'] ?? 0) ?> мс)</span>
                        <?php else: ?>
                            <span class="badge err"><span class="dot"></span>Недоступна</span>
                        <?php endif; ?>
                    </div>
                    <div class="ep-meta">
                        <span>Хост: <strong><?= htmlspecialchars((string)($replica['host'] ?? $GLOBALS['_db_ep_replica_host'] ?? '—')) ?></strong></span>
                        <span>База: <strong><?= htmlspecialchars((string)($replica['name'] ?? $GLOBALS['_db_ep_replica_name'] ?? '—')) ?></strong></span>
                    </div>
                    <?php if (!empty($replica['error'])): ?>
                        <div class="ep-error"><?= htmlspecialchars((string)$replica['error']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="hint-box">
                <strong>Что можно сделать:</strong><br>
                1. Проверьте запущен ли сервер MySQL/MariaDB (systemctl status mariadb / mysqld)<br>
                2. Если база временно перегружена — подождите 1–2 минуты и повторите попытку.<br>
                <?php if ($bothDown): ?>
                    <br>Обе базы недоступны — изменить настройки через панель нельзя. Исправьте сервер БД или отредактируйте <code>monitoring/data/db.local.php</code> по SSH.
                <?php elseif (!$replicaEnabled): ?>
                    <br>💡 Совет: включите резервную базу в «Настройки → База данных» — панель сможет работать при падении основной.
                <?php else: ?>
                    <br>Хотя бы одна база отвечает — параметры можно изменить в «Настройки → База данных».
                <?php endif; ?>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-primary" onclick="location.reload()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Повторить подключение
                </button>
                <?php if (!$bothDown): ?>
                <a href="settings.php#database" class="btn btn-secondary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
                    Настройки базы данных
                </a>
                <?php endif; ?>
                <?php if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION)): ?>
                    <a href="logout.php" class="btn btn-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Выйти
                    </a>
                <?php endif; ?>
            </div>
            <div class="foot">HostMonitor · страница будет автоматически обновлена через 60 секунд<span class="retry-tip">(или нажмите кнопку выше)</span></div>
        </div>
    </div>
    <script>
        setTimeout(function(){ location.reload(); }, 60000);
    </script>
</body>
</html>
    <?php
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

function db_copy_table_schema(PDO $src, PDO $dst, string $table): void
{
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
}

function db_table_pk_column(PDO $pdo, string $table): ?string
{
    $quoted = db_quote_ident($table);
    try {
        $stmt = $pdo->query('SHOW KEYS FROM ' . $quoted . " WHERE Key_name = 'PRIMARY'");
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = (string)($row['Column_name'] ?? '');
        }
        if (count($cols) === 1 && $cols[0] !== '') {
            return $cols[0];
        }
    } catch (Throwable $e) {
        // ignore
    }
    return null;
}

function db_table_approx_rows(PDO $pdo, string $table): int
{
    $quoted = db_quote_ident($table);
    try {
        $n = $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
        return max(0, (int)$n);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Копирует порцию строк таблицы. Короткий запрос — чтобы не ловить HTTP 504 у nginx.
 *
 * @return array{rows:int,next_offset:int,next_cursor:?string,table_done:bool,pk:?string}
 */
function db_copy_table_chunk(
    PDO $src,
    PDO $dst,
    string $table,
    int $offset = 0,
    ?string $cursor = null,
    int $limit = 200,
    float $maxSeconds = 5.0
): array {
    // Маленькие порции: удалённый SkySQL/SSL + nginx иначе отдают HTTP 504.
    $limit = max(25, min(400, $limit));
    $quoted = db_quote_ident($table);
    $pk = db_table_pk_column($src, $table);
    $started = microtime(true);
    $count = 0;
    $nextCursor = $cursor;
    $batch = [];
    $cols = null;
    $insertBatch = 40;

    if ($pk !== null) {
        $pkQuoted = db_quote_ident($pk);
        if ($cursor !== null && $cursor !== '') {
            $stmt = $src->prepare("SELECT * FROM {$quoted} WHERE {$pkQuoted} > ? ORDER BY {$pkQuoted} ASC LIMIT {$limit}");
            $stmt->execute([$cursor]);
        } else {
            $stmt = $src->query("SELECT * FROM {$quoted} ORDER BY {$pkQuoted} ASC LIMIT {$limit}");
        }
    } else {
        $stmt = $src->query("SELECT * FROM {$quoted} LIMIT {$limit} OFFSET " . max(0, $offset));
    }

    $stoppedEarly = false;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($cols === null) {
            $cols = array_keys($row);
        }
        $batch[] = $row;
        if ($pk !== null) {
            $nextCursor = (string)($row[$pk] ?? $nextCursor);
        }
        if (count($batch) >= $insertBatch) {
            db_insert_batch($dst, $table, $cols, $batch);
            $count += count($batch);
            $batch = [];
            if ((microtime(true) - $started) >= $maxSeconds) {
                $stoppedEarly = true;
                break;
            }
        }
    }
    if ($batch !== [] && $cols !== null) {
        db_insert_batch($dst, $table, $cols, $batch);
        $count += count($batch);
    }
    $stmt->closeCursor();

    // table_done только если выборка исчерпана, а не из‑за лимита времени.
    $tableDone = !$stoppedEarly && $count < $limit;
    return [
        'rows' => $count,
        'next_offset' => $offset + $count,
        'next_cursor' => $pk !== null ? $nextCursor : null,
        'table_done' => $tableDone,
        'pk' => $pk,
    ];
}

function db_copy_table(PDO $src, PDO $dst, string $table): int
{
    db_copy_table_schema($src, $dst, $table);
    $offset = 0;
    $cursor = null;
    $total = 0;
    do {
        $chunk = db_copy_table_chunk($src, $dst, $table, $offset, $cursor, 2000, 60.0);
        $total += $chunk['rows'];
        $offset = $chunk['next_offset'];
        $cursor = $chunk['next_cursor'];
    } while (!$chunk['table_done']);
    return $total;
}

function db_sync_endpoints(string $direction): array
{
    if ($direction !== 'to_replica' && $direction !== 'to_primary') {
        throw new InvalidArgumentException('direction: to_replica или to_primary');
    }
    $cfg = db_config_load();
    if (!db_replica_enabled($cfg)) {
        throw new RuntimeException('Сначала включите резервную базу');
    }
    $primary = db_endpoint($cfg, 'primary');
    $replica = db_endpoint($cfg, 'replica');
    if (db_endpoint_same($primary, $replica)) {
        throw new RuntimeException('Основная и резервная база совпадают — копировать некуда');
    }
    if ($direction === 'to_replica') {
        return [
            'src' => $primary,
            'dst' => $replica,
            'source_label' => 'основная',
            'target_label' => 'резервная',
        ];
    }
    return [
        'src' => $replica,
        'dst' => $primary,
        'source_label' => 'резервная',
        'target_label' => 'основная',
    ];
}

function db_sync_restore_checks(PDO $dst): void
{
    $dst->exec('SET UNIQUE_CHECKS=1');
    $dst->exec('SET FOREIGN_KEY_CHECKS=1');
}

function db_copy_database(PDO $src, PDO $dst): array
{
    @set_time_limit(0);
    $dst->exec('SET FOREIGN_KEY_CHECKS=0');
    $dst->exec('SET UNIQUE_CHECKS=0');
    $tables = db_list_base_tables($src);
    $copied = [];
    foreach ($tables as $table) {
        $copied[$table] = db_copy_table($src, $dst, $table);
    }
    db_sync_restore_checks($dst);
    return ['tables' => $copied, 'table_count' => count($copied), 'row_count' => array_sum($copied)];
}

function web_config_path(): string
{
    $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
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
