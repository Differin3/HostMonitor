<?php
/**
 * Managed rules fail2ban: желаемое состояние в БД и рендер файла.
 *
 * Панель — источник истины. Агент присылает хеш и содержимое
 * 90-hostmonitor.local, панель сравнивает их с рендером из БД и показывает
 * дрейф, если файл правили руками. Рендер обязан совпадать по формату с
 * agent/fail2ban.py::_f2b_managed_write, иначе каждая синхронизация давала бы
 * ложный дрейф.
 */
declare(strict_types=1);

if (!function_exists('f2b_managed_ensure')) {
    function f2b_managed_ensure(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS fail2ban_jail_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    node_id INT NOT NULL,
                    jail VARCHAR(64) NOT NULL,
                    enabled TINYINT(1) NOT NULL DEFAULT 1,
                    maxretry INT NULL,
                    findtime INT NULL,
                    bantime INT NULL,
                    ignoreip TEXT NULL,
                    updated_by INT NULL,
                    updated_at DATETIME NULL,
                    UNIQUE KEY uq_node_jail (node_id, jail),
                    KEY idx_node (node_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS fail2ban_managed_state (
                    node_id INT PRIMARY KEY,
                    file_path VARCHAR(255) NOT NULL DEFAULT \'\',
                    file_hash CHAR(64) NULL,
                    file_content MEDIUMTEXT NULL,
                    desired_hash CHAR(64) NULL,
                    drift TINYINT(1) NOT NULL DEFAULT 0,
                    reported_at DATETIME NULL,
                    applied_at DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable $e) {
            // Нет прав на DDL — таблицы должны быть созданы миграцией.
        }
    }
}

if (!function_exists('f2b_settings_for_node')) {
    function f2b_settings_for_node(PDO $pdo, int $nodeId): array
    {
        f2b_managed_ensure($pdo);
        $stmt = $pdo->prepare('SELECT * FROM fail2ban_jail_settings WHERE node_id = ? ORDER BY jail');
        $stmt->execute([$nodeId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['jail']] = $row;
        }
        return $out;
    }
}

if (!function_exists('f2b_settings_all')) {
    function f2b_settings_all(PDO $pdo): array
    {
        f2b_managed_ensure($pdo);
        try {
            return $pdo->query('SELECT * FROM fail2ban_jail_settings ORDER BY node_id, jail')
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('f2b_ignoreip_normalize')) {
    /**
     * Проверяет список IP/CIDR через пробел или запятую.
     * Возвращает нормализованную строку или null, если что-то невалидно.
     */
    function f2b_ignoreip_normalize($raw): ?string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return '';
        }
        $tokens = preg_split('/[\s,]+/', $raw) ?: [];
        $out = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            [$ip, $mask] = array_pad(explode('/', $token, 2), 2, null);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return null;
            }
            $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            if ($mask !== null) {
                if (!ctype_digit($mask)) {
                    return null;
                }
                $maskInt = (int)$mask;
                if ($maskInt < 0 || $maskInt > ($isV4 ? 32 : 128)) {
                    return null;
                }
                $out[] = $ip . '/' . $maskInt;
            } else {
                $out[] = $ip;
            }
        }
        return implode(' ', $out);
    }
}

if (!function_exists('f2b_render_managed')) {
    /**
     * Полный текст managed-файла из строк fail2ban_jail_settings.
     *
     * Пустая секция (enabled=1 и нет параметров) не пишется: джейл просто
     * наследует настройки из своих штатных конфигов.
     */
    function f2b_render_managed(array $settings): string
    {
        $rows = [];
        foreach ($settings as $row) {
            $jail = trim((string)($row['jail'] ?? ''));
            if ($jail === '') {
                continue;
            }
            $params = [];
            if ((int)($row['enabled'] ?? 1) === 0) {
                $params['enabled'] = 'false';
            }
            foreach (['maxretry', 'findtime', 'bantime'] as $key) {
                if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
                    $params[$key] = (string)(int)$row[$key];
                }
            }
            $ignoreip = trim((string)($row['ignoreip'] ?? ''));
            if ($ignoreip !== '') {
                $params['ignoreip'] = $ignoreip;
            }
            if (!$params) {
                continue;
            }
            $rows[$jail] = $params;
        }
        if (!$rows) {
            return '';
        }
        ksort($rows, SORT_STRING);

        $lines = [
            '# Managed by HostMonitor. Файл перезаписывается панелью,',
            '# ручные правки будут потеряны при следующем изменении.',
            '',
        ];
        foreach ($rows as $jail => $params) {
            $lines[] = '[' . $jail . ']';
            foreach ($params as $key => $value) {
                $lines[] = $key . ' = ' . $value;
            }
            $lines[] = '';
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('f2b_managed_desired')) {
    /**
     * @return array{text:string,hash:string,jails:list<string>}
     */
    function f2b_managed_desired(PDO $pdo, int $nodeId): array
    {
        $settings = f2b_settings_for_node($pdo, $nodeId);
        $text = f2b_render_managed($settings);
        $jails = [];
        foreach (array_keys($settings) as $jail) {
            $jails[] = (string)$jail;
        }
        return [
            'text' => $text,
            'hash' => hash('sha256', $text),
            'jails' => $jails,
        ];
    }
}

if (!function_exists('f2b_state_for_node')) {
    function f2b_state_for_node(PDO $pdo, int $nodeId): ?array
    {
        f2b_managed_ensure($pdo);
        try {
            $stmt = $pdo->prepare('SELECT * FROM fail2ban_managed_state WHERE node_id = ?');
            $stmt->execute([$nodeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('f2b_state_save')) {
    /**
     * Сохраняет присланный агентом снимок файла и пересчитывает дрейф.
     *
     * @param array $managed path/exists/hash/content
     */
    function f2b_state_save(PDO $pdo, int $nodeId, array $managed, string $desiredHash): void
    {
        f2b_managed_ensure($pdo);
        $path = substr((string)($managed['path'] ?? ''), 0, 255);
        $exists = !empty($managed['exists']);
        $hash = null;
        $content = null;
        if ($exists) {
            $hash = is_string($managed['hash'] ?? null) ? substr($managed['hash'], 0, 64) : null;
            $content = is_string($managed['content'] ?? null) ? substr($managed['content'], 0, 65535) : null;
        }
        // Дрейф только когда панель чем-то управляет: пустой desired — не дрейф.
        $drift = ($desiredHash !== '' && $hash !== null && $hash !== $desiredHash) ? 1 : 0;
        // Файл совпал с рендером — фиксируем момент успешного применения.
        $appliedAt = ($desiredHash !== '' && $hash === $desiredHash) ? date('Y-m-d H:i:s') : null;

        $stmt = $pdo->prepare(
            'INSERT INTO fail2ban_managed_state
                (node_id, file_path, file_hash, file_content, desired_hash, drift, reported_at, applied_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), file_hash = VALUES(file_hash),
                 file_content = VALUES(file_content), desired_hash = VALUES(desired_hash),
                 drift = VALUES(drift), reported_at = VALUES(reported_at),
                 applied_at = COALESCE(VALUES(applied_at), applied_at)'
        );
        $stmt->execute([
            $nodeId, $path, $hash, $content, $desiredHash !== '' ? $desiredHash : null,
            $drift, date('Y-m-d H:i:s'), $appliedAt,
        ]);
    }
}

if (!function_exists('f2b_settings_save')) {
    function f2b_settings_save(PDO $pdo, int $nodeId, string $jail, array $values, int $userId): void
    {
        f2b_managed_ensure($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO fail2ban_jail_settings
                (node_id, jail, enabled, maxretry, findtime, bantime, ignoreip, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), maxretry = VALUES(maxretry),
                 findtime = VALUES(findtime), bantime = VALUES(bantime), ignoreip = VALUES(ignoreip),
                 updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            $nodeId,
            $jail,
            !empty($values['enabled']) ? 1 : 0,
            $values['maxretry'] ?? null,
            $values['findtime'] ?? null,
            $values['bantime'] ?? null,
            ($values['ignoreip'] ?? '') !== '' ? $values['ignoreip'] : null,
            $userId > 0 ? $userId : null,
            date('Y-m-d H:i:s'),
        ]);
    }
}

if (!function_exists('f2b_settings_delete')) {
    function f2b_settings_delete(PDO $pdo, int $nodeId, string $jail): void
    {
        f2b_managed_ensure($pdo);
        $pdo->prepare('DELETE FROM fail2ban_jail_settings WHERE node_id = ? AND jail = ?')->execute([$nodeId, $jail]);
    }
}

if (!function_exists('f2b_diff_lines')) {
    /**
     * Построчный diff (LCS): ' - ' удалено, ' + ' добавлено, '   ' совпало.
     * Файлы маленькие, поэтому O(n*m) не проблема.
     */
    function f2b_diff_lines(string $desired, string $actual): array
    {
        $a = $desired === '' ? [] : explode("\n", rtrim($desired, "\n"));
        $b = $actual === '' ? [] : explode("\n", rtrim($actual, "\n"));
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['op' => ' ', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['op' => '-', 'text' => $a[$i]];
                $i++;
            } else {
                $out[] = ['op' => '+', 'text' => $b[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $out[] = ['op' => '-', 'text' => $a[$i]];
            $i++;
        }
        while ($j < $m) {
            $out[] = ['op' => '+', 'text' => $b[$j]];
            $j++;
        }
        return $out;
    }
}
