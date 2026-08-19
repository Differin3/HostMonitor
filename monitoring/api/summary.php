<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/database.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

try {
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

    $summary = [
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

    echo json_encode($summary);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
