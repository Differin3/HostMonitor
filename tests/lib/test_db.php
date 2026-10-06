<?php
/**
 * Общая обвязка тестов, которым нужна MySQL: подключение по TEST_DB_* и
 * приведение схемы к рабочему состоянию (схема проекта + свежая миграция).
 *
 * Используется tests/test_auth.php и tests/test_enroll.php. Обе обязаны
 * работать и без TEST_DB_DSN — тогда DB-часть помечается как skip (в CI
 * по умолчанию MySQL нет, он поднимается только в job `security`).
 */
declare(strict_types=1);

/**
 * Подключение по окружению. null — если TEST_DB_DSN не задан.
 * Возвращает [PDO, host, port, name, user, pass] для передачи env веб-серверу.
 */
function test_db_env(): ?array
{
    static $env = null;
    static $resolved = false;
    if ($resolved) {
        return $env;
    }
    $resolved = true;

    $dsn = getenv('TEST_DB_DSN') ?: '';
    if ($dsn === '') {
        return null;
    }

    $parse = static function (string $pattern, string $dsn, string $default) {
        return preg_match($pattern, $dsn, $m) === 1 ? $m[1] : $default;
    };

    $env = [
        'dsn'  => $dsn,
        'host' => $parse('/host=([^;]+)/', $dsn, '127.0.0.1'),
        'port' => $parse('/port=(\d+)/', $dsn, '3306'),
        'name' => $parse('/dbname=([^;]+)/', $dsn, 'monitoring'),
        'user' => getenv('TEST_DB_USER') ?: 'root',
        'pass' => getenv('TEST_DB_PASS') !== false ? (string)getenv('TEST_DB_PASS') : '',
    ];
    return $env;
}

/**
 * PDO из TEST_DB_*. Буферизация обязательна: миграция при уже существующей
 * колонке выполняет исполняемый стейтмент с набором строк, и без буфера
 * следующий exec() падает с «2014 Cannot execute queries…».
 */
function test_db_connect(): PDO
{
    $env = test_db_env();
    if ($env === null) {
        throw new RuntimeException('TEST_DB_DSN is not set');
    }
    return new PDO($env['dsn'], $env['user'], $env['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

/** SQL-файл → список стейтментов (комментарии вырезаются, тело режется по ';'). */
function test_db_statements(string $sql): array
{
    $sql = (string)preg_replace('/^--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}

/**
 * Схема проекта (если база пустая) + миграция Ed25519. Оба файла
 * идемпотентны, повторный запуск безопасен.
 */
function test_db_ensure_schema(PDO $pdo): void
{
    try {
        $pdo->query('SELECT id FROM nodes LIMIT 1');
    } catch (PDOException $e) {
        $root = dirname(__DIR__, 2);
        $schema = (string)file_get_contents($root . '/database/schema_mysql.sql');
        foreach (test_db_statements($schema) as $stmt) {
            $pdo->exec($stmt);
        }
    }

    $root = dirname(__DIR__, 2);
    $migration = (string)file_get_contents($root . '/migrations/001_ed25519_auth.sql');
    foreach (test_db_statements($migration) as $stmt) {
        $pdo->exec($stmt);
    }
}
