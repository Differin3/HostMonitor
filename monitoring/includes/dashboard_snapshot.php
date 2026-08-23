<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Сбор данных дашборда для REST и SSE (без ping/GPU — быстрый снимок).
 */

function dashboard_summary(PDO $pdo): array
{
    $nodesStmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online FROM nodes");
    $nodesStats = $nodesStmt->fetch(PDO::FETCH_ASSOC);

    $processesActive = 0;
    $containersRunning = 0;
    try {
        $processesActive = (int)$pdo->query("SELECT COUNT(*) FROM processes")->fetchColumn();
    } catch (Throwable $e) {
        $processesActive = 0;
    }
    try {
        $containersRunning = (int)$pdo->query("SELECT COUNT(*) FROM containers WHERE status = 'running'")->fetchColumn();
    } catch (Throwable $e) {
        $containersRunning = 0;
    }

    $avgSql = "SELECT AVG(cpu_percent) as avg_cpu, AVG(memory_percent) as avg_ram, AVG(disk_percent) as avg_disk
               FROM metrics WHERE timestamp >= DATE_SUB(NOW(), INTERVAL %s)";
    $cpuStats = $pdo->query(sprintf($avgSql, '5 MINUTE'))->fetch(PDO::FETCH_ASSOC);
    if (!isset($cpuStats['avg_cpu']) || $cpuStats['avg_cpu'] === null) {
        $cpuStats = $pdo->query(sprintf($avgSql, '1 HOUR'))->fetch(PDO::FETCH_ASSOC);
    }

    $alertsCount = 0;
    try {
        $alertsCount = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE resolved = 0")->fetchColumn();
    } catch (Throwable $e) {
        $alertsCount = 0;
    }

    $dbTotal = 0;
    $dbOnline = 0;
    try {
        $dbRow = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) AS online FROM monitored_databases WHERE enabled = 1")->fetch(PDO::FETCH_ASSOC);
        $dbTotal = (int)($dbRow['total'] ?? 0);
        $dbOnline = (int)($dbRow['online'] ?? 0);
    } catch (Throwable $e) {
        $dbTotal = 0;
        $dbOnline = 0;
    }

    return [
        'nodes_total' => (int)($nodesStats['total'] ?? 0),
        'nodes_online' => (int)($nodesStats['online'] ?? 0),
        'processes_active' => $processesActive,
        'containers_running' => $containersRunning,
        'cpu_avg' => round((float)($cpuStats['avg_cpu'] ?? 0), 1),
        'ram_avg' => round((float)($cpuStats['avg_ram'] ?? 0), 1),
        'disk_avg' => round((float)($cpuStats['avg_disk'] ?? 0), 1),
        'alerts_count' => $alertsCount,
        'databases_total' => $dbTotal,
        'databases_online' => $dbOnline,
    ];
}

function dashboard_alerts(PDO $pdo, int $limit = 6): array
{
    $limit = max(1, min($limit, 50));
    try {
        $stmt = $pdo->prepare(
            "SELECT a.*, COALESCE(n.name, CONCAT('Node ', a.node_id)) as node_name
             FROM alerts a
             LEFT JOIN nodes n ON a.node_id = n.id
             WHERE a.resolved = FALSE
             ORDER BY a.created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    return array_map(static function (array $alert): array {
        return [
            'id' => $alert['id'],
            'level' => $alert['level'],
            'title' => $alert['title'],
            'node' => $alert['node_name'] ?? 'N/A',
            'node_id' => $alert['node_id'],
            'timestamp' => $alert['created_at'],
        ];
    }, $alerts);
}

function dashboard_nodes_light(PDO $pdo, int $limit = 6): array
{
    $limit = max(1, min($limit, 50));
    $stmt = $pdo->query("SELECT id, name, host, status, last_seen FROM nodes ORDER BY name");
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$nodes) {
        return [];
    }

    $nodeIds = array_column($nodes, 'id');
    $metricsByNode = [];
    $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
    $metricsSql = "
        SELECT m.node_id, m.cpu_percent, m.memory_percent, m.disk_percent
        FROM metrics m
        INNER JOIN (
            SELECT node_id, MAX(timestamp) AS ts
            FROM metrics
            WHERE node_id IN ($placeholders)
            GROUP BY node_id
        ) last ON last.node_id = m.node_id AND last.ts = m.timestamp
    ";
    try {
        $metricsStmt = $pdo->prepare($metricsSql);
        $metricsStmt->execute($nodeIds);
        while ($row = $metricsStmt->fetch(PDO::FETCH_ASSOC)) {
            $metricsByNode[(int)$row['node_id']] = $row;
        }
    } catch (Throwable $e) {
        $metricsByNode = [];
    }

    $heartbeatTimeout = function_exists('node_heartbeat_timeout_sec')
        ? node_heartbeat_timeout_sec()
        : 180;
    $out = [];
    foreach ($nodes as $node) {
        $id = (int)$node['id'];
        if ($node['last_seen']) {
            $secondsSince = time() - (int)strtotime((string)$node['last_seen']);
            $node['status'] = ($secondsSince > $heartbeatTimeout) ? 'offline' : 'online';
        }
        if (empty($node['name'])) {
            $node['name'] = "Node {$id}";
        }
        $m = $metricsByNode[$id] ?? null;
        $out[] = [
            'id' => $id,
            'name' => $node['name'],
            'host' => $node['host'] ?? '',
            'status' => $node['status'] ?? 'offline',
            'cpu_usage' => (float)($m['cpu_percent'] ?? 0),
            'memory_usage' => (float)($m['memory_percent'] ?? 0),
            'disk_usage' => (float)($m['disk_percent'] ?? 0),
        ];
    }

    usort($out, static function (array $a, array $b): int {
        $aOff = ($a['status'] ?? '') === 'online' ? 1 : 0;
        $bOff = ($b['status'] ?? '') === 'online' ? 1 : 0;
        if ($aOff !== $bOff) {
            return $aOff - $bOff;
        }
        return (int)(($b['cpu_usage'] ?? 0) - ($a['cpu_usage'] ?? 0));
    });

    return array_slice($out, 0, $limit);
}

function dashboard_charts_bucket_seconds(int $from, int $to, int $limit): int
{
    $span = max(60, $to - $from);
    $limit = max(20, min($limit, 800));
    return (int)max(60, (int)ceil($span / $limit));
}

function dashboard_charts(PDO $pdo, string $range = '1h'): array
{
    $ranges = ['15m' => 900, '1h' => 3600, '6h' => 21600, '24h' => 86400];
    $seconds = $ranges[$range] ?? 3600;
    $from = time() - $seconds;
    $to = time();
    $limit = $range === '24h' ? 160 : ($range === '6h' ? 120 : 80);
    $bucket = dashboard_charts_bucket_seconds($from, $to, $limit);

    $select = "FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(timestamp) / {$bucket}) * {$bucket}) AS ts,
               AVG(cpu_percent) AS cpu,
               AVG(memory_percent) AS ram,
               AVG(disk_percent) AS disk,
               AVG(network_in) AS network_in,
               AVG(network_out) AS network_out,
               AVG(load_avg) AS load_avg";

    $sql = "SELECT {$select}
            FROM metrics
            WHERE timestamp BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?)
            GROUP BY 1
            ORDER BY ts ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$from, $to]);
    $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(static function (array $m): array {
        return [
            'ts' => $m['ts'],
            'cpu' => (float)($m['cpu'] ?? 0),
            'ram' => (float)($m['ram'] ?? 0),
            'memory' => (float)($m['ram'] ?? 0),
            'disk' => (float)($m['disk'] ?? 0),
            'network_in' => (float)($m['network_in'] ?? 0),
            'network_out' => (float)($m['network_out'] ?? 0),
            'load_avg' => (float)($m['load_avg'] ?? 0),
        ];
    }, $metrics);

    return ['range' => $range, 'data' => $data];
}

function dashboard_overview(PDO $pdo, int $listLimit = 6): array
{
    return [
        'summary' => dashboard_summary($pdo),
        'nodes' => dashboard_nodes_light($pdo, $listLimit),
        'alerts' => dashboard_alerts($pdo, $listLimit),
        'ts' => time(),
    ];
}
