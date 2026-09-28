<?php
declare(strict_types=1);

// Каталог monitoring/includes. Модули лежат уровнем ниже, поэтому их
// собственный __DIR__ на уровень глубже; пути конфигурации и данных
// должны считаться от каталога, где лежит db_config.php.
if (!defined('DB_INCLUDES_DIR')) {
    define('DB_INCLUDES_DIR', dirname(__DIR__));
}

/**
 * Подключение к MySQL.
 *
 * Отказоустойчивые опции PDO, TCP-preflight, запрет нерасхождения конфигурации на диске и в окружении, экранирование имён.
 *
 * Часть monitoring/includes/db_config.php; подключается оттуда.
 * Напрямую этот файл не включают.
 */

function db_reset_pdo(): void
{
    getDbConnection(true);
}

function db_tcp_preflight(string $host, int $port, float $timeoutSec): void
{
    if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
        return;
    }
    $timeoutSec = max(0.5, min($timeoutSec, 5.0));
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        $timeoutSec,
        STREAM_CLIENT_CONNECT
    );
    if (!is_resource($fp)) {
        throw new RuntimeException("TCP {$host}:{$port} недоступен ({$errno}: {$errstr})");
    }
    fclose($fp);
}

/**
 * Плейсхолдеры, которые могли остаться от установки/шаблона.
 * Подключаться с ними нельзя: MySQL отвергнет, но пользователь увидит
 * невнятную ошибку доступа вместо подсказки. Пустая строка — не плейсхолдер,
 * а осмысленный выбор (unix-сокет / trust-аутентификация), её пропускаем.
 */
function db_placeholder_values(): array
{
    // Список приводим к нижнему регистру — сравнение идёт со strtolower($password),
    // иначе запись в верхнем регистре (CHANGE_ME) никогда не совпадёт.
    return array_map('strtolower', [
        'password', 'CHANGE_ME', 'changeme', 'rootpassword',
        'adminpassword', 'your-password', 'secret', 'PASSWORD',
    ]);
}

/**
 * @throws RuntimeException если в конфиге остался плейсхолдер вместо пароля
 */
function db_assert_configured(array $cfg): void
{
    $password = (string)($cfg['password'] ?? '');
    if ($password === '') {
        return;
    }
    if (!in_array(strtolower($password), db_placeholder_values(), true)) {
        return;
    }
    $fromFile = !empty($cfg['from_file']);
    $where = $fromFile ? 'monitoring/data/db.local.php' : 'переменные окружения DB_*';
    throw new RuntimeException(
        "В $where вместо пароля БД остался плейсхолдер «{$password}». "
        . ($fromFile
            ? 'Укажите реальный пароль в панели: Настройки → База данных.'
            : 'Задайте реальный пароль в DB_PASSWORD (например, в systemd-юните monitoring-web.service) '
              . 'либо в monitoring/data/db.local.php.')
    );
}

function db_pdo(array $cfg, ?string $dbname = null, int $timeout = 3): PDO
{
    db_assert_configured($cfg);
    $host = $cfg['host'] ?: 'localhost';
    $port = (int)($cfg['port'] ?: 3306);
    $timeout = max(1, min($timeout, 8));
    // PDO/SSL часто игнорирует ATTR_TIMEOUT — сначала жёсткий TCP
    db_tcp_preflight($host, $port, (float)$timeout);
    $prevSock = ini_get('default_socket_timeout');
    @ini_set('default_socket_timeout', (string)$timeout);
    $dsn = $dbname
        ? "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4"
        : "mysql:host={$host};port={$port};charset=utf8mb4";
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => $timeout,
    ];
    if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
        $opts[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = $timeout;
    }
    $opts = array_replace($opts, db_pdo_ssl_opts($cfg));
    try {
        return new PDO($dsn, (string)$cfg['user'], (string)$cfg['password'], $opts);
    } finally {
        if ($prevSock !== false) {
            @ini_set('default_socket_timeout', (string)$prevSock);
        }
    }
}

function db_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('Недопустимое имя базы данных');
    }
    return $name;
}

function db_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}
