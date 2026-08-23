<?php
declare(strict_types=1);

function panel_config_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'panel.local.php';
}

function panel_config_load(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $cfg = [
        'repo_root' => '',
        'git_bin' => '',
        'git_wrapper' => '',
        'git_sudo_user' => '',
        'web_root' => '',
    ];
    $path = panel_config_path();
    if (is_file($path)) {
        $loaded = include $path;
        if (is_array($loaded)) {
            // Старые/альтернативные ключи из разных установщиков.
            if (empty($loaded['repo_root']) && !empty($loaded['repository_root'])) {
                $loaded['repo_root'] = (string)$loaded['repository_root'];
            }
            if (empty($loaded['git_wrapper']) && !empty($loaded['git_script'])) {
                $loaded['git_wrapper'] = (string)$loaded['git_script'];
            }
            if (empty($loaded['git_sudo_user']) && !empty($loaded['sudo_user'])) {
                $loaded['git_sudo_user'] = (string)$loaded['sudo_user'];
            }
            $cfg = array_merge($cfg, $loaded);
        }
    }
    $envRoot = getenv('PANEL_REPO_ROOT');
    if ($envRoot !== false && $envRoot !== '') {
        $cfg['repo_root'] = (string)$envRoot;
    }
    $envUser = getenv('PANEL_GIT_USER');
    if ($envUser !== false && $envUser !== '') {
        $cfg['git_sudo_user'] = (string)$envUser;
    }
    // Python-сервис часто идёт от monitoring/root — sudo не нужен по умолчанию.
    if (($cfg['git_sudo_user'] ?? '') === '' && panel_posix_user() === 'monitoring') {
        $cfg['git_sudo_user'] = 'monitoring';
    }
    return $cfg;
}

function panel_repo_root(): ?string
{
    $cfg = panel_config_load();
    $candidates = [];
    if (($cfg['repo_root'] ?? '') !== '') {
        $candidates[] = (string)$cfg['repo_root'];
    }
    $fromIncludes = realpath(dirname(__DIR__, 2));
    if ($fromIncludes !== false) {
        $candidates[] = $fromIncludes;
    }
    foreach (['/opt/monitoring', '/var/www/monitoring'] as $fixed) {
        $candidates[] = $fixed;
    }
    $webRoot = realpath(dirname(__DIR__));
    if ($webRoot !== false) {
        $candidates[] = $webRoot;
        $candidates[] = dirname($webRoot);
    }

    $seen = [];
    foreach ($candidates as $path) {
        $path = rtrim((string)$path, '/\\');
        if ($path === '' || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        $real = realpath($path);
        if ($real === false) {
            continue;
        }
        if (is_dir($real . DIRECTORY_SEPARATOR . '.git')) {
            return $real;
        }
    }
    return null;
}

function panel_git_bin(): string
{
    $cfg = panel_config_load();
    if (($cfg['git_bin'] ?? '') !== '' && is_executable((string)$cfg['git_bin'])) {
        return (string)$cfg['git_bin'];
    }
    foreach (['/usr/bin/git', '/usr/local/bin/git', '/bin/git'] as $bin) {
        if (is_executable($bin)) {
            return $bin;
        }
    }
    return 'git';
}

function panel_disabled_functions(): array
{
    return array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
}

function panel_can_call(string $fn): bool
{
    return function_exists($fn) && !in_array($fn, panel_disabled_functions(), true);
}

function panel_run_shell(string $cmd, int $timeoutSec = 30): array
{
    if (panel_can_call('proc_open')) {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, [
            'HOME' => '/tmp',
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
        if (is_resource($proc)) {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $stdout = '';
            $stderr = '';
            $deadline = microtime(true) + max(1, $timeoutSec);
            while (microtime(true) < $deadline) {
                $stdout .= (string)stream_get_contents($pipes[1]);
                $stderr .= (string)stream_get_contents($pipes[2]);
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    break;
                }
                usleep(100000);
            }
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
            $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
            return ['ok' => $exitCode === 0, 'output' => $output, 'exit_code' => $exitCode];
        }
    }

    if (panel_can_call('exec')) {
        $lines = [];
        $code = 1;
        @exec($cmd, $lines, $code);
        return ['ok' => $code === 0, 'output' => trim(implode("\n", $lines)), 'exit_code' => $code];
    }

    if (panel_can_call('shell_exec')) {
        $output = shell_exec($cmd);
        $text = trim((string)$output);
        $failed = $text === '' || stripos($text, 'fatal:') !== false || stripos($text, 'error:') !== false;
        return ['ok' => !$failed, 'output' => $text, 'exit_code' => $failed ? 1 : 0];
    }

    return ['ok' => false, 'output' => 'В PHP отключены proc_open, exec и shell_exec', 'exit_code' => 127];
}

function panel_posix_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = @posix_getpwuid(posix_geteuid());
        if (is_array($info) && !empty($info['name'])) {
            return (string)$info['name'];
        }
    }
    $who = trim((string)@shell_exec('whoami 2>/dev/null'));
    return $who !== '' ? $who : 'unknown';
}

function panel_git_can_write(string $root): bool
{
    $gitDir = $root . DIRECTORY_SEPARATOR . '.git';
    if (!is_dir($gitDir)) {
        return false;
    }
    return is_writable($root) && is_writable($gitDir);
}

function panel_git_command(string $root, string $args, bool $useSudo = true): string
{
    $cfg = panel_config_load();
    $wrapper = trim((string)($cfg['git_wrapper'] ?? ''));
    if ($wrapper === '') {
        $wrapper = dirname(__DIR__, 2) . '/scripts/panel_git.sh';
    }
    if (is_file($wrapper)) {
        @chmod($wrapper, 0755);
    }

    $me = panel_posix_user();
    $sudoUser = trim((string)($cfg['git_sudo_user'] ?? ''));
    $needSudo = $useSudo && $sudoUser !== '' && $sudoUser !== $me && $me !== 'root';

    if (is_file($wrapper) && is_executable($wrapper)) {
        $base = escapeshellarg($wrapper) . ' ' . escapeshellarg($root) . ' ' . $args;
        if ($needSudo) {
            return 'sudo -n -u ' . escapeshellarg($sudoUser) . ' -- ' . $base . ' 2>&1';
        }
        return $base . ' 2>&1';
    }

    $git = escapeshellarg(panel_git_bin());
    $safe = '-c safe.directory=' . escapeshellarg($root);
    $gitCmd = $git . ' ' . $safe . ' -C ' . escapeshellarg($root) . ' ' . $args;
    if ($needSudo) {
        return 'sudo -n -u ' . escapeshellarg($sudoUser)
            . ' -- env HOME=/tmp GIT_TERMINAL_PROMPT=0 '
            . $gitCmd . ' 2>&1';
    }
    return 'env HOME=/tmp GIT_TERMINAL_PROMPT=0 ' . $gitCmd . ' 2>&1';
}

function panel_git_diagnose(string $root): string
{
    $parts = [];
    $parts[] = 'user=' . panel_posix_user();
    $parts[] = 'root=' . $root;
    $parts[] = 'writable=' . (panel_git_can_write($root) ? 'yes' : 'no');
    $gitDir = $root . '/.git';
    if (function_exists('posix_getpwuid') && is_dir($gitDir)) {
        $uid = @fileowner($gitDir);
        if ($uid !== false) {
            $owner = @posix_getpwuid($uid);
            $parts[] = 'git_owner=' . (is_array($owner) ? (string)($owner['name'] ?? $uid) : (string)$uid);
        }
    }
    $cfg = panel_config_load();
    $wrapper = (string)($cfg['git_wrapper'] ?? '');
    if ($wrapper === '') {
        $wrapper = dirname(__DIR__, 2) . '/scripts/panel_git.sh';
    }
    $parts[] = 'wrapper=' . (is_executable($wrapper) ? 'ok' : 'missing');
    $parts[] = 'sudo_user=' . ((string)($cfg['git_sudo_user'] ?? '') ?: '-');
    return implode('; ', $parts);
}

function panel_git(string $root, string $args, int $timeoutSec = 30): array
{
    // 1) Если текущий пользователь уже может писать в .git — без sudo.
    // 2) Иначе sudo на git_sudo_user.
    // 3) Последний шанс — прямой git без sudo.
    $attempts = [];
    if (panel_git_can_write($root) || panel_posix_user() === 'root') {
        $attempts[] = false;
    }
    $cfg = panel_config_load();
    $sudoUser = trim((string)($cfg['git_sudo_user'] ?? ''));
    if ($sudoUser !== '' && $sudoUser !== panel_posix_user() && panel_posix_user() !== 'root') {
        $attempts[] = true;
    }
    if (!in_array(false, $attempts, true)) {
        $attempts[] = false;
    }

    $last = ['ok' => false, 'output' => '', 'exit_code' => 1];
    foreach ($attempts as $useSudo) {
        $result = panel_run_shell(panel_git_command($root, $args, $useSudo), $timeoutSec);
        if ($result['ok']) {
            return $result;
        }
        $last = $result;
    }
    return $last;
}

function panel_git_branch(string $root): string
{
    $r = panel_git($root, 'rev-parse --abbrev-ref HEAD', 10);
    if (!$r['ok'] || $r['output'] === '') {
        return 'main';
    }
    $branch = trim($r['output']);
    if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $branch)) {
        return 'main';
    }
    return $branch;
}

function panel_git_remote_url(string $root): string
{
    $r = panel_git($root, 'remote get-url origin', 10);
    return $r['ok'] ? $r['output'] : '';
}

function panel_web_root(): string
{
    $cfg = panel_config_load();
    if (($cfg['web_root'] ?? '') !== '') {
        $p = realpath((string)$cfg['web_root']);
        if ($p !== false) {
            return $p;
        }
    }
    $p = realpath(dirname(__DIR__));
    return $p !== false ? $p : dirname(__DIR__);
}

function panel_sync_web_from_repo(string $repoRoot): array
{
    $webRoot = panel_web_root();
    $srcMonitoring = $repoRoot . DIRECTORY_SEPARATOR . 'monitoring';
    $srcFrontend = $repoRoot . DIRECTORY_SEPARATOR . 'frontend';
    if (!is_dir($srcMonitoring)) {
        return ['ok' => true, 'skipped' => true, 'message' => ''];
    }
    if (realpath($webRoot) === realpath($srcMonitoring)) {
        return ['ok' => true, 'skipped' => true, 'message' => ''];
    }

    $rsync = '/usr/bin/rsync';
    if (!is_executable($rsync)) {
        $rsync = trim((string)shell_exec('command -v rsync 2>/dev/null') ?: '');
    }
    if ($rsync !== '' && is_executable($rsync)) {
        $excludes = [
            '--exclude=data/db.local.php',
            '--exclude=data/db.active.php',
            '--exclude=data/panel.local.php',
            '--exclude=data/retention.last',
        ];
        $cmd1 = escapeshellarg($rsync) . ' -a --delete ' . implode(' ', $excludes) . ' '
            . escapeshellarg($srcMonitoring . '/') . ' ' . escapeshellarg($webRoot . '/') . ' 2>&1';
        $r1 = panel_run_shell($cmd1, 120);
        if (!$r1['ok']) {
            return ['ok' => false, 'message' => 'rsync monitoring: ' . $r1['output']];
        }
        if (is_dir($srcFrontend)) {
            $destFrontend = dirname($webRoot) . DIRECTORY_SEPARATOR . 'frontend';
            if (realpath($destFrontend) !== realpath($srcFrontend)) {
                $cmd2 = escapeshellarg($rsync) . ' -a --delete '
                    . escapeshellarg($srcFrontend . '/') . ' ' . escapeshellarg($destFrontend . '/') . ' 2>&1';
                $r2 = panel_run_shell($cmd2, 120);
                if (!$r2['ok']) {
                    return ['ok' => false, 'message' => 'rsync frontend: ' . $r2['output']];
                }
            }
        }
        return ['ok' => true, 'skipped' => false, 'message' => 'Файлы панели синхронизированы'];
    }

    return ['ok' => false, 'message' => 'rsync не найден — установите rsync для синхронизации после git pull'];
}

function panel_update_check(bool $fetch = true): array
{
    $root = panel_repo_root();
    if ($root === null) {
        return [
            'available' => false,
            'error' => 'Репозиторий git не найден. Укажите repo_root в data/panel.local.php (обычно /opt/monitoring).',
            'current_commit' => '',
            'remote_commit' => '',
            'branch' => '',
            'commits' => [],
            'repo_url' => '',
            'repo_root' => '',
        ];
    }

    if ($fetch) {
        $fetchResult = panel_git($root, 'fetch origin --prune', 90);
        if (!$fetchResult['ok']) {
            $detail = trim((string)($fetchResult['output'] ?? ''));
            if ($detail === '') {
                $detail = 'пустой ответ (exit ' . (int)($fetchResult['exit_code'] ?? 1) . ')';
            }
            $hint = panel_update_hint($detail);
            $diag = panel_git_diagnose($root);
            return [
                'available' => false,
                'error' => 'git fetch не удался: ' . $detail . $hint . "\n\nДиагностика: " . $diag,
                'current_commit' => '',
                'remote_commit' => '',
                'branch' => panel_git_branch($root),
                'commits' => [],
                'repo_url' => panel_git_remote_url($root),
                'repo_root' => $root,
            ];
        }
    }

    $branch = panel_git_branch($root);
    $local = panel_git($root, 'rev-parse HEAD', 10);
    $remote = panel_git($root, 'rev-parse origin/' . $branch, 10);
    if (!$local['ok'] || !$remote['ok']) {
        return [
            'available' => false,
            'error' => 'Не удалось определить версию: ' . ($local['output'] ?: $remote['output']),
            'current_commit' => $local['output'] ?? '',
            'remote_commit' => $remote['output'] ?? '',
            'branch' => $branch,
            'commits' => [],
            'repo_url' => panel_git_remote_url($root),
            'repo_root' => $root,
        ];
    }

    $available = $local['output'] !== $remote['output'];
    $commits = [];
    if ($available) {
        $log = panel_git($root, 'log --oneline HEAD..origin/' . $branch, 15);
        if ($log['ok'] && $log['output'] !== '') {
            foreach (preg_split('/\r?\n/', $log['output']) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $space = strpos($line, ' ');
                $hash = $space === false ? $line : substr($line, 0, $space);
                $msg = $space === false ? '' : trim(substr($line, $space + 1));
                $commits[] = ['hash' => $hash, 'message' => $msg];
            }
        }
    }

    return [
        'available' => $available,
        'error' => null,
        'current_commit' => $local['output'],
        'remote_commit' => $remote['output'],
        'branch' => $branch,
        'commits' => $commits,
        'repo_url' => panel_git_remote_url($root),
        'repo_root' => $root,
    ];
}

function panel_update_hint(string $output): string
{
    $out = strtolower($output);
    $hints = [];
    $me = panel_posix_user();
    if (str_contains($out, 'dubious ownership') || str_contains($out, 'safe.directory')) {
        $hints[] = 'Выполните: sudo -u monitoring git config --global --add safe.directory /opt/monitoring';
    }
    if (str_contains($out, 'permission denied') || str_contains($out, 'cannot open') || str_contains($out, 'unable to access')) {
        $hints[] = 'Проверьте владельца репозитория: chown -R monitoring:monitoring /opt/monitoring';
    }
    if (str_contains($out, 'sudo:') || str_contains($out, 'a password is required') || str_contains($out, 'not allowed')) {
        $hints[] = "Добавьте sudoers для пользователя PHP ({$me}): {$me} ALL=(monitoring) NOPASSWD: /opt/monitoring/scripts/panel_git.sh";
    }
    if (str_contains($out, 'could not resolve host') || str_contains($out, 'failed to connect') || str_contains($out, 'network is unreachable')) {
        $hints[] = 'Нет доступа к GitHub с сервера (DNS/firewall). Проверьте: curl -I https://github.com';
    }
    if (str_contains($out, 'authentication failed') || str_contains($out, 'could not read username') || str_contains($out, 'publickey')) {
        $hints[] = 'Нужна авторизация к remote (HTTPS token или SSH-ключ для пользователя monitoring).';
    }
    if (str_contains($out, 'git: command not found') || str_contains($out, 'git not found')) {
        $hints[] = 'Установите git: apt install git';
    }
    if ($hints === []) {
        $hints[] = 'На сервере: bash /opt/monitoring/scripts/fix_panel_git.sh';
    }
    return "\n\nПодсказка: " . implode(' ', $hints);
}

function panel_update_apply(): array
{
    $root = panel_repo_root();
    if ($root === null) {
        return ['success' => false, 'error' => 'Репозиторий git не найден'];
    }

    $status = panel_git($root, 'status --porcelain', 10);
    if (!$status['ok']) {
        return ['success' => false, 'error' => 'git status: ' . $status['output']];
    }
    if (trim($status['output']) !== '') {
        return [
            'success' => false,
            'error' => 'Рабочая копия содержит локальные изменения — обновление отменено',
            'dirty' => true,
        ];
    }

    $check = panel_update_check(true);
    if (!empty($check['error'])) {
        return ['success' => false, 'error' => $check['error']];
    }
    if (!$check['available']) {
        return ['success' => true, 'message' => 'Панель уже актуальна', 'already_up_to_date' => true];
    }

    $branch = $check['branch'];
    $pull = panel_git($root, 'pull --ff-only origin ' . $branch, 180);
    if (!$pull['ok']) {
        return ['success' => false, 'error' => 'git pull: ' . $pull['output'] . panel_update_hint($pull['output'])];
    }

    $sync = panel_sync_web_from_repo($root);
    if (!$sync['ok']) {
        return [
            'success' => false,
            'error' => $pull['output'] . "\n" . ($sync['message'] ?? 'Ошибка синхронизации'),
            'pulled' => true,
        ];
    }

    $after = panel_git($root, 'rev-parse HEAD', 10);
    $msg = 'Панель обновлена';
    if (!empty($sync['message'])) {
        $msg .= '. ' . $sync['message'];
    }
    return [
        'success' => true,
        'message' => $msg,
        'output' => $pull['output'],
        'commit' => $after['output'] ?? '',
        'branch' => $branch,
    ];
}

function panel_config_save(array $cfg): void
{
    $dir = dirname(panel_config_path());
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог data/');
    }
    $export = var_export([
        'repo_root' => (string)($cfg['repo_root'] ?? '/opt/monitoring'),
        'git_wrapper' => (string)($cfg['git_wrapper'] ?? '/opt/monitoring/scripts/panel_git.sh'),
        'git_sudo_user' => (string)($cfg['git_sudo_user'] ?? 'monitoring'),
        'web_root' => (string)($cfg['web_root'] ?? ''),
    ], true);
    $php = "<?php\n// Сгенерировано установщиком. Не коммить.\nreturn {$export};\n";
    if (file_put_contents(panel_config_path(), $php, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать data/panel.local.php');
    }
    @chmod(panel_config_path(), 0600);
}
