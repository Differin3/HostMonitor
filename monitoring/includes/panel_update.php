<?php
declare(strict_types=1);

function panel_repo_root(): ?string
{
    $root = realpath(dirname(__DIR__, 2));
    if ($root === false || !is_dir($root . DIRECTORY_SEPARATOR . '.git')) {
        return null;
    }
    return $root;
}

function panel_git(string $root, string $args, int $timeoutSec = 30): array
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true)) {
        return ['ok' => false, 'output' => 'shell_exec отключён в PHP'];
    }
    $cmd = 'cd ' . escapeshellarg($root) . ' && git ' . $args . ' 2>&1';
    $output = null;
    if ($timeoutSec > 0 && function_exists('proc_open')) {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'output' => 'Не удалось запустить git'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSec;
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
        return ['ok' => $exitCode === 0, 'output' => $output];
    }
    $output = shell_exec($cmd);
    return ['ok' => $output !== null, 'output' => trim((string)$output)];
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

function panel_update_check(bool $fetch = true): array
{
    $root = panel_repo_root();
    if ($root === null) {
        return [
            'available' => false,
            'error' => 'Репозиторий git не найден (панель установлена не через git?)',
            'current_commit' => '',
            'remote_commit' => '',
            'branch' => '',
            'commits' => [],
            'repo_url' => '',
        ];
    }

    if ($fetch) {
        $fetchResult = panel_git($root, 'fetch origin --prune', 60);
        if (!$fetchResult['ok']) {
            return [
                'available' => false,
                'error' => 'git fetch не удался: ' . $fetchResult['output'],
                'current_commit' => '',
                'remote_commit' => '',
                'branch' => panel_git_branch($root),
                'commits' => [],
                'repo_url' => panel_git_remote_url($root),
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
                $hash = substr($line, 0, strpos($line, ' ') ?: 7);
                $msg = trim(substr($line, strlen($hash)));
                $commits[] = ['hash' => $hash, 'message' => ltrim($msg)];
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
    ];
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
    $pull = panel_git($root, 'pull --ff-only origin ' . $branch, 120);
    if (!$pull['ok']) {
        return ['success' => false, 'error' => 'git pull: ' . $pull['output']];
    }

    $after = panel_git($root, 'rev-parse HEAD', 10);
    return [
        'success' => true,
        'message' => 'Панель обновлена',
        'output' => $pull['output'],
        'commit' => $after['output'] ?? '',
        'branch' => $branch,
    ];
}
