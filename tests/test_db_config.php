<?php
/**
 * Тесты конфигурации БД панели: db_assert_configured() и приоритет источников.
 *
 * Запуск:  php tests/test_db_config.php
 * Выход:   0 — все проверки прошли, 1 — есть падения.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/monitoring/includes/db_config.php';

$passed = 0;
$failed = 0;

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

function throwsFor(string $password, bool $fromFile, string $name): void
{
    try {
        db_assert_configured(['password' => $password, 'from_file' => $fromFile]);
        check(false, $name, 'исключения не было, ожидался RuntimeException');
    } catch (RuntimeException $e) {
        check(strpos($e->getMessage(), 'пароля БД остался плейсхолдер') !== false, $name, 'не тот текст ошибки');
    }
}

function passesFor(string $password, string $name): void
{
    try {
        db_assert_configured(['password' => $password, 'from_file' => false]);
        check(true, $name);
    } catch (RuntimeException $e) {
        check(false, $name, 'бросил: ' . $e->getMessage());
    }
}

echo "db_assert_configured: отвергаемые плейсхолдеры\n";
// Регистр не должен влиять: сравнение идёт через strtolower().
foreach (['CHANGE_ME', 'change_me', 'password', 'PASSWORD', 'Password', 'changeme',
          'rootpassword', 'adminpassword', 'your-password', 'secret'] as $pw) {
    throwsFor($pw, false, "отвергнут «{$pw}» (из env)");
    throwsFor($pw, true, "отвергнут «{$pw}» (из db.local.php)");
}

echo "db_assert_configured: допустимые значения\n";
// Пустой пароль — осмысленный выбор (unix-сокет / trust), его пропускаем.
foreach (['', 're4lP4ss', 'MySQL#8812', 'mypassword1', 'public', 'hash123',
          'пароль-с-кириллицей', 'p@ss w0rd!'] as $pw) {
    passesFor($pw, $pw === '' ? 'пустой пароль пропущен' : "пропущен «{$pw}»");
}

echo "db_assert_configured: текст ошибки подсказывает путь\n";
try {
    db_assert_configured(['password' => 'CHANGE_ME', 'from_file' => true]);
    check(false, 'from_file=true бросает', 'исключения не было');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 'db.local.php') !== false, 'from_file=true упоминает db.local.php');
}
try {
    db_assert_configured(['password' => 'CHANGE_ME', 'from_file' => false]);
    check(false, 'from_file=false бросает', 'исключения не было');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 'DB_PASSWORD') !== false, 'from_file=false упоминает DB_PASSWORD');
}

echo "db_config_load: приоритет db.local.php над DB_* (регрессия e0888da)\n";
$dataDir = dirname(__DIR__) . '/monitoring/data';
$confFile = $dataDir . '/db.local.php';
$created = !is_file($confFile);
if ($created) {
    file_put_contents($confFile, "<?php\nreturn ['host' => 'file-host', 'port' => '3399', 'name' => 'file-db',"
        . " 'user' => 'file-user', 'password' => 'file-pass'];\n");
}
putenv('DB_HOST=env-host');
putenv('DB_USER=env-user');
putenv('DB_PASSWORD=env-pass');
try {
    $cfg = db_config_load();
    check($cfg['host'] === 'file-host', 'хост берётся из db.local.php', "получено {$cfg['host']}");
    check($cfg['user'] === 'file-user', 'пользователь берётся из db.local.php', "получено {$cfg['user']}");
    check($cfg['password'] === 'file-pass', 'пароль берётся из db.local.php', "получено {$cfg['password']}");
    check(!empty($cfg['from_file']), 'from_file выставлен');
} finally {
    if ($created) {
        @unlink($confFile);
    }
}

// Файл удалён (или его не было) — те же DB_* теперь обязаны сработать.
echo "db_config_load: без файла берём DB_* из окружения\n";
$cfg = db_config_load();
check($cfg['host'] === 'env-host', 'хост из окружения', "получено {$cfg['host']}");
check($cfg['user'] === 'env-user', 'пользователь из окружения', "получено {$cfg['user']}");
check($cfg['password'] === 'env-pass', 'пароль из окружения', "получено {$cfg['password']}");
check(empty($cfg['from_file']), 'from_file пуст');
putenv('DB_HOST');
putenv('DB_USER');
putenv('DB_PASSWORD');

echo "db_ident: защита от инъекции в имя БД\n";
foreach (['monitoring', 'db_1'] as $okName) {
    try {
        check(db_ident($okName) === $okName, "корректное имя «{$okName}» принято");
    } catch (InvalidArgumentException $e) {
        check(false, "корректное имя «{$okName}» принято", $e->getMessage());
    }
}
foreach (['db`; DROP DATABASE x; --', 'db name', "db'name", 'db;DROP', ''] as $bad) {
    try {
        db_ident($bad);
        check(false, "некорректное имя отвергнуто: «{$bad}»");
    } catch (InvalidArgumentException $e) {
        check(true, "некорректное имя отвергнуто: «{$bad}»");
    }
}

echo "db_quote_ident: экранирование обратных кавычек\n";
check(db_quote_ident('we`ird') === '`we``ird`', 'обратная кавычка удваивается', db_quote_ident('we`ird'));

echo "\nИтог: passed={$passed} failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
