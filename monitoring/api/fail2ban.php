<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/agent_auth.php';
require_once __DIR__ . '/../includes/commands.php';
require_once __DIR__ . '/../includes/fail2ban_actions.php';
require_once __DIR__ . '/../includes/fail2ban_managed.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$isAuthorized = false;
$nodeInfo = null;
if (isset($_SESSION['user_id'])) {
    $isAuthorized = true;
} else {
    $agent = authenticate_agent($pdo);
    if ($agent !== null) {
        require_scope($agent, 'fail2ban:write');
        $nodeInfo = ['id' => (int)$agent['node_id']];
        $isAuthorized = true;
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

/**
 * Идемпотентное добавление колонки: миграция применяется руками, а страница
 * должна работать сразу после деплоя кода.
 */
function f2b_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        if ($stmt->fetchColumn() === false) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    } catch (Throwable $e) {
        // нет прав на ALTER — колонку добавит миграция
    }
}

/**
 * Идемпотентная схема: миграция применяется руками, а страница должна
 * работать сразу после деплоя кода.
 */
function f2b_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS fail2ban_status (
        node_id INT NOT NULL PRIMARY KEY,
        installed TINYINT(1) NOT NULL DEFAULT 0,
        version VARCHAR(32) NULL,
        active TINYINT(1) NOT NULL DEFAULT 0,
        jail_count INT NOT NULL DEFAULT 0,
        error VARCHAR(255) NULL,
        collected_at DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fail2ban_jails (
        id INT AUTO_INCREMENT PRIMARY KEY,
        node_id INT NOT NULL,
        jail VARCHAR(64) NOT NULL,
        currently_failed INT NOT NULL DEFAULT 0,
        total_failed INT NOT NULL DEFAULT 0,
        currently_banned INT NOT NULL DEFAULT 0,
        total_banned INT NOT NULL DEFAULT 0,
        logpath VARCHAR(512) NULL,
        backend VARCHAR(32) NULL,
        maxretry INT NULL,
        findtime INT NULL,
        bantime INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_node_jail (node_id, jail),
        KEY idx_node (node_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fail2ban_bans (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        node_id INT NOT NULL,
        jail VARCHAR(64) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        banned_at DATETIME NULL,
        expires_at DATETIME NULL,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        UNIQUE KEY uq_node_jail_ip (node_id, jail, ip),
        KEY idx_node_active (node_id, active),
        KEY idx_ip (ip),
        KEY idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fail2ban_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        node_id INT NOT NULL,
        jail VARCHAR(64) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        event ENUM('ban','unban','discovered') NOT NULL,
        ts DATETIME NOT NULL,
        KEY idx_node_ts (node_id, ts),
        KEY idx_ts (ts),
        KEY idx_jail (jail)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    f2b_ensure_column($pdo, 'fail2ban_bans', 'unban_requested_at', 'DATETIME NULL');
    f2b_ensure_column($pdo, 'fail2ban_bans', 'unban_requested_by', 'INT NULL');
    $done = true;
}

/**
 * Снимок агента: статус, джейлы, активные баны. История строится диффом
 * с прошлым снимком — sqlite fail2ban хранит баны только сутки.
 */
function f2b_ingest(PDO $pdo, int $nodeId, array $data): array
{
    $jailName = static function ($v): ?string {
        return is_string($v) && preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $v) === 1 ? $v : null;
    };
    $isIp = static function ($v): bool {
        return is_string($v) && filter_var($v, FILTER_VALIDATE_IP) !== false;
    };
    $intOrNull = static function ($v): ?int {
        return is_numeric($v) ? (int)$v : null;
    };

    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $installed = !empty($data['installed']);
    $active = $installed && !empty($data['active']);
    $version = isset($data['version']) && is_string($data['version']) ? substr($data['version'], 0, 32) : null;
    $error = isset($data['error']) && is_string($data['error']) ? substr($data['error'], 0, 255) : null;

    $jailsIn = [];
    foreach ((array)($data['jails'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $jail = $jailName($row['jail'] ?? null);
        if ($jail === null) {
            continue;
        }
        $jailsIn[$jail] = [
            'currently_failed' => max(0, (int)($row['currently_failed'] ?? 0)),
            'total_failed' => max(0, (int)($row['total_failed'] ?? 0)),
            'currently_banned' => max(0, (int)($row['currently_banned'] ?? 0)),
            'total_banned' => max(0, (int)($row['total_banned'] ?? 0)),
            'logpath' => isset($row['logpath']) && is_string($row['logpath']) ? substr($row['logpath'], 0, 512) : null,
            'backend' => isset($row['backend']) && is_string($row['backend']) ? substr($row['backend'], 0, 32) : null,
            'maxretry' => $intOrNull($row['maxretry'] ?? null),
            'findtime' => $intOrNull($row['findtime'] ?? null),
            'bantime' => $intOrNull($row['bantime'] ?? null),
        ];
        if (count($jailsIn) >= 100) {
            break;
        }
    }

    $bansIn = [];
    foreach ((array)($data['bans'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $jail = $jailName($row['jail'] ?? null);
        $ip = $row['ip'] ?? null;
        if ($jail === null || !$isIp($ip) || !isset($jailsIn[$jail])) {
            continue;
        }
        $bansIn[$jail . "\0" . $ip] = true;
        if (count($bansIn) >= 2000) {
            break;
        }
    }

    $stmt = $pdo->prepare('SELECT collected_at FROM fail2ban_status WHERE node_id = ?');
    $stmt->execute([$nodeId]);
    $prev = $stmt->fetch(PDO::FETCH_ASSOC);
    $isFirstReport = !$prev || $prev['collected_at'] === null;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO fail2ban_status (node_id, installed, version, active, jail_count, error, collected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE installed = VALUES(installed), version = VALUES(version),
                 active = VALUES(active), jail_count = VALUES(jail_count), error = VALUES(error),
                 collected_at = VALUES(collected_at)'
        );
        $stmt->execute([
            $nodeId, $installed ? 1 : 0, $version, $active ? 1 : 0,
            count($jailsIn), $error, $nowSql,
        ]);

        $stmt = $pdo->prepare(
            'INSERT INTO fail2ban_jails
                (node_id, jail, currently_failed, total_failed, currently_banned, total_banned,
                 logpath, backend, maxretry, findtime, bantime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE currently_failed = VALUES(currently_failed),
                 total_failed = VALUES(total_failed), currently_banned = VALUES(currently_banned),
                 total_banned = VALUES(total_banned), logpath = VALUES(logpath), backend = VALUES(backend),
                 maxretry = VALUES(maxretry), findtime = VALUES(findtime), bantime = VALUES(bantime)'
        );
        foreach ($jailsIn as $jail => $row) {
            $stmt->execute([
                $nodeId, $jail, $row['currently_failed'], $row['total_failed'], $row['currently_banned'],
                $row['total_banned'], $row['logpath'], $row['backend'], $row['maxretry'], $row['findtime'],
                $row['bantime'],
            ]);
        }

        if ($jailsIn) {
            $names = array_keys($jailsIn);
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $stmt = $pdo->prepare("DELETE FROM fail2ban_jails WHERE node_id = ? AND jail NOT IN ($placeholders)");
            $stmt->execute(array_merge([$nodeId], $names));
        } else {
            $pdo->prepare('DELETE FROM fail2ban_jails WHERE node_id = ?')->execute([$nodeId]);
        }

        $stmt = $pdo->prepare('SELECT id, jail, ip FROM fail2ban_bans WHERE node_id = ? AND active = 1');
        $stmt->execute([$nodeId]);
        $activeBans = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $activeBans[$row['jail'] . "\0" . $row['ip']] = (int)$row['id'];
        }

        $insertBan = $pdo->prepare(
            'INSERT INTO fail2ban_bans
                (node_id, jail, ip, active, banned_at, expires_at, first_seen, last_seen)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE active = 1, banned_at = VALUES(banned_at),
                 last_seen = VALUES(last_seen), expires_at = VALUES(expires_at)'
        );
        $touchBan = $pdo->prepare('UPDATE fail2ban_bans SET last_seen = ?, expires_at = ? WHERE id = ?');
        $closeBan = $pdo->prepare('UPDATE fail2ban_bans SET active = 0, last_seen = ? WHERE id = ?');
        $insertEvent = $pdo->prepare(
            'INSERT INTO fail2ban_events (node_id, jail, ip, event, ts) VALUES (?, ?, ?, ?, ?)'
        );

        $newEvents = 0;
        foreach ($bansIn as $key => $unused) {
            [$jail, $ip] = explode("\0", $key, 2);
            $bantime = $jailsIn[$jail]['bantime'];
            $expiresSql = ($bantime !== null && $bantime > 0) ? date('Y-m-d H:i:s', $now + $bantime) : null;
            if (isset($activeBans[$key])) {
                $touchBan->execute([$nowSql, $expiresSql, $activeBans[$key]]);
                unset($activeBans[$key]);
                continue;
            }
            $insertBan->execute([$nodeId, $jail, $ip, $nowSql, $expiresSql, $nowSql, $nowSql]);
            $insertEvent->execute([$nodeId, $jail, $ip, $isFirstReport ? 'discovered' : 'ban', $nowSql]);
            $newEvents++;
        }

        foreach ($activeBans as $key => $id) {
            [$jail, $ip] = explode("\0", $key, 2);
            $closeBan->execute([$nowSql, $id]);
            $insertEvent->execute([$nodeId, $jail, $ip, 'unban', $nowSql]);
            $newEvents++;
        }

        // Если ban всё ещё активен спустя 2 минуты после запроса разбана,
        // команда не сработала — снимаем пометку, чтобы UI не врал.
        $pdo->prepare(
            'UPDATE fail2ban_bans
                SET unban_requested_at = NULL, unban_requested_by = NULL
              WHERE node_id = ? AND active = 1
                AND unban_requested_at IS NOT NULL AND unban_requested_at < ?'
        )->execute([$nodeId, date('Y-m-d H:i:s', time() - 120)]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Managed-файл: хеш и содержимое с диска сравниваем с рендером из БД.
    if (isset($data['managed']) && is_array($data['managed'])) {
        $desired = f2b_managed_desired($pdo, $nodeId);
        f2b_state_save(
            $pdo,
            $nodeId,
            $data['managed'],
            $desired['text'] === '' ? '' : $desired['hash']
        );
    }

    return ['ok' => true, 'active_bans' => count($bansIn), 'events' => $newEvents];
}

function f2b_fill_series(array $rows, string $fromSql, int $days): array
{
    $map = [];
    foreach ($rows as $row) {
        $map[$row['bucket']] = [(int)$row['bans'], (int)$row['unbans']];
    }
    $points = [];
    $step = $days <= 1 ? 3600 : 86400;
    $fmt = $days <= 1 ? 'Y-m-d H:00:00' : 'Y-m-d 00:00:00';
    $start = strtotime($fromSql);
    $end = time();
    for ($t = $start; $t <= $end + $step; $t += $step) {
        $bucket = date($fmt, $t);
        $points[] = [
            'bucket' => $bucket,
            'bans' => $map[$bucket][0] ?? 0,
            'unbans' => $map[$bucket][1] ?? 0,
        ];
    }
    return $points;
}

f2b_ensure_schema($pdo);
f2b_managed_ensure($pdo);

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        json_error('Empty payload');
    }
    if (strlen($raw) > 1048576) {
        json_error('Payload too large', 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Invalid JSON');
    }

    // Канал агента: только приём снимка.
    if ($nodeInfo !== null) {
        try {
            echo json_encode(f2b_ingest($pdo, (int)$nodeInfo['id'], $data));
        } catch (Throwable $e) {
            json_exception($e);
        }
        exit;
    }

    // Действия из UI — только администратор.
    if (($_SESSION['role'] ?? 'admin') !== 'admin') {
        json_error('Forbidden: admin required', 403);
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $action = (string)($data['action'] ?? '');

    if ($action === 'unban') {
        [$status, $payload] = f2b_action_unban($pdo, $userId, $data);
    } elseif ($action === 'set_param') {
        [$status, $payload] = f2b_action_set_param($pdo, $userId, $data);
    } elseif ($action === 'save_settings') {
        [$status, $payload] = f2b_action_save_settings($pdo, $userId, $data);
    } elseif ($action === 'sync') {
        [$status, $payload] = f2b_action_sync($pdo, $userId, $data);
    } elseif ($action === 'delete_settings') {
        [$status, $payload] = f2b_action_delete_settings($pdo, $userId, $data);
    } else {
        [$status, $payload] = [404, ['error' => 'Unknown action']];
    }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($method !== 'GET') {
    json_error('Method not allowed', 405);
}

$action = $_GET['action'] ?? 'summary';

try {
    if ($action === 'summary') {
        $dayAgo = date('Y-m-d H:i:s', time() - 86400);
        $stmt24 = $pdo->prepare(
            "SELECT COUNT(*) FROM fail2ban_events WHERE event = 'ban' AND ts >= ?"
        );
        $stmt24->execute([$dayAgo]);
        $totals = [
            'nodes' => (int)$pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn(),
            'installed' => (int)$pdo->query('SELECT COUNT(*) FROM fail2ban_status WHERE installed = 1')->fetchColumn(),
            'active' => (int)$pdo->query('SELECT COUNT(*) FROM fail2ban_status WHERE active = 1')->fetchColumn(),
            'active_bans' => (int)$pdo->query('SELECT COUNT(*) FROM fail2ban_bans WHERE active = 1')->fetchColumn(),
            'bans_24h' => (int)$stmt24->fetchColumn(),
            'drift' => (int)$pdo->query('SELECT COUNT(*) FROM fail2ban_managed_state WHERE drift = 1')->fetchColumn(),
        ];
        $sql = "SELECT n.id, n.name, n.host, n.status,
                       s.installed, s.active, s.version, s.jail_count, s.collected_at, s.error,
                       COALESCE(b.active_bans, 0) AS active_bans
                  FROM nodes n
                  LEFT JOIN fail2ban_status s ON s.node_id = n.id
                  LEFT JOIN (
                      SELECT node_id, COUNT(*) AS active_bans
                        FROM fail2ban_bans WHERE active = 1 GROUP BY node_id
                  ) b ON b.node_id = n.id
              ORDER BY n.name";
        $nodes = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nodes[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'host' => $row['host'],
                'status' => $row['status'],
                'installed' => (int)$row['installed'] === 1,
                'active' => (int)$row['active'] === 1,
                'version' => $row['version'],
                'jail_count' => (int)$row['jail_count'],
                'collected_at' => $row['collected_at'],
                'error' => $row['error'],
                'active_bans' => (int)$row['active_bans'],
            ];
        }
        echo json_encode(['totals' => $totals, 'nodes' => $nodes]);
        exit;
    }

    if ($action === 'jails') {
        $nodeId = isset($_GET['node_id']) && $_GET['node_id'] !== '' ? (int)$_GET['node_id'] : null;
        $sql = 'SELECT j.*, n.name AS node_name FROM fail2ban_jails j
                  JOIN nodes n ON n.id = j.node_id';
        $params = [];
        if ($nodeId !== null) {
            $sql .= ' WHERE j.node_id = ?';
            $params[] = $nodeId;
        }
        $sql .= ' ORDER BY n.name, j.jail';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'bans') {
        $where = [];
        $params = [];
        if (isset($_GET['node_id']) && $_GET['node_id'] !== '') {
            $where[] = 'b.node_id = ?';
            $params[] = (int)$_GET['node_id'];
        }
        if (isset($_GET['jail']) && $_GET['jail'] !== '') {
            $where[] = 'b.jail = ?';
            $params[] = (string)$_GET['jail'];
        }
        $status = $_GET['status'] ?? 'active';
        if ($status === 'active') {
            $where[] = 'b.active = 1';
        } elseif ($status === 'expired') {
            $where[] = 'b.active = 0';
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'b.ip LIKE ?';
            $params[] = $q . '%';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM fail2ban_bans b $whereSql");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
        $perPage = max(10, min($perPage, 200));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT b.id, b.node_id, n.name AS node_name, b.jail, b.ip, b.active,
                       b.banned_at, b.expires_at, b.first_seen, b.last_seen,
                       b.unban_requested_at, b.unban_requested_by
                  FROM fail2ban_bans b
                  JOIN nodes n ON n.id = b.node_id
                  $whereSql
              ORDER BY b.active DESC, b.last_seen DESC, b.id DESC
                 LIMIT $perPage OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode([
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ]);
        exit;
    }

    if ($action === 'desired') {
        // Канал агента: отдаём полный текст managed-файла для применения.
        if ($nodeInfo === null) {
            json_error('Forbidden: agent only', 403);
        }
        $desired = f2b_managed_desired($pdo, (int)$nodeInfo['id']);
        echo json_encode([
            'ok' => true,
            'file' => $desired['text'],
            'hash' => $desired['hash'],
            'jails' => $desired['jails'],
        ]);
        exit;
    }

    if ($action === 'managed') {
        if (($_SESSION['role'] ?? 'admin') !== 'admin') {
            json_error('Forbidden: admin required', 403);
        }
        $nodesOut = [];
        $sql = "SELECT n.id, n.name, s.installed
                  FROM nodes n
                  LEFT JOIN fail2ban_status s ON s.node_id = n.id
                 WHERE s.installed = 1
                    OR EXISTS (SELECT 1 FROM fail2ban_jail_settings js WHERE js.node_id = n.id)
              ORDER BY n.name";
        $jailStmt = $pdo->prepare(
            'SELECT jail, maxretry, findtime, bantime FROM fail2ban_jails WHERE node_id = ? ORDER BY jail'
        );
        // Последняя попытка sync: по ошибке видно, почему файл не применился
        // (например, джейла нет в конфигах fail2ban и `-t` его отклонил).
        $cmdStmt = $pdo->prepare(
            "SELECT command_status, result, error, created_at FROM node_commands
              WHERE node_id = ? AND command = 'fail2ban-sync' ORDER BY id DESC LIMIT 1"
        );
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $node) {
            $nodeId = (int)$node['id'];
            $settings = f2b_settings_for_node($pdo, $nodeId);
            $desired = f2b_managed_desired($pdo, $nodeId);
            $state = f2b_state_for_node($pdo, $nodeId);

            $jailStmt->execute([$nodeId]);
            $jails = [];
            foreach ($jailStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $jail = (string)$row['jail'];
                $jails[] = [
                    'jail' => $jail,
                    'maxretry' => $row['maxretry'] !== null ? (int)$row['maxretry'] : null,
                    'findtime' => $row['findtime'] !== null ? (int)$row['findtime'] : null,
                    'bantime' => $row['bantime'] !== null ? (int)$row['bantime'] : null,
                    'managed' => isset($settings[$jail]),
                    'enabled' => isset($settings[$jail]) ? (int)$settings[$jail]['enabled'] === 1 : true,
                ];
            }
            $settingsOut = [];
            foreach ($settings as $jail => $row) {
                $settingsOut[] = [
                    'jail' => $jail,
                    'enabled' => (int)$row['enabled'] === 1,
                    'maxretry' => $row['maxretry'] !== null ? (int)$row['maxretry'] : null,
                    'findtime' => $row['findtime'] !== null ? (int)$row['findtime'] : null,
                    'bantime' => $row['bantime'] !== null ? (int)$row['bantime'] : null,
                    'ignoreip' => (string)($row['ignoreip'] ?? ''),
                    'updated_by' => $row['updated_by'] !== null ? (int)$row['updated_by'] : null,
                    'updated_at' => $row['updated_at'],
                ];
            }

            $cmdStmt->execute([$nodeId]);
            $lastCommand = $cmdStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $desiredText = $desired['text'];
            $fileContent = $state !== null && $state['file_content'] !== null ? (string)$state['file_content'] : '';
            $nodesOut[] = [
                'node_id' => $nodeId,
                'node_name' => $node['name'],
                'installed' => (int)$node['installed'] === 1,
                'path' => $state['file_path'] ?? '/etc/fail2ban/jail.d/90-hostmonitor.local',
                'desired_hash' => $desiredText === '' ? null : $desired['hash'],
                'file_hash' => $state['file_hash'] ?? null,
                'file_exists' => $state !== null && $state['file_hash'] !== null,
                'drift' => $state !== null && (int)$state['drift'] === 1,
                'reported_at' => $state['reported_at'] ?? null,
                'applied_at' => $state['applied_at'] ?? null,
                'desired_text' => $desiredText,
                'file_content' => $fileContent,
                'diff' => f2b_diff_lines($fileContent, $desiredText),
                'last_command' => $lastCommand === null ? null : [
                    'status' => $lastCommand['command_status'],
                    'result' => $lastCommand['result'],
                    'error' => $lastCommand['error'],
                    'created_at' => $lastCommand['created_at'],
                ],
                'settings' => $settingsOut,
                'jails' => $jails,
            ];
        }
        echo json_encode(['nodes' => $nodesOut]);
        exit;
    }

    if ($action === 'history') {
        if (($_SESSION['role'] ?? 'admin') !== 'admin') {
            json_error('Forbidden: admin required', 403);
        }
        $nodeId = isset($_GET['node_id']) && $_GET['node_id'] !== '' ? (int)$_GET['node_id'] : null;
        $jail = isset($_GET['jail']) && $_GET['jail'] !== '' ? (string)$_GET['jail'] : null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        echo json_encode(['items' => f2b_history_list($pdo, $nodeId, $jail, $limit)]);
        exit;
    }

    if ($action === 'series') {
        $days = (int)($_GET['days'] ?? 1);
        if (!in_array($days, [1, 7, 30], true)) {
            $days = 1;
        }
        $fromSql = date('Y-m-d H:i:s', time() - $days * 86400);
        $bucket = $days <= 1 ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d 00:00:00';
        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(ts, '$bucket') AS bucket,
                    SUM(event = 'ban') AS bans,
                    SUM(event = 'unban') AS unbans
               FROM fail2ban_events
              WHERE ts >= ?
           GROUP BY bucket
           ORDER BY bucket"
        );
        $stmt->execute([$fromSql]);
        echo json_encode([
            'days' => $days,
            'points' => f2b_fill_series($stmt->fetchAll(PDO::FETCH_ASSOC), $fromSql, $days),
            'active_bans' => (int)$pdo->query('SELECT COUNT(*) FROM fail2ban_bans WHERE active = 1')->fetchColumn(),
        ]);
        exit;
    }

    json_error('Unknown action', 404);
} catch (Throwable $e) {
    json_exception($e);
}
