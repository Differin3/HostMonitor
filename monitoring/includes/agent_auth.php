<?php
/**
 * Аутентификация агентов: Ed25519-подписи + fallback на Bearer-токен.
 *
 * Формат подписи (должен ТОЧНО совпадать с agent/auth.py):
 *
 *     {METHOD}\n{PATH}\n{TIMESTAMP}\n{BODY}
 *
 *   METHOD   — верхний регистр (GET, POST)
 *   PATH     — REQUEST_URI как есть, с query string, без перекодирования
 *   TIMESTAMP— unix timestamp в секундах
 *   BODY     — сырое тело запроса (для GET — пустая строка)
 *
 * Заголовки запроса: X-Agent-Node-Id, X-Agent-Timestamp, X-Agent-Signature.
 *
 * Почему sodium, а не openssl_verify():
 *   в PHP нет константы OPENSSL_ALGO_ED25519, и openssl_verify() на
 *   подписях Ed25519 возвращает 0/false — алгоритм в PHP не поддерживается.
 *   Проверка идёт через libsodium (sodium_crypto_sign_verify_detached),
 *   который входит в PHP с версии 7.2 и есть во всех средах проекта.
 *
 * Совместимость: PHP 7.4 (прод) и 8.x (CI).
 */
declare(strict_types=1);

if (!defined('AGENT_AUTH_CLOCK_SKEW')) {
    define('AGENT_AUTH_CLOCK_SKEW', 60);
}

if (!function_exists('agent_signature_message')) {
    /**
     * Строит подписываемое сообщение. Разделитель — LF, не CRLF.
     */
    function agent_signature_message(string $method, string $path, string $timestamp, string $body): string
    {
        return strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $body;
    }
}

if (!function_exists('agent_raw_public_key')) {
    /**
     * DER SPKI Ed25519 (RFC 8410) → сырые 32 байта ключа, либо null.
     *
     * Ожидаемая структура: 44 байта = 12 байт префикс + 32 байта ключ.
     * Префикс 302a300506032b6570032100 означает OID 1.3.101.112 (Ed25519),
     * поэтому RSA/P-256-ключи здесь же отклоняются — строже, чем проверка
     * на OPENSSL_KEYTYPE_EC, которая пропускает любой эллиптический ключ.
     */
    function agent_raw_public_key(string $pem): ?string
    {
        $b64 = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $pem);
        if ($b64 === null || $b64 === '') {
            return null;
        }
        $der = base64_decode($b64, true);
        if ($der === false || strlen($der) !== 44) {
            return null;
        }
        if (substr($der, 0, 12) !== hex2bin('302a300506032b6570032100')) {
            return null;
        }
        $raw = substr($der, 12);
        return strlen($raw) === 32 ? $raw : null;
    }
}

if (!function_exists('agent_key_fingerprint')) {
    /**
     * Отпечаток ключа для журналов и UI. Это не секрет.
     */
    function agent_key_fingerprint(string $pem): ?string
    {
        $raw = agent_raw_public_key($pem);
        if ($raw === null) {
            return null;
        }
        return hash('sha256', $raw);
    }
}

if (!function_exists('agent_default_scopes')) {
    /**
     * Scopes новой ноды. Опасные права (system:power, system:exec,
     * firewall:write, update:install) НЕ выдаются по умолчанию.
     */
    function agent_default_scopes(): array
    {
        return [
            'metrics:write',
            'logs:write',
            'containers:write',
            'ports:write',
            'processes:write',
            'commands:read',
            'commands:ack',
        ];
    }
}

if (!function_exists('agent_request_header')) {
    function agent_request_header(string $name): string
    {
        // X-Agent-Node-Id → HTTP_X_AGENT_NODE_ID
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? '';
        if ($value === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $hk => $hv) {
                if (strcasecmp((string)$hk, $name) === 0) {
                    $value = (string)$hv;
                    break;
                }
            }
        }
        if ($value === '' && $name === 'X-Agent-Signature') {
            // Некоторые прокси складывают Authorization-заголовки отдельно.
            $value = $_SERVER['REDIRECT_HTTP_X_AGENT_SIGNATURE'] ?? '';
        }
        return trim((string)$value);
    }
}

if (!function_exists('agent_request_path')) {
    /**
     * Путь запроса ровно в том виде, в каком он пришёл по сети, включая
     * query string — агент подписывает именно отправленную строку.
     */
    function agent_request_path(): string
    {
        if (!empty($_SERVER['REQUEST_URI'])) {
            return (string)$_SERVER['REQUEST_URI'];
        }
        $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        return $query !== '' ? $path . '?' . $query : $path;
    }
}

if (!function_exists('agent_request_body')) {
    /**
     * Сырое тело запроса. Читается один раз и кэшируется: подпись
     * проверяется до того, как какой-либо код разобрал POST.
     *
     * В CLI php://input — это stdin, поэтому тесты подменяют тело
     * переменной $GLOBALS['agent_auth_test_body']. Снаружи по HTTP её
     * задать нельзя: заголовки приходят в $_SERVER как HTTP_*, а не в
     * глобальную область видимости.
     */
    function agent_request_body(): string
    {
        if (array_key_exists('agent_auth_test_body', $GLOBALS)) {
            return (string)$GLOBALS['agent_auth_test_body'];
        }
        static $body = null;
        if ($body === null) {
            $body = (string)file_get_contents('php://input');
        }
        return $body;
    }
}

if (!function_exists('agent_log_auth_failure')) {
    function agent_log_auth_failure(string $reason, array $context = []): void
    {
        $suffix = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        error_log('[agent_auth] ' . $reason . $suffix);
    }
}

if (!function_exists('authenticate_agent_request')) {
    /**
     * Проверяет Ed25519-подпись запроса агента.
     *
     * @return array|null ['node_id'=>int, 'name'=>string, 'scopes'=>array,
     *                     'auth'=>'ed25519'] либо null, если подпись неверна.
     */
    function authenticate_agent_request(PDO $pdo): ?array
    {
        $nodeIdHeader = agent_request_header('X-Agent-Node-Id');
        $timestamp    = agent_request_header('X-Agent-Timestamp');
        $signature    = agent_request_header('X-Agent-Signature');

        if ($nodeIdHeader === '' && $timestamp === '' && $signature === '') {
            // Подписи нет — это не Ed25519-запрос, а возможно legacy/сессия.
            return null;
        }

        if ($nodeIdHeader === '' || !ctype_digit($nodeIdHeader)) {
            agent_log_auth_failure('missing/invalid X-Agent-Node-Id');
            return null;
        }
        if ($timestamp === '' || !ctype_digit($timestamp)) {
            agent_log_auth_failure('missing/invalid X-Agent-Timestamp', ['node_id' => $nodeIdHeader]);
            return null;
        }
        if ($signature === '') {
            agent_log_auth_failure('missing X-Agent-Signature', ['node_id' => $nodeIdHeader]);
            return null;
        }

        // Replay-защита: ±60 секунд.
        $skew = time() - (int)$timestamp;
        if (abs($skew) > AGENT_AUTH_CLOCK_SKEW) {
            agent_log_auth_failure('replay/rejected timestamp', [
                'node_id' => (int)$nodeIdHeader,
                'skew'    => $skew,
            ]);
            return null;
        }

        $nodeId = (int)$nodeIdHeader;
        try {
            $stmt = $pdo->prepare('SELECT id, name, public_key, scopes FROM nodes WHERE id = ? LIMIT 1');
            $stmt->execute([$nodeId]);
            $node = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            agent_log_auth_failure('node lookup failed: ' . $e->getMessage());
            return null;
        }

        if (!$node) {
            // Отдельно фиксируем пробы несуществующих id — это типовой
            // признак перебора чужих нод после утечки токена.
            agent_log_auth_failure('node not found', ['node_id' => $nodeId]);
            return null;
        }
        if (empty($node['public_key'])) {
            agent_log_auth_failure('node has no public key', ['node_id' => $nodeId]);
            return null;
        }

        $raw = agent_raw_public_key((string)$node['public_key']);
        if ($raw === null) {
            agent_log_auth_failure('stored public key is not Ed25519 SPKI', ['node_id' => $nodeId]);
            return null;
        }

        $sigBin = base64_decode($signature, true);
        if ($sigBin === false || strlen($sigBin) !== 64) {
            // Попытка в hex — принимаем и её, чтобы упростить отладку инструментами,
            // но не иначе: длина всё равно должна быть 64 байта.
            if (preg_match('/^[0-9a-fA-F]{128}$/', $signature) === 1) {
                $sigBin = hex2bin($signature);
            } else {
                agent_log_auth_failure('signature is not 64 bytes', ['node_id' => $nodeId]);
                return null;
            }
        }

        $message = agent_signature_message(
            (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            agent_request_path(),
            $timestamp,
            agent_request_body()
        );

        try {
            $ok = sodium_crypto_sign_verify_detached($sigBin, $message, $raw);
        } catch (Throwable $e) {
            agent_log_auth_failure('sodium verify error: ' . $e->getMessage(), ['node_id' => $nodeId]);
            return null;
        }

        if (!$ok) {
            agent_log_auth_failure('signature mismatch', ['node_id' => $nodeId, 'path' => agent_request_path()]);
            return null;
        }

        return [
            'node_id' => $nodeId,
            'name'    => (string)$node['name'],
            'scopes'  => agent_parse_scopes($node['scopes'] ?? null),
            'auth'    => 'ed25519',
        ];
    }
}

if (!function_exists('agent_parse_scopes')) {
    function agent_parse_scopes($stored): array
    {
        if (is_array($stored)) {
            return $stored;
        }
        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return agent_default_scopes();
    }
}

if (!function_exists('authenticate_agent_legacy')) {
    /**
     * Fallback на Bearer-токен — работает параллельно с Ed25519, пока
     * ноды не переведены на подписи. Legacy-агенты получают полный scope.
     */
    function authenticate_agent_legacy(PDO $pdo): ?array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $hk => $hv) {
                if (strcasecmp((string)$hk, 'Authorization') === 0) {
                    $authHeader = (string)$hv;
                    break;
                }
            }
        }
        if ($authHeader === '') {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        if ($authHeader === '' || preg_match('/Bearer\s+(.+)/i', $authHeader, $m) !== 1) {
            return null;
        }

        $token = trim($m[1]);
        try {
            $stmt = $pdo->prepare('SELECT id, name FROM nodes WHERE node_token = ? LIMIT 1');
            $stmt->execute([$token]);
            $node = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            agent_log_auth_failure('legacy lookup failed: ' . $e->getMessage());
            return null;
        }

        if (!$node) {
            agent_log_auth_failure('legacy Bearer token unknown');
            return null;
        }

        return [
            'node_id' => (int)$node['id'],
            'name'    => (string)$node['name'],
            'scopes'  => ['legacy:full'],
            'auth'    => 'legacy',
        ];
    }
}

if (!function_exists('authenticate_agent')) {
    /**
     * Сначала Ed25519, затем legacy. null = не агент (сессия или гость).
     */
    function authenticate_agent(PDO $pdo): ?array
    {
        $agent = authenticate_agent_request($pdo);
        if ($agent !== null) {
            return $agent;
        }
        // Сигнатура была, но не прошла — на Bearer не откатываемся,
        // иначе можно было бы подделать заголовки и уйти в legacy-ветку.
        if (agent_request_header('X-Agent-Signature') !== ''
            || agent_request_header('X-Agent-Node-Id') !== ''
            || agent_request_header('X-Agent-Timestamp') !== '') {
            return null;
        }
        return authenticate_agent_legacy($pdo);
    }
}

if (!function_exists('require_scope')) {
    /**
     * Проверяет scope и завершает запрос 403, если права нет.
     *
     * Legacy-агенты получают ['legacy:full'] и проходят любую проверку —
     * иначе существующие ноды перестали бы отдавать метрики.
     */
    function require_scope(array $agent, string $scope): void
    {
        $scopes = $agent['scopes'] ?? [];
        if (in_array('legacy:full', $scopes, true)) {
            return;
        }
        if (!in_array($scope, $scopes, true)) {
            agent_log_auth_failure('scope denied', [
                'node_id' => $agent['node_id'] ?? null,
                'scope'   => $scope,
                'auth'    => $agent['auth'] ?? '?',
            ]);
            json_error('Forbidden: scope ' . $scope . ' required', 403);
        }
    }
}

if (!function_exists('log_agent_request')) {
    /**
     * Аудит запросов агентов + подтверждение живости ноды.
     *
     * Пишется всё: таблица чистится от записей старше 14 дней, иначе при
     * опросе раз в минуту она бы росла бесконечно.
     */
    function log_agent_request(PDO $pdo, int $nodeId, string $endpoint, string $method, string $authType, int $status = 200): void
    {
        // Коллекторы метрик/умных дисков/UPnP пишут last_seen сами и ходят
        // каждые секунды — шуметь такими строками в аудите бессмысленно,
        // таблица вырастет на миллионы записей за сутки.
        $selfStampingCollectors = ['/api/metrics.php', '/api/smart.php', '/api/upnp.php'];
        foreach ($selfStampingCollectors as $skipPrefix) {
            if (strpos($endpoint, $skipPrefix) === 0) {
                return;
            }
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO agent_requests_log (node_id, endpoint, method, auth_type, status, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$nodeId, $endpoint, $method, $authType, $status, client_ip() ?: null]);
            $pdo->exec('DELETE FROM agent_requests_log WHERE created_at < NOW() - INTERVAL 14 DAY');
            $stmt = $pdo->prepare('UPDATE nodes SET last_seen = NOW() WHERE id = ?');
            $stmt->execute([$nodeId]);
        } catch (Throwable $e) {
            // Аудит не должен ломать рабочий запрос.
            agent_log_auth_failure('log_agent_request failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('agent_generate_keypair')) {
    /**
     * Генерация пары Ed25519 для новой/ротируемой ноды.
     *
     * Панель хранит ТОЛЬКО публичный ключ (SPKI PEM в nodes.public_key).
     * Приватный ключ отдаётся один раз в config (seed 32 байта в base64)
     * и после невосстановим — иначе утечка одной ноды снова открыла бы все.
     *
     * Возвращает seed_b64, public_pem, public_b64, fingerprint или null.
     */
    function agent_generate_keypair(): ?array
    {
        try {
            $keypair = sodium_crypto_sign_keypair();
            $secret  = sodium_crypto_sign_secretkey($keypair); // 64 Б: seed||public
            $rawPub  = sodium_crypto_sign_publickey($keypair);
        } catch (Throwable $e) {
            agent_log_auth_failure('keypair generation failed: ' . $e->getMessage());
            return null;
        }
        $seed = substr($secret, 0, 32);
        if ($seed === false || strlen($seed) !== 32 || strlen((string)$rawPub) !== 32) {
            agent_log_auth_failure('keypair generation produced invalid material');
            return null;
        }
        // RFC 8410: SPKI всегда 44 байта = 12 байт заголовка + 32 байта ключа.
        // Собираем вручную: sodium отдаёт сырые байты, а не DER/PEM.
        $spki = hex2bin('302a300506032b6570032100') . $rawPub;
        $pem  = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----";
        return [
            'seed_b64'    => base64_encode($seed),
            'public_pem'  => $pem,
            'public_b64'  => base64_encode($rawPub),
            'fingerprint' => agent_key_fingerprint($pem),
        ];
    }
}

if (!function_exists('agent_node_auth_method')) {
    /**
     * Способ аутентификации ноды: 'ed25519' | 'legacy' | null.
     */
    function agent_node_auth_method(array $node): ?string
    {
        if (!empty($node['public_key'])) {
            return 'ed25519';
        }
        if (!empty($node['node_token'])) {
            return 'legacy';
        }
        return null;
    }
}
