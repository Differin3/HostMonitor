<?php
/**
 * enroll.php — обмен одноразовым кодом enrollment на Ed25519-ключ ноды.
 *
 * Публичный endpoint: сессии и CSRF здесь нет, единственная защита —
 * одноразовый 8-символьный код (sha256 в БД), окно жизни 15 минут и
 * rate limit 10 попыток / 5 минут на IP.
 *
 * Ответ никогда не содержит секретов: узел уже держит свой приватный ключ.
 *
 * Тело запроса (JSON):
 *   token      — выданный администратором код, 8 символов;
 *   public_key — SPKI PEM публичного Ed25519-ключа (openssl genpkey);
 *   name       — имя новой ноды (нужно, если код не привязан к ноде).
 *
 *   POST /api/enroll.php
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Rate limit: 10 попыток / 5 минут на IP ──────────────────────────────────
// Считаются все попытки, а не только неудачные: код одноразовый, и попытки
// перебора должны упираться в лимит так же, как и неверные коды.
$clientIp = client_ip() ?: '0.0.0.0';
$rlFile = __DIR__ . '/../data/enroll_attempts.json';
$rlMax = 10;
$rlWindow = 300;
$attempts = [];
if (is_file($rlFile)) {
    $decoded = json_decode((string)file_get_contents($rlFile), true);
    if (is_array($decoded)) {
        $attempts = $decoded;
    }
}
$now = time();
$ipAttempts = array_values(array_filter(
    (array)($attempts[$clientIp] ?? []),
    static function ($ts) use ($now, $rlWindow) {
        return (int)$ts > $now - $rlWindow;
    }
));
if (count($ipAttempts) >= $rlMax) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many enrollment attempts, retry later']);
    exit;
}
$ipAttempts[] = $now;
$attempts[$clientIp] = $ipAttempts;
// Не раздуваем файл бесконечно: выкидываем записи, которые уже вышли из окна.
if (count($attempts) > 500) {
    foreach ($attempts as $ip => $stamps) {
        $alive = array_filter((array)$stamps, static function ($ts) use ($now, $rlWindow) {
            return (int)$ts > $now - $rlWindow;
        });
        if ($alive === []) {
            unset($attempts[$ip]);
        }
    }
}
@file_put_contents($rlFile, json_encode($attempts), LOCK_EX);

// ── Разбор тела ─────────────────────────────────────────────────────────────
$raw = (string)file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    json_error('JSON body expected', 400);
}

$token = trim((string)($body['token'] ?? ''));
$pubPem = (string)($body['public_key'] ?? '');
$requestedName = trim((string)($body['name'] ?? ''));
// Без /u: байтовое вырезание контрольных символов не падает на invalid UTF-8.
$requestedName = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $requestedName) ?? '');
if ($requestedName !== '') {
    // Колонка utf8mb4: имя вне таблицы уронит INSERT, а mb_substr есть не
    // везде, поэтому валидируем UTF-8 и режем регуляркой по символам.
    if (preg_match('//u', $requestedName) !== 1) {
        json_error('name must be valid UTF-8', 400);
    }
    if (preg_match('/^(.{0,100})/us', $requestedName, $nameMatch) === 1) {
        $requestedName = $nameMatch[1];
    }
}

// ── Валидация формата до похода в БД ────────────────────────────────────────
// Та же алфавит/длина, что генерирует enrollment/create.php: лучше отказать
// сразу, чем тратить запрос на заведомо мусорный код.
$tokenAlphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
if (strlen($token) !== 8
    || preg_match('/^[' . $tokenAlphabet . ']+$/', $token) !== 1) {
    json_error('Invalid or expired enrollment code', 401);
}

$rawKey = agent_raw_public_key($pubPem);
if ($rawKey === null) {
    json_error('public_key must be an Ed25519 SPKI PEM', 400);
}
$fingerprint = 'sha256:' . substr(hash('sha256', $rawKey), 0, 32);

// ── БД ──────────────────────────────────────────────────────────────────────
try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    error_log('[enroll] db connection failed: ' . $e->getMessage());
    json_error('Service unavailable', 503);
}

/**
 * Аудит попытки enrollment в login_attempts (username='__enroll__').
 * Отдельной таблицы под это не заводим: лимит выше уже файловый, здесь —
 * только след для расследования. Ошибки молча глотаем, таблица может
 * отсутствовать, если миграция ещё не применена.
 */
$auditEnroll = static function (PDO $pdo, bool $success, string $detail) use ($clientIp): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (username, ip_address, success, message)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['__enroll__', $clientIp, $success ? 1 : 0, $detail]);
    } catch (Throwable $e) {
        error_log('[enroll] audit failed: ' . $e->getMessage());
    }
};

try {
    $pdo->beginTransaction();

    // Один и тот же код в двух параллельных запросах должен сработать один
    // раз — блокируем строку токена до конца транзакции.
    $stmt = $pdo->prepare(
        'SELECT id, node_id, expires_at, used_at
           FROM enrollment_tokens
          WHERE token_hash = ?
          LIMIT 1
          FOR UPDATE'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Одинаковый ответ на «нет такого», «истёк» и «уже использован» — иначе
    // по тексту ошибки можно перебирать живые коды.
    if (!$row || $row['used_at'] !== null || strtotime((string)$row['expires_at']) <= $now) {
        $pdo->rollBack();
        $auditEnroll($pdo, false, 'invalid_or_expired_token');
        json_error('Invalid or expired enrollment code', 401);
    }

    $existingNodeId = $row['node_id'] !== null ? (int)$row['node_id'] : null;

    if ($existingNodeId !== null) {
        // Код выдан под конкретную ноду — ключ записываем в неё.
        $stmt = $pdo->prepare('SELECT id, name, public_key, scopes FROM nodes WHERE id = ? FOR UPDATE');
        $stmt->execute([$existingNodeId]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$node) {
            $pdo->rollBack();
            $auditEnroll($pdo, false, 'bound_node_missing');
            json_error('Invalid or expired enrollment code', 401);
        }
        $oldFp = $node['public_key'] ? agent_raw_public_key((string)$node['public_key']) : null;
        $oldFp = $oldFp ? 'sha256:' . substr(hash('sha256', $oldFp), 0, 32) : null;
        $isRotate = $oldFp !== null && $oldFp !== $fingerprint;

        // MySQL при последовательных присвоениях видит уже новое значение,
        // поэтому key_rotated_at считаем в PHP: NULL при первой выдаче,
        // NOW() при реальной смене ключа.
        // Права ноды при перерегистрации не сбрасываем: администратор мог
        // выдать ноде только чтение, и enrollment не должен её развязывать.
        $scopesJson = $node['scopes'] !== null && $node['scopes'] !== ''
            ? (string)$node['scopes']
            : json_encode(agent_default_scopes());

        $stmt = $pdo->prepare(
            'UPDATE nodes
                SET public_key = ?,
                    scopes = ?,
                    enrolled_at = COALESCE(enrolled_at, NOW()),
                    key_rotated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $pubPem,
            $scopesJson,
            $oldFp !== null ? date('Y-m-d H:i:s') : null,
            $existingNodeId,
        ]);
        $nodeId = $existingNodeId;
        $nodeName = (string)$node['name'];
    } else {
        // Код не привязан — создаём новую ноду.
        $name = $requestedName !== '' ? $requestedName : 'agent-' . strtoupper(bin2hex(random_bytes(3)));
        $oldFp = null;
        $isRotate = false;
        $scopesJson = json_encode(agent_default_scopes());

        $stmt = $pdo->prepare('SELECT id FROM nodes WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            $auditEnroll($pdo, false, 'duplicate_name');
            json_error('Node name already exists', 409);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO nodes (name, host, port, status, node_token, public_key, scopes, enrolled_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?, NOW())'
        );
        $stmt->execute([
            $name,
            $clientIp === '0.0.0.0' ? 'unknown' : $clientIp,
            22,
            'offline',
            $pubPem,
            $scopesJson,
        ]);
        $nodeId = (int)$pdo->lastInsertId();
        $nodeName = $name;
    }

    // Фиксируем, чей ключ сменился на чей (oldFp = NULL при первой выдаче).
    $stmt = $pdo->prepare(
        'INSERT INTO key_rotation_log (node_id, old_key_fp, new_key_fp, actor_type, reason)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $nodeId,
        $oldFp,
        $fingerprint,
        'enroll',
        $isRotate ? 'rotate' : 'enroll',
    ]);

    // Код одноразовый: гасим и запоминаем, какая нода им воспользовалась.
    $stmt = $pdo->prepare(
        'UPDATE enrollment_tokens SET used_at = NOW(), node_id = ? WHERE id = ?'
    );
    $stmt->execute([$nodeId, (int)$row['id']]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // 42S02 — таблицы миграции ещё нет: это не ошибка кода, а неготовность.
    if ((string)$e->getCode() === '42S02') {
        error_log('[enroll] migration not applied: ' . $e->getMessage());
        json_error('Enrollment is not configured', 503);
    }
    error_log('[enroll] failed: ' . $e->getMessage());
    json_error('Internal server error', 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[enroll] failed: ' . $e->getMessage());
    json_error('Internal server error', 500);
}

$auditEnroll($pdo, true, 'enrolled:' . $nodeId);

echo json_encode([
    'node_id'     => $nodeId,
    'name'        => $nodeName,
    'fingerprint' => $fingerprint,
    'scopes'      => agent_parse_scopes($scopesJson),
    'key_type'    => 'ed25519',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
