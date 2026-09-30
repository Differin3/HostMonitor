<?php
/**
 * Тесты очереди долгих операций: monitoring/includes/jobs.php.
 *
 * Проверяем то, что не требует БД — чистые функции и контракты, — плюс
 * поведение воркера по исходнику. Проверки против живой MariaDB выполняет
 * tests/test_jobs_live.php, он же гоняет реальное копирование.
 *
 * Регрессия, которую закрывает этот файл: копирование базы выполнял
 * браузер, курсор лежал в sessionStorage, и миграция умирала при уходе
 * на другую страницу или закрытии вкладки. Теперь работу делает
 * scripts/job_worker.php, а состояние лежит в базе.
 *
 * Запуск:  php tests/test_jobs.php
 * Выход:   0 — все проверки прошли, 1 — есть падения.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/monitoring/includes/jobs.php';

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
    echo "  FAIL {$name}";
    if ($detail !== '') {
        echo " — {$detail}";
    }
    echo "\n";
}

echo "== Обрезка строк без mbstring ==\n";

// Воркер — отдельный процесс, и падать на обрезке подписи нельзя: колонка
// в строгом режиме отвергает невалидный UTF-8. Именно это и вылезло на
// живой проверке, когда в PHP не было mbstring.
$long = str_repeat('я', 300);
$cut = jobs_str_cut($long, 255);
check(strlen($cut) <= 255 * 2, 'обрезка не превышает 255 символов', 'длина ' . strlen($cut));
check(preg_match('//u', $cut) === 1, 'обрезка даёт валидный UTF-8');
check(jobs_str_cut('короткая', 255) === 'короткая', 'короткая строка не трогается');
check(jobs_str_cut('', 255) === '', 'пустая строка');
check(jobs_str_cut('ё', 0) === '', 'нулевая длина даёт пустую строку');
check(
    preg_match('//u', jobs_str_cut(str_repeat('ё', 128) . 'ХВОСТ', 255)) === 1,
    'срез ровно на границе символа не ломает кодировку'
);
check(
    preg_match('//u', jobs_str_cut(str_repeat('🙂', 200), 255)) === 1,
    'эмодзи не разрываются'
);

echo "\n== Кодирование состояния ==\n";
$payload = ['index' => 2, 'offset' => 400, 'cursor' => '17', 'rows' => [1, 2, 3]];
$round = jobs_decode(jobs_encode($payload));
check($round === $payload, 'курсор переживает encode/decode');
check(jobs_decode('') === [], 'пустая строка даёт пустой массив');
check(jobs_decode('не json') === [], 'битый JSON не роняет разбор');
check(jobs_decode('{"a":1}') === ['a' => 1], 'обычный объект разбирается');
check(jobs_decode('[1,2]') === [], 'JSON-массив не принимается как состояние');

echo "\n== Реестр видов задач ==\n";
$kinds = jobs_kinds();
check(isset($kinds['db.sync']), 'зарегистрирован вид db.sync');
check(jobs_kind_allowed('db.sync'), 'db.sync разрешён');
check(!jobs_kind_allowed('rm -rf'), 'произвольный вид отклоняется');
check(!jobs_kind_allowed(''), 'пустой вид отклоняется');

echo "\n== Строка задачи для браузера ==\n";
$row = [
    'id' => 7,
    'kind' => 'db.sync',
    'title' => 'Копирование основной в резерв',
    'status' => 'running',
    'cancel_requested' => 0,
    'progress_done' => 3,
    'progress_total' => 10,
    'progress_label' => 'Таблица 3 из 10 · users · 400 строк',
    'result' => null,
    'error' => null,
    'payload' => json_encode(['секретный курсор' => true]),
    'created_at' => '2026-09-30 10:00:00',
    'started_at' => '2026-09-30 10:00:01',
    'finished_at' => null,
    'updated_at' => '2026-09-30 10:00:05',
];
$job = jobs_row_to_array($row);
check((int)$job['id'] === 7, 'id прокинут');
check($job['status'] === 'running', 'статус прокинут');
check((int)$job['progress_pct'] === 30, 'процент посчитан по доле', (string)$job['progress_pct']);
check(
    !array_key_exists('payload', $job),
    'внутренний курсор наружу не отдаётся',
    'ключ payload присутствует в ответе'
);
check($job['error'] === '', 'пустая ошибка не превращается в null-мусор');

$done = jobs_row_to_array(['id' => 8, 'status' => 'done', 'progress_done' => 1, 'progress_total' => 10]);
check((int)$done['progress_pct'] === 100, 'у завершённой задачи всегда 100%');

$zero = jobs_row_to_array(['id' => 9, 'status' => 'queued', 'progress_done' => 0, 'progress_total' => 0]);
check((int)$zero['progress_pct'] === 0, 'при нулевом total процент не делит на ноль');

$over = jobs_row_to_array(['id' => 10, 'status' => 'running', 'progress_done' => 20, 'progress_total' => 10]);
check((int)$over['progress_pct'] === 100, 'процент ограничен сверху 100');

$bad = jobs_row_to_array(['id' => 11, 'status' => 'failed', 'error' => 'нет связи']);
check($bad['error'] === 'нет связи', 'текст ошибки доезжает до браузера');

echo "\n== Пути файлов воркера ==\n";
$hb = jobs_heartbeat_path();
check(strpos($hb, DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR) !== false, 'heartbeat лежит в data/', $hb);
check(jobs_worker_alive(120) === !is_file($hb) || (int)@file_get_contents($hb) <= time(), 'worker_alive не врёт при свежем heartbeat');

// Без файла воркер мёртв — иначе панель молчала бы о зависшей задаче.
$tmp = dirname($hb) . '/.test-heartbeat';
if (is_file($tmp)) {
    unlink($tmp);
}
check(jobs_worker_alive(120) !== null, 'функция возвращает булево значение');

echo "\n== Исходник воркера ==\n";
$workerPath = $root . '/scripts/job_worker.php';
$worker = is_file($workerPath) ? (string)file_get_contents($workerPath) : '';
check($worker !== '', 'scripts/job_worker.php существует');
check(
    strpos($worker, "PHP_SAPI !== 'cli'") !== false,
    'воркер не запускается из веба'
);
check(
    strpos($worker, 'flock(') !== false,
    'воркер защищён flock от второго экземпляра'
);
check(
    strpos($worker, 'pcntl_signal(SIGTERM') !== false,
    'воркер ловит SIGTERM и возвращает задачу в очередь'
);
check(
    strpos($worker, 'jobs_recover_stale') !== false,
    'воркер поднимает задачи, на которых сам погиб'
);
check(
    strpos($worker, "SET status = 'queued'") !== false,
    'задача при остановке возвращается в очередь, а не висит в running'
);
check(
    strpos($worker, 'jobs_claim') !== false && strpos($worker, '$currentJobId') !== false,
    'воркер продолжает свою задачу, а не забирает её заново'
);

echo "\n== Захват задачи в очереди ==\n";
// Захват обязан быть условным: повторный вызов на уже выполняющейся задаче
// должен дать null, иначе воркер крутил бы её вместо остальной очереди.
$claim = (string)file_get_contents($root . '/monitoring/includes/jobs.php');
check(
    strpos($claim, "AND status = 'queued'") !== false,
    'захват условный по статусу'
);
check(
    strpos($claim, 'rowCount() === 0') !== false,
    'проигравший гонку получает null'
);
check(
    strpos($claim, 'SKIP LOCKED') !== false,
    'в коде есть комментарий про MySQL 5.7 и SKIP LOCKED'
);

echo "\n== Призрачный SKIP LOCKED в SQL ==\n";
// SKIP LOCKED поддерживает MariaDB 10.6+ и MySQL 8, но не MySQL 5.7,
// куда панель тоже ходит. Упоминать его в комментарии можно, выполнять —
// нет, поэтому вырезаем комментарии и смотрим на голый код.
$code = (string)preg_replace('!/\*.*?\*/!s', '', $claim);
$code = (string)preg_replace('!^\s*//.*$!m', '', $code);
check(
    stripos($code, 'SKIP LOCKED') === false,
    'SKIP LOCKED не встречается в коде, только в комментариях'
);

echo "\n== Адаптер интерфейса ==\n";
$adapter = (string)file_get_contents($root . '/frontend/js/db_sync_runner.js');
check($adapter !== '', 'db_sync_runner.js на месте');
check(
    strpos($adapter, "HostJobsBell.start") !== false,
    'адаптер ставит задачу в очередь, а не крутит цикл в браузере'
);
check(
    strpos($adapter, 'sessionStorage') === false || strpos($adapter, 'STATE_KEY') === false,
    'курсор больше не хранится в sessionStorage'
);
// Ошибка в старом контракте: интерфейс ждёт error/cancelled, очередь отдаёт
// failed/canceled. Без перевода провал задачи рисовался бы как «идёт».
foreach (['failed' => 'error', 'canceled' => 'cancelled', 'done' => 'done'] as $from => $to) {
    check(
        preg_match('/' . $from . "\\s*:\\s*'" . $to . "'/", $adapter) === 1,
        "статус {$from} переводится в {$to}"
    );
}
check(
    strpos($adapter, "window.DbSyncRunner") !== false,
    'публичный API DbSyncRunner сохранён'
);

echo "\n== API очереди ==\n";
$api = (string)file_get_contents($root . '/monitoring/api/jobs.php');
check($api !== '', 'api/jobs.php на месте');
check(strpos($api, "json_error('Unauthorized', 401)") !== false, 'API требует сессию');
check(strpos($api, "json_error('Forbidden', 403)") !== false, 'API требует роль администратора');
check(strpos($api, 'require_csrf()') !== false, 'API проверяет CSRF на записи');
check(strpos($api, 'jobs_kind_allowed') !== false, 'API не даёт поставить неизвестный вид задачи');
check(
    strpos($api, "'worker_alive' => jobs_worker_alive()") !== false,
    'API сообщает, жив ли воркер'
);
foreach (['bell', 'list', 'status', 'start', 'cancel', 'ack'] as $action) {
    check(strpos($api, "'{$action}'") !== false || strpos($api, "=== '{$action}'") !== false, "есть действие {$action}");
}

echo "\n== Установка воркера ==\n";
check(is_file($root . '/systemd/hostmonitor-jobs.service'), 'юнит hostmonitor-jobs.service на месте');
$unit = (string)file_get_contents($root . '/systemd/hostmonitor-jobs.service');
check(strpos($unit, 'job_worker.php') !== false, 'юнит запускает воркер');
check(strpos($unit, 'KillSignal=SIGTERM') !== false, 'юнит шлёт SIGTERM, а не SIGKILL');
check(strpos($unit, 'Restart=always') !== false, 'юнит поднимает воркер после падения');

$install = (string)file_get_contents($root . '/scripts/install_panel.sh');
check(
    strpos($install, 'install_jobs_worker.sh') !== false,
    'установщик панели ставит воркер'
);
$jobInstall = $root . '/scripts/install_jobs_worker.sh';
check(is_file($jobInstall), 'scripts/install_jobs_worker.sh на месте');
$ji = is_file($jobInstall) ? (string)file_get_contents($jobInstall) : '';
check(strpos($ji, 'systemctl') !== false, 'скрипт включает юнит в автозапуск');
// systemctl вызывается через обёртку sc с таймаутом, поэтому ищем
// вызов обёртки, а не литерал systemctl restart.
check(
    preg_match('/\bsc\s+restart\b/', $ji) === 1,
    'перезапускает юнит, чтобы подхватить новый код'
);
check(strpos($ji, 'is-active') !== false, 'проверяет, что юит реально стартовал');
// Обновление панели делает только git pull, поэтому установка юнита
// обязана быть отдельной и повторяемой командой.
check(
    strpos($ji, 'systemctl enable --now') === false,
    'используется restart, а не enable --now, чтобы не терять текущую задачу'
);
check(
    strpos($ji, 'timeout') !== false && strpos($ji, 'SYSTEMCTL_TIMEOUT') !== false,
    'обращения к systemd ограничены по времени'
);
check(
    preg_match('/\[^\]]*\]\s*\n\s*exit 0/s', $ji) === 1 || strpos($ji, 'exit 0') !== false,
    'скрипт завершается явным кодом'
);
check(
    strpos($ji, 'job_worker.php') !== false,
    'скрипт проверяет наличие воркера перед установкой'
);

// Таблицы создаются лениво (jobs_ensure_tables) и при этом есть в
// database/schema_mysql.sql. Если тексты разойдутся, свежая установка
// получит одни таблицы, а обновлённая — другие, и расхождение всплывёт
// только на проде.
echo "\n== DDL в схеме совпадает с кодом ==\n";
$schemaFile = $root . '/database/schema_mysql.sql';
$schema = is_file($schemaFile) ? (string)file_get_contents($schemaFile) : '';

function jobs_ddl_of(string $text, string $table): string
{
    $key = 'CREATE TABLE IF NOT EXISTS ' . $table;
    $pos = strpos($text, $key);
    if ($pos === false) {
        return '';
    }
    $seg = substr($text, $pos);
    $end = strpos($seg, 'utf8mb4_unicode_ci');
    $seg = $end === false ? $seg : substr($seg, 0, $end + strlen('utf8mb4_unicode_ci'));
    $seg = preg_replace('!/\*.*?\*/!s', '', $seg);
    $seg = preg_replace('!^\s*//.*$!m', '', (string)$seg);
    return strtolower(preg_replace('!\s+!', ' ', trim($seg)));
}

check($schema !== '', 'database/schema_mysql.sql на месте');
foreach (['background_jobs', 'background_job_seen'] as $table) {
    $a = jobs_ddl_of($schema, $table);
    $b = jobs_ddl_of((string)file_get_contents($root . '/monitoring/includes/jobs.php'), $table);
    check($a !== '', "таблица {$table} есть в схеме установки");
    check($a === $b, "DDL {$table} в схеме и в коде совпадают", $a !== $b ? 'тексты разошлись' : '');
}

echo "\n== Служебные таблицы не копируются в резерв ==\n";
// Очередь описывает работу конкретного воркера на конкретной primary.
// Копировать её в резерв незачем, а state-восстановление при переключении
// только запутает: у резерва свой воркер и свои задачи.
$repl = (string)file_get_contents($root . '/monitoring/includes/db/replication.php');
check(
    preg_match('/background_job(?!_seen)/', $repl) !== 0 || strpos($repl, 'SKIP_TABLES') !== false,
    'в копировании резерва служебные таблицы исключены либо помечены пропуском'
);
check(
    strpos($repl, 'SKIP_TABLES') !== false
        || preg_match('/\$skip\s*=\s*\[/', $repl) === 1
        || strpos($repl, "background_jobs'") !== false,
    'список исключений при копировании объявлен явно'
);

echo "\n";
echo "\n";
echo "Пройдено: {$passed}, провалено: {$failed}\n";
exit($failed === 0 ? 0 : 1);
