<?php
/**
 * Тесты логики отказоустойчивости БД панели: db_replica_password_inherited().
 *
 * Закрывает регрессию из реальной установки: в реквизитах резерва пароль
 * оказался в поле пользователя, а поле пароля осталось пустым. Панель при
 * этом молча подставила пароль основной базы, и MySQL ответил
 * «using password: YES» — хотя пароль резерва не вводили. Формулировка
 * вводила в заблуждение: в форме поле пустое, в ошибке пароль есть.
 *
 * db_replica_password_inherited() позволяет прямо сказать в тексте ошибки,
 * откуда взялся пароль.
 *
 * Запуск:  php tests/test_db_ha.php
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

function cfg(array $replica, string $primaryPassword): array
{
    return [
        'password' => $primaryPassword,
        'replica' => $replica,
    ];
}

echo "db_replica_password_inherited: наследование пароля основной базы\n";

// Реальный случай: пароль резерва пуст, у основной он есть → наследование.
check(
    db_replica_password_inherited(cfg(['password' => ''], 'main-secret')) === true,
    'пустой пароль резерва наследует пароль основной'
);

// Пароль резерва задан явно → наследования нет.
check(
    db_replica_password_inherited(cfg(['password' => 'replica-secret'], 'main-secret')) === false,
    'заданный пароль резерва не наследуется'
);

// Пароля нет ни там, ни там → подставлять нечего.
check(
    db_replica_password_inherited(cfg(['password' => ''], '')) === false,
    'без пароля основной наследования нет'
);

// Секрет резерва не должен считаться наследованием из-за пробелов.
check(
    db_replica_password_inherited(cfg(['password' => '0'], 'main-secret')) === false,
    'пароль «0» — это пароль, а не пустое значение'
);

// Отсутствующий ключ password в реплике равносилен пустому.
check(
    db_replica_password_inherited(cfg(['host' => 'db-standby'], 'main-secret')) === true,
    'отсутствующий ключ password считается пустым'
);

// Секции replica может вообще не быть — db_endpoint() всё равно подставит
// пароль основной базы, поэтому и здесь наследование есть.
check(
    db_replica_password_inherited(['password' => 'main-secret']) === true,
    'без секции replica пароль основной тоже наследуется'
);

// ...и не наследуется, если у основной базы пароля нет.
check(
    db_replica_password_inherited(['password' => '']) === false,
    'без секции replica и без пароля основной наследования нет'
);

// Регрессия: подсказка должна быть подключена к ответу API, иначе функция
// ничего не изменит в том, что видит админ.
$api = (string)file_get_contents(dirname(__DIR__) . '/monitoring/api/db_ha.php');
check(
    strpos($api, 'db_replica_password_inherited($cfg)') !== false,
    'подсказка подключена в api/db_ha.php'
);
check(
    strpos($api, 'подставлен пароль основной базы') !== false,
    'в текст ошибки дописывается источник пароля'
);

// Регрессия на молчаливый успех: обработчик сохранения обязан разбирать
// результат проверки, а не показывать зелёный тост всегда.
$js = (string)file_get_contents(dirname(__DIR__) . '/frontend/js/settings.js');
$saveHandler = substr($js, (int)strpos($js, "val('db-ha-save')"), 1200);
check(
    strpos($saveHandler, 'dbHaSaveProblems(data)') !== false,
    'сохранение проверяет результат подключения'
);
check(
    preg_match("/dbHaSaveProblems[\s\S]{0,2000}showToast\([\s\S]{0,400}'success'/", $js) === 1,
    'успешный toast показывается только когда проблем нет'
);

echo "\nИтог: passed={$passed} failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
