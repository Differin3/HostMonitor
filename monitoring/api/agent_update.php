<?php
declare(strict_types=1);

/**
 * Обновление агентов с панели.
 * Session admin: GET status | POST check | POST apply
 * Bearer agent: POST report
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

// Только активная БД. _all (primary+replica) на каждый status вешает UI.
nodes_ensure_agent_columns($pdo);

function agent_desired_version(): array
{
    $version = '0.0.0';
    $commit = '';
    $root = dirname(__DIR__, 2);
    $verFile = $root . '/agent/VERSION';
    if (is_file($verFile)) {
        $raw = trim((string)file_get_contents($verFile));
        $version = trim(explode("\n", $raw)[0] ?? '') ?: '0.0.0';
    }
    if (is_dir($root . '/.git')) {
        $out = [];
        $cmd = 'git -c safe.directory=' . escapeshellarg($root)
            . ' -C ' . escapeshellarg($root)
            . ' rev-parse --short HEAD 2>/dev/null';
        @exec($cmd, $out);
        $commit = trim((string)($out[0] ?? ''));
    }
    return ['desired_version' => $version, 'desired_commit' => $commit];
}

function agent_queue_command(PDO $pdo, int $nodeId, string $command): bool
{
    $stmt = $pdo->prepare('SELECT last_command, command_status, command_timestamp FROM nodes WHERE id = ?');
    $stmt->execute([$nodeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if (($row['command_status'] ?? '') === 'pending' && !empty($row['last_command'])) {
        $age = time() - (int)strtotime((string)($row['command_timestamp'] ?? 'now'));
        if ($age < 120 && (string)$row['last_command'] !== $command) {
            return false;
        }
    }
    $upd = $pdo->prepare(
        "UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW(), command_result = NULL WHERE id = ?"
    );
    $upd->execute([$command, $nodeId]);
    return true;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');

$authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$bearer = '';
if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
    $bearer = $m[1];
}

$agentNode = null;
if ($bearer !== '') {
    $stmt = $pdo->prepare('SELECT id, name FROM nodes WHERE node_token = ? LIMIT 1');
    $stmt->execute([$bearer]);
    $agentNode = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

try {
    if ($method === 'POST' && $action === 'report') {
        if (!$agentNode) {
            json_error('Unauthorized', 401);
        }
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data)) {
            json_error('Invalid JSON', 400);
        }
        $nodeId = (int)$agentNode['id'];
        $version = substr((string)($data['agent_version'] ?? ''), 0, 32);
        $commit = substr((string)($data['agent_commit'] ?? ''), 0, 64);
        $remote = substr((string)($data['agent_remote_commit'] ?? ''), 0, 64);
        $branch = substr((string)($data['agent_branch'] ?? ''), 0, 64);
        $available = !empty($data['update_available']) ? 1 : 0;
        $msg = substr((string)($data['message'] ?? $data['error'] ?? ''), 0, 2000);

        $stmt = $pdo->prepare(
            "UPDATE nodes SET
                agent_version = COALESCE(NULLIF(?, ''), agent_version),
                agent_commit = COALESCE(NULLIF(?, ''), agent_commit),
                agent_remote_commit = COALESCE(NULLIF(?, ''), agent_remote_commit),
                agent_branch = COALESCE(NULLIF(?, ''), agent_branch),
                agent_update_available = ?,
                agent_updated_at = NOW(),
                command_result = COALESCE(NULLIF(?, ''), command_result),
                status = 'online',
                last_seen = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$version, $commit, $remote, $branch, $available, $msg, $nodeId]);
        echo json_encode(['ok' => true, 'node_id' => $nodeId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($_SESSION['user_id'])) {
        json_error('Unauthorized', 401);
    }

    if ($method === 'GET' && ($action === '' || $action === 'status')) {
        $desired = agent_desired_version();
        try {
            $rows = $pdo->query(
                "SELECT id, name, host, status, last_seen,
                        agent_version, agent_commit, agent_remote_commit, agent_branch,
                        agent_update_available, agent_updated_at, command_result, last_command, command_status
                 FROM nodes ORDER BY name"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Колонки ещё не созданы / старая схема — добиваем ALTER и повторяем
            nodes_ensure_agent_columns($pdo);
            $rows = $pdo->query(
                "SELECT id, name, host, status, last_seen,
                        agent_version, agent_commit, agent_remote_commit, agent_branch,
                        agent_update_available, agent_updated_at, last_command, command_status
                 FROM nodes ORDER BY name"
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $outdated = 0;
        foreach ($rows as &$row) {
            $local = (string)($row['agent_commit'] ?? '');
            $flag = (int)($row['agent_update_available'] ?? 0) === 1;
            $ver = (string)($row['agent_version'] ?? '');
            if (!$flag && $desired['desired_commit'] !== '' && $local !== '' && $local !== $desired['desired_commit']) {
                $flag = true;
            }
            if (!$flag && $desired['desired_version'] !== '' && $ver !== '' && $ver !== $desired['desired_version']) {
                $flag = true;
            }
            $row['outdated'] = $flag;
            if ($flag) {
                $outdated++;
            }
        }
        unset($row);

        echo json_encode([
            'ok' => true,
            'desired' => $desired,
            'outdated_count' => $outdated,
            'nodes' => $rows,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && in_array($action, ['check', 'apply'], true)) {
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = [];
        }
        $command = $action === 'apply' ? 'update-agent' : 'check-agent-update';
        $ids = $data['node_ids'] ?? $data['ids'] ?? null;
        $onlyOutdated = !empty($data['only_outdated']);

        if (is_array($ids) && $ids) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0));
            if (!$ids) {
                json_error('No nodes selected', 400);
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, name FROM nodes WHERE id IN ($ph)");
            $stmt->execute($ids);
            $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $sql = "SELECT id, name FROM nodes WHERE status = 'online'";
            if ($onlyOutdated && $action === 'apply') {
                $sql .= ' AND agent_update_available = 1';
            }
            $nodes = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }

        $queued = 0;
        $skipped = 0;
        foreach ($nodes as $node) {
            if (agent_queue_command($pdo, (int)$node['id'], $command)) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        echo json_encode([
            'ok' => true,
            'command' => $command,
            'queued' => $queued,
            'skipped' => $skipped,
            'message' => "Команда «{$command}» поставлена в очередь для {$queued} нод(ы)",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    json_error('Invalid action', 400);
} catch (Throwable $e) {
    json_exception($e, true);
}
