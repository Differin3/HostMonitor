<?php
/**
 * Тесты механики смены ветки и обновления панели.
 *
 * Проверяет то, что молча ломалось до фикса:
 *  - сохранённый update_branch реально используется (раньше только записывался);
 *  - битый сохранённый канал не рушит страницу, а даёт fallback + warning;
 *  - имя ветки валидируется и не попадает в shell как произвольная строка;
 *  - apply переключает ветку ДО pull, а не тянет чужую ветку в текущую.
 *
 * Каждый сценарий выполняется в отдельном процессе: panel_config_load()
 * кэширует конфиг в static, поэтому сменить update_branch «на лету» нельзя.
 *
 * Запуск:  php tests/test_panel_update.php
 * Выход:   0 — все проверки прошли, 1 — есть падения.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/monitoring/includes/panel_update.php';

$passed = 0;
$failed = 0;

// ident для git нужен на весь тест: в цепочках «checkout && commit && push»
// флаги -c относятся только к первому git, а коммит без ident падает с
// «Please tell me who you are» и тест молча проверяет не тот ref.
putenv('GIT_AUTHOR_NAME=hm-test');
putenv('GIT_AUTHOR_EMAIL=hm-test@example.invalid');
putenv('GIT_COMMITTER_NAME=hm-test');
putenv('GIT_COMMITTER_EMAIL=hm-test@example.invalid');
putenv('GIT_CONFIG_GLOBAL=/dev/null');
putenv('GIT_CONFIG_SYSTEM=/dev/null');

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

function fail(string $name, string $detail = ''): void
{
    check(false, $name, $detail);
}

function sh(string $cmd, string $cwd): string
{
    $out = [];
    $rc = 0;
    exec('cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1', $out, $rc);
    return $rc === 0 ? trim(implode("\n", $out)) : 'ERR(' . $rc . '): ' . implode(' ', $out);
}

// ─── Песочница: bare origin + рабочий клон панели ────────────────────────────

$base = sys_get_temp_dir() . '/hm_panel_update_test';
$sandbox = $base . '/sandbox';
$origin = $base . '/origin.git';
$panel = $base . '/panel';
$author = '-c user.name=t -c user.email=t@t -c commit.gpgsign=false';

sh('rm -rf ' . escapeshellarg($base), sys_get_temp_dir());
mkdir($sandbox, 0777, true);

// Клон пустого origin пишет warning в stderr, поэтому проверяем результат по
// наличию каталогов, а не по пустому выводу sh().
sh('git init -q --bare ' . escapeshellarg($origin), $sandbox);
sh('git clone -q ' . escapeshellarg($origin) . ' work', $sandbox);
$work = $sandbox . '/work';
if (!is_dir($origin . '/objects') || !is_dir($work . '/.git')) {
    fwrite(STDERR, "не удалось создать origin: " . sh('git init --bare ' . escapeshellarg($origin), $sandbox) . "\n");
    exit(1);
}
sh('git ' . $author . ' checkout -q -b main', $work);
file_put_contents($work . '/f.txt', "one\n");
sh('git add -A && git commit -qm one', $work);
sh('git branch dev && git push -q origin main dev', $work);
sh('git symbolic-ref HEAD refs/heads/main', $origin);

sh('git clone -q -b main ' . escapeshellarg($origin) . ' ' . escapeshellarg($panel), $sandbox);
if (!is_dir($panel . '/.git')) {
    fwrite(STDERR, "не удалось клонировать панель\n");
    exit(1);
}

$cfgPath = dirname(__DIR__) . '/monitoring/data/panel.local.php';
$cfgExisted = is_file($cfgPath);
$cfgBackup = $cfgExisted ? file_get_contents($cfgPath) : null;

register_shutdown_function(static function () use ($base, $cfgPath, $cfgExisted, $cfgBackup): void {
    if ($cfgExisted) {
        file_put_contents($cfgPath, (string)$cfgBackup);
    } else {
        @unlink($cfgPath);
    }
    sh('rm -rf ' . escapeshellarg($base), sys_get_temp_dir());
});

function writeConfig(string $repoRoot, string $branch): void
{
    global $cfgPath;
    file_put_contents($cfgPath, "<?php\nreturn " . var_export(
        ['repo_root' => $repoRoot, 'update_branch' => $branch],
        true
    ) . ";\n");
}

// Каждый сценарий выполняется в отдельном процессе: panel_config_load()
// кэширует конфиг в static, поэтому сменить update_branch «на лету» нельзя.

// ─── 1. Сохранённый канал используется, а не игнорируется ────────────────────

writeConfig($panel, 'dev');
sh('git ' . $author . ' checkout -q dev && echo two > g.txt && git add -A && git commit -qm two && git push -q origin dev', $work);
// fetch нужен: панель клонирована до появления коммита в dev, а check(false)
// намеренно не ходит на remote — локальный origin/dev был бы устаревшим.
$check = panel_update_check(true);
check(
    ($check['branch'] ?? '') === 'dev',
    'check() берёт ветку из сохранённого update_branch',
    'получено ' . var_export($check['branch'] ?? null, true)
);
check(
    ($check['selected_branch'] ?? '') === 'dev',
    'selected_branch совпадает с сохранённым каналом',
    'получено ' . var_export($check['selected_branch'] ?? null, true)
);
check(
    ($check['available'] ?? false) === true,
    'обновление видно для сохранённого канала, даже когда панель на другой ветке',
    'available=' . var_export($check['available'] ?? null, true)
    . ' local=' . substr((string)($check['current_commit'] ?? ''), 0, 8)
    . ' remote=' . substr((string)($check['remote_commit'] ?? ''), 0, 8)
);

// Явно запрошенная ветка по-прежнему главнее сохранённой
$checkMain = panel_update_check(false, 'main');
check(
    ($checkMain['branch'] ?? '') === 'main',
    'явно запрошенная ветка перекрывает сохранённую',
    'получено ' . var_export($checkMain['branch'] ?? null, true)
);
check(
    ($checkMain['available'] ?? true) === false,
    'для ветки main обновлений нет (панель на ней и она актуальна)',
    'available=' . var_export($checkMain['available'] ?? null, true)
);

// ─── 2. Сохранённый канал, которого нет на origin → fallback + warning ───────

$r = panel_update_resolve_branch('', $panel);
$cfg = panel_config_load();
$staleCfg = (string)$cfgPath;
$backup = file_get_contents($staleCfg);
file_put_contents($staleCfg, str_replace("'dev'", "'feature/gone'", (string)$backup));

// Конфиг кэширован в static, поэтому проверяем сам guard, а не повторный check
$guardStale = panel_branch_guard('feature/gone', $panel);
check(
    $guardStale !== '',
    'guard отклоняет сохранённую ветку, которой нет на origin',
    'получено: ' . var_export($guardStale, true)
);

// Итоговый сценарий «битого канала» — в отдельном процессе
putenv('PANEL_REPO_ROOT=' . $panel);
// Дочерние сценарии требуют только панельный код, а не сам тест: иначе
// require запустил бы весь набор заново и процесс завис бы на рекурсии.
$bootstrap = $base . '/bootstrap.php';
file_put_contents($bootstrap, '<?php require ' . var_export(
    dirname(__DIR__) . '/monitoring/includes/panel_update.php',
    true
) . ";\n");

$childFile = $panel . '/.stale.php';
file_put_contents($childFile, <<<'PHPCODE'
<?php
require $argv[1];
putenv('PANEL_REPO_ROOT=' . $argv[2]);
$r = panel_update_check(false);
echo json_encode(['branch' => $r['branch'] ?? '', 'warning' => $r['warning'] ?? '',
                  'error' => $r['error'] ?? null], JSON_UNESCAPED_UNICODE);
PHPCODE
);
$out = [];
$rc = 0;
exec('PANEL_REPO_ROOT=' . escapeshellarg($panel) . ' ' . escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg($childFile) . ' ' . escapeshellarg($bootstrap) . ' ' . escapeshellarg($panel)
    . ' 2>/dev/null', $out, $rc);
@unlink($childFile);
$stale = json_decode(trim(implode('', $out)), true);
check(
    is_array($stale) && ($stale['branch'] ?? '') === 'main',
    'битый сохранённый канал откатывается на текущую ветку, а не ломает страницу',
    'получено ' . var_export(is_array($stale) ? $stale['branch'] : null, true)
);
check(
    is_array($stale) && $stale['error'] === null,
    'при битом канале error пуст (страница остаётся рабочей)',
    'получено ' . var_export(is_array($stale) ? $stale['error'] : null, true)
);
check(
    is_array($stale) && strpos((string)($stale['warning'] ?? ''), 'feature/gone') !== false,
    'админ получает warning с именем слетевшего канала',
    'получено ' . var_export(is_array($stale) ? $stale['warning'] : null, true)
);

file_put_contents($staleCfg, (string)$backup);

// ─── 3. Валидация имени ветки / защита от shell-инъекции ──────────────────────

$canary = $base . '/pwned';
$injections = [
    'dev;touch ' . $canary,
    'dev --upload-pack=evil',
    '--upload-pack=evil',
    'dev$(touch ' . $canary . ')',
    '../etc',
    'dev/../../etc',
    '-dev',
    str_repeat('a', 300),
];
$allBlocked = true;
foreach ($injections as $bad) {
    if (panel_branch_guard($bad, $panel) === '') {
        $allBlocked = false;
        echo "       не заблокировано: " . var_export($bad, true) . "\n";
    }
}
check($allBlocked, 'guard отклоняет все недопустимые имена веток', implode(', ', array_slice($injections, 0, 3)));
check(!file_exists($canary), 'инъекция в имя ветки не выполнилась (canary не создан)');

// Валидные имена проверяем на реально существующих в origin ветках:
// guard требует не только корректный синтаксис, но и наличие ветки.
$validBranches = ['feature/my-branch', 'release_1.2', 'v2'];
foreach ($validBranches as $b) {
    sh('git ' . $author . ' checkout -q -b ' . escapeshellarg($b) . ' main && git push -q origin '
        . escapeshellarg($b), $work);
}
$allValid = true;
foreach (array_merge(['main', 'dev'], $validBranches) as $ok) {
    if (panel_branch_guard($ok, $panel) !== '') {
        $allValid = false;
        echo "       отвергнута валидная ветка: {$ok} (" . panel_branch_guard($ok, $panel) . ")\n";
    }
}
check($allValid, 'guard принимает обычные существующие имена веток', implode(', ', $validBranches));

// ─── 4. apply переключает ветку до pull ───────────────────────────────────────

sh('git ' . $author . ' checkout -q main', $panel);
$apply = panel_update_apply(false, 'dev');
check(
    ($apply['success'] ?? false) === true,
    'apply() успешно переключает панель на другую ветку',
    (string)($apply['error'] ?? '')
);
$nowBranch = trim((string)shell_exec('cd ' . escapeshellarg($panel) . ' && git rev-parse --abbrev-ref HEAD'));
check($nowBranch === 'dev', 'после apply панель реально на целевой ветке', 'получено ' . $nowBranch);

$hasTwo = strpos((string)shell_exec(
    'cd ' . escapeshellarg($panel) . ' && git log --oneline -1'
), 'two') !== false;
check($hasTwo, 'apply притянул коммиты целевой ветки, а не текущей');

// apply с несуществующей веткой обязан отказать и не менять состояние
$before = trim((string)shell_exec('cd ' . escapeshellarg($panel) . ' && git rev-parse --abbrev-ref HEAD'));
$badApply = panel_update_apply(false, 'no-such-branch');
$after = trim((string)shell_exec('cd ' . escapeshellarg($panel) . ' && git rev-parse --abbrev-ref HEAD'));
check(($badApply['success'] ?? true) === false, 'apply() отказывает для несуществующей ветки');
check($before === $after, 'неудачный apply не переключил ветку', "было {$before}, стало {$after}");

// apply с инъекцией
$injApply = panel_update_apply(false, 'dev; touch ' . $canary);
check(($injApply['success'] ?? true) === false, 'apply() отказывает для имени с инъекцией');
check(!file_exists($canary), 'инъекция через apply не выполнилась');

// ─── Итог ─────────────────────────────────────────────────────────────────────

echo "\n";
echo "  Итог: {$passed} успешно, {$failed} провалено\n";
exit($failed === 0 ? 0 : 1);
