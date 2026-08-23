<?php
declare(strict_types=1);

function dbmon_ensure_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS monitored_databases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        kind VARCHAR(20) NOT NULL DEFAULT 'custom',
        engine VARCHAR(20) NOT NULL DEFAULT 'mysql',
        host VARCHAR(255) NOT NULL,
        port INT NOT NULL DEFAULT 3306,
        db_name VARCHAR(100) DEFAULT '',
        username VARCHAR(255) NOT NULL DEFAULT '',
        password TEXT,
        node_id INT NULL,
        notes VARCHAR(500) DEFAULT '',
        enabled TINYINT(1) DEFAULT 1,
        status VARCHAR(20) DEFAULT 'unknown',
        last_error TEXT NULL,
        last_seen TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dbmon_status (status),
        INDEX idx_dbmon_kind (kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'ssl TINYINT(1) NOT NULL DEFAULT 0',
        'ssl_verify TINYINT(1) NOT NULL DEFAULT 0',
        "ssl_ca VARCHAR(255) NOT NULL DEFAULT ''",
    ] as $colDef) {
        try {
            $pdo->exec('ALTER TABLE monitored_databases ADD COLUMN ' . $colDef);
        } catch (Throwable $e) {
            // column exists
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS database_metrics (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        database_id INT NOT NULL,
        ping_ms INT NULL,
        uptime_sec BIGINT NULL,
        threads_connected INT NULL,
        threads_running INT NULL,
        max_connections INT NULL,
        questions BIGINT NULL,
        qps FLOAT NULL,
        slow_queries BIGINT NULL,
        bytes_received BIGINT NULL,
        bytes_sent BIGINT NULL,
        data_bytes BIGINT NULL,
        replica_lag_sec INT NULL,
        version VARCHAR(80) NULL,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dbm_ts (database_id, timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    dbmon_sync_builtins($pdo);
}

function dbmon_sync_builtins(PDO $pdo): void
{
    $cfg = db_config_load();
    $primary = db_endpoint($cfg, 'primary');
    dbmon_upsert_builtin($pdo, 'panel', 'Панель', $primary, (string)($cfg['name'] ?? 'monitoring'));

    $replicaOn = db_replica_enabled($cfg);
    if ($replicaOn) {
        $replica = db_endpoint($cfg, 'replica');
        dbmon_upsert_builtin($pdo, 'replica', 'Резерв панели', $replica, (string)($replica['name'] ?? $cfg['name'] ?? 'monitoring'));
    } else {
        $pdo->prepare("UPDATE monitored_databases SET enabled = 0 WHERE kind = 'replica'")->execute();
    }
}

function dbmon_upsert_builtin(PDO $pdo, string $kind, string $name, array $ep, string $dbName): void
{
    $host = (string)($ep['host'] ?? 'localhost');
    $port = (int)($ep['port'] ?? 3306);
    $user = (string)($ep['user'] ?? '');
    $stmt = $pdo->prepare('SELECT id FROM monitored_databases WHERE kind = ? LIMIT 1');
    $stmt->execute([$kind]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $upd = $pdo->prepare('UPDATE monitored_databases SET name = ?, engine = ?, host = ?, port = ?, db_name = ?, username = ?, enabled = 1 WHERE id = ?');
        $upd->execute([$name, 'mysql', $host, $port, $dbName, $user, (int)$id]);
        return;
    }
    $ins = $pdo->prepare('INSERT INTO monitored_databases (name, kind, engine, host, port, db_name, username, password, enabled, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
    $ins->execute([$name, $kind, 'mysql', $host, $port, $dbName, $user, '', 'unknown']);
}

function dbmon_ssl_ca_relative(int $id): string
{
    return 'ssl/dbmon-' . $id . '-ca.pem';
}

function dbmon_ssl_public(array $row): array
{
    $kind = (string)($row['kind'] ?? 'custom');
    if ($kind === 'replica') {
        $ep = db_public_endpoint(db_endpoint(db_config_load(), 'replica'));
        return [
            'ssl' => !empty($ep['ssl']),
            'ssl_verify' => !empty($ep['ssl_verify']),
            'has_ssl_ca' => !empty($ep['has_ssl_ca']),
            'ssl_ca' => (string)($ep['ssl_ca'] ?? ''),
            'ssl_ca_subject' => (string)($ep['ssl_ca_subject'] ?? ''),
            'ssl_ca_cert_count' => (int)($ep['ssl_ca_cert_count'] ?? 0),
            'ssl_managed_in' => 'settings',
        ];
    }
    if ($kind === 'panel') {
        return [
            'ssl' => false,
            'ssl_verify' => false,
            'has_ssl_ca' => false,
            'ssl_ca' => '',
            'ssl_managed_in' => '',
        ];
    }
    $rel = (string)($row['ssl_ca'] ?? '');
    $caInfo = db_ssl_ca_info($rel !== '' ? $rel : '', false);
    $out = [
        'ssl' => !empty($row['ssl']),
        'ssl_verify' => !empty($row['ssl_verify']),
        'has_ssl_ca' => !empty($caInfo['installed']),
        'ssl_ca' => !empty($caInfo['installed']) ? (string)($caInfo['relative'] ?? $rel) : '',
        'ssl_managed_in' => 'local',
    ];
    if (!empty($caInfo['subject'])) {
        $out['ssl_ca_subject'] = (string)$caInfo['subject'];
    }
    if (!empty($caInfo['cert_count'])) {
        $out['ssl_ca_cert_count'] = (int)$caInfo['cert_count'];
    }
    return $out;
}

function dbmon_save_replica_ssl(array $data): void
{
    $cfg = db_config_load();
    $replica = is_array($cfg['replica'] ?? null) ? $cfg['replica'] : [];
    db_config_save(array_merge($cfg, [
        'replica' => array_merge($replica, [
            'ssl' => !empty($data['ssl']),
            'ssl_verify' => !empty($data['ssl_verify']),
            'ssl_ca' => (string)($replica['ssl_ca'] ?? $cfg['replica']['ssl_ca'] ?? ''),
        ]),
    ]));
}

function dbmon_endpoint_for(array $row): array
{
    $kind = (string)($row['kind'] ?? 'custom');
    if ($kind === 'panel' || $kind === 'replica') {
        $cfg = db_config_load();
        return db_endpoint($cfg, $kind === 'replica' ? 'replica' : 'primary');
    }
    return [
        'host' => (string)($row['host'] ?? ''),
        'port' => (string)($row['port'] ?? '3306'),
        'name' => (string)($row['db_name'] ?? ''),
        'user' => (string)($row['username'] ?? ''),
        'password' => (string)($row['password'] ?? ''),
        'ssl' => !empty($row['ssl']),
        'ssl_verify' => !empty($row['ssl_verify']),
        'ssl_ca' => (string)($row['ssl_ca'] ?? ''),
        'ssl_ca_default' => false,
    ];
}

function dbmon_connect(array $ep, string $engine, int $timeout = 12): PDO
{
    $host = $ep['host'] ?: 'localhost';
    $port = $ep['port'] ?: ($engine === 'postgres' ? '5432' : '3306');
    $db = (string)($ep['name'] ?? '');
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => $timeout,
    ];
    if ($engine === 'postgres') {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('PHP без pdo_pgsql — поставьте php-pgsql или используйте MySQL');
        }
        $dsn = $db !== ''
            ? "pgsql:host={$host};port={$port};dbname={$db}"
            : "pgsql:host={$host};port={$port}";
        return new PDO($dsn, (string)$ep['user'], (string)$ep['password'], $opts);
    }
    if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
        $opts[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = $timeout;
    }
    $opts = array_replace($opts, db_pdo_ssl_opts($ep));
    $dsn = $db !== ''
        ? "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4"
        : "mysql:host={$host};port={$port};charset=utf8mb4";
    return new PDO($dsn, (string)$ep['user'], (string)$ep['password'], $opts);
}

function dbmon_kv(PDO $pdo, string $sql): array
{
    $out = [];
    foreach ($pdo->query($sql) as $row) {
        $keys = array_keys($row);
        $out[(string)$row[$keys[0]]] = $row[$keys[1]] ?? null;
    }
    return $out;
}

function dbmon_probe_mysql(PDO $pdo): array
{
    $metrics = [
        'version' => null,
        'uptime_sec' => null,
        'threads_connected' => null,
        'threads_running' => null,
        'max_connections' => null,
        'questions' => null,
        'slow_queries' => null,
        'bytes_received' => null,
        'bytes_sent' => null,
        'data_bytes' => null,
        'replica_lag_sec' => null,
    ];
    try {
        $metrics['version'] = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $status = dbmon_kv($pdo, 'SHOW GLOBAL STATUS');
        $metrics['uptime_sec'] = isset($status['Uptime']) ? (int)$status['Uptime'] : null;
        $metrics['threads_connected'] = isset($status['Threads_connected']) ? (int)$status['Threads_connected'] : null;
        $metrics['threads_running'] = isset($status['Threads_running']) ? (int)$status['Threads_running'] : null;
        $metrics['questions'] = isset($status['Questions']) ? (int)$status['Questions'] : null;
        $metrics['slow_queries'] = isset($status['Slow_queries']) ? (int)$status['Slow_queries'] : null;
        $metrics['bytes_received'] = isset($status['Bytes_received']) ? (int)$status['Bytes_received'] : null;
        $metrics['bytes_sent'] = isset($status['Bytes_sent']) ? (int)$status['Bytes_sent'] : null;
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $vars = dbmon_kv($pdo, 'SHOW VARIABLES WHERE Variable_name IN ("max_connections","version_comment")');
        $metrics['max_connections'] = isset($vars['max_connections']) ? (int)$vars['max_connections'] : null;
        if (!empty($vars['version_comment']) && $metrics['version']) {
            $metrics['version'] = trim($metrics['version'] . ' ' . preg_replace('/\s+/', ' ', (string)$vars['version_comment']));
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $sql = "SELECT SUM(data_length + index_length) FROM information_schema.tables
                WHERE table_schema NOT IN ('mysql','information_schema','performance_schema','sys')";
        $metrics['data_bytes'] = (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
    foreach (['SHOW REPLICA STATUS', 'SHOW SLAVE STATUS'] as $sql) {
        try {
            $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $lag = $row['Seconds_Behind_Source'] ?? $row['Seconds_Behind_Master'] ?? null;
                $metrics['replica_lag_sec'] = $lag === null || $lag === '' ? null : (int)$lag;
                break;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    if ($metrics['version'] && strlen($metrics['version']) > 80) {
        $metrics['version'] = substr($metrics['version'], 0, 80);
    }
    return $metrics;
}

function dbmon_probe_postgres(PDO $pdo): array
{
    $metrics = [
        'version' => null,
        'uptime_sec' => null,
        'threads_connected' => null,
        'threads_running' => null,
        'max_connections' => null,
        'questions' => null,
        'slow_queries' => null,
        'bytes_received' => null,
        'bytes_sent' => null,
        'data_bytes' => null,
        'replica_lag_sec' => null,
    ];
    try {
        $ver = (string)$pdo->query('SELECT version()')->fetchColumn();
        $metrics['version'] = substr(preg_replace('/\s+/', ' ', $ver) ?? $ver, 0, 80);
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $metrics['threads_connected'] = (int)$pdo->query('SELECT count(*) FROM pg_stat_activity')->fetchColumn();
        $metrics['threads_running'] = (int)$pdo->query("SELECT count(*) FROM pg_stat_activity WHERE state = 'active'")->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $metrics['max_connections'] = (int)$pdo->query("SELECT setting FROM pg_settings WHERE name = 'max_connections'")->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $metrics['uptime_sec'] = (int)$pdo->query("SELECT EXTRACT(EPOCH FROM (now() - pg_postmaster_start_time()))")->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $metrics['data_bytes'] = (int)$pdo->query('SELECT COALESCE(SUM(pg_database_size(datname)), 0) FROM pg_database WHERE datistemplate = false')->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $lag = $pdo->query("SELECT EXTRACT(EPOCH FROM (now() - pg_last_xact_replay_timestamp()))")->fetchColumn();
        if ($lag !== false && $lag !== null) {
            $metrics['replica_lag_sec'] = (int)$lag;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $metrics;
}

function dbmon_last_sample(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT questions, timestamp FROM database_metrics WHERE database_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function dbmon_probe_one(PDO $pdo, array $row): array
{
    $id = (int)$row['id'];
    $engine = (string)($row['engine'] ?? 'mysql');
    $ep = dbmon_endpoint_for($row);
    $started = microtime(true);
    $error = '';
    $metrics = [];
    try {
        $conn = dbmon_connect($ep, $engine, 12);
        $conn->query('SELECT 1');
        $metrics = $engine === 'postgres' ? dbmon_probe_postgres($conn) : dbmon_probe_mysql($conn);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $ping = (int)round((microtime(true) - $started) * 1000);
    $online = $error === '';
    $qps = null;
    if ($online && isset($metrics['questions'])) {
        $prev = dbmon_last_sample($pdo, $id);
        if ($prev && $prev['questions'] !== null) {
            $elapsed = max(1, time() - strtotime((string)$prev['timestamp']));
            $delta = (int)$metrics['questions'] - (int)$prev['questions'];
            if ($delta >= 0) {
                $qps = round($delta / $elapsed, 2);
            }
        }
        if ($qps === null && !empty($metrics['uptime_sec'])) {
            $qps = round(((int)$metrics['questions']) / max(1, (int)$metrics['uptime_sec']), 2);
        }
    }

    $oldStatus = (string)($row['status'] ?? 'unknown');
    $newStatus = $online ? 'online' : 'offline';
    $upd = $pdo->prepare('UPDATE monitored_databases SET status = ?, last_error = ?, last_seen = ? WHERE id = ?');
    $upd->execute([$newStatus, $online ? null : $error, date('Y-m-d H:i:s'), $id]);

    if ($online) {
        $ins = $pdo->prepare('INSERT INTO database_metrics
            (database_id, ping_ms, uptime_sec, threads_connected, threads_running, max_connections, questions, qps, slow_queries, bytes_received, bytes_sent, data_bytes, replica_lag_sec, version)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([
            $id,
            $ping,
            $metrics['uptime_sec'] ?? null,
            $metrics['threads_connected'] ?? null,
            $metrics['threads_running'] ?? null,
            $metrics['max_connections'] ?? null,
            $metrics['questions'] ?? null,
            $qps,
            $metrics['slow_queries'] ?? null,
            $metrics['bytes_received'] ?? null,
            $metrics['bytes_sent'] ?? null,
            $metrics['data_bytes'] ?? null,
            $metrics['replica_lag_sec'] ?? null,
            $metrics['version'] ?? null,
        ]);
    }

    dbmon_status_alert($pdo, $row, $oldStatus, $newStatus, $error);

    $row['status'] = $newStatus;
    $row['last_error'] = $online ? null : $error;
    $row['last_seen'] = date('Y-m-d H:i:s');
    $row['metrics'] = $online ? array_merge($metrics, ['ping_ms' => $ping, 'qps' => $qps]) : null;
    return $row;
}

function dbmon_status_alert(PDO $pdo, array $row, string $old, string $now, string $error): void
{
    $name = (string)($row['name'] ?? 'БД');
    $nodeId = $row['node_id'] !== null && $row['node_id'] !== '' ? (int)$row['node_id'] : null;
    try {
        if ($old === 'online' && $now === 'offline') {
            $stmt = $pdo->prepare("INSERT INTO alerts (node_id, level, title, message, resolved) VALUES (?, 'warning', ?, ?, 0)");
            $stmt->execute([$nodeId, 'БД «' . $name . '» недоступна', $error !== '' ? $error : 'Нет ответа']);
        }
        if ($old === 'offline' && $now === 'online') {
            $stmt = $pdo->prepare("UPDATE alerts SET resolved = 1, resolved_at = NOW() WHERE resolved = 0 AND title = ?");
            $stmt->execute(['БД «' . $name . '» недоступна']);
        }
    } catch (Throwable $e) {
        error_log('[dbmon] alert: ' . $e->getMessage());
    }
}

function dbmon_probe_all(PDO $pdo, bool $onlyStale = false, int $staleSec = 90): array
{
    dbmon_ensure_tables($pdo);
    $rows = $pdo->query("SELECT * FROM monitored_databases WHERE enabled = 1 ORDER BY kind <> 'panel', id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $seen = (string)($row['last_seen'] ?? '');
        $age = $seen !== '' ? time() - strtotime($seen) : 99999;
        if ($onlyStale && $age < $staleSec && ($row['status'] ?? '') !== 'unknown') {
            $out[] = $row;
            continue;
        }
        $out[] = dbmon_probe_one($pdo, $row);
    }
    return $out;
}

function dbmon_public(array $row, ?array $metrics = null): array
{
    unset($row['password']);
    $row['id'] = (int)$row['id'];
    $row['port'] = (int)$row['port'];
    $row['enabled'] = (int)($row['enabled'] ?? 1);
    $row['is_builtin'] = in_array($row['kind'] ?? '', ['panel', 'replica'], true);
    $row['has_password'] = false;
    $row = array_merge($row, dbmon_ssl_public($row));
    if ($metrics) {
        $row['metrics'] = $metrics;
    }
    return $row;
}

function dbmon_attach_latest(PDO $pdo, array $rows): array
{
    if (!$rows) {
        return [];
    }
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT m.* FROM database_metrics m
        INNER JOIN (SELECT database_id, MAX(id) AS mid FROM database_metrics WHERE database_id IN ({$place}) GROUP BY database_id) x
        ON m.id = x.mid");
    $stmt->execute($ids);
    $map = [];
    while ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int)$m['database_id']] = $m;
    }
    $out = [];
    foreach ($rows as $row) {
        $pub = dbmon_public($row);
        $pub['has_password'] = (string)($row['password'] ?? '') !== '';
        $pub['metrics'] = $map[(int)$row['id']] ?? ($row['metrics'] ?? null);
        $out[] = $pub;
    }
    return $out;
}