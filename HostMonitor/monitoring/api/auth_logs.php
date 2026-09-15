<?php
// Журнал входов и событий безопасности (auth_logs)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();
require_api_auth($pdo);

// Гарантируем наличие таблицы (log_auth_event её создаёт, но на всякий случай)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        username VARCHAR(100),
        ip_address VARCHAR(45),
        event_type VARCHAR(30) NOT NULL,
        success BOOLEAN DEFAULT FALSE,
        message TEXT,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_event_type (event_type),
        INDEX idx_timestamp (timestamp),
        INDEX idx_ip_address (ip_address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    // ignore
}

$where = [];
$params = [];

$type = trim((string)($_GET['type'] ?? ''));
if ($type !== '') {
    $where[] = 'event_type = ?';
    $params[] = $type;
}

$result = trim((string)($_GET['result'] ?? ''));
if ($result === 'ok') {
    $where[] = 'success = 1';
} elseif ($result === 'fail') {
    $where[] = 'success = 0';
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $where[] = '(username LIKE ? OR ip_address LIKE ? OR message LIKE ?)';
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}

$days = (int)($_GET['days'] ?? 0);
if ($days > 0) {
    $where[] = 'timestamp >= DATE_SUB(NOW(), INTERVAL ? DAY)';
    $params[] = $days;
}

$limit = (int)($_GET['limit'] ?? 100);
$limit = max(1, min($limit, 500));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM auth_logs {$sqlWhere}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id, user_id, username, ip_address, event_type, success, message, timestamp
        FROM auth_logs {$sqlWhere}
        ORDER BY timestamp DESC, id DESC
        LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Сводка за выбранный период (или всё время)
    $statsWhere = $days > 0 ? 'WHERE timestamp >= DATE_SUB(NOW(), INTERVAL ? DAY)' : '';
    $statsParams = $days > 0 ? [$days] : [];
    $statsStmt = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(success = 0) AS failed,
        SUM(success = 1) AS ok,
        SUM(event_type IN ('login_2fa','totp_enable','totp_disable','totp_recovery_regen')) AS twofa,
        COUNT(DISTINCT ip_address) AS unique_ips
        FROM auth_logs {$statsWhere}");
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Подозрительные IP: >=3 неудачных попыток за сутки
    $suspStmt = $pdo->query("SELECT ip_address, COUNT(*) AS fails, MAX(timestamp) AS last
        FROM auth_logs
        WHERE event_type = 'failed' AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        GROUP BY ip_address HAVING fails >= 3 ORDER BY fails DESC LIMIT 10");
    $suspicious = $suspStmt ? $suspStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    echo json_encode([
        'logs' => $logs,
        'total' => $total,
        'stats' => [
            'total' => (int)($stats['total'] ?? 0),
            'failed' => (int)($stats['failed'] ?? 0),
            'ok' => (int)($stats['ok'] ?? 0),
            'twofa' => (int)($stats['twofa'] ?? 0),
            'unique_ips' => (int)($stats['unique_ips'] ?? 0),
        ],
        'suspicious' => $suspicious,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_exception($e, true);
}
