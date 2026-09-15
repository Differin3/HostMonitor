<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/db_monitor.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();
require_api_auth($pdo);
dbmon_ensure_tables($pdo);

function dbmon_read_body(): array
{
    $data = json_decode((string)file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function dbmon_normalize_engine(string $engine): string
{
    $engine = strtolower(trim($engine));
    if (in_array($engine, ['postgres', 'postgresql', 'pgsql'], true)) {
        return 'postgres';
    }
    if (in_array($engine, ['mariadb', 'maria'], true)) {
        return 'mariadb';
    }
    return 'mysql';
}

function dbmon_save_row(PDO $pdo, int $id, array $data): void
{
    if ($id < 1) {
        json_error('ID обязателен');
    }
    $stmt = $pdo->prepare('SELECT * FROM monitored_databases WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_error('База не найдена', 404);
    }
    $builtin = in_array($row['kind'], ['panel', 'replica'], true);
    $name = trim((string)($data['name'] ?? $row['name']));
    $notes = trim((string)($data['notes'] ?? $row['notes']));
    $enabled = array_key_exists('enabled', $data) ? (!empty($data['enabled']) ? 1 : 0) : (int)$row['enabled'];
    if ($builtin) {
        $pdo->prepare('UPDATE monitored_databases SET name = ?, notes = ?, enabled = ? WHERE id = ?')
            ->execute([$name !== '' ? $name : $row['name'], $notes, $enabled, $id]);
        if (($row['kind'] ?? '') === 'replica' && (array_key_exists('ssl', $data) || array_key_exists('ssl_verify', $data))) {
            dbmon_save_replica_ssl($data);
        }
    } else {
        $engine = dbmon_normalize_engine((string)($data['engine'] ?? $row['engine']));
        $host = trim((string)($data['host'] ?? $row['host']));
        $port = (int)($data['port'] ?? $row['port']);
        $dbName = array_key_exists('db_name', $data) ? trim((string)$data['db_name']) : (string)$row['db_name'];
        $user = trim((string)($data['username'] ?? $row['username']));
        $nodeId = array_key_exists('node_id', $data)
            ? ($data['node_id'] !== '' && $data['node_id'] !== null ? (int)$data['node_id'] : null)
            : ($row['node_id'] !== null ? (int)$row['node_id'] : null);
        $password = (string)($data['password'] ?? '');
        $ssl = array_key_exists('ssl', $data) ? (!empty($data['ssl']) ? 1 : 0) : (int)($row['ssl'] ?? 0);
        $sslVerify = array_key_exists('ssl_verify', $data) ? (!empty($data['ssl_verify']) ? 1 : 0) : (int)($row['ssl_verify'] ?? 0);
        if ($password === '') {
            $pdo->prepare('UPDATE monitored_databases SET name = ?, engine = ?, host = ?, port = ?, db_name = ?, username = ?, node_id = ?, notes = ?, enabled = ?, ssl = ?, ssl_verify = ? WHERE id = ?')
                ->execute([$name, $engine, $host, $port, $dbName, $user, $nodeId, $notes, $enabled, $ssl, $sslVerify, $id]);
        } else {
            $pdo->prepare('UPDATE monitored_databases SET name = ?, engine = ?, host = ?, port = ?, db_name = ?, username = ?, password = ?, node_id = ?, notes = ?, enabled = ?, ssl = ?, ssl_verify = ? WHERE id = ?')
                ->execute([$name, $engine, $host, $port, $dbName, $user, $password, $nodeId, $notes, $enabled, $ssl, $sslVerify, $id]);
        }
    }
    $stmt->execute([$id]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
    dbmon_probe_one($pdo, $fresh);
    $stmt->execute([$id]);
    echo json_encode(['ok' => true, 'database' => dbmon_public($stmt->fetch(PDO::FETCH_ASSOC) ?: $fresh)], JSON_UNESCAPED_UNICODE);
}

function dbmon_upload_ssl_ca(PDO $pdo, int $id, string $pem): void
{
    if ($id < 1) {
        json_error('ID обязателен');
    }
    if (!db_ssl_validate_pem($pem)) {
        json_error('Некорректный PEM: нужен текст с -----BEGIN CERTIFICATE-----');
    }
    $stmt = $pdo->prepare('SELECT * FROM monitored_databases WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_error('База не найдена', 404);
    }
    $kind = (string)($row['kind'] ?? 'custom');
    if ($kind === 'replica') {
        $rel = db_ssl_ca_save($pem);
        $cfg = db_config_load();
        $replica = is_array($cfg['replica'] ?? null) ? $cfg['replica'] : [];
        db_config_save(array_merge($cfg, [
            'replica' => array_merge($replica, ['ssl_ca' => $rel, 'ssl' => true]),
        ]));
        $sslInfo = db_ssl_ca_info($rel);
    } elseif ($kind === 'panel') {
        json_error('SSL для основной базы панели настраивается в Настройки → База данных');
    } else {
        $rel = db_ssl_ca_save($pem, dbmon_ssl_ca_relative($id));
        $pdo->prepare('UPDATE monitored_databases SET ssl = 1, ssl_ca = ? WHERE id = ?')->execute([$rel, $id]);
        $sslInfo = db_ssl_ca_info($rel, false);
    }
    $stmt->execute([$id]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
    dbmon_probe_one($pdo, $fresh);
    $stmt->execute([$id]);
    echo json_encode([
        'ok' => true,
        'ssl_ca' => $sslInfo,
        'database' => dbmon_public($stmt->fetch(PDO::FETCH_ASSOC) ?: $fresh),
        'message' => 'CA-сертификат сохранён',
    ], JSON_UNESCAPED_UNICODE);
}

function dbmon_remove_ssl_ca(PDO $pdo, int $id): void
{
    if ($id < 1) {
        json_error('ID обязателен');
    }
    $stmt = $pdo->prepare('SELECT * FROM monitored_databases WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_error('База не найдена', 404);
    }
    $kind = (string)($row['kind'] ?? 'custom');
    if ($kind === 'replica') {
        db_ssl_ca_remove();
        $cfg = db_config_load();
        $replica = is_array($cfg['replica'] ?? null) ? $cfg['replica'] : [];
        db_config_save(array_merge($cfg, [
            'replica' => array_merge($replica, ['ssl_ca' => '']),
        ]));
        $sslInfo = db_ssl_ca_info('', true);
    } elseif ($kind === 'panel') {
        json_error('SSL для основной базы панели настраивается в Настройки → База данных');
    } else {
        db_ssl_ca_remove(dbmon_ssl_ca_relative($id));
        $pdo->prepare("UPDATE monitored_databases SET ssl_ca = '' WHERE id = ?")->execute([$id]);
        $sslInfo = db_ssl_ca_info('', false);
    }
    $stmt->execute([$id]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok' => true,
        'ssl_ca' => $sslInfo,
        'database' => dbmon_public($fresh),
        'message' => 'CA-сертификат удалён',
    ], JSON_UNESCAPED_UNICODE);
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET') {
        if ($id > 0 && isset($_GET['history'])) {
            $from = (int)($_GET['from'] ?? (time() - 3600));
            $limit = max(10, min(400, (int)($_GET['limit'] ?? 120)));
            $stmt = $pdo->prepare("SELECT ping_ms, uptime_sec, threads_connected, threads_running, max_connections, qps, data_bytes, replica_lag_sec, timestamp
                FROM database_metrics WHERE database_id = ? AND UNIX_TIMESTAMP(timestamp) >= ? ORDER BY timestamp ASC LIMIT {$limit}");
            $stmt->execute([$id, $from]);
            echo json_encode(['ok' => true, 'points' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $probe = isset($_GET['probe']);
        if ($probe) {
            dbmon_probe_all($pdo, false);
        } else {
            dbmon_probe_all($pdo, true, 90);
        }

        $rows = $pdo->query("SELECT * FROM monitored_databases ORDER BY FIELD(kind, 'panel', 'replica', 'custom'), name")->fetchAll(PDO::FETCH_ASSOC);
        $list = dbmon_attach_latest($pdo, $rows);
        $online = 0;
        $conn = 0;
        $bytes = 0;
        foreach ($list as $item) {
            if (($item['status'] ?? '') === 'online') {
                $online++;
            }
            $m = $item['metrics'] ?? [];
            $conn += (int)($m['threads_connected'] ?? 0);
            $bytes += (int)($m['data_bytes'] ?? 0);
        }
        echo json_encode([
            'ok' => true,
            'databases' => $list,
            'stats' => [
                'total' => count($list),
                'online' => $online,
                'connections' => $conn,
                'data_bytes' => $bytes,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        $data = dbmon_read_body();
        $action = (string)($data['action'] ?? '');
        if ($action === 'probe') {
            $target = (int)($data['id'] ?? 0);
            if ($target > 0) {
                $stmt = $pdo->prepare('SELECT * FROM monitored_databases WHERE id = ?');
                $stmt->execute([$target]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    json_error('База не найдена', 404);
                }
                dbmon_probe_one($pdo, $row);
            } else {
                dbmon_probe_all($pdo, false);
            }
            $rows = $pdo->query("SELECT * FROM monitored_databases ORDER BY FIELD(kind, 'panel', 'replica', 'custom'), name")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'databases' => dbmon_attach_latest($pdo, $rows)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'update') {
            dbmon_save_row($pdo, (int)($data['id'] ?? 0), $data);
            exit;
        }
        if ($action === 'upload_ssl_ca') {
            $pem = trim((string)($data['pem'] ?? ''));
            dbmon_upload_ssl_ca($pdo, (int)($data['id'] ?? 0), $pem);
            exit;
        }
        if ($action === 'remove_ssl_ca') {
            dbmon_remove_ssl_ca($pdo, (int)($data['id'] ?? 0));
            exit;
        }

        $name = trim((string)($data['name'] ?? ''));
        $engine = dbmon_normalize_engine((string)($data['engine'] ?? 'mysql'));
        $host = trim((string)($data['host'] ?? ''));
        $port = (int)($data['port'] ?? ($engine === 'postgres' ? 5432 : 3306));
        $dbName = trim((string)($data['db_name'] ?? ''));
        $user = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $notes = trim((string)($data['notes'] ?? ''));
        $nodeId = isset($data['node_id']) && $data['node_id'] !== '' && $data['node_id'] !== null ? (int)$data['node_id'] : null;
        if ($name === '' || $host === '' || $user === '') {
            json_error('Имя, хост и пользователь обязательны');
        }
        if ($port < 1 || $port > 65535) {
            json_error('Некорректный порт');
        }

        $ssl = !empty($data['ssl']) ? 1 : 0;
        $sslVerify = !empty($data['ssl_verify']) ? 1 : 0;

        $ins = $pdo->prepare('INSERT INTO monitored_databases (name, kind, engine, host, port, db_name, username, password, node_id, notes, enabled, status, ssl, ssl_verify)
            VALUES (?, "custom", ?, ?, ?, ?, ?, ?, ?, ?, 1, "unknown", ?, ?)');
        $ins->execute([$name, $engine, $host, $port, $dbName, $user, $password, $nodeId, $notes, $ssl, $sslVerify]);
        $newId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM monitored_databases WHERE id = ?');
        $stmt->execute([$newId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $probed = dbmon_probe_one($pdo, $row);
        echo json_encode(['ok' => true, 'database' => dbmon_public($probed, $probed['metrics'] ?? null)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        dbmon_save_row($pdo, $id, dbmon_read_body());
        exit;
    }

    if ($method === 'DELETE') {
        if ($id < 1) {
            json_error('ID обязателен');
        }
        $stmt = $pdo->prepare('SELECT kind FROM monitored_databases WHERE id = ?');
        $stmt->execute([$id]);
        $kind = $stmt->fetchColumn();
        if ($kind === false) {
            json_error('База не найдена', 404);
        }
        if (in_array($kind, ['panel', 'replica'], true)) {
            json_error('Встроенное подключение панели удаляется в настройках БД');
        }
        db_ssl_ca_remove(dbmon_ssl_ca_relative($id));
        $pdo->prepare('DELETE FROM monitored_databases WHERE id = ?')->execute([$id]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    json_error('Method not allowed', 405);
} catch (Throwable $e) {
    json_exception($e, true);
}
