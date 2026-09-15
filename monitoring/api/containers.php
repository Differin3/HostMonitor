<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDbConnection();

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
    require_csrf();
} else {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        $token = $matches[1];
        $nodeInfo = validateNodeToken($pdo, $token);
        if ($nodeInfo) {
            $isAuthorized = true;
        }
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

function containers_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (function_exists('schema_marker_fresh') && schema_marker_fresh('containers')) {
        $done = true;
        return;
    }
    if (function_exists('schema_short_lock')) {
        schema_short_lock($pdo);
    }

    $alters = [
        "ALTER TABLE containers ADD COLUMN networks TEXT NULL",
        "ALTER TABLE containers ADD COLUMN ports TEXT NULL",
        "ALTER TABLE containers ADD COLUMN ipv4 VARCHAR(45) NULL",
        "ALTER TABLE containers ADD COLUMN network_mode VARCHAR(128) NULL",
        "ALTER TABLE containers ADD COLUMN raw_status VARCHAR(255) NULL",
    ];
    foreach ($alters as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Exception $e) {
            // column already exists
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS docker_networks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        node_id INT NOT NULL,
        network_id VARCHAR(64) NOT NULL,
        name VARCHAR(255),
        driver VARCHAR(64),
        scope VARCHAR(32),
        subnet VARCHAR(64),
        gateway VARCHAR(45),
        containers TEXT,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_node_net (node_id, network_id),
        INDEX idx_node_id (node_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (function_exists('schema_marker_touch')) {
        schema_marker_touch('containers');
    }
    $done = true;
}

function containers_decode_json($value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value) && $value !== '') {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

function containers_format_row(array $row): array
{
    $row['networks'] = containers_decode_json($row['networks'] ?? null);
    $row['ports'] = containers_decode_json($row['ports'] ?? null);
    $row['cpu_percent'] = isset($row['cpu_percent']) ? (float)$row['cpu_percent'] : 0;
    $row['memory_percent'] = isset($row['memory_percent']) ? (float)$row['memory_percent'] : 0;
    return $row;
}

function containers_valid_id($id): bool
{
    return is_string($id) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,254}$/', $id);
}

try {
    containers_ensure_schema($pdo);

    if ($method === 'POST' && isset($_GET['action'], $_GET['container_id'], $_GET['node_id'])) {
        handleContainerAction($pdo);
        exit;
    }

    switch ($method) {
        case 'GET':
            handleGet($pdo);
            break;
        case 'POST':
            handlePost($pdo);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log('containers.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

function handleGet($pdo) {
    $nodeId = $_GET['node_id'] ?? null;
    $containerId = $_GET['container_id'] ?? null;
    $logs = isset($_GET['logs']) && $_GET['logs'] == '1';
    $network = isset($_GET['network']) && $_GET['network'] == '1';
    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 10000)) : 200;

    if ($logs && $containerId && $nodeId) {
        echo json_encode(['logs' => getContainerLogs($pdo, $nodeId, $containerId, $limit)]);
        return;
    }

    if ($network && $nodeId) {
        echo json_encode(buildNetworkView($pdo, (int)$nodeId));
        return;
    }

    if ($nodeId) {
        $stmt = $pdo->prepare("SELECT * FROM containers WHERE node_id = ? ORDER BY name");
        $stmt->execute([$nodeId]);
    } else {
        $stmt = $pdo->query("SELECT * FROM containers ORDER BY timestamp DESC");
    }
    $containers = array_map('containers_format_row', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    echo json_encode(['containers' => $containers]);
}

function buildNetworkView(PDO $pdo, int $nodeId): array
{
    $stmt = $pdo->prepare("SELECT * FROM containers WHERE node_id = ? ORDER BY name");
    $stmt->execute([$nodeId]);
    $containers = array_map('containers_format_row', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

    $netStmt = $pdo->prepare("SELECT * FROM docker_networks WHERE node_id = ? ORDER BY name");
    $netStmt->execute([$nodeId]);
    $storedNets = $netStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $networks = [];
    $attachments = [];

    if ($storedNets) {
        foreach ($storedNets as $net) {
            $members = containers_decode_json($net['containers'] ?? null);
            $networks[] = [
                'name' => $net['name'],
                'driver' => $net['driver'] ?: '—',
                'scope' => $net['scope'] ?: '',
                'subnet' => $net['subnet'] ?: '—',
                'gateway' => $net['gateway'] ?: '—',
                'containers' => count($members),
                'members' => $members,
            ];
            foreach ($members as $member) {
                $attachments[] = [
                    'container' => $member['name'] ?? ($member['id'] ?? ''),
                    'network' => $net['name'],
                    'ipv4' => $member['ipv4'] ?? '',
                ];
            }
        }
    } else {
        $byName = [];
        foreach ($containers as $c) {
            foreach ($c['networks'] as $net) {
                $name = is_string($net) ? $net : ($net['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (!isset($byName[$name])) {
                    $byName[$name] = [
                        'name' => $name,
                        'driver' => '—',
                        'scope' => '',
                        'subnet' => '—',
                        'gateway' => '—',
                        'containers' => 0,
                        'members' => [],
                    ];
                }
                $ip = is_array($net) ? ($net['ipv4'] ?? ($c['ipv4'] ?? '')) : ($c['ipv4'] ?? '');
                $byName[$name]['members'][] = [
                    'name' => $c['name'],
                    'ipv4' => $ip,
                    'id' => $c['container_id'],
                ];
                $attachments[] = [
                    'container' => $c['name'],
                    'network' => $name,
                    'ipv4' => $ip,
                ];
            }
        }
        foreach ($byName as &$net) {
            $net['containers'] = count($net['members']);
            $networks[] = $net;
        }
        unset($net);
    }

    $ports = [];
    foreach ($containers as $c) {
        foreach ($c['ports'] as $p) {
            $host = $p['host'] ?? $p['host_port'] ?? null;
            $cport = $p['container'] ?? $p['container_port'] ?? null;
            if (!$host && !$cport) {
                continue;
            }
            $ports[] = [
                'container' => $c['name'],
                'host' => $host ?: '—',
                'host_ip' => $p['host_ip'] ?? '',
                'container_port' => $cport ?: '—',
                'protocol' => strtolower($p['protocol'] ?? 'tcp'),
            ];
        }
    }

    return [
        'networks' => $networks,
        'attachments' => $attachments,
        'connections' => [],
        'ports' => $ports,
    ];
}

function getContainerLogs($pdo, $nodeId, $containerId, $limit = 200) {
    $sql = "SELECT level, message, timestamp FROM (
                SELECT id, level, message, timestamp
                FROM container_logs
                WHERE node_id = ? AND (container_id = ? OR container_id LIKE ?)
                ORDER BY timestamp DESC, id DESC
                LIMIT ?
            ) t ORDER BY timestamp ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $like = substr((string)$containerId, 0, 12) . '%';
    $stmt->bindValue(1, $nodeId, PDO::PARAM_INT);
    $stmt->bindValue(2, $containerId);
    $stmt->bindValue(3, $like);
    $stmt->bindValue(4, (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $logs = [];
    foreach ($rows as $row) {
        $logs[] = [
            'timestamp' => $row['timestamp'],
            'level' => $row['level'] ?: 'info',
            'message' => $row['message'],
        ];
    }
    return $logs;
}

function handlePost($pdo) {
    global $nodeInfo;

    $raw = file_get_contents('php://input');
    $data = $raw !== '' ? json_decode($raw, true) : null;

    if ($data === null && $raw !== '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }

    $nodeId = $nodeInfo['id'] ?? (is_array($data) ? ($data['node_id'] ?? null) : null);
    if (!$nodeId) {
        http_response_code(400);
        echo json_encode(['error' => 'node_id is required']);
        return;
    }

    $containers = null;
    $networks = null;
    if (is_array($data) && array_key_exists('networks', $data)) {
        $networks = is_array($data['networks']) ? $data['networks'] : [];
    }
    if (is_array($data) && isset($data[0]) && isset($data[0]['container_id'])) {
        $containers = $data;
    } elseif (isset($data['containers']) && is_array($data['containers'])) {
        $containers = $data['containers'];
    }

    if ($containers !== null) {
        replaceContainerSnapshot($pdo, (int)$nodeId, $containers);
        if ($networks !== null) {
            replaceNetworkSnapshot($pdo, (int)$nodeId, $networks);
        }
        http_response_code(201);
        echo json_encode([
            'message' => 'Containers updated',
            'count' => count($containers),
            'networks' => $networks !== null ? count($networks) : null,
        ]);
        return;
    }

    if ($networks !== null) {
        replaceNetworkSnapshot($pdo, (int)$nodeId, $networks);
        echo json_encode(['message' => 'Networks updated', 'count' => count($networks)]);
        return;
    }

    $containerId = $data['container_id'] ?? null;
    $name = $data['name'] ?? null;
    $image = $data['image'] ?? '';
    $status = $data['status'] ?? 'stopped';
    $cpuPercent = $data['cpu_percent'] ?? 0;
    $memoryPercent = $data['memory_percent'] ?? 0;

    if (!$containerId || !$name) {
        http_response_code(400);
        echo json_encode(['error' => 'container_id and name are required']);
        return;
    }

    $checkStmt = $pdo->prepare("SELECT id FROM containers WHERE node_id = ? AND container_id = ?");
    $checkStmt->execute([$nodeId, $containerId]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        $updateStmt = $pdo->prepare("UPDATE containers SET name = ?, image = ?, status = ?, cpu_percent = ?, memory_percent = ? WHERE id = ?");
        $updateStmt->execute([$name, $image, $status, $cpuPercent, $memoryPercent, $existing['id']]);
    } else {
        $insertStmt = $pdo->prepare("INSERT INTO containers (node_id, container_id, name, image, status, cpu_percent, memory_percent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->execute([$nodeId, $containerId, $name, $image, $status, $cpuPercent, $memoryPercent]);
    }

    http_response_code(201);
    echo json_encode(['message' => 'Container updated']);
}

function replaceContainerSnapshot(PDO $pdo, int $nodeId, array $containers): void
{
    $deleteStmt = $pdo->prepare("DELETE FROM containers WHERE node_id = ?");
    $deleteStmt->execute([$nodeId]);
    if (!$containers) {
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO containers
            (node_id, container_id, name, image, status, cpu_percent, memory_percent, networks, ports, ipv4, network_mode, raw_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($containers as $container) {
        $networks = $container['networks'] ?? [];
        $ports = $container['ports'] ?? [];
        $stmt->execute([
            $nodeId,
            $container['container_id'] ?? '',
            $container['name'] ?? 'unknown',
            $container['image'] ?? '',
            $container['status'] ?? 'stopped',
            $container['cpu_percent'] ?? 0,
            $container['memory_percent'] ?? 0,
            json_encode(is_array($networks) ? $networks : [], JSON_UNESCAPED_UNICODE),
            json_encode(is_array($ports) ? $ports : [], JSON_UNESCAPED_UNICODE),
            $container['ipv4'] ?? '',
            $container['network_mode'] ?? '',
            $container['raw_status'] ?? '',
        ]);
    }
}

function replaceNetworkSnapshot(PDO $pdo, int $nodeId, array $networks): void
{
    $deleteStmt = $pdo->prepare("DELETE FROM docker_networks WHERE node_id = ?");
    $deleteStmt->execute([$nodeId]);
    if (!$networks) {
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO docker_networks
            (node_id, network_id, name, driver, scope, subnet, gateway, containers)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($networks as $net) {
        $members = $net['containers'] ?? $net['members'] ?? [];
        if (!is_array($members)) {
            $members = [];
        }
        $networkId = $net['network_id'] ?? $net['id'] ?? ($net['name'] ?? '');
        if ($networkId === '') {
            continue;
        }
        $stmt->execute([
            $nodeId,
            substr((string)$networkId, 0, 64),
            $net['name'] ?? '',
            $net['driver'] ?? '',
            $net['scope'] ?? '',
            $net['subnet'] ?? '',
            $net['gateway'] ?? '',
            json_encode($members, JSON_UNESCAPED_UNICODE),
        ]);
    }
}

function handleContainerAction($pdo) {
    $nodeId = $_GET['node_id'];
    $containerId = $_GET['container_id'];
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? ($_GET['action'] ?? '');

    if (!in_array($action, ['start', 'stop', 'restart', 'logs'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use start, stop, restart, or logs']);
        return;
    }
    if (!containers_valid_id((string)$containerId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid container_id']);
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM containers WHERE node_id = ? AND (container_id = ? OR container_id LIKE ? OR ? LIKE CONCAT(LEFT(container_id, 12), '%')) LIMIT 1"
    );
    $like = substr((string)$containerId, 0, 12) . '%';
    $stmt->execute([$nodeId, $containerId, $like, $containerId]);
    $container = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$container) {
        http_response_code(404);
        echo json_encode(['error' => 'Container not found']);
        return;
    }
    // Используем полный id из БД для docker-logs
    $containerId = $container['container_id'];

    $nodeStmt = $pdo->prepare("SELECT id FROM nodes WHERE id = ?");
    $nodeStmt->execute([$nodeId]);
    if (!$nodeStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Node not found']);
        return;
    }

    if ($action === 'logs') {
        // Сбрасываем старые логи контейнера, чтобы UI не показывал устаревшие строки
        try {
            $del = $pdo->prepare("DELETE FROM container_logs WHERE node_id = ? AND (container_id = ? OR container_id LIKE ?)");
            $del->execute([$nodeId, $containerId, substr((string)$containerId, 0, 12) . '%']);
        } catch (Exception $e) {
            // ignore
        }
        $command = "docker-logs {$containerId} 200";
        $stmt = $pdo->prepare(
            "UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW(), command_result = NULL WHERE id = ?"
        );
        $stmt->execute([$command, $nodeId]);
        echo json_encode([
            'success' => true,
            'queued' => true,
            'logs_url' => "/api/containers.php?node_id={$nodeId}&container_id={$containerId}&logs=1",
            'container_id' => $containerId,
        ]);
        return;
    }

    $command = "docker {$action} {$containerId}";
    $stmt = $pdo->prepare("UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW() WHERE id = ?");
    $stmt->execute([$command, $nodeId]);

    echo json_encode([
        'success' => true,
        'message' => "Команда «{$action}» поставлена в очередь",
        'node_id' => $nodeId,
        'container_id' => $containerId,
        'action' => $action,
    ]);
}
