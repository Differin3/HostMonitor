<?php
/**
 * Тесты аутентификации агентов: формат подписи, разбор ключа, scopes,
 * Ed25519-проверка, legacy-режим, replay-защита.
 *
 * Запуск:  php tests/test_auth.php
 * Без БД:  тесты формата и криптографии идут всегда.
 * С БД:    export TEST_DB_DSN='mysql:host=127.0.0.1;port=13306;dbname=monitoring'
 *          export TEST_DB_USER=root TEST_DB_PASS=test
 *          — тогда добавляются проверки authenticate_agent_request() и
 *            интеграции require_api_auth(). Без переменных они помечаются
 *            как SKIP, чтобы CI без MySQL не падал.
 *
 * Выход:   0 — все проверки прошли, 1 — есть падения.
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

/** Запускает PHP-код в отдельном процессе (нужно для json_error с exit). */
function run_php(string $code): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'auth');
    file_put_contents($tmp, "<?php\n" . $code . "\n");
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1';
    $out = (string)shell_exec($cmd);
    unlink($tmp);
    return $out;
}

$fixturePath = dirname(__DIR__) . '/tests/fixtures/ed25519_cross_lang.json';
$fixture = is_file($fixturePath) ? json_decode((string)file_get_contents($fixturePath), true) : null;

/* ─── 1. Формат подписываемого сообщения ──────────────────────────────────── */

section('Формат подписываемого сообщения');

check(
    agent_signature_message('post', '/api/x?a=1&b=2', '1727000000', '') === "POST\n/api/x?a=1&b=2\n1727000000\n",
    'метод верхним регистром, разделитель LF, пустое тело → 3 переноса'
);

$withBody = agent_signature_message('POST', '/api/metrics.php', '100', '{"cpu":1}');
check(
    $withBody === "POST\n/api/metrics.php\n100\n{\"cpu\":1}",
    'тело подставляется последним, без экранирования',
    var_export($withBody, true)
);

check(
    agent_signature_message('GET', '/api/nodes.php?id=1', '5', '') ===
        "GET\n/api/nodes.php?id=1\n5\n",
    'query string остаётся в пути как есть'
);

/* ─── 2. Разбор публичного ключа ──────────────────────────────────────────── */

section('Разбор Ed25519-публичного ключа');

$pyPem = $fixture['python']['public_key_pem'] ?? '';
$phpPem = $fixture['php']['public_key_pem'] ?? '';

$raw = $pyPem !== '' ? agent_raw_public_key($pyPem) : null;
check(is_string($raw) && strlen($raw) === 32, 'PEM Python → сырые 32 байта');

$rawPhp = $phpPem !== '' ? agent_raw_public_key($phpPem) : null;
check(is_string($rawPhp) && strlen($rawPhp) === 32, 'PEM PHP → сырые 32 байта');

check(
    $raw !== null && hash('sha256', $raw) === agent_key_fingerprint($pyPem),
    'отпечаток ключа считается от сырых байт'
);

// НЕ Ed25519 → null: RSA и P-256 обязаны отклоняться на этапе разбора.
$rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$rsaPem = '';
if ($rsa !== false) {
    openssl_pkey_export($rsa, $rsaPem);
    $details = openssl_pkey_get_details($rsa);
    $rsaPem = $details['key'];
}
check($rsaPem !== '', 'RSA-ключ сгенерирован для негативного теста');
check(agent_raw_public_key($rsaPem) === null, 'RSA-PPEM отклоняется');

$ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$ecPem = '';
if ($ec !== false) {
    $details = openssl_pkey_get_details($ec);
    $ecPem = $details['key'] ?? '';
}
check($ecPem !== '', 'EC P-256 ключ сгенерирован для негативного теста');
check(agent_raw_public_key($ecPem) === null, 'P-256 (любой EC) отклоняется — не Ed25519');

check(agent_raw_public_key('') === null, 'пустая строка → null');
check(agent_raw_public_key("не PEM вообще") === null, 'мусор → null');
check(agent_raw_public_key("-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----") === null, 'битый base64 → null');

// Обрезанный DER (43 байта) — тоже отказ.
if ($raw !== null) {
    $trunc = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(substr(hex2bin('302a300506032b6570032100') . $raw, 0, 43)), 64, "\n") . "-----END PUBLIC KEY-----";
    check(agent_raw_public_key($trunc) === null, 'обрезанный DER → null');
}

/* ─── 3. Кросс-языковая сверка (Python подписала → PHP принимает) ─────────── */

section('Кросс-языковая сверка формата подписи');

if ($fixture === null) {
    skip('фикстура Python↔PHP', 'нет tests/fixtures/ed25519_cross_lang.json');
} else {
    $msg = base64_decode($fixture['message_b64']);
    $expected = $fixture['method'] . "\n" . $fixture['path'] . "\n" . $fixture['timestamp'] . "\n";
    check($msg === $expected, 'фикстурное сообщение собрано ровно по спецификации', bin2hex((string)$msg));

    check(
        $msg === agent_signature_message($fixture['method'], $fixture['path'], (string)$fixture['timestamp'], ''),
        'PHP собирает идентичное сообщение'
    );

    $rawPy = agent_raw_public_key($fixture['python']['public_key_pem']);
    check($rawPy !== null, 'ключ Python разобран');

    $sigPy = base64_decode($fixture['python']['signature_b64']);
    $ok = $rawPy !== null && sodium_crypto_sign_verify_detached($sigPy, $msg, $rawPy);
    check($ok, 'PHP принимает подпись, сделанную Python (cryptography)');

    $sigPhp = base64_decode($fixture['php']['signature_b64']);
    $okPhp = $rawPhp !== null && sodium_crypto_sign_verify_detached($sigPhp, $msg, $rawPhp);
    check($okPhp, 'подпись PHP принимается своим же кодом');

    $tampered = $msg . "X";
    check(
        $rawPy !== null && !sodium_crypto_sign_verify_detached($sigPy, $tampered, $rawPy),
        'подмена сообщения отклоняется'
    );
}

/* ─── 4. Round-trip: генерация ключа и подпись ────────────────────────────── */

section('Round-trip: генерация ключа и подпись');

$keypair = sodium_crypto_sign_keypair();
$rawPub = sodium_crypto_sign_publickey($keypair);
$der = hex2bin('302a300506032b6570032100') . $rawPub;
$pemFromSodium = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----";

check(
    agent_raw_public_key($pemFromSodium) === $rawPub,
    'SPKI-PEM из сырых байт возвращается без искажений'
);

$message = agent_signature_message('POST', '/api/metrics.php', (string)time(), '{"cpu":42}');
$sig = sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($keypair));
check(strlen($sig) === 64, 'Ed25519-подпись ровно 64 байта');
check(sodium_crypto_sign_verify_detached($sig, $message, $rawPub), 'своя подпись проходит проверку');

$broken = $sig;
$broken[0] = $broken[0] === "\x00" ? "\x01" : "\x00";
check(!sodium_crypto_sign_verify_detached($broken, $message, $rawPub), 'битая подпись отклоняется');

/* ─── 5. Scopes ───────────────────────────────────────────────────────────── */

section('Scopes и require_scope()');

$defaults = agent_default_scopes();
check(in_array('metrics:write', $defaults, true), 'metrics:write выдаётся по умолчанию');
check(in_array('commands:read', $defaults, true), 'commands:read выдаётся по умолчанию');
check(!in_array('system:power', $defaults), 'system:power НЕ выдаётся по умолчанию');
check(!in_array('system:exec', $defaults), 'system:exec НЕ выдаётся по умолчанию');
check(!in_array('firewall:write', $defaults), 'firewall:write НЕ выдаётся по умолчанию');
check(!in_array('update:install', $defaults), 'update:install НЕ выдаётся по умолчанию');

check(agent_parse_scopes(null) === $defaults, 'NULL из БД → scopes по умолчанию');
check(agent_parse_scopes('["a"]') === ['a'], 'JSON-строка разбирается');
check(agent_parse_scopes('мусор') === $defaults, 'битый JSON → scopes по умолчанию');
check(agent_parse_scopes(['x']) === ['x'], 'массив проходит без изменений');

// require_scope() завершает запрос через json_error + exit — только отдельным
// процессом, иначе он убьёт весь прогон теста.
$probe = function (string $scopesJson, string $scope): string {
    $code = '
require_once ' . var_export(dirname(__DIR__) . '/monitoring/includes/helpers.php', true) . ';
$agent = ["node_id" => 1, "scopes" => json_decode(' . var_export($scopesJson, true) . ', true), "auth" => "ed25519"];
require_scope($agent, ' . var_export($scope, true) . ');
echo "ALLOWED";
';
    return run_php($code);
};

check(strpos($probe('["metrics:write"]', 'metrics:write'), 'ALLOWED') !== false, 'имеющийся scope проходит');
$out = $probe('["metrics:write"]', 'system:power');
check(strpos($out, 'ALLOWED') === false && strpos($out, 'Forbidden') !== false, 'нет scope → 403 Forbidden', trim($out));
check(strpos($probe('["legacy:full"]', 'system:power'), 'ALLOWED') !== false, 'legacy:full проходит любой scope (обратная совместимость)');

/* ─── 6. Ed25519-проверка через authenticate_agent_request() ──────────────── */

$dbEnv = test_db_env();
if ($dbEnv === null) {
    section('Интеграция с БД (authenticate_agent_request)');
    skip('подпись/реплей/legacy', 'нет TEST_DB_DSN — запустите с тестовой MySQL, чтобы проверить интеграцию');
} else {
    section('Интеграция с БД (authenticate_agent_request)');

    $dsn = $dbEnv['dsn'];
    $user = $dbEnv['user'];
    $pass = $dbEnv['pass'];
    $pdo = test_db_connect();
    test_db_ensure_schema($pdo);

    $kp = sodium_crypto_sign_keypair();
    $pubRaw = sodium_crypto_sign_publickey($kp);
    $der = hex2bin('302a300506032b6570032100') . $pubRaw;
    $pubPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----";
    $secret = sodium_crypto_sign_secretkey($kp);

    $pdo->exec("DELETE FROM nodes WHERE name IN ('auth-test-ed', 'auth-test-legacy', 'auth-test-nokey')");
    $stmt = $pdo->prepare(
        "INSERT INTO nodes (name, host, port, status, node_token, public_key, scopes, enrolled_at)
         VALUES (?, '127.0.0.1', 22, 'online', ?, ?, ?, NOW())"
    );
    $scopesJson = json_encode(agent_default_scopes());
    $stmt->execute(['auth-test-ed', 'legacy-token-abc123', $pubPem, $scopesJson]);
    $edNodeId = (int)$pdo->lastInsertId();

    $stmt->execute(['auth-test-legacy', 'legacy-token-xyz789', null, null]);
    $legacyNodeId = (int)$pdo->lastInsertId();

    $stmt->execute(['auth-test-nokey', 'legacy-token-nokey', null, null]);
    $noKeyId = (int)$pdo->lastInsertId();

    // Подменяет $_SERVER на подписанный запрос. $over['message'] — готовое
    // сообщение (чтобы подписать не тот путь), $over['no_signature'] — убрать
    // подпись вовсе, $over['bearer'] — legacy-заголовок.
    $makeRequest = static function (array $over) use ($secret, $edNodeId): void {
        $method = $over['method'] ?? 'GET';
        $uri = $over['uri'] ?? '/api/nodes.php?id=' . $edNodeId . '&action=get-command';
        $ts = $over['timestamp'] ?? (string)time();
        $body = $over['body'] ?? '';

        $sig = '';
        if (empty($over['no_signature'])) {
            $message = array_key_exists('message', $over) && $over['message'] !== null
                ? $over['message']
                : agent_signature_message($method, $uri, $ts, $body);
            $sig = base64_encode(sodium_crypto_sign_detached($message, $secret));
        }

        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_X_AGENT_NODE_ID'] = array_key_exists('node_id', $over)
            ? (string)$over['node_id']
            : (string)$edNodeId;
        $_SERVER['HTTP_X_AGENT_TIMESTAMP'] = $ts;
        $_SERVER['HTTP_X_AGENT_SIGNATURE'] = $sig;
        $_SERVER['HTTP_AUTHORIZATION'] = $over['bearer'] ?? '';
        $GLOBALS['agent_auth_test_body'] = $body;
    };

    // Корректная подпись.
    $makeRequest([]);
    $agent = authenticate_agent_request($pdo);
    check(is_array($agent), 'валидная подпись принимается', json_encode($agent));
    check(($agent['node_id'] ?? null) === $edNodeId, 'node_id берётся из заголовка', json_encode($agent));
    check(($agent['auth'] ?? '') === 'ed25519', 'auth = ed25519');
    check(
        is_array($agent) && in_array('metrics:write', $agent['scopes'], true),
        'scopes читаются из БД'
    );

    // Неверная подпись: подписано сообщение для другого пути, сервер
    // пересчитает своё — и должно отклонить.
    $makeRequest(['message' => "GET\n/wrong\n1\n"]);
    $bad = authenticate_agent_request($pdo);
    check($bad === null, 'подпись для другого пути отклоняется', json_encode($bad));

    // Replay: timestamp старше 60 секунд.
    $makeRequest(['timestamp' => (string)(time() - 120)]);
    check(authenticate_agent_request($pdo) === null, 'replay (120 сек назад) отклоняется');

    // Слишком «свежий» timestamp тоже вне окна.
    $makeRequest(['timestamp' => (string)(time() + 120)]);
    check(authenticate_agent_request($pdo) === null, 'timestamp из будущего отклоняется');

    // Пустая подпись.
    $makeRequest(['no_signature' => true]);
    check(authenticate_agent_request($pdo) === null, 'запрос без подписи → null (не Ed25519)');

    // Нода без public_key: подпись валидна, но ключа для проверки нет.
    $makeRequest(['node_id' => $noKeyId]);
    check(authenticate_agent_request($pdo) === null, 'нода без public_key → null');

    // Несуществующая нода.
    $makeRequest(['node_id' => 999999]);
    check(authenticate_agent_request($pdo) === null, 'неизвестный node_id → null');

    // Legacy Bearer: подпись не передана.
    $makeRequest(['no_signature' => true, 'bearer' => 'Bearer legacy-token-abc123']);
    $legacy = authenticate_agent_legacy($pdo);
    check(is_array($legacy) && $legacy['node_id'] === $edNodeId, 'legacy Bearer работает');
    check(is_array($legacy) && $legacy['scopes'] === ['legacy:full'], 'legacy получает legacy:full');

    // Критично: заголовки подписи есть, но подпись невалидна → в legacy-ветку
    // НЕ уходим, иначе утечка чужого токена снова даст полный доступ.
    $makeRequest(['message' => "GET\n/wrong\n1\n", 'bearer' => 'Bearer legacy-token-abc123']);
    check(authenticate_agent($pdo) === null, 'битая подпись не откатывается в legacy-ветку');

    // authenticate_agent: корректная подпись.
    $makeRequest([]);
    $viaBoth = authenticate_agent($pdo);
    check(is_array($viaBoth) && $viaBoth['auth'] === 'ed25519', 'authenticate_agent выбирает ed25519');

    // require_api_auth: нода, scopes, отсутствие user.
    $makeRequest([]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    unset($_SESSION['user_id']);
    $auth = require_api_auth($pdo);
    check(array_key_exists('user', $auth) && $auth['user'] === null, 'у агента нет user');
    check(($auth['node']['id'] ?? null) === $edNodeId, 'require_api_auth отдаёт node id');
    check(($auth['auth'] ?? '') === 'ed25519', 'require_api_auth отдаёт тип аутентификации');
    check(is_array($auth['scopes']) && count($auth['scopes']) === count(agent_default_scopes()), 'scopes из БД');

    // Дыра, закрытая при интеграции: сессия админа + мусорные заголовки
    // подписи НЕ должны давать сессию в обход CSRF (json_error → exit,
    // поэтому отдельный процесс).
    $probeCode = '
require_once ' . var_export(dirname(__DIR__) . '/monitoring/includes/helpers.php', true) . ';
$pdo = new PDO(' . var_export($dsn, true) . ', ' . var_export($user, true) . ', ' . var_export($pass, true) . ',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
session_start();
$_SESSION["user_id"] = 1;
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REQUEST_URI"] = "/api/settings.php";
$_SERVER["HTTP_X_AGENT_NODE_ID"] = "1";
$_SERVER["HTTP_X_AGENT_TIMESTAMP"] = (string)time();
$_SERVER["HTTP_X_AGENT_SIGNATURE"] = base64_encode(random_bytes(64));
$GLOBALS["agent_auth_test_body"] = "{}";
$auth = require_api_auth($pdo);
echo "SESSION_BRANCH user=" . var_export($auth["user"], true);
';
    $probeOut = run_php($probeCode);
    check(
        strpos($probeOut, 'SESSION_BRANCH') === false && strpos($probeOut, 'Unauthorized') !== false,
        'сессия + мусорная подпись → 401, а не сессия без CSRF',
        trim($probeOut)
    );

    // Лог запроса: строка появляется, last_seen обновляется.
    $pdo->exec('DELETE FROM agent_requests_log WHERE node_id = ' . $edNodeId);
    $pdo->exec("UPDATE nodes SET last_seen = NULL WHERE id = " . $edNodeId);
    log_agent_request($pdo, $edNodeId, '/api/nodes.php', 'GET', 'ed25519', 200);
    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM agent_requests_log WHERE node_id = ' . $edNodeId)->fetchColumn();
    check($cnt >= 1, 'запрос записан в agent_requests_log');
    $seen = (string)$pdo->query('SELECT COALESCE(last_seen, "") FROM nodes WHERE id = ' . $edNodeId)->fetchColumn();
    check($seen !== '', 'last_seen обновлён при запросе агента');

    $pdo->exec("DELETE FROM agent_requests_log WHERE node_id = " . $edNodeId);
    $pdo->exec("DELETE FROM nodes WHERE name IN ('auth-test-ed', 'auth-test-legacy', 'auth-test-nokey')");
}

echo "\nПройдено: {$passed}, провалено: {$failed}, пропущено: {$skipped}\n";
exit($failed === 0 ? 0 : 1);
