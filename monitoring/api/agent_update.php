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

function agent_git_short_commit(string $root): string
{
    // 1) git exec (может быть запрещён open_basedir / disable_functions)
    if (is_dir($root . '/.git')) {
        $out = [];
        $cmd = 'git -c safe.directory=' . escapeshellarg($root)
            . ' -C ' . escapeshellarg($root)
            . ' rev-parse --short HEAD 2>/dev/null';
        @exec($cmd, $out);
        $commit = trim((string)($out[0] ?? ''));
        if ($commit !== '' && preg_match('/^[0-9a-f]{4,40}$/i', $commit)) {
            return strtolower($commit);
        }
    }

    // 2) Чтение .git без exec
    $headFile = $root . '/.git/HEAD';
    if (is_file($headFile)) {
        $head = trim((string)@file_get_contents($headFile));
        if (str_starts_with($head, 'ref:')) {
            $ref = trim(substr($head, 4));
            $refFile = $root . '/.git/' . $ref;
            if (is_file($refFile)) {
                $hash = trim((string)@file_get_contents($refFile));
                if (preg_match('/^[0-9a-f]{7,40}$/i', $hash)) {
                    return strtolower(substr($hash, 0, 7));
                }
            }
            // packed-refs
            $packed = $root . '/.git/packed-refs';
            if (is_file($packed)) {
                $lines = @file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $line = trim((string)$line);
                    if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                        continue;
                    }
                    if (!preg_match('/^([0-9a-f]{7,40})\s+(\S+)$/i', $line, $m)) {
                        continue;
                    }
                    if ($m[2] === $ref) {
                        return strtolower(substr($m[1], 0, 7));
                    }
                }
            }
        } elseif (preg_match('/^[0-9a-f]{7,40}$/i', $head)) {
            return strtolower(substr($head, 0, 7));
        }
    }

    // 3) Явный файл agent/COMMIT (если панель без .git)
    foreach ([$root . '/agent/COMMIT', $root . '/COMMIT'] as $f) {
        if (!is_file($f)) {
            continue;
        }
        $raw = trim((string)@file_get_contents($f));
        $line = trim(explode("\n", $raw)[0] ?? '');
        if (preg_match('/^[0-9a-f]{4,40}$/i', $line)) {
            return strtolower(substr($line, 0, 7));
        }
    }

    return '';
}

function agent_desired_version(): array
{
    $version = '0.0.0';
    $root = dirname(__DIR__, 2);
    $verFile = $root . '/agent/VERSION';
    if (is_file($verFile)) {
        $raw = trim((string)file_get_contents($verFile));
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $version = trim((string)($lines[0] ?? '')) ?: '0.0.0';
        // вторая строка VERSION может быть short commit
        if (!empty($lines[1]) && preg_match('/^[0-9a-f]{4,40}$/i', trim($lines[1]))) {
            return [
                'desired_version' => $version,
                'desired_commit' => strtolower(substr(trim($lines[1]), 0, 7)),
            ];
        }
    }
    $commit = agent_git_short_commit($root);
    return ['desired_version' => $version, 'desired_commit' => $commit];
}

function agent_commit_same(string $a, string $b): bool
{
    $a = strtolower(trim($a));
    $b = strtolower(trim($b));
    if ($a === '' || $b === '') {
        return false;
    }
    // short vs short/full
    return str_starts_with($a, $b) || str_starts_with($b, $a);
}

/**
 * outdated | current | unknown
 * unknown = нет локального commit, нельзя считать «актуален».
 */
function agent_update_state(array $row, array $desired): string
{
    $local = trim((string)($row['agent_commit'] ?? ''));
    $remote = trim((string)($row['agent_remote_commit'] ?? ''));
    $ver = trim((string)($row['agent_version'] ?? ''));
    $desiredCommit = trim((string)($desired['desired_commit'] ?? ''));
    $desiredVer = trim((string)($desired['desired_version'] ?? ''));
    $flag = (int)($row['agent_update_available'] ?? 0);

    if ($flag === 1) {
        return 'outdated';
    }
    if ($desiredVer !== '' && $ver !== '' && $ver !== $desiredVer) {
        return 'outdated';
    }
    if ($desiredCommit !== '' && $local !== '' && !agent_commit_same($local, $desiredCommit)) {
        return 'outdated';
    }
    if ($remote !== '' && $local !== '' && !agent_commit_same($local, $remote)) {
        return 'outdated';
    }
    // Панель знает целевой commit, а агент его не сообщил — не «актуален»
    if ($desiredCommit !== '' && $local === '') {
        return 'outdated';
    }
    // Нет ни локального, ни remote commit — неизвестно (раньше ошибочно было «актуален»)
    if ($local === '' && $remote === '') {
        return 'unknown';
    }
    // Есть локальный commit и он совпадает с desired (или desired пуст, но remote совпал)
    if ($desiredCommit !== '' && $local !== '' && agent_commit_same($local, $desiredCommit)) {
        return 'current';
    }
    if ($desiredCommit === '' && $remote !== '' && $local !== '' && agent_commit_same($local, $remote)) {
        return 'current';
    }
    if ($desiredCommit === '' && $local !== '' && $remote === '') {
        // Только локальный commit без цели панели — не уверены
        return 'unknown';
    }
    return 'current';
}

function agent_is_outdated(array $row, array $desired): bool
{
    $state = agent_update_state($row, $desired);
    // unknown тоже предлагаем обновить: иначе ноды без commit вечно «актуальны»
    return $state === 'outdated' || $state === 'unknown';
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
    $result = trim((string)($row['command_result'] ?? ''));

    // Ошибка обновления/проверки: last_command мог уже очиститься — смотрим status + result
    if (in_array($status, ['failed', 'error'], true)) {
        if (agent_is_agent_cmd($cmd) || $result !== '') {
            return 'failed';
        }
    }
    if (!agent_is_agent_cmd($cmd)) {
        return 'idle';
    }
    // Зависший running/pending после claim — не крутим «Проверка» вечно
    // check: 3м (git fetch до 120с), update: 10м
    $age = agent_pending_age_sec($row);
    if (agent_is_check_cmd($cmd) && $age > 180) {
        return 'idle';
    }
    if (agent_is_update_cmd($cmd) && $age > 600) {
        return 'idle';
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
    $busy = in_array($status, ['pending', 'running', 'installing', 'in_progress'], true);

    // Протухший pending/running: check >3м, update >10м
    if ($busy && $pending !== '' && agent_is_update_cmd($pending) && $age > 600) {
        $force = true;
    } elseif ($busy && $pending !== '' && $age > 180 && !agent_is_update_cmd($pending)) {
        $force = true;
    }

    if (!$force && $busy && $pending !== '' && $pending !== $command) {
        // Команды обновления агента с панели всегда имеют приоритет над чужим слотом
        if (agent_is_agent_cmd($command)) {
            $force = true;
        } elseif ($age < 45) {
            return false;
        }
    }

    // Не перебивать свежий running/pending update-agent (идёт pull)
    if (
        agent_is_update_cmd($pending)
        && $busy
        && $age < 600
        && !agent_is_update_cmd($command)
    ) {
        return false;
    }

    // Не перебивать свежий running update-agent другой update-командой без force
    if (
        !$force
        && $status === 'running'
        && agent_is_update_cmd($pending)
        && $age < 600
        && $pending !== $command
    ) {
        return false;
    }

    $upd = $pdo->prepare(
        "UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW(), command_result = NULL WHERE id = ?"
    );
    $upd->execute([$command, $nodeId]);
    return true;
}

/**
 * Чистит зависшие pending/running.
 * update-agent держим дольше (git pull), иначе опрос status/вторая очередь сносит живые обновления.
 */
function agent_clear_stale_pending(PDO $pdo, int $maxAgeSec = 600): int
{
    $cleared = 0;
    try {
        // Короткие команды (check) — 3м (fetch может быть долгим)
        $stmt = $pdo->prepare(
            "UPDATE nodes
             SET last_command = NULL, command_status = NULL, command_timestamp = NULL
             WHERE command_status IN ('pending', 'running', 'installing', 'in_progress')
               AND last_command IN ('check-agent-update', 'check-agent-updates', 'check-updates')
               AND (command_timestamp IS NULL OR command_timestamp < (NOW() - INTERVAL 180 SECOND))"
        );
        $stmt->execute();
        $cleared += (int)$stmt->rowCount();

        // update-agent / upgrade-agent — не трогаем раньше 10 мин
        $stmtUpd = $pdo->prepare(
            "UPDATE nodes
             SET last_command = NULL, command_status = NULL, command_timestamp = NULL
             WHERE command_status IN ('pending', 'running', 'installing', 'in_progress')
               AND last_command IN ('update-agent', 'upgrade-agent')
               AND (command_timestamp IS NULL OR command_timestamp < (NOW() - INTERVAL 600 SECOND))"
        );
        $stmtUpd->execute();
        $cleared += (int)$stmtUpd->rowCount();

        // Прочие команды — maxAgeSec (по умолчанию 10м)
        $stmt2 = $pdo->prepare(
            "UPDATE nodes
             SET last_command = NULL, command_status = NULL, command_timestamp = NULL
             WHERE command_status IN ('pending', 'running', 'installing', 'in_progress')
               AND last_command IS NOT NULL
               AND last_command != ''
               AND last_command NOT IN (
                   'check-agent-update', 'check-agent-updates', 'check-updates',
                   'update-agent', 'upgrade-agent'
               )
               AND (command_timestamp IS NULL OR command_timestamp < (NOW() - INTERVAL ? SECOND))"
        );
        $stmt2->execute([max(90, (int)$maxAgeSec)]);
        $cleared += (int)$stmt2->rowCount();
        return $cleared;
    } catch (Throwable $e) {
        return $cleared;
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
        $ok = array_key_exists('ok', $data) ? !empty($data['ok']) : (trim((string)($data['error'] ?? '')) === '');

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

        if ($ok) {
            // Успех: снимаем слот (рестарт агента часто не успевает command-status)
            $clr = $pdo->prepare(
                "UPDATE nodes
                 SET last_command = NULL, command_status = NULL, command_timestamp = NULL
                 WHERE id = ?
                   AND last_command IN ('check-agent-update','check-agent-updates','update-agent','upgrade-agent')"
            );
            $clr->execute([$nodeId]);
        } else {
            // Ошибка: оставляем failed + текст в command_result для UI
            $fail = $pdo->prepare(
                "UPDATE nodes
                 SET command_status = 'failed',
                     command_result = COALESCE(NULLIF(?, ''), command_result)
                 WHERE id = ?
                   AND last_command IN ('check-agent-update','check-agent-updates','update-agent','upgrade-agent')"
            );
            $fail->execute([$msg !== '' ? $msg : 'Ошибка обновления агента', $nodeId]);
        }

        echo json_encode(['ok' => true, 'node_id' => $nodeId, 'reported_ok' => $ok], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($_SESSION['user_id'])) {
        json_error('Unauthorized', 401);
    }
    require_csrf();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if ($method === 'GET' && ($action === '' || $action === 'status')) {
        // Сразу снимаем залипшие check; update-agent не трогаем раньше 10м
        agent_clear_stale_pending($pdo, 600);
        // check старше 3м — UI не должен висеть (fetch timeout агента 120с)
        try {
            $pdo->exec(
                "UPDATE nodes
                 SET last_command = NULL, command_status = NULL, command_timestamp = NULL
                 WHERE last_command IN ('check-agent-update', 'check-agent-updates')
                   AND command_status IN ('pending', 'running')
                   AND (command_timestamp IS NULL OR command_timestamp < (NOW() - INTERVAL 180 SECOND))"
            );
        } catch (Throwable $e) {
            // ignore
        }
        $desired = agent_desired_version();
        if (function_exists('nodes_refresh_presence_status')) {
            nodes_refresh_presence_status($pdo);
        }
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
        $hbTimeout = function_exists('node_heartbeat_timeout_sec') ? node_heartbeat_timeout_sec() : 180;
        foreach ($rows as &$row) {
            // Не доверяем залипшему nodes.status — только last_seen агента
            $row['status'] = function_exists('node_presence_from_last_seen')
                ? node_presence_from_last_seen(
                    isset($row['last_seen']) ? (string)$row['last_seen'] : null,
                    $hbTimeout
                )
                : ((($row['status'] ?? '') === 'online') ? 'online' : 'offline');
            $state = agent_update_state($row, $desired);
            $flag = agent_is_outdated($row, $desired);
            $row['update_state'] = $state;
            $row['outdated'] = $flag;
            $row['agent_job'] = agent_job_state($row);
            $row['command_age_sec'] = agent_is_agent_cmd(trim((string)($row['last_command'] ?? '')))
                ? agent_pending_age_sec($row)
                : 0;
            // Для UI: если агент не прислал remote — показываем целевой commit панели
            if (trim((string)($row['agent_remote_commit'] ?? '')) === '' && ($desired['desired_commit'] ?? '') !== '') {
                $row['agent_remote_commit'] = $desired['desired_commit'];
            }
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

        agent_clear_stale_pending($pdo, 600);

        $desired = agent_desired_version();
        $explicitIds = is_array($ids) && count($ids) > 0;

        if ($explicitIds) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0));
            if (!$ids) {
                json_error('No nodes selected', 400);
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, name, agent_version, agent_commit, agent_update_available, status,
                        last_command, command_status, command_timestamp
                 FROM nodes WHERE id IN ($ph)"
            );
            $stmt->execute($ids);
            $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $nodes = $pdo->query(
                "SELECT id, name, agent_version, agent_commit, agent_update_available, status,
                        last_command, command_status, command_timestamp
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

        // Сбрасываем залипшее только у целевых нод (не трогаем чужие очереди)
        $targetIds = array_values(array_map(static fn($n) => (int)$n['id'], $nodes));
        if ($targetIds) {
            $ph = implode(',', array_fill(0, count($targetIds), '?'));
            try {
                // check: сбрасываем любой зависший check у цели
                if ($action === 'check') {
                    $clr = $pdo->prepare(
                        "UPDATE nodes
                         SET last_command = NULL, command_status = NULL, command_timestamp = NULL
                         WHERE id IN ($ph)
                           AND last_command IN ('check-agent-update','check-agent-updates')
                           AND command_status IN ('pending','running')"
                    );
                    $clr->execute($targetIds);
                }
                // apply: сбрасываем только stale (>10м) или check — не убиваем свежий update-agent
                if ($action === 'apply') {
                    $clr = $pdo->prepare(
                        "UPDATE nodes
                         SET last_command = NULL, command_status = NULL, command_timestamp = NULL
                         WHERE id IN ($ph)
                           AND (
                               last_command IN ('check-agent-update','check-agent-updates')
                               OR (
                                   last_command IN ('update-agent','upgrade-agent')
                                   AND command_status IN ('pending','running')
                                   AND (command_timestamp IS NULL OR command_timestamp < (NOW() - INTERVAL 600 SECOND))
                               )
                           )"
                    );
                    $clr->execute($targetIds);
                }
            } catch (Throwable $e) {
                // ignore
            }
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
