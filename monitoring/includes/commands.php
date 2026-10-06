<?php
/**
 * Единый реестр и жизненный цикл команд агентов.
 *
 * Два канала потребления:
 *  - node_commands (новые агенты, Ed25519) — issue/pull/ack/report здесь;
 *  - legacy-поле nodes.last_command (старые агенты, Bearer) — та же issue
 *    пишет сюда, чтобы get-command в api/nodes.php продолжал работать.
 *
 * Опасные команды (reboot/shutdown) требуют подтверждения TOTP админа,
 * если не задан глобальный allaway ALLOW_DANGEROUS_COMMANDS=true.
 * TTL ограничен сверху максимальным значением для команды.
 */
declare(strict_types=1);

if (!defined('COMMAND_MAX_TTL_ABS')) {
    define('COMMAND_MAX_TTL_ABS', 3600);
}

if (!function_exists('command_registry')) {
    /**
     * Допустимые команды. Ключ — имя в last_command / node_commands.command.
     * TTL усекается до max_ttl; dangerous включает требование 2FA.
     */
    function command_registry(): array
    {
        return [
            'reboot'              => ['dangerous' => true,  'max_ttl' => 180,  'label' => 'Перезагрузка'],
            'shutdown'            => ['dangerous' => true,  'max_ttl' => 180,  'label' => 'Выключение'],
            'sync'                => ['dangerous' => false, 'max_ttl' => 3600, 'label' => 'Синхронизация'],
            'check-updates'       => ['dangerous' => false, 'max_ttl' => 300,  'label' => 'Проверить обновления'],
            'check-agent-update'  => ['dangerous' => false, 'max_ttl' => 300,  'label' => 'Проверить агента'],
            'check-agent-updates' => ['dangerous' => false, 'max_ttl' => 300,  'label' => 'Проверить агента'],
            'update-agent'        => ['dangerous' => false, 'max_ttl' => 1800, 'label' => 'Обновить агента'],
            'update-agent-force'  => ['dangerous' => false, 'max_ttl' => 1800, 'label' => 'Обновить агента (принудительно)'],
            'upgrade-agent'       => ['dangerous' => false, 'max_ttl' => 1800, 'label' => 'Обновить агента'],
        ];
    }
}

if (!function_exists('command_known')) {
    /**
     * Метаданные команды по имени либо null.
     */
    function command_known(string $command): ?array
    {
        $all = command_registry();
        return $all[$command] ?? null;
    }
}

if (!function_exists('command_resolve_ttl')) {
    /**
     * Нормализует TTL (секунды): минимум 5, максимум — max_ttl команды,
     * но не больше COMMAND_MAX_TTL_ABS.
     */
    function command_resolve_ttl(array $meta, $ttlSeconds, int $defaultSeconds): int
    {
        if ($ttlSeconds !== null) {
            $ttl = (int)$ttlSeconds;
        } else {
            $ttl = $defaultSeconds;
        }
        $cap = (int)$meta['max_ttl'];
        return max(5, min($ttl, $cap, COMMAND_MAX_TTL_ABS));
    }
}

if (!function_exists('command_dangerous_approval')) {
    /**
     * Проверка второго фактора для опасных команд.
     *
     * Проходит, если глобально разрешено ALLOW_DANGEROUS_COMMANDS=true
     * либо админ прислал корректный TOTP-код. Возвращает [ok, status, error].
     */
    function command_dangerous_approval(PDO $pdo, int $userId, ?string $totpCode): array
    {
        if (getenv('ALLOW_DANGEROUS_COMMANDS') === 'true') {
            return ['ok' => true, 'status' => 200, 'error' => ''];
        }
        if ($totpCode === null || trim($totpCode) === '') {
            return ['ok' => false, 'status' => 403,
                'error' => 'Опасная команда требует TOTP-кода: включите 2FA или ALLOW_DANGEROUS_COMMANDS=true'];
        }
        try {
            $stmt = $pdo->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            users_ensure_totp_columns($pdo);
            $stmt = $pdo->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$user || (int)($user['totp_enabled'] ?? 0) !== 1 || empty((string)($user['totp_secret'] ?? ''))) {
            return ['ok' => false, 'status' => 403,
                'error' => 'Опасная команда доступна только при включённом 2FA либо ALLOW_DANGEROUS_COMMANDS=true'];
        }
        if (!totp_verify((string)$user['totp_secret'], trim((string)$totpCode))) {
            return ['ok' => false, 'status' => 403, 'error' => 'Неверный TOTP-код'];
        }
        return ['ok' => true, 'status' => 200, 'error' => ''];
    }
}

if (!function_exists('command_issue')) {
    /**
     * Ставит команду: строка в node_commands + nodes.last_command для legacy.
     *
     * @return array [http_status, payload]
     */
    function command_issue(PDO $pdo, array $auth, int $nodeId, string $command, ?string $args, $ttlSeconds = null, ?string $totpCode = null): array
    {
        $meta = command_known($command);
        if ($meta === null) {
            return [400, ['error' => "Unknown command: {$command}"]];
        }

        $stmt = $pdo->prepare('SELECT id, node_token, public_key FROM nodes WHERE id = ?');
        $stmt->execute([$nodeId]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$node) {
            return [404, ['error' => 'Node not found']];
        }

        $ttl = command_resolve_ttl($meta, $ttlSeconds, (int)$meta['max_ttl']);
        $require2fa = $meta['dangerous'] ? 1 : 0;
        if ($meta['dangerous']) {
            $approval = command_dangerous_approval($pdo, (int)$auth['user'], $totpCode);
            if (!$approval['ok']) {
                return [$approval['status'], ['error' => $approval['error']]];
            }
            $require2fa = 0; // подтверждено кодом (или глобальным разрешением)
        }

        $argsJson = $args !== null && $args !== '' ? $args : null;

        try {
            $ins = $pdo->prepare(
                'INSERT INTO node_commands
                    (node_id, command, args, scope, dangerous, require_2fa, created_by, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $nodeId,
                $command,
                $argsJson,
                $meta['dangerous'] ? 'system:power' : 'commands:read',
                $meta['dangerous'] ? 1 : 0,
                $require2fa,
                $auth['user'] ?? null,
                date('Y-m-d H:i:s', time() + $ttl),
            ]);
            $commandId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            return [500, ['error' => 'Failed to store command']];
        }

        // legacy-канал: старые агенты читают nodes.last_command через get-command
        $upd = $pdo->prepare(
            "UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW() WHERE id = ?"
        );
        $upd->execute([$command, $nodeId]);

        try {
            $aud = $pdo->prepare(
                'INSERT INTO command_audit (command_id, node_id, command, action, actor_type, actor_id, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $aud->execute([$commandId, $nodeId, $command, 'issue', 'admin', $auth['user'] ?? null, 'ttl=' . $ttl]);
        } catch (Throwable $e) {
            // аудит не блокирует выдачу
        }

        return [201, [
            'command_id'   => $commandId,
            'node_id'      => $nodeId,
            'command'      => $command,
            'ttl_seconds'  => $ttl,
            'expires_at'   => date('Y-m-d H:i:s', time() + $ttl),
            'dangerous'    => (bool)$meta['dangerous'],
            'message'      => 'Command issued',
        ]];
    }
}

if (!function_exists('command_pull')) {
    /**
     * Агент забирает неистёкшую команду для своей ноды. Первая по id.
     *
     * @return array|null ['id','command','args','expires_at']
     */
    function command_pull(PDO $pdo, int $nodeId): ?array
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, command, args, command_status
                 FROM node_commands
                 WHERE node_id = ? AND acknowledged = 0 AND expires_at > NOW()
                 ORDER BY id ASC LIMIT 1'
            );
            $stmt->execute([$nodeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null; // таблицы node_commands нет → старый канал get-command
        }
        if (!$row) {
            return null;
        }
        $agency = $pdo->prepare('UPDATE node_commands SET acknowledged = 1, acknowledged_at = NOW() WHERE id = ?');
        $agency->execute([(int)$row['id']]);
        try {
            $aud = $pdo->prepare(
                'INSERT INTO command_audit (command_id, node_id, command, action, actor_type, detail)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $aud->execute([(int)$row['id'], $nodeId, $row['command'], 'ack', 'node', '']);
        } catch (Throwable $e) {
        }
        return [
            'id'      => (int)$row['id'],
            'command' => $row['command'],
            'args'    => $row['args'],
            'expires_at' => null,
        ];
    }
}

if (!function_exists('command_report')) {
    /**
     * Отчёт агента о выполнении команды.
     *
     * Обновляет node_commands (статус/результат) и, для совместимости со
     * старыми агентами, legacy-поля nodes. Защита от «затирания»: слот
     * команд не трогается, если отчёт пришёл по чужой/старой команде.
     *
     * @return array [http_status, payload]
     */
    function command_report(PDO $pdo, int $nodeId, string $command, string $status, string $result): array
    {
        $currentStmt = $pdo->prepare('SELECT last_command, command_status FROM nodes WHERE id = ?');
        $currentStmt->execute([$nodeId]);
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
        $currentCmd = trim((string)($current['last_command'] ?? ''));

        $sameCommand = ($command === '' || $currentCmd === '' || $command === $currentCmd);

        if ($status === 'failed' && $sameCommand) {
            if ($result !== '') {
                $pdo->prepare('UPDATE nodes SET command_status = ?, command_result = ? WHERE id = ?')
                    ->execute(['failed', $result, $nodeId]);
            } else {
                $pdo->prepare("UPDATE nodes SET command_status = 'failed' WHERE id = ?")->execute([$nodeId]);
            }
        } elseif ($status === 'completed' && $sameCommand) {
            $pdo->prepare(
                "UPDATE nodes SET command_status = 'completed', last_command = NULL, command_timestamp = NULL, command_result = NULL WHERE id = ?"
            )->execute([$nodeId]);
        } elseif ($status === 'completed' || $status === 'failed') {
            // Старый отчёт после постановки новой команды — игнор.
            return [200, [
                'status' => 'ok', 'success' => true,
                'message' => 'Stale command status ignored',
                'command_status' => $current['command_status'] ?? null,
                'ignored' => true,
            ]];
        } else {
            $pdo->prepare('UPDATE nodes SET command_status = ? WHERE id = ?')->execute([$status, $nodeId]);
        }

        // Обновляем строку node_commands, если это команда оттуда.
        try {
            $nc = $pdo->prepare(
                'UPDATE node_commands SET command_status = ?, result = ?, error = ? WHERE node_id = ? AND command = ? AND acknowledged = 1 ORDER BY id DESC LIMIT 1'
            );
            $nc->execute([$status === 'failed' ? 'failed' : 'completed', $result, $result, $nodeId, $currentCmd !== '' ? $currentCmd : $command]);
        } catch (Throwable $e) {
            // таблицы нет — legacy-путь
        }

        try {
            $aud = $pdo->prepare(
                'INSERT INTO command_audit (node_id, command, action, actor_type, detail)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $aud->execute([$nodeId, $command, 'report:' . $status, 'node', mb_substr($result, 0, 200)]);
        } catch (Throwable $e) {
        }

        return [200, [
            'status' => 'ok', 'success' => true,
            'message' => 'Command status updated',
            'command_status' => $status,
        ]];
    }
}