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

// Регрессия: db_endpoint() подставляет name/user основной базы, когда у
// резерва они пусты. Форма не должна показывать унаследованные значения —
// иначе первое же сохранение закрепит их как явные, и резерв «прилипнет»
// к старому имени/пользователю основной базы.
echo "\ndb_replica_stored: форма показывает сохранённые значения резерва\n";

$stored = db_replica_stored([
    'name' => 'prod_db',
    'user' => 'prod_user',
    'password' => 'main-secret',
    'replica' => ['host' => 'db-standby', 'name' => '', 'user' => '', 'password' => ''],
]);
check($stored['name'] === '', 'пустое имя резерва не подменяется именем основной');
check($stored['user'] === '', 'пустой пользователь резерва не подменяется пользователем основной');
check($stored['has_password'] === false, 'пустой пароль резерва не считается заданным');

$storedExplicit = db_replica_stored([
    'name' => 'prod_db',
    'user' => 'prod_user',
    'password' => 'main-secret',
    'replica' => ['name' => 'rep_db', 'user' => 'rep_user', 'password' => 'rep-secret'],
]);
check($storedExplicit['name'] === 'rep_db', 'явное имя резерва возвращается как есть');
check($storedExplicit['user'] === 'rep_user', 'явный пользователь резерва возвращается как есть');
check($storedExplicit['has_password'] === true, 'заданный пароль резерва отмечается как сохранённый');

$storedNoSection = db_replica_stored(['name' => 'prod_db', 'user' => 'prod_user']);
check(
    $storedNoSection['name'] === '' && $storedNoSection['user'] === '' && $storedNoSection['has_password'] === false,
    'без секции replica форма пуста, наследования нет'
);

check(
    strpos($api, "'replica_stored' => db_replica_stored(\$cfg)") !== false,
    'api/db_ha.php отдаёт replica_stored'
);

$settingsPhp = (string)file_get_contents(dirname(__DIR__) . '/monitoring/settings.php');
check(
    strpos($settingsPhp, 'db_replica_stored($dbHaCfg)') !== false,
    'settings.php берёт сохранённые значения резерва'
);
check(
    strpos($settingsPhp, "dbHaReplicaStored['name']") !== false
        && strpos($settingsPhp, "dbHaReplicaStored['user']") !== false,
    'settings.php рендерит имя и пользователя резерва из хранилища'
);

check(strpos($js, 'data.replica_stored') !== false, 'settings.js читает data.replica_stored');
check(
    strpos($js, "setv('db-replica-name', replicaName)") !== false
        && strpos($js, "setv('db-replica-user', replicaUser)") !== false,
    'settings.js берёт имя и пользователя резерва из сохранённых'
);

echo "\nИтог: passed={$passed} failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
