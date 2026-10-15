<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/retention.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

logs_ensure_ssh_schema($pdo);

function validateNodeToken($pdo, $token) {
    if (!$token) return null;
    $stmt = $pdo->prepare("SELECT id, name FROM nodes WHERE node_token = ?");
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$isAuthorized = false;
$nodeInfo = null;

if (isset($_SESSION['user_id'])) {
    $isAuthorized = true;
} else {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        $nodeInfo = validateNodeToken($pdo, $matches[1]);
        $isAuthorized = (bool)$nodeInfo;
    }
}

if (!$isAuthorized) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

function logs_flush_batch(PDO $pdo, string $prefix, array $rows, int $batchSize = 50): int
{
    $total = 0;
    $chunks = array_chunk($rows, $batchSize);
    foreach ($chunks as $chunk) {
        $placeholders = [];
        $flat = [];
        foreach ($chunk as $row) {
            $rowPlaceholders = [];
            foreach ($row as $val) {
                $rowPlaceholders[] = '?';
                $flat[] = $val;
            }
            $placeholders[] = '(' . implode(',', $rowPlaceholders) . ')';
        }
        $sql = $prefix . implode(', ', $placeholders);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($flat);
        $total += count($chunk);
    }
    return $total;
}

function logs_per_page(PDO $pdo): int
{
    $default = 100;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'logs_per_page'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && $result['setting_value']) {
            return max(10, min((int)$result['setting_value'], 2000));
        }
    } catch (Exception $e) {
    }
    return $default;
}

function logs_fetch_page(PDO $pdo, string $fromSql, string $where, array $params, int $limit, int $offset): array
{
    $sql = "SELECT * {$fromSql} {$where} ORDER BY timestamp DESC, id DESC LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($sql);
    $i = 1;
    foreach ($params as $value) {
        $stmt->bindValue($i++, $value);
    }
    $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $countSql = "SELECT COUNT(*) {$fromSql} {$where}";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    return [$rows, $total];
}

function logs_like(string $q): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
}

/**
 * Приводит схему ssh_auth_logs к структурированному виду: добавляет колонку
 * event_type и один раз выполняет бэкфилл старых записей + чистку мусорных
 * баннеров journalctl. Идемпотентна.
 */
function logs_ensure_ssh_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        if (!retention_table_exists($pdo, 'ssh_auth_logs')) {
            return;
        }
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'ssh_auth_logs' AND column_name = 'event_type'");
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                WHERE table_name = 'ssh_auth_logs' AND column_name = 'event_type'");
        }
        $stmt->execute();
        $hasColumn = (int)$stmt->fetchColumn() > 0;

        if (!$hasColumn) {
            $pdo->exec("ALTER TABLE ssh_auth_logs ADD COLUMN event_type VARCHAR(32) NULL");
            try {
                $pdo->exec("ALTER TABLE ssh_auth_logs ADD INDEX idx_event_type (event_type)");
            } catch (Throwable $e) {
                // индекс мог уже существовать
            }
        }

        logs_backfill_ssh_event_type($pdo, $hasColumn);
    } catch (Throwable $e) {
        app_log_warning('[logs] ensure ssh schema: ' . $e->getMessage());
    }
}

function logs_backfill_ssh_event_type(PDO $pdo, bool $columnExisted): void
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ssh_event_type_backfilled'");
        $stmt->execute();
        if ($stmt->fetchColumn()) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    try {
        // Мусорные строки journalctl, попавшие в журнал как события.
        $pdo->exec("DELETE FROM ssh_auth_logs WHERE message LIKE '-- %' OR raw_message LIKE '-- %'");

        $pdo->exec("UPDATE ssh_auth_logs SET event_type = 'accepted' WHERE event_type IS NULL AND success = 1");
        $pdo->exec("UPDATE ssh_auth_logs SET event_type = 'max_attempts' WHERE event_type IS NULL AND message LIKE '%maximum authentication attempts%'");
        $pdo->exec("UPDATE ssh_auth_logs SET event_type = 'pam_failure' WHERE event_type IS NULL AND message LIKE '%authentication failure%'");
        $pdo->exec("UPDATE ssh_auth_logs SET event_type = 'invalid_user' WHERE event_type IS NULL AND success = 0 AND (message LIKE '%invalid user%' OR message LIKE '%Invalid user%' OR message LIKE '%user=invalid%')");
        $pdo->exec("UPDATE ssh_auth_logs SET event_type = 'failed' WHERE event_type IS NULL AND success = 0");
        $pdo->exec("UPDATE ssh_auth_logs SET event_type = 'disconnect' WHERE event_type IS NULL AND message LIKE '%disconnect%'");
        $pdo->exec("UPDATE ssh_auth_logs SET event_type = 'connection_closed' WHERE event_type IS NULL AND (message LIKE '%Connection closed%' OR message LIKE '%Connection reset%' OR message LIKE '%INFO connection%')");
        $pdo->exec("UPDATE ssh_auth_logs SET event_type = 'session' WHERE event_type IS NULL AND message LIKE '%session %for user%'");
        $pdo->exec("UPDATE ssh_auth_logs SET event_type = 'other' WHERE event_type IS NULL");

        try {
            $mark = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('ssh_event_type_backfilled', '1')");
            $mark->execute();
        } catch (Throwable $e) {
            // повторная попытка безвредна, ключ уникален
        }
    } catch (Throwable $e) {
        app_log_warning('[logs] ssh event_type backfill: ' . $e->getMessage());
    }
}

try {
    if ($method === 'GET') {
        $type = $_GET['type'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min((int)($_GET['per_page'] ?? logs_per_page($pdo)), 2000));
        $offset = ($page - 1) * $perPage;
        $q = trim((string)($_GET['q'] ?? ''));
        $level = $_GET['level'] ?? null;
        $nodeId = $_GET['node_id'] ?? null;

        if ($type === 'processes') {
            $pid = isset($_GET['pid']) ? (int)$_GET['pid'] : null;
            $where = "";
            $params = [];
            if ($nodeId) {
                $where .= " AND node_id = ?";
                $params[] = $nodeId;
            }
            if ($pid) {
                $where .= " AND pid = ?";
                $params[] = $pid;
            }
            if ($q !== '') {
                $where .= " AND message LIKE ?";
                $params[] = logs_like($q);
            }
            [$logs, $total] = logs_fetch_page($pdo, "FROM process_logs WHERE 1=1", $where, $params, $perPage, $offset);
            echo json_encode(['logs' => $logs, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
            exit;
        }

        if ($type === 'ssh_auth') {
            $result = trim((string)($_GET['result'] ?? ''));
            $eventType = trim((string)($_GET['event_type'] ?? ''));
            $days = (int)($_GET['days'] ?? 0);

            $where = "";
            $params = [];
            if ($nodeId) {
                $where .= " AND node_id = ?";
                $params[] = $nodeId;
            }
            if ($level) {
                $where .= " AND level = ?";
                $params[] = $level;
            }
            if ($result === 'ok') {
                $where .= " AND success = 1";
            } elseif ($result === 'fail') {
                $where .= " AND success = 0";
            } elseif ($result === 'info') {
                $where .= " AND success IS NULL";
            }
            if ($eventType !== '') {
                $where .= " AND event_type = ?";
                $params[] = $eventType;
            }
            if ($days > 0) {
                $where .= " AND timestamp >= DATE_SUB(NOW(), INTERVAL ? DAY)";
                $params[] = $days;
            }
            if ($q !== '') {
                $where .= " AND (username LIKE ? OR ip_address LIKE ? OR message LIKE ? OR process LIKE ?)";
                $like = logs_like($q);
                array_push($params, $like, $like, $like, $like);
            }
            [$logs, $total] = logs_fetch_page($pdo, "FROM ssh_auth_logs WHERE 1=1", $where, $params, $perPage, $offset);
            foreach ($logs as &$row) {
                if (array_key_exists('success', $row) && $row['success'] !== null) {
                    $row['success'] = ((int)$row['success']) === 1;
                }
            }
            unset($row);

            // Сводка и топ-атакующие считаем по ноде/периоду/поиску, без учёта
            // фильтров result/event_type — чтобы карточки не «скакали».
            $scopeWhere = " WHERE 1=1";
            $scopeParams = [];
            if ($nodeId) {
                $scopeWhere .= " AND node_id = ?";
                $scopeParams[] = $nodeId;
            }
            if ($days > 0) {
                $scopeWhere .= " AND timestamp >= DATE_SUB(NOW(), INTERVAL ? DAY)";
                $scopeParams[] = $days;
            }
            if ($q !== '') {
                $scopeWhere .= " AND (username LIKE ? OR ip_address LIKE ? OR message LIKE ? OR process LIKE ?)";
                $like = logs_like($q);
                array_push($scopeParams, $like, $like, $like, $like);
            }
            $stats = ['total' => 0, 'ok' => 0, 'failed' => 0, 'info' => 0, 'unique_ips' => 0];
            $eventTypes = [];
            $topIps = [];
            try {
                $statsStmt = $pdo->prepare("SELECT
                    COUNT(*) AS total,
                    SUM(success = 1) AS ok,
                    SUM(success = 0) AS failed,
                    SUM(success IS NULL) AS info,
                    COUNT(DISTINCT ip_address) AS unique_ips
                    FROM ssh_auth_logs {$scopeWhere}");
                $statsStmt->execute($scopeParams);
                $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: $stats;
                $stats = [
                    'total' => (int)($stats['total'] ?? 0),
                    'ok' => (int)($stats['ok'] ?? 0),
                    'failed' => (int)($stats['failed'] ?? 0),
                    'info' => (int)($stats['info'] ?? 0),
                    'unique_ips' => (int)($stats['unique_ips'] ?? 0),
                ];

                $typeStmt = $pdo->prepare("SELECT event_type, COUNT(*) AS count
                    FROM ssh_auth_logs {$scopeWhere}
                    GROUP BY event_type ORDER BY count DESC");
                $typeStmt->execute($scopeParams);
                $eventTypes = $typeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $topStmt = $pdo->prepare("SELECT ip_address, COUNT(*) AS fails, MAX(timestamp) AS last
                    FROM ssh_auth_logs {$scopeWhere}
                    AND success = 0 AND ip_address IS NOT NULL
                    GROUP BY ip_address HAVING fails >= 3 ORDER BY fails DESC LIMIT 10");
                $topStmt->execute($scopeParams);
                $topIps = $topStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                app_log_warning('[logs] ssh stats: ' . $e->getMessage());
            }

            echo json_encode([
                'logs' => $logs,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'stats' => $stats,
                'event_types' => $eventTypes,
                'top_ips' => $topIps,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $where = "";
        $params = [];
        if ($nodeId) {
            $where .= " AND node_id = ?";
            $params[] = $nodeId;
        }
        if ($level) {
            $where .= " AND level = ?";
            $params[] = $level;
        }
        if ($q !== '') {
            $where .= " AND message LIKE ?";
            $params[] = logs_like($q);
        }
        [$logs, $total] = logs_fetch_page($pdo, "FROM logs WHERE 1=1", $where, $params, $perPage, $offset);
        echo json_encode(['logs' => $logs, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
        exit;
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if ($data === null && $raw !== '') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }

        $nodeId = $nodeInfo['id'] ?? (is_array($data) ? ($data['node_id'] ?? null) : null);
        if (!$nodeId) {
            http_response_code(400);
            echo json_encode(['error' => 'node_id is required']);
            exit;
        }

        $logs = null;
        if (is_array($data) && isset($data[0]) && isset($data[0]['message'])) {
            $logs = $data;
        } elseif (isset($data['logs']) && is_array($data['logs'])) {
            $logs = $data['logs'];
        } elseif (isset($data['message'])) {
            $logs = [$data];
        }

        if (!$logs) {
            echo json_encode(['message' => 'No logs reported', 'count' => 0]);
            exit;
        }

        $inserted = 0;
        $BATCH_SIZE = 50;

        // ── Системные логи — batch INSERT ──
        $systemBatch = [];
        $processBatch = [];
        $containerBatch = [];
        $sshBatch = [];

        foreach ($logs as $log) {
            $level = $log['level'] ?? 'info';
            $message = $log['message'] ?? '';
            $timestamp = $log['timestamp'] ?? date('Y-m-d H:i:s');
            $type = $log['type'] ?? 'system';
            if ($message === '' || $message === null) {
                continue;
            }

            if ($type === 'process') {
                $processBatch[] = [
                    $nodeId,
                    isset($log['pid']) ? (int)$log['pid'] : null,
                    $log['process'] ?? 'system',
                    $level,
                    mb_substr($message, 0, 2000),
                    $timestamp,
                ];
            } elseif ($type === 'container' && !empty($log['container_id'])) {
                $containerBatch[] = [$nodeId, $log['container_id'], $level, mb_substr($message, 0, 2000), $timestamp];
            } elseif ($type === 'auth_ssh') {
                $successForDb = null;
                if (array_key_exists('success', $log) && $log['success'] !== null && $log['success'] !== '') {
                    $successForDb = !empty($log['success']) && $log['success'] !== 'false' && $log['success'] !== '0' ? 1 : 0;
                    if ($log['success'] === false || $log['success'] === 0 || $log['success'] === '0') {
                        $successForDb = 0;
                    }
                    if ($log['success'] === true || $log['success'] === 1 || $log['success'] === '1') {
                        $successForDb = 1;
                    }
                }
                $port = isset($log['port']) ? (int)$log['port'] : null;
                $rawMsg = mb_substr($log['raw_message'] ?? $message, 0, 500);
                $allowedTypes = ['accepted', 'failed', 'invalid_user', 'max_attempts', 'pam_failure', 'disconnect', 'connection_closed', 'session', 'other'];
                $eventType = (string)($log['event_type'] ?? '');
                if (!in_array($eventType, $allowedTypes, true)) {
                    $eventType = ssh_event_type_from($successForDb, $message);
                }
                $sshBatch[] = [
                    $nodeId,
                    $level,
                    $log['process'] ?? 'sshd',
                    $eventType,
                    $log['username'] ?? null,
                    $log['ip'] ?? ($log['ip_address'] ?? null),
                    $port,
                    $successForDb,
                    mb_substr($message, 0, 2000),
                    $rawMsg,
                    $timestamp,
                ];
            } else {
                $systemBatch[] = [$nodeId, $level, mb_substr($message, 0, 2000), $timestamp, $type];
            }
        }

        // Flush batches — each wrapped so one failure doesn't skip the rest
        foreach ([
            ['logs (node_id, level, message, timestamp, type)', $systemBatch],
            ['process_logs (node_id, pid, process, level, message, timestamp)', $processBatch],
            ['container_logs (node_id, container_id, level, message, timestamp)', $containerBatch],
            ['ssh_auth_logs (node_id, level, process, event_type, username, ip_address, port, success, message, raw_message, timestamp)', $sshBatch],
        ] as [$table, $batch]) {
            if (!$batch) continue;
            try {
                $inserted += logs_flush_batch($pdo, "INSERT INTO {$table} VALUES ", $batch, $BATCH_SIZE);
            } catch (Exception $e) {
                app_log_warning("[logs] batch insert into {$table} failed: " . $e->getMessage());
            }
        }

        retention_maybe_tick($pdo);
        echo json_encode(['message' => 'Logs saved', 'count' => $inserted]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Exception $e) {
    app_log_error('logs.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
