<?php
/**
 * E2E-тест enrollment: выдача кода админом → обмен на Ed25519-ключ →
 * подписанный запрос этим ключом.
 *
 * Запуск (нужна MySQL, как и для интеграционной части test_auth.php):
 *   TEST_DB_DSN='mysql:host=127.0.0.1;port=13306;dbname=monitoring' \
 *   TEST_DB_USER=root TEST_DB_PASS=test php tests/test_enroll.php
 *
 * Без TEST_DB_DSN тест помечается как skip и выходит с кодом 0 — в CI MySQL нет.
 *
 * Что проверяется:
 *   1. админская сессия + CSRF → код выдан, формат 8 символов, в ответе один раз;
 *   2. код обменивается на ключ, в ответе нет секретов;
 *   3. повторное/чужое использование кода → 401;
 *   4. не-Ed25519 ключ → 400;
 *   5. подписанный запрос проходит по HTTP, но агенту недоступна выдача кодов;
 *   6. код, привязанный к ноде, пишет ключ именно в неё и не сбрасывает scopes;
 *   7. rate limit 10/5 мин на IP → 429.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/monitoring/includes/helpers.php';
require_once __DIR__ . '/lib/test_db.php';

$passed = 0;
$failed = 0;
$skipped = 0;

function check(bool $ok, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function skip(string $name, string $why): void
{
    global $skipped;
    $skipped++;
    echo "  skip {$name} — {$why}\n";
}

function section(string $title): void
{
    echo "\n== {$title} ==\n";
}

/** HTTP через curl CLI: ['status'=>int, 'body'=>string, 'headers'=>string]. */
function http_req(string $method, string $url, array $opts = []): array
{
    $cmd = ['curl', '-s', '-S', '--max-time', '15', '-o', '-', '-D', '-', '-X', $method];
    if (isset($opts['cookie'])) {
        $cmd[] = '-b';
        $cmd[] = (string)$opts['cookie'];
    }
    if (isset($opts['cookie_jar'])) {
        $cmd[] = '-c';
        $cmd[] = (string)$opts['cookie_jar'];
    }
    foreach ((array)($opts['headers'] ?? []) as $h) {
        $cmd[] = '-H';
        $cmd[] = (string)$h;
    }
    if (array_key_exists('body', $opts)) {
        $cmd[] = '--data-binary';
        $cmd[] = (string)$opts['body'];
    }
    $cmd[] = $url;

    $proc = proc_open(
        array_map('strval', $cmd),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => '', 'headers' => ''];
    }
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $parts = preg_split('/\r?\n\r?\n/', $out, 2);
    $headers = $parts[0] ?? '';
    $body = $parts[1] ?? '';
    $status = 0;
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headers, $m)) {
        $status = (int)$m[1];
    }
    return ['status' => $status, 'body' => $body, 'headers' => $headers, 'err' => $err];
}

/** Заголовки Ed25519-подписи для запроса (та же каноника, что на сервере). */
function sign_request(string $secretKey, string $method, string $path, string $timestamp, string $body, int $nodeId): array
{
    $message = $method . "\n" . $path . "\n" . $timestamp . "\n" . $body;
    $sig = base64_encode(sodium_crypto_sign_detached($message, $secretKey));
    return [
        'X-Agent-Node-Id: ' . $nodeId,
        'X-Agent-Timestamp: ' . $timestamp,
        'X-Agent-Signature: ' . $sig,
    ];
}

/** SPKI-PEM из сырого Ed25519-ключа (превращение ровно как в agent_auth). */
function raw_to_spki_pem(string $raw): string
{
    $der = hex2bin('302a300506032b6570032100') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----";
}

$dbEnv = test_db_env();
if ($dbEnv === null) {
    section('Enrollment E2E');
    skip('весь прогон', 'нет TEST_DB_DSN — нужен MySQL (см. шапку файла)');
    echo "\nПройдено: {$passed}, провалено: {$failed}, пропущено: {$skipped}\n";
    exit(0);
}
$dbHost = $dbEnv['host'];
$dbPort = $dbEnv['port'];
$dbName = $dbEnv['name'];
$dbUser = $dbEnv['user'];
$dbPass = $dbEnv['pass'];

$root = dirname(__DIR__);
$serverLog = tempnam(sys_get_temp_dir(), 'enroll_srv');
$cookieJar = tempnam(sys_get_temp_dir(), 'enroll_cookie');
$rlFile = $root . '/monitoring/data/enroll_attempts.json';
$rlBackup = is_file($rlFile) ? (string)file_get_contents($rlFile) : null;

$pdo = test_db_connect();
test_db_ensure_schema($pdo);

$adminUser = 'enroll-test-admin';
$adminPass = 'Enroll-Test-P@ssw0rd';
$testNodeName = 'enroll-e2e-node';

// Чистим только то, что создали сами: тест могут запустить и на базе, где уже
// есть живые ноды и история ротации.
$track = ['node_ids' => [], 'token_ids' => []];

$cleanup = static function () use ($pdo, $adminUser, &$track, $rlFile, $rlBackup, $cookieJar, $serverLog): void {
    if ($track['node_ids']) {
        $in = implode(',', array_map('intval', $track['node_ids']));
        $pdo->exec("DELETE FROM key_rotation_log WHERE node_id IN ({$in})");
        $pdo->exec("DELETE FROM enrollment_tokens WHERE node_id IN ({$in})");
        $pdo->exec("DELETE FROM agent_requests_log WHERE node_id IN ({$in})");
        $pdo->exec("DELETE FROM node_commands WHERE node_id IN ({$in})");
        $pdo->exec("DELETE FROM nodes WHERE id IN ({$in})");
    }
    if ($track['token_ids']) {
        $in = implode(',', array_map('intval', $track['token_ids']));
        $pdo->exec("DELETE FROM enrollment_tokens WHERE id IN ({$in})");
    }
    $stmt = $pdo->prepare('DELETE FROM users WHERE username = ?');
    $stmt->execute([$adminUser]);
    $pdo->exec("DELETE FROM login_attempts WHERE username = '__enroll__'");
    if ($rlBackup !== null) {
        file_put_contents($rlFile, $rlBackup, LOCK_EX);
    } elseif (is_file($rlFile)) {
        unlink($rlFile);
    }
    @unlink($cookieJar);
    @unlink($serverLog);
};

// ── Поднимаем панель на свободном порту ─────────────────────────────────────
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = 0;
if ($sock) {
    $name = stream_socket_get_name($sock, false);
    $port = (int)substr((string)$name, strrpos((string)$name, ':') + 1);
    fclose($sock);
}
if ($port === 0) {
    echo "  FAIL не удалось выделить порт для тестового сервера\n";
    exit(1);
}
$base = 'http://127.0.0.1:' . $port;

$env = array_merge(getenv(), [
    'DB_HOST' => $dbHost,
    'DB_PORT' => $dbPort,
    'DB_NAME' => $dbName,
    'DB_USER' => $dbUser,
    'DB_PASSWORD' => $dbPass,
]);
$srvCmd = sprintf(
    '%s -S 127.0.0.1:%d -t %s',
    escapeshellarg(PHP_BINARY),
    $port,
    escapeshellarg($root . '/monitoring')
);
$server = proc_open(
    $srvCmd,
    [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $pipes,
    $root,
    $env
);

$ready = false;
for ($i = 0; $i < 50; $i++) {
    usleep(100000);
    $probe = http_req('GET', $base . '/login.php');
    // 302 — тоже признак жизни: login.php редиректит уже вошедшего.
    if ($probe['status'] === 200 || $probe['status'] === 302) {
        $ready = true;
        break;
    }
}
if (!$ready) {
    echo "  FAIL тестовый сервер не поднялся на {$base}\n";
    echo (string)file_get_contents($serverLog) . "\n";
    $cleanup();
    exit(1);
}

register_shutdown_function(static function () use (&$server, $cleanup) {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $cleanup();
});

section('Схема и админская сессия');

// Пользователь для входа.
$stmt = $pdo->prepare('DELETE FROM users WHERE username = ?');
$stmt->execute([$adminUser]);
$stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
$stmt->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT)]);
$adminId = (int)$pdo->lastInsertId();

$login = http_req('POST', $base . '/login.php', [
    'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    'body' => http_build_query(['username' => $adminUser, 'password' => $adminPass]),
    'cookie_jar' => $cookieJar,
]);
check(in_array($login['status'], [302, 200], true), 'вход админа принят', 'status=' . $login['status']);

$index = http_req('GET', $base . '/index.php', ['cookie' => $cookieJar]);
$csrf = '';
if (preg_match('/<meta name="csrf-token" content="([0-9a-f]+)"/', $index['body'], $m)) {
    $csrf = $m[1];
}
check($csrf !== '', 'CSRF-токен из страницы получен', 'status=' . $index['status']);

section('Выдача кода админом');

$mkHeaders = static function () use ($csrf): array {
    return [
        'Content-Type: application/json',
        'X-CSRF-Token: ' . $csrf,
    ];
};

$resp = http_req('POST', $base . '/api/enrollment/create.php', [
    'headers' => $mkHeaders(),
    'body' => json_encode(['ttl_seconds' => 600]),
    'cookie' => $cookieJar,
]);
$created = json_decode($resp['body'], true);
if (isset($created['id'])) {
    $track['token_ids'][] = (int)$created['id'];
}
check($resp['status'] === 200, 'POST create → 200', 'status=' . $resp['status'] . ' body=' . substr($resp['body'], 0, 200));
check(is_array($created) && isset($created['token']), 'в ответе есть token');
$token = (string)($created['token'] ?? '');
check(strlen($token) === 8 && preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', $token) === 1, 'формат кода: 8 символов, без I/O/0/1', $token);
check(is_array($created) && $created['expires_at'] !== null, 'срок действия указан');

// Без CSRF код не выдают.
$noCsrf = http_req('POST', $base . '/api/enrollment/create.php', [
    'headers' => ['Content-Type: application/json'],
    'body' => '{}',
    'cookie' => $cookieJar,
]);
check($noCsrf['status'] === 403, 'POST без CSRF → 403', 'status=' . $noCsrf['status']);

// Без сессии (агент или гость) код не выдают.
$noSession = http_req('POST', $base . '/api/enrollment/create.php', [
    'headers' => ['Content-Type: application/json'],
    'body' => '{}',
]);
check($noSession['status'] === 401 || $noSession['status'] === 403, 'POST без сессии → 401/403', 'status=' . $noSession['status']);

// GET списка — метаданные без хэшей.
$list = http_req('GET', $base . '/api/enrollment/create.php', ['cookie' => $cookieJar]);
$listData = json_decode($list['body'], true);
check($list['status'] === 200 && is_array($listData), 'GET списка кодов → 200', 'status=' . $list['status']);
check(
    $list['status'] === 200 && strpos($list['body'], 'token_hash') === false,
    'список не отдаёт sha256-хэши'
);

section('Обмен кода на ключ');

$keypair = sodium_crypto_sign_keypair();
$pubPem = raw_to_spki_pem(sodium_crypto_sign_publickey($keypair));
$secretKey = sodium_crypto_sign_secretkey($keypair);
$fingerprint = 'sha256:' . substr(hash('sha256', sodium_crypto_sign_publickey($keypair)), 0, 32);

$enrollBody = json_encode([
    'token' => $token,
    'public_key' => $pubPem,
    'name' => $testNodeName,
]);
$enroll = http_req('POST', $base . '/api/enroll.php', [
    'headers' => ['Content-Type: application/json'],
    'body' => $enrollBody,
]);
$enrolled = json_decode($enroll['body'], true);
check($enroll['status'] === 200, 'обмен кода → 200', 'status=' . $enroll['status'] . ' body=' . substr($enroll['body'], 0, 240));
check(is_array($enrolled) && isset($enrolled['node_id']), 'node_id в ответе', $enroll['body']);
$nodeId = (int)($enrolled['node_id'] ?? 0);
if ($nodeId > 0) {
    $track['node_ids'][] = $nodeId;
}
check($nodeId > 0, 'node_id положительный');
check(
    is_array($enrolled) && ($enrolled['fingerprint'] ?? '') === $fingerprint,
    'отпечаток ключа посчитан от сырых байт',
    json_encode($enrolled)
);
$leak = $enroll['body'] . json_encode(array_keys((array)$enrolled));
check(
    strpos($leak, 'node_token') === false && strpos($leak, 'secret_key') === false,
    'в ответе нет секретов',
    $leak
);

// Ключ реально записан в ноду.
$stmt = $pdo->prepare('SELECT public_key, scopes, enrolled_at, node_token FROM nodes WHERE id = ?');
$stmt->execute([$nodeId]);
$nodeRow = $stmt->fetch(PDO::FETCH_ASSOC);
check(is_array($nodeRow) && $nodeRow['public_key'] !== null, 'public_key сохранён в nodes');
check(is_array($nodeRow) && $nodeRow['node_token'] === null, 'legacy node_token новой ноде не выдан');
check(is_array($nodeRow) && $nodeRow['enrolled_at'] !== null, 'enrolled_at проставлен');

// Код одноразовый.
$reuse = http_req('POST', $base . '/api/enroll.php', [
    'headers' => ['Content-Type: application/json'],
    'body' => $enrollBody,
]);
check($reuse['status'] === 401, 'повторное использование кода → 401', 'status=' . $reuse['status']);

section('Отклонения при обмене');

$badFmt = http_req('POST', $base . '/api/enroll.php', [
    'headers' => ['Content-Type: application/json'],
    'body' => json_encode(['token' => 'short', 'public_key' => $pubPem]),
]);
check($badFmt['status'] === 401, 'короткий код → 401', 'status=' . $badFmt['status']);

$unknown = http_req('POST', $base . '/api/enroll.php', [
    'headers' => ['Content-Type: application/json'],
    'body' => json_encode(['token' => 'ABCDEFGH', 'public_key' => $pubPem, 'name' => 'x']),
]);
check($unknown['status'] === 401, 'неизвестный код → 401', 'status=' . $unknown['status']);

$rsaPem = '';
$rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
if ($rsa !== false) {
    $det = openssl_pkey_get_details($rsa);
    $rsaPem = (string)($det['key'] ?? '');
}
$respRsa = http_req('POST', $base . '/api/enroll.php', [
    'headers' => ['Content-Type: application/json'],
    'body' => json_encode(['token' => 'ABCDEFGH', 'public_key' => $rsaPem, 'name' => 'x']),
]);
check($respRsa['status'] === 400, 'не-Ed25519 ключ → 400 (даже до проверки кода)', 'status=' . $respRsa['status']);

$methodNotAllowed = http_req('GET', $base . '/api/enroll.php');
check($methodNotAllowed['status'] === 405, 'GET /api/enroll.php → 405', 'status=' . $methodNotAllowed['status']);

section('Подписанные запросы по HTTP');

// Валидная подпись открывает чтение API...
$ts = (string)time();
$path = '/api/auth_logs.php';
$signed = http_req('GET', $base . $path, [
    'headers' => sign_request($secretKey, 'GET', $path, $ts, '', $nodeId),
]);
check($signed['status'] === 200, 'подписанный GET проходит', 'status=' . $signed['status'] . ' body=' . substr($signed['body'], 0, 200));

// ...но агент не может выдать себе код.
$ts2 = (string)time();
$path2 = '/api/enrollment/create.php';
$body2 = json_encode(['ttl_seconds' => 600]);
$agentCreate = http_req('POST', $base . $path2, [
    'headers' => array_merge(
        ['Content-Type: application/json'],
        sign_request($secretKey, 'POST', $path2, $ts2, $body2, $nodeId)
    ),
    'body' => $body2,
]);
check($agentCreate['status'] === 403, 'агенту выдача кодов закрыта → 403', 'status=' . $agentCreate['status'] . ' body=' . substr($agentCreate['body'], 0, 200));

// Просроченный timestamp отклоняется (replay-защита).
$stale = http_req('GET', $base . $path, [
    'headers' => sign_request($secretKey, 'GET', $path, (string)(time() - 600), '', $nodeId),
]);
check($stale['status'] === 401, 'устаревший timestamp → 401', 'status=' . $stale['status']);

section('Код, привязанный к ноде');

$respBind = http_req('POST', $base . '/api/enrollment/create.php', [
    'headers' => $mkHeaders(),
    'body' => json_encode(['ttl_seconds' => 600, 'node_id' => $nodeId]),
    'cookie' => $cookieJar,
]);
$bound = json_decode($respBind['body'], true);
if (isset($bound['id'])) {
    $track['token_ids'][] = (int)$bound['id'];
}
check($respBind['status'] === 200 && isset($bound['token']), 'код под ноду выдан', $respBind['body']);

// Администратор мог ограничить ноду только чтением — enrollment не должен
// возвращать ей полный набор прав.
$pdo->prepare("UPDATE nodes SET scopes = ? WHERE id = ?")
    ->execute([json_encode(['metrics:write']), $nodeId]);

$keypair2 = sodium_crypto_sign_keypair();
$pubPem2 = raw_to_spki_pem(sodium_crypto_sign_publickey($keypair2));
$oldFingerprint = $fingerprint;
$newFingerprint = 'sha256:' . substr(hash('sha256', sodium_crypto_sign_publickey($keypair2)), 0, 32);

$reEnroll = http_req('POST', $base . '/api/enroll.php', [
    'headers' => ['Content-Type: application/json'],
    'body' => json_encode([
        'token' => (string)$bound['token'],
        'public_key' => $pubPem2,
    ]),
]);
$reData = json_decode($reEnroll['body'], true);
check($reEnroll['status'] === 200, 'повторная выдача по привязанному коду → 200', $reEnroll['body']);
check(
    is_array($reData) && (int)($reData['node_id'] ?? 0) === $nodeId,
    'ключ ушёл в ту же ноду',
    json_encode($reData)
);
check(
    is_array($reData) && ($reData['fingerprint'] ?? '') === $newFingerprint,
    'отпечаток обновлён'
);
check(
    is_array($reData) && $reData['scopes'] === ['metrics:write'],
    'scopes ноды не сброшены',
    json_encode($reData['scopes'] ?? null)
);

$stmt = $pdo->prepare('SELECT public_key, scopes, key_rotated_at FROM nodes WHERE id = ?');
$stmt->execute([$nodeId]);
$rotated = $stmt->fetch(PDO::FETCH_ASSOC);
check(is_array($rotated) && $rotated['public_key'] === $pubPem2, 'в БД новый ключ');
check(is_array($rotated) && $rotated['key_rotated_at'] !== null, 'key_rotated_at проставлен при смене');

$stmt = $pdo->prepare(
    "SELECT old_key_fp, new_key_fp, reason FROM key_rotation_log
      WHERE node_id = ? AND actor_type = 'enroll' ORDER BY id DESC LIMIT 1"
);
$stmt->execute([$nodeId]);
$rotLog = $stmt->fetch(PDO::FETCH_ASSOC);
check(
    is_array($rotLog) && $rotLog['reason'] === 'rotate' && $rotLog['old_key_fp'] === $oldFingerprint,
    'ротация зафиксирована в key_rotation_log',
    json_encode($rotLog)
);

// Ключ действительно работает после ротации.
$ts3 = (string)time();
$signed2 = http_req('GET', $base . $path, [
    'headers' => sign_request(sodium_crypto_sign_secretkey($keypair2), 'GET', $path, $ts3, '', $nodeId),
]);
check($signed2['status'] === 200, 'новый ключ подтверждён по HTTP', 'status=' . $signed2['status']);

section('Rate limit (10 попыток / 5 минут на IP)');

$rlIp = '203.0.113.77';
$statuses = [];
for ($i = 0; $i < 12; $i++) {
    $r = http_req('POST', $base . '/api/enroll.php', [
        'headers' => ['Content-Type: application/json', 'X-Forwarded-For: ' . $rlIp],
        'body' => json_encode(['token' => 'ZZZZZZZZ', 'public_key' => $pubPem, 'name' => 'x']),
    ]);
    $statuses[] = $r['status'];
    if ($r['status'] === 429) {
        break;
    }
}
check(in_array(429, $statuses, true), 'после 10 попыток → 429', json_encode($statuses));
check(count(array_filter($statuses, static function ($s) {
    return $s === 401;
})) >= 10, 'первые 10 попыток обрабатывались как неверный код', json_encode($statuses));

echo "\nПройдено: {$passed}, провалено: {$failed}, пропущено: {$skipped}\n";
$exit = $failed === 0 ? 0 : 1;
exit($exit);
