<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/retention.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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

try {
    if ($method === 'GET') {
        $type = $_GET['type'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min((int)($_GET['per_page'] ?? logs_per_page($pdo)), 2000));
        $offset = ($page - 1) * $perPage;
        $q = trim((string)($_GET['q'] ?? ''));
        $level = $_GET['level'] ?? null;
        $nodeId = $_GET['node_id'] ?? null;

        if ($type === 'auth') {
            $eventType = $_GET['event_type'] ?? null;
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                username VARCHAR(100),
                ip_address VARCHAR(45),
                event_type VARCHAR(20) NOT NULL,
                success BOOLEAN DEFAULT FALSE,
                message TEXT,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_event_type (event_type),
                INDEX idx_timestamp (timestamp),
                INDEX idx_ip_address (ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $where = "";
            $params = [];
            if ($eventType) {
                $where .= " AND event_type = ?";
                $params[] = $eventType;
            }
            if ($q !== '') {
                $where .= " AND (username LIKE ? OR ip_address LIKE ? OR message LIKE ?)";
                $like = logs_like($q);
                array_push($params, $like, $like, $like);
            }
            [$logs, $total] = logs_fetch_page($pdo, "FROM auth_logs WHERE 1=1", $where, $params, $perPage, $offset);
            echo json_encode(['logs' => $logs, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
            exit;
        }

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
            echo json_encode(['logs' => $logs, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
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

        $stmtSystem = $pdo->prepare("INSERT INTO logs (node_id, level, message, timestamp, type) VALUES (?, ?, ?, ?, ?)");
        $stmtProcess = $pdo->prepare("INSERT INTO process_logs (node_id, pid, process, level, message, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtContainer = $pdo->prepare("INSERT INTO container_logs (node_id, container_id, level, message, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmtSshAuth = $pdo->prepare("INSERT INTO ssh_auth_logs (node_id, level, process, username, ip_address, port, success, message, raw_message, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $inserted = 0;

        foreach ($logs as $log) {
            $level = $log['level'] ?? 'info';
            $message = $log['message'] ?? '';
            $timestamp = $log['timestamp'] ?? date('Y-m-d H:i:s');
            $type = $log['type'] ?? 'system';
            if ($message === '' || $message === null) {
                continue;
            }

            if ($type === 'process') {
                $pid = isset($log['pid']) ? (int)$log['pid'] : null;
                $stmtProcess->bindValue(1, $nodeId, PDO::PARAM_INT);
                $stmtProcess->bindValue(2, $pid, $pid !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $stmtProcess->bindValue(3, $log['process'] ?? 'system', PDO::PARAM_STR);
                $stmtProcess->bindValue(4, $level, PDO::PARAM_STR);
                $stmtProcess->bindValue(5, $message, PDO::PARAM_STR);
                $stmtProcess->bindValue(6, $timestamp, PDO::PARAM_STR);
                $stmtProcess->execute();
            } elseif ($type === 'container' && !empty($log['container_id'])) {
                $stmtContainer->execute([$nodeId, $log['container_id'], $level, $message, $timestamp]);
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
                $stmtSshAuth->bindValue(1, $nodeId, PDO::PARAM_INT);
                $stmtSshAuth->bindValue(2, $level, PDO::PARAM_STR);
                $stmtSshAuth->bindValue(3, $log['process'] ?? 'sshd', PDO::PARAM_STR);
                $stmtSshAuth->bindValue(4, $log['username'] ?? null, $log['username'] ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmtSshAuth->bindValue(5, $log['ip'] ?? ($log['ip_address'] ?? null), PDO::PARAM_STR);
                $stmtSshAuth->bindValue(6, $port, $port !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $stmtSshAuth->bindValue(7, $successForDb, $successForDb === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmtSshAuth->bindValue(8, $message, PDO::PARAM_STR);
                $stmtSshAuth->bindValue(9, $log['raw_message'] ?? $message, PDO::PARAM_STR);
                $stmtSshAuth->bindValue(10, $timestamp, PDO::PARAM_STR);
                try {
                    $stmtSshAuth->execute();
                } catch (Exception $e) {
                    error_log("Error inserting SSH log: " . $e->getMessage());
                    continue;
                }
            } else {
                $stmtSystem->execute([$nodeId, $level, $message, $timestamp, $type]);
            }
            $inserted++;
        }

        retention_maybe_tick($pdo);
        echo json_encode(['message' => 'Logs saved', 'count' => $inserted]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
