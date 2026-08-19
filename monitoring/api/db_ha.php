<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    json_error('Unauthorized', 401);
}
if (($_SESSION['role'] ?? 'admin') !== 'admin') {
    json_error('Forbidden', 403);
}

function db_ha_status_payload(bool $ping): array
{
    $cfg = db_config_load();
    $state = db_ha_state_load();
    $primary = db_endpoint($cfg, 'primary');
    $replica = db_endpoint($cfg, 'replica');
    $enabled = db_replica_enabled($cfg);
    $out = [
        'ok' => true,
        'active_role' => db_active_role(),
        'replica_enabled' => $enabled,
        'replica_failback' => !empty($cfg['replica_failback']),
        'primary' => db_public_endpoint($primary),
        'replica' => db_public_endpoint($replica),
        'state' => [
            'role' => $state['role'],
            'reason' => $state['reason'],
            'since' => $state['since'],
            'last_primary_try' => $state['last_primary_try'],
        ],
        'ping' => null,
    ];
    if ($ping) {
        $out['ping'] = [
            'primary' => db_ping_endpoint($primary, 3),
            'replica' => $enabled ? db_ping_endpoint($replica, 3) : ['ok' => false, 'ms' => 0, 'error' => 'Резерв выключен'],
        ];
    }
    return $out;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        echo json_encode(db_ha_status_payload(true), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        json_error('Method not allowed', 405);
    }

    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) {
        json_error('Invalid JSON', 400);
    }
    $action = (string)($data['action'] ?? '');

    if ($action === 'save') {
        $host = trim((string)($data['host'] ?? ''));
        $port = trim((string)($data['port'] ?? '3306'));
        $name = trim((string)($data['name'] ?? ''));
        $user = trim((string)($data['user'] ?? ''));
        $replicaIn = is_array($data['replica'] ?? null) ? $data['replica'] : [];
        $replicaEnabled = !empty($data['replica_enabled']);
        $replicaHost = trim((string)($replicaIn['host'] ?? ''));
        $replicaPort = trim((string)($replicaIn['port'] ?? '3306'));
        $replicaName = trim((string)($replicaIn['name'] ?? ''));
        $replicaUser = trim((string)($replicaIn['user'] ?? ''));

        if ($host === '' || $name === '' || $user === '') {
            json_error('Хост, имя базы и пользователь основной MySQL обязательны');
        }
        db_ident($name);
        if (!preg_match('/^\d+$/', $port) || (int)$port < 1 || (int)$port > 65535) {
            json_error('Некорректный порт основной MySQL');
        }
        if ($replicaEnabled) {
            if ($replicaHost === '') {
                json_error('Укажите хост резервной MySQL или выключите резерв');
            }
            $checkName = $replicaName !== '' ? $replicaName : $name;
            db_ident($checkName);
            if (!preg_match('/^\d+$/', $replicaPort) || (int)$replicaPort < 1 || (int)$replicaPort > 65535) {
                json_error('Некорректный порт резервной MySQL');
            }
        }

        db_config_save([
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'password' => (string)($data['password'] ?? ''),
            'replica_enabled' => $replicaEnabled,
            'replica_failback' => !isset($data['replica_failback']) || !empty($data['replica_failback']),
            'replica' => [
                'host' => $replicaHost,
                'port' => $replicaPort ?: '3306',
                'name' => $replicaName,
                'user' => $replicaUser,
                'password' => (string)($replicaIn['password'] ?? ''),
            ],
        ]);
        getDbConnection(true);
        $created = false;
        if ($replicaEnabled) {
            try {
                db_ensure_database(db_endpoint(db_config_load(), 'replica'));
                $created = true;
            } catch (Throwable $e) {
                error_log('Replica database create skipped: ' . $e->getMessage());
            }
        }
        echo json_encode(array_merge(db_ha_status_payload(true), [
            'saved' => true,
            'replica_created' => $created,
        ]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'prefer_primary') {
        $cfg = db_config_load();
        try {
            db_try_connect(db_endpoint($cfg, 'primary'), 3);
        } catch (Throwable $e) {
            json_error('Основная база недоступна: ' . $e->getMessage(), 503);
        }
        db_ha_state_save('primary', 'manual');
        getDbConnection(true);
        echo json_encode(db_ha_status_payload(true), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'sync') {
        $direction = (string)($data['direction'] ?? 'to_replica');
        if ($direction !== 'to_replica' && $direction !== 'to_primary') {
            json_error('direction: to_replica или to_primary');
        }
        $cfg = db_config_load();
        if (!db_replica_enabled($cfg)) {
            json_error('Сначала включите резервную базу');
        }
        $primary = db_endpoint($cfg, 'primary');
        $replica = db_endpoint($cfg, 'replica');
        if (db_endpoint_same($primary, $replica)) {
            json_error('Основная и резервная база совпадают — копировать некуда');
        }
        $srcEp = $direction === 'to_replica' ? $primary : $replica;
        $dstEp = $direction === 'to_replica' ? $replica : $primary;
        try {
            $src = db_try_connect($srcEp, 8);
            $dst = db_try_connect($dstEp, 8);
        } catch (Throwable $e) {
            json_error('Нет соединения для синхронизации: ' . $e->getMessage(), 503);
        }
        $stats = db_copy_database($src, $dst);
        getDbConnection(true);
        echo json_encode([
            'ok' => true,
            'direction' => $direction,
            'copied' => $stats,
            'status' => db_ha_status_payload(true),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    json_error('Unknown action');
} catch (Throwable $e) {
    json_exception($e, true);
}
