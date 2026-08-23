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
        'editable' => true,
    ];
    if ($ping) {
        $out['ping'] = [
            'primary' => db_ping_endpoint($primary, 3),
            'replica' => $enabled ? db_ping_endpoint($replica, 3) : ['ok' => false, 'ms' => 0, 'error' => 'Резерв выключен'],
        ];
        $out['editable'] = db_connection_editable([
            'configured' => true,
            'replica_enabled' => $enabled,
            'primary' => $out['ping']['primary'],
            'replica' => $out['ping']['replica'],
        ]);
    }
    return $out;
}

function db_ha_require_editable(): void
{
    $payload = db_ha_status_payload(true);
    if (empty($payload['editable'])) {
        json_error('Настройки недоступны: нет соединения ни с основной, ни с резервной базой', 503);
    }
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
        db_ha_require_editable();
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
                'ssl' => !empty($replicaIn['ssl']),
                'ssl_verify' => !empty($replicaIn['ssl_verify']),
                'ssl_ca' => (string)($replicaIn['ssl_ca'] ?? db_config_load()['replica']['ssl_ca'] ?? ''),
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

    if ($action === 'upload_ssl_ca') {
        db_ha_require_editable();
        $pem = trim((string)($data['pem'] ?? ''));
        if ($pem === '' && !empty($data['pem_base64'])) {
            $decoded = base64_decode((string)$data['pem_base64'], true);
            $pem = is_string($decoded) ? trim($decoded) : '';
        }
        if (!db_ssl_validate_pem($pem)) {
            json_error('Некорректный PEM: нужен текст с -----BEGIN CERTIFICATE-----');
        }
        $rel = db_ssl_ca_save($pem);
        $cfg = db_config_load();
        $replica = is_array($cfg['replica'] ?? null) ? $cfg['replica'] : [];
        db_config_save(array_merge($cfg, [
            'replica' => array_merge($replica, ['ssl_ca' => $rel]),
        ]));
        getDbConnection(true);
        echo json_encode(array_merge(db_ha_status_payload(true), [
            'ssl_ca' => db_ssl_ca_info($rel),
            'message' => 'CA-сертификат сохранён',
        ]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'remove_ssl_ca') {
        db_ha_require_editable();
        db_ssl_ca_remove();
        $cfg = db_config_load();
        $replica = is_array($cfg['replica'] ?? null) ? $cfg['replica'] : [];
        db_config_save(array_merge($cfg, [
            'replica' => array_merge($replica, ['ssl_ca' => '']),
        ]));
        getDbConnection(true);
        echo json_encode(array_merge(db_ha_status_payload(true), [
            'ssl_ca' => db_ssl_ca_info(''),
            'message' => 'CA-сертификат удалён',
        ]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'prefer_primary') {
        db_ha_require_editable();
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

    // Полное копирование одним запросом отключено — ловит HTTP 504 у nginx.
    if ($action === 'sync') {
        json_error('Используйте пошаговую синхронизацию из интерфейса (короткие порции)', 400);
    }

    if ($action === 'sync_prepare') {
        db_ha_require_editable();
        @set_time_limit(60);
        $direction = (string)($data['direction'] ?? 'to_replica');
        try {
            $eps = db_sync_endpoints($direction);
            $src = db_try_connect($eps['src'], 8);
            $tables = db_list_base_tables($src);
        } catch (Throwable $e) {
            json_error($e->getMessage(), 503);
        }
        echo json_encode([
            'ok' => true,
            'direction' => $direction,
            'tables' => $tables,
            'table_count' => count($tables),
            'source_label' => $eps['source_label'],
            'target_label' => $eps['target_label'],
            'source_name' => (string)($eps['src']['name'] ?? ''),
            'target_name' => (string)($eps['dst']['name'] ?? ''),
            'chunk_limit' => 200,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Только схема таблицы (отдельно от данных — меньше риск 504).
    if ($action === 'sync_table_schema') {
        @set_time_limit(45);
        header('X-Accel-Buffering: no');
        $direction = (string)($data['direction'] ?? 'to_replica');
        $table = trim((string)($data['table'] ?? ''));
        if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            json_error('Некорректное имя таблицы');
        }
        try {
            $eps = db_sync_endpoints($direction);
            $src = db_try_connect($eps['src'], 10);
            $dst = db_try_connect($eps['dst'], 10);
            $dst->exec('SET FOREIGN_KEY_CHECKS=0');
            $dst->exec('SET UNIQUE_CHECKS=0');
            db_copy_table_schema($src, $dst, $table);
        } catch (Throwable $e) {
            json_error('Схема: ' . $e->getMessage(), 503);
        }
        echo json_encode(['ok' => true, 'table' => $table, 'schema' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Короткие чанки данных (~400 строк / ≤8 с) — обход nginx 504 на SkySQL/SSL.
    if ($action === 'sync_table') {
        @set_time_limit(45);
        @ini_set('max_execution_time', '45');
        header('X-Accel-Buffering: no');

        $direction = (string)($data['direction'] ?? 'to_replica');
        $table = trim((string)($data['table'] ?? ''));
        $index = max(0, (int)($data['index'] ?? 0));
        $total = max(1, (int)($data['total'] ?? 1));
        $offset = max(0, (int)($data['offset'] ?? 0));
        $cursor = array_key_exists('cursor', $data) && $data['cursor'] !== null && $data['cursor'] !== ''
            ? (string)$data['cursor']
            : null;
        $limit = max(25, min(300, (int)($data['limit'] ?? 200)));

        if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            json_error('Некорректное имя таблицы');
        }

        try {
            $eps = db_sync_endpoints($direction);
            $src = db_try_connect($eps['src'], 10);
            $dst = db_try_connect($eps['dst'], 10);
        } catch (Throwable $e) {
            json_error('Нет соединения: ' . $e->getMessage(), 503);
        }

        try {
            $dst->exec('SET FOREIGN_KEY_CHECKS=0');
            $dst->exec('SET UNIQUE_CHECKS=0');

            // recreate в этом экшене больше не делаем — только sync_table_schema
            $chunk = db_copy_table_chunk($src, $dst, $table, $offset, $cursor, $limit, 5.0);
            $tableDone = !empty($chunk['table_done']);
            $allDone = $tableDone && $index >= $total - 1;

            if ($allDone) {
                db_sync_restore_checks($dst);
                getDbConnection(true);
            }

            echo json_encode([
                'ok' => true,
                'table' => $table,
                'rows' => (int)$chunk['rows'],
                'offset' => $offset,
                'next_offset' => (int)$chunk['next_offset'],
                'next_cursor' => $chunk['next_cursor'],
                'table_done' => $tableDone,
                'index' => $index,
                'total' => $total,
                'done' => $allDone,
                'status' => $allDone ? db_ha_status_payload(true) : null,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            try {
                db_sync_restore_checks($dst);
            } catch (Throwable $ignored) {
            }
            throw $e;
        }
        exit;
    }

    if ($action === 'sync_abort') {
        $direction = (string)($data['direction'] ?? 'to_replica');
        try {
            $eps = db_sync_endpoints($direction);
            $dst = db_try_connect($eps['dst'], 8);
            db_sync_restore_checks($dst);
        } catch (Throwable $e) {
            json_error($e->getMessage(), 503);
        }
        echo json_encode(['ok' => true, 'message' => 'Синхронизация прервана'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    json_error('Unknown action');
} catch (Throwable $e) {
    json_exception($e, true);
}
