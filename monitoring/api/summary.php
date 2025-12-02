<?php
// API для получения сводной статистики дашборда
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
    // Статистика нод
    $nodesStmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online FROM nodes");
    $nodesStats = $nodesStmt->fetch(PDO::FETCH_ASSOC);
    
    // Статистика процессов
    $processesStmt = $pdo->query("SELECT COUNT(*) as active FROM processes WHERE status = 'running'");
    $processesStats = $processesStmt->fetch(PDO::FETCH_ASSOC);
    
    // Статистика контейнеров
    $containersStmt = $pdo->query("SELECT COUNT(*) as running FROM containers WHERE status = 'running'");
    $containersStats = $containersStmt->fetch(PDO::FETCH_ASSOC);
    
    // Средняя загрузка CPU
    $cpuStmt = $pdo->query("SELECT AVG(cpu_percent) as avg_cpu FROM metrics WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $cpuStats = $cpuStmt->fetch(PDO::FETCH_ASSOC);
    
    // Средний uptime онлайн нод (в секундах)
    $uptimeStmt = $pdo->query("SELECT AVG(TIMESTAMPDIFF(SECOND, last_seen, NOW())) as avg_uptime 
                                FROM nodes 
                                WHERE status = 'online' AND last_seen IS NOT NULL");
    $uptimeStats = $uptimeStmt->fetch(PDO::FETCH_ASSOC);
    $avgUptimeSeconds = (int)($uptimeStats['avg_uptime'] ?? 0);
    
    $summary = [
        'nodes_total' => (int)($nodesStats['total'] ?? 0),
        'nodes_online' => (int)($nodesStats['online'] ?? 0),
        'processes_active' => (int)($processesStats['active'] ?? 0),
        'containers_running' => (int)($containersStats['running'] ?? 0),
        'cpu_avg' => round((float)($cpuStats['avg_cpu'] ?? 0), 1),
        'uptime_avg' => $avgUptimeSeconds
    ];
    
    echo json_encode($summary);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

