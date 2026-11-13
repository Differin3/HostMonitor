<?php
/**
 * Действия fail2ban из панели: разбан IP и правка параметров джейла.
 *
 * Вынесено из api/fail2ban.php, чтобы функции можно было проверять без
 * маршрутизации HTTP (CLI-харнесс) и подключать из других мест.
 */
declare(strict_types=1);

/**
 * Разбан IP из панели. Возвращает [http_status, payload].
 * Команда идёт в общий канал; ban будет снят агентом и подтверждён снимком.
 */
function f2b_action_unban(PDO $pdo, int $userId, array $data): array
{
    $banId = (int)($data['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, node_id, jail, ip, active, unban_requested_at FROM fail2ban_bans WHERE id = ?');
    $stmt->execute([$banId]);
    $ban = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ban) {
        return [404, ['error' => 'Ban not found']];
    }
    if ((int)$ban['active'] !== 1) {
        return [409, ['error' => 'Ban is not active']];
    }
    if (!empty($ban['unban_requested_at']) && strtotime((string)$ban['unban_requested_at']) > time() - 120) {
        return [409, ['error' => 'Разбан уже запрошен — дождитесь выполнения']];
    }
    $jail = (string)$ban['jail'];
    $ip = (string)$ban['ip'];
    if (!preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $jail) || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return [400, ['error' => 'Некорректные данные бана']];
    }
    $legacy = "fail2ban-unban {$jail} {$ip}";
    [$status, $payload] = command_issue(
        $pdo, ['user' => $userId], (int)$ban['node_id'], 'fail2ban-unban', null, 120, null, $legacy
    );
    if ($status >= 400) {
        return [$status, $payload];
    }
    $pdo->prepare('UPDATE fail2ban_bans SET unban_requested_at = ?, unban_requested_by = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), $userId, $banId]);
    return [200, ['ok' => true, 'command_id' => $payload['command_id'] ?? null]];
}

/**
 * Изменение параметров джейла. Рантайм применяет агент, персистентность —
 * запись jail.d/90-hostmonitor.local с проверкой `fail2ban-client -t`.
 */
function f2b_action_set_param(PDO $pdo, int $userId, array $data): array
{
    $nodeId = (int)($data['node_id'] ?? 0);
    $jail = trim((string)($data['jail'] ?? ''));
    $params = $data['params'] ?? null;
    if ($nodeId <= 0 || !preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $jail) || !is_array($params) || !$params) {
        return [400, ['error' => 'Требуются node_id, jail и params']];
    }
    $limits = [
        'maxretry' => [1, 100],
        'findtime' => [60, 86400],
        'bantime'  => [-1, 31536000],
    ];
    $clean = [];
    foreach ($params as $key => $value) {
        $key = strtolower((string)$key);
        if (!isset($limits[$key])) {
            return [400, ['error' => "Неподдерживаемый параметр: {$key}"]];
        }
        if (!is_numeric($value)) {
            return [400, ['error' => "{$key}: ожидается число"]];
        }
        $int = (int)$value;
        if ($int < $limits[$key][0] || $int > $limits[$key][1]) {
            return [400, ['error' => "{$key}: вне допустимого диапазона"]];
        }
        $clean[$key] = $int;
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM fail2ban_jails j
           JOIN fail2ban_status s ON s.node_id = j.node_id
          WHERE j.node_id = ? AND j.jail = ? AND s.installed = 1'
    );
    $stmt->execute([$nodeId, $jail]);
    if ($stmt->fetchColumn() === false) {
        return [404, ['error' => 'Джейл не найден у этой ноды']];
    }
    $pairs = [];
    foreach ($clean as $key => $value) {
        $pairs[] = "{$key}={$value}";
    }
    $legacy = "fail2ban-set {$jail} " . implode(' ', $pairs);
    [$status, $payload] = command_issue(
        $pdo, ['user' => $userId], $nodeId, 'fail2ban-set', implode(' ', $pairs), 120, null, $legacy
    );
    if ($status >= 400) {
        return [$status, $payload];
    }
    return [200, ['ok' => true, 'command_id' => $payload['command_id'] ?? null, 'params' => $clean]];
}
