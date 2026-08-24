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

function agent_is_outdated(array $row, array $desired): bool
{
    if ((int)($row['agent_update_available'] ?? 0) === 1) {
        return true;
    }
    $local = (string)($row['agent_commit'] ?? '');
    $ver = (string)($row['agent_version'] ?? '');
    if ($desired['desired_commit'] !== '' && $local !== '' && $local !== $desired['desired_commit']) {
        return true;
    }
    if ($desired['desired_version'] !== '' && $ver !== '' && $ver !== $desired['desired_version']) {
        return true;
    }
    return false;
}

/**
 * Очередь команд для агента.
 * check/update-agent всегда перезаписывают любой pending (кнопка с панели = приоритет).
 * Прочие команды блокируют только свежий чужой pending (<45с).
 */
function agent_is_agent_cmd(string $command): bool
{
    return in_array($command, [
        'check-agent-update',
        'check-agent-updates',
        'update-agent',
        'upgrade-agent',
    ], true);
}

function agent_is_update_cmd(string $command): bool
{
    return in_array($command, ['update-agent', 'upgrade-agent'], true);
}

function agent_is_check_cmd(string $command): bool
{
    return in_array($command, ['check-agent-update', 'check-agent-updates'], true);
}

/** idle | checking | updating | failed */
function agent_job_state(array $row): string
{
    $cmd = trim((string)($row['last_command'] ?? ''));
    $status = strtolower(trim((string)($row['command_status'] ?? '')));
    if (!agent_is_agent_cmd($cmd)) {
        return 'idle';
    }
    if (in_array($status, ['failed', 'error'], true)) {
        return 'failed';
    }
    if (in_array($status, ['pending', 'running', 'installing', 'in_progress'], true) || $status === '') {
        if (agent_is_update_cmd($cmd)) {
            return 'updating';
        }
        if (agent_is_check_cmd($cmd)) {
            return 'checking';
        }
    }
    return 'idle';
}

function agent_pending_age_sec(array $row): int
{
    $raw = $row['command_timestamp'] ?? null;
    if ($raw === null || $raw === '') {
        return 999999; // нет метки — считаем протухшим
    }
    $ts = strtotime((string)$raw);
    if ($ts === false || $ts <= 0) {
        return 999999;
    }
    return max(0, time() - $ts);
}

function agent_queue_command(PDO $pdo, int $nodeId, string $command, bool $force = false): bool
{
    $stmt = $pdo->prepare('SELECT last_command, command_status, command_timestamp FROM nodes WHERE id = ?');
    $stmt->execute([$nodeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $pending = trim((string)($row['last_command'] ?? ''));
    $status = (string)($row['command_status'] ?? '');
    $age = agent_pending_age_sec($row);

    // Протухший pending (>3 мин) всегда можно заменить
    if ($status === 'pending' && $pending !== '' && $age > 180) {
        $force = true;
    }

    if (!$force && $status === 'pending' && $pending !== '' && $pending !== $command) {
        // Команды обновления агента с панели всегда имеют приоритет
        if (agent_is_agent_cmd($command)) {
            $force = true;
        } elseif ($age < 45) {
            return false;
        }
    }

    $upd = $pdo->prepare(
        "UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW(), command_result = NULL WHERE id = ?"
    );
    $upd->execute([$command, $nodeId]);
    return true;
}

function agent_clear_stale_pending(PDO $pdo, int $maxAgeSec = 180): int
{
    try {
        $stmt = $pdo->prepare(
            "UPDATE nodes
             SET last_command = NULL, command_status = NULL, command_timestamp = NULL
             WHERE command_status = 'pending'
               AND last_command IS NOT NULL
               AND last_command != ''
               AND (command_timestamp IS NULL OR command_timestamp < (NOW() - INTERVAL ? SECOND))"
        );
        $stmt->execute([$maxAgeSec]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
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

        // Снимаем зависший check/update-agent после отчёта (рестарт агента часто не успевает command-status)
        $clr = $pdo->prepare(
            "UPDATE nodes
             SET last_command = NULL, command_status = NULL, command_timestamp = NULL
             WHERE id = ?
               AND last_command IN ('check-agent-update','check-agent-updates','update-agent','upgrade-agent')"
        );
        $clr->execute([$nodeId]);

        echo json_encode(['ok' => true, 'node_id' => $nodeId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($_SESSION['user_id'])) {
        json_error('Unauthorized', 401);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if ($method === 'GET' && ($action === '' || $action === 'status')) {
        agent_clear_stale_pending($pdo, 180);
        $desired = agent_desired_version();
        try {
            $rows = $pdo->query(
                "SELECT id, name, host, status, last_seen,
                        agent_version, agent_commit, agent_remote_commit, agent_branch,
                        agent_update_available, agent_updated_at, command_result,
                        last_command, command_status, command_timestamp
                 FROM nodes ORDER BY name"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Колонки ещё не созданы / старая схема — добиваем ALTER и повторяем
            nodes_ensure_agent_columns($pdo);
            $rows = $pdo->query(
                "SELECT id, name, host, status, last_seen,
                        agent_version, agent_commit, agent_remote_commit, agent_branch,
                        agent_update_available, agent_updated_at,
                        last_command, command_status, command_timestamp
                 FROM nodes ORDER BY name"
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $outdated = 0;
        $sync = $pdo->prepare('UPDATE nodes SET agent_update_available = ? WHERE id = ?');
        foreach ($rows as &$row) {
            $flag = agent_is_outdated($row, $desired);
            $row['outdated'] = $flag;
            $row['agent_job'] = agent_job_state($row);
            $row['command_age_sec'] = agent_is_agent_cmd(trim((string)($row['last_command'] ?? '')))
                ? agent_pending_age_sec($row)
                : 0;
            // Синхронизируем флаг в БД с тем, что видит UI (commit/version панели)
            $dbFlag = (int)($row['agent_update_available'] ?? 0);
            $want = $flag ? 1 : 0;
            if ($dbFlag !== $want) {
                try {
                    $sync->execute([$want, (int)$row['id']]);
                    $row['agent_update_available'] = $want;
                } catch (Throwable $e) {
                    // не ломаем status из‑за sync
                }
            }
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
        // С панели всегда force: иначе чужой/зависший pending блокирует обновление
        $force = !isset($data['force']) || !empty($data['force']);

        agent_clear_stale_pending($pdo, 120);

        $desired = agent_desired_version();
        $explicitIds = is_array($ids) && count($ids) > 0;

        if ($explicitIds) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0));
            if (!$ids) {
                json_error('No nodes selected', 400);
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, name, agent_version, agent_commit, agent_update_available, status
                 FROM nodes WHERE id IN ($ph)"
            );
            $stmt->execute($ids);
            $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $nodes = $pdo->query(
                "SELECT id, name, agent_version, agent_commit, agent_update_available, status
                 FROM nodes WHERE status = 'online'"
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        // Явный список node_ids — доверяем UI (уже отфильтровал outdated+online).
        // Без списка + only_outdated — та же логика, что status.
        if ($onlyOutdated && $action === 'apply' && !$explicitIds) {
            $nodes = array_values(array_filter(
                $nodes,
                static fn(array $row): bool => agent_is_outdated($row, $desired)
            ));
        }

        if (!$explicitIds) {
            $nodes = array_values(array_filter(
                $nodes,
                static fn(array $row): bool => (string)($row['status'] ?? '') === 'online'
            ));
        }

        $queued = 0;
        $skipped = 0;
        $names = [];
        $skippedNames = [];
        foreach ($nodes as $node) {
            if (agent_queue_command($pdo, (int)$node['id'], $command, $force || agent_is_agent_cmd($command))) {
                $queued++;
                $names[] = (string)($node['name'] ?? $node['id']);
            } else {
                $skipped++;
                $skippedNames[] = (string)($node['name'] ?? $node['id']);
            }
        }

        if ($queued > 0) {
            $message = "Команда «{$command}» поставлена в очередь для {$queued} нод(ы): " . implode(', ', $names);
            if ($skipped > 0) {
                $message .= ". Пропущено ({$skipped}): " . implode(', ', $skippedNames);
            }
        } elseif ($skipped > 0) {
            $message = "Не удалось поставить «{$command}» (заняты другой pending-командой): " . implode(', ', $skippedNames);
        } elseif ($onlyOutdated) {
            $message = 'Нет устаревших онлайн-нод. Обновите статус агентов или нажмите «Проверить агенты».';
        } else {
            $message = 'Нет подходящих нод для команды.';
        }

        echo json_encode([
            'ok' => true,
            'command' => $command,
            'queued' => $queued,
            'skipped' => $skipped,
            'nodes' => $names,
            'skipped_nodes' => $skippedNames,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    json_error('Invalid action', 400);
} catch (Throwable $e) {
    json_exception($e, true);
}
