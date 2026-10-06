<?php
/**
 * enrollment/create.php — выдача одноразовых кодов enrollment (админ).
 *
 * Код — 8 символов без «похожих на ошибка» букв (I/O/0/1), живёт 15 минут
 * (60–900 с), в БД хранится только sha256. Сам код отдаётся ровно один раз
 * в ответе на POST: повторно получить его нельзя, только заменить.
 *
 *   GET  /api/enrollment/create.php[?node_id=N] — активные и последние
 *        использованные коды (только метаданные, без хэшей);
 *   POST /api/enrollment/create.php — создать код.
 *        Тело: {"ttl_seconds": 900, "node_id": null}
 *        node_id заполнен — код привязан к ноде, ключ уйдёт именно в неё.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    error_log('[enrollment] db connection failed: ' . $e->getMessage());
    json_error('Service unavailable', 503);
}
$auth = require_api_auth($pdo);

// Выдавать коды может только админская сессия: код — это способ получить
// запись в nodes и привязать свой ключ, отдавать его агенту нельзя.
if ($auth['user'] === null || $auth['auth'] !== 'session') {
    json_error('Forbidden: admin session required', 403);
}
if (($_SESSION['role'] ?? 'admin') !== 'admin') {
    json_error('Forbidden: admin role required', 403);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/**
 * Генерация кода. Алфавит без I, O, 0, 1 — оператор вводит код руками,
 * похожие символы порождают «неверный код» на валидном коде.
 */
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$makeToken = static function () use ($alphabet): string {
    $token = '';
    for ($i = 0; $i < 8; $i++) {
        $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $token;
};

// ── GET: список кодов ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $nodeFilter = trim((string)($_GET['node_id'] ?? ''));
    $where = 'used_at IS NULL AND expires_at > NOW()';
    $params = [];
    if ($nodeFilter !== '') {
        $where .= ' AND node_id = ?';
        $params[] = (int)$nodeFilter;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.node_id, n.name AS node_name, t.ip_address, t.created_by,
                    t.created_at, t.expires_at
               FROM enrollment_tokens t
               LEFT JOIN nodes n ON n.id = t.node_id
              WHERE {$where}
              ORDER BY t.expires_at DESC
              LIMIT 100"
        );
        $stmt->execute($params);
        $active = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            "SELECT t.id, t.node_id, n.name AS node_name, t.ip_address, t.created_by,
                    t.created_at, t.expires_at, t.used_at
               FROM enrollment_tokens t
               LEFT JOIN nodes n ON n.id = t.node_id
              WHERE t.used_at IS NOT NULL
              ORDER BY t.used_at DESC
              LIMIT 20"
        );
        $stmt->execute();
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '42S02') {
            json_error('Enrollment is not configured', 503);
        }
        error_log('[enrollment] list failed: ' . $e->getMessage());
        json_error('Internal server error', 500);
    }

    echo json_encode([
        'active' => $active,
        'recent' => $recent,
        'ttl'    => ['min' => 60, 'max' => 900, 'default' => 900],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── POST: создание кода ─────────────────────────────────────────────────────
$raw = (string)file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    json_error('JSON body expected', 400);
}

$ttl = (int)($body['ttl_seconds'] ?? 900);
if ($ttl < 60) {
    $ttl = 60;
}
if ($ttl > 900) {
    $ttl = 900;
}

$nodeId = null;
if (isset($body['node_id']) && $body['node_id'] !== null && $body['node_id'] !== '') {
    $nodeId = (int)$body['node_id'];
    if ($nodeId <= 0) {
        json_error('node_id must be a positive integer', 400);
    }
    $stmt = $pdo->prepare('SELECT id, name FROM nodes WHERE id = ?');
    $stmt->execute([$nodeId]);
    $boundNode = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$boundNode) {
        json_error('Node not found', 404);
    }
}

try {
    // Поток живых кодов держим ограниченным: мусор из старых прогонов
    // подчищаем, а выдачу новых — режем, если их и так слишком много.
    $pdo->exec('DELETE FROM enrollment_tokens WHERE expires_at < NOW() - INTERVAL 1 DAY');

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM enrollment_tokens WHERE used_at IS NULL AND expires_at > NOW()'
    );
    $stmt->execute();
    if ((int)$stmt->fetchColumn() >= 50) {
        json_error('Too many active enrollment codes', 429);
    }

    $token = $makeToken();
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

    $stmt = $pdo->prepare(
        'INSERT INTO enrollment_tokens (token_hash, created_by, ip_address, expires_at, node_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        hash('sha256', $token),
        $auth['user'],
        client_ip() ?: null,
        $expiresAt,
        $nodeId,
    ]);
    $tokenRowId = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
    if ((string)$e->getCode() === '42S02') {
        json_error('Enrollment is not configured', 503);
    }
    error_log('[enrollment] create failed: ' . $e->getMessage());
    json_error('Internal server error', 500);
}

// Код возвращаем единственный раз — на этот ответ ссылается установщик.
echo json_encode([
    'id'          => $tokenRowId,
    'token'       => $token,
    'expires_at'  => $expiresAt,
    'ttl_seconds' => $ttl,
    'node_id'     => $nodeId,
    'node_name'   => isset($boundNode) ? (string)$boundNode['name'] : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
