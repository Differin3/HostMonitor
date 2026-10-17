<?php
/**
 * Регрессия формы первичной настройки (monitoring/setup.php).
 *
 * Поля replica_ssl/replica_ssl_verify читались только из $_POST, без
 * fallback на сохранённый конфиг при GET (в отличие от replica_enabled).
 * Повторное открытие setup.php показывало галочки снятыми, и следующее
 * сохранение молча выключало SSL резерва.
 *
 * Проверка по исходнику: страница setup.php выполняет побочные эффекты
 * (сессия, подключение к БД), поэтому её нельзя просто подключить в тесте.
 *
 * Запуск:  php tests/test_setup.php
 * Выход:   0 — все проверки прошли, 1 — есть падения.
 */
declare(strict_types=1);

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

$src = (string)file_get_contents(dirname(__DIR__) . '/monitoring/setup.php');

echo "setup.php: галочки SSL резерва переживают GET\n";

// Каждое из полей должно иметь POST-ветку (из $_POST) и GET-ветку (из $oldReplica),
// как это уже сделано для replica_enabled.
$fields = [
    'replica_ssl' => 'ssl',
    'replica_ssl_verify' => 'ssl_verify',
];
foreach ($fields as $field => $cfgKey) {
    $postNeedle = "'{$field}' => (\$_SERVER['REQUEST_METHOD'] === 'POST')";
    $getNeedle = "!empty(\$oldReplica['{$cfgKey}'])";
    check(
        strpos($src, $postNeedle) !== false,
        "{$field} различает POST и GET"
    );
    check(
        strpos($src, $getNeedle) !== false,
        "{$field} подхватывает сохранённый {$cfgKey} при GET"
    );
}

// Регрессия: нельзя возвращаться к чтению только из $_POST — на GET это даёт false.
check(
    strpos($src, "'replica_ssl' => isset(\$_POST['replica_ssl'])") === false,
    'replica_ssl без GET-fallback не осталось'
);
check(
    strpos($src, "'replica_ssl_verify' => isset(\$_POST['replica_ssl_verify'])") === false,
    'replica_ssl_verify без GET-fallback не осталось'
);

// replica_enabled уже был эталоном — пусть остаётся им и дальше.
check(
    strpos($src, ": !empty(\$cfg['replica_enabled']),") !== false,
    'replica_enabled по-прежнему читает сохранённый конфиг при GET'
);

echo "\nИтог: passed={$passed} failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
