<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/retention.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDbConnection();

$nodeInfo = null;
if ($method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
} else {
    $auth = require_api_auth($pdo);
    $nodeInfo = $auth['node'];
}

function metrics_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (function_exists('schema_marker_fresh') && schema_marker_fresh('metrics')) {
        return;
    }
    if (function_exists('schema_short_lock')) {
        schema_short_lock($pdo);
    }
    foreach ([
        "ALTER TABLE metrics ADD COLUMN memory_used BIGINT NULL",
        "ALTER TABLE metrics ADD COLUMN memory_total BIGINT NULL",
        "ALTER TABLE metrics ADD COLUMN disk_used BIGINT NULL",
        "ALTER TABLE metrics ADD COLUMN disk_total BIGINT NULL",
        "ALTER TABLE metrics ADD COLUMN swap_percent FLOAT NULL",
        "ALTER TABLE metrics ADD COLUMN load_avg FLOAT NULL",
        "ALTER TABLE metrics ADD COLUMN cpu_count SMALLINT NULL",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Exception $e) {
            // column exists
        }
    }
    if (function_exists('schema_marker_touch')) {
        schema_marker_touch('metrics');
    }
}

function metrics_bucket_seconds(int $from, int $to, int $limit): int
{
    $span = max(60, $to - $from);
    $limit = max(20, min($limit, 800));
    return (int)max(60, (int)ceil($span / $limit));
}

try {
    metrics_ensure_schema($pdo);
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
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleGet($pdo) {
    $nodeId = $_GET['node_id'] ?? null;
    $range = $_GET['range'] ?? '1h';
    $limit = (int)($_GET['limit'] ?? 400);
    $limit = max(20, min($limit, 800));
    $from = isset($_GET['from']) ? (int)$_GET['from'] : null;
    $to = isset($_GET['to']) ? (int)$_GET['to'] : null;

    if ($from || $to) {
        if (!$from) {
            $base = $to ?: time();
            $from = $base - 3600;
        }
        if (!$to) {
            $to = time();
        }
    } else {
        $timeRange = strtotime("-{$range}");
        if (!$timeRange) {
            $timeRange = strtotime('-1 hour');
        }
        $from = $timeRange;
        $to = time();
    }

    $bucket = metrics_bucket_seconds((int)$from, (int)$to, $limit);
    $select = "FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(timestamp) / {$bucket}) * {$bucket}) AS ts,
               AVG(cpu_percent) AS cpu,
               AVG(memory_percent) AS ram,
               AVG(disk_percent) AS disk,
               AVG(network_in) AS network_in,
               AVG(network_out) AS network_out,
               AVG(memory_used) AS memory_used,
               AVG(memory_total) AS memory_total,
               AVG(disk_used) AS disk_used,
               AVG(disk_total) AS disk_total,
               AVG(swap_percent) AS swap_percent,
               AVG(load_avg) AS load_avg";

    if ($nodeId) {
        $sql = "SELECT {$select}
                FROM metrics
                WHERE node_id = ?
                  AND timestamp BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?)
                GROUP BY 1
                ORDER BY ts ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nodeId, $from, $to]);
    } else {
        $sql = "SELECT {$select}
                FROM metrics
                WHERE timestamp BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?)
                GROUP BY 1
                ORDER BY ts ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$from, $to]);
    }
    $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(function ($m) {
        return [
            'ts' => $m['ts'],
            'cpu' => (float)($m['cpu'] ?? 0),
            'ram' => (float)($m['ram'] ?? 0),
            'memory' => (float)($m['ram'] ?? 0),
            'disk' => (float)($m['disk'] ?? 0),
            'network_in' => (float)($m['network_in'] ?? 0),
            'network_out' => (float)($m['network_out'] ?? 0),
            'memory_used' => (float)($m['memory_used'] ?? 0),
            'memory_total' => (float)($m['memory_total'] ?? 0),
            'disk_used' => (float)($m['disk_used'] ?? 0),
            'disk_total' => (float)($m['disk_total'] ?? 0),
            'swap_percent' => (float)($m['swap_percent'] ?? 0),
            'load_avg' => (float)($m['load_avg'] ?? 0),
        ];
    }, $metrics);

    echo json_encode(['data' => $data]);
}

function handlePost($pdo) {
    global $nodeInfo;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }

    $nodeId = $nodeInfo ? $nodeInfo['id'] : ($data['node_id'] ?? null);
    $nodeName = null;
    $gpuInfo = null;

    if (isset($data['metrics'])) {
        $metrics = $data['metrics'];
        $nodeName = $metrics['node_name'] ?? null;
        if (!$nodeId && $nodeName) {
            $stmt = $pdo->prepare("SELECT id FROM nodes WHERE name = ?");
            $stmt->execute([$nodeName]);
            $node = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($node) {
                $nodeId = $node['id'];
            } else {
                error_log("[metrics.php] Node not found by name: {$nodeName}");
            }
        }
        $row = $metrics;
        $gpuInfo = $metrics['gpu'] ?? null;
    } else {
        $row = $data;
        $gpuInfo = $data['gpu'] ?? null;
    }

    if (!$nodeId) {
        error_log("[metrics.php] node_id is required. nodeInfo: " . json_encode($nodeInfo) . ", node_name: " . ($nodeName ?? 'null'));
        http_response_code(400);
        echo json_encode(['error' => 'node_id is required. Check node_token or provide node_name in metrics.']);
        return;
    }

    $updateStmt = $pdo->prepare("UPDATE nodes SET status = 'online', last_seen = NOW() WHERE id = ?");
    $updateStmt->execute([$nodeId]);

    $stmt = $pdo->prepare(
        "INSERT INTO metrics
            (node_id, cpu_percent, memory_percent, disk_percent, network_in, network_out,
             memory_used, memory_total, disk_used, disk_total, swap_percent, load_avg, cpu_count)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $nodeId,
        $row['cpu_percent'] ?? null,
        $row['memory_percent'] ?? null,
        $row['disk_percent'] ?? null,
        $row['network_in'] ?? null,
        $row['network_out'] ?? null,
        $row['memory_used'] ?? null,
        $row['memory_total'] ?? null,
        $row['disk_used'] ?? null,
        $row['disk_total'] ?? null,
        $row['swap_percent'] ?? null,
        $row['load_avg'] ?? null,
        $row['cpu_count'] ?? null,
    ]);
    $id = $pdo->lastInsertId();
    retention_maybe_tick($pdo);

    if ($gpuInfo && is_array($gpuInfo)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS gpu_metrics (
                id INT AUTO_INCREMENT PRIMARY KEY,
                node_id INT,
                gpu_index INT,
                gpu_name VARCHAR(255),
                vendor VARCHAR(20),
                utilization FLOAT,
                memory_used BIGINT,
                memory_total BIGINT,
                temperature FLOAT,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_node_id (node_id),
                INDEX idx_timestamp (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Exception $e) {
            error_log("Error creating gpu_metrics table: " . $e->getMessage());
        }

        $deleteGpuStmt = $pdo->prepare("DELETE FROM gpu_metrics WHERE node_id = ?");
        $deleteGpuStmt->execute([$nodeId]);

        $gpuStmt = $pdo->prepare("INSERT INTO gpu_metrics (node_id, gpu_index, gpu_name, vendor, utilization, memory_used, memory_total, temperature) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($gpuInfo as $gpu) {
            $gpuStmt->execute([
                $nodeId,
                $gpu['index'] ?? 0,
                $gpu['name'] ?? 'Unknown',
                $gpu['vendor'] ?? 'unknown',
                $gpu['utilization'] ?? 0,
                $gpu['memory_used'] ?? 0,
                $gpu['memory_total'] ?? 0,
                $gpu['temperature'] ?? 0
            ]);
        }
    }

    http_response_code(201);
    echo json_encode(['id' => $id, 'message' => 'Metric created']);
}
