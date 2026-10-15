<?php
/**
 * Тесты извлечения источника системного лога (system_source_from).
 *
 * Источник (process/юнит) раньше был склеен с сообщением в виде
 * "process: message". Функция выносит его в отдельное поле и нужна как
 * fallback для старых агентов и при бэкфилле уже накопленных записей logs.
 *
 * Запуск:  php tests/test_log_source.php
 * Выход:   0 — все проверки прошли, 1 — есть падения.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/monitoring/includes/helpers.php';

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

$cases = [
    ['sshd: Accepted password for root from 1.2.3.4 port 22', 'sshd', 'Accepted password for root from 1.2.3.4 port 22'],
    ['CRON: pam_unix(cron:session): session closed for user root', 'CRON', 'pam_unix(cron:session): session closed for user root'],
    ['python3: [php] PHP Notice: Array to string conversion', 'python3', '[php] PHP Notice: Array to string conversion'],
    ['systemd-logind: New session 5 of user root', 'systemd-logind', 'New session 5 of user root'],
    ['pve-ha-lrm: watchdog active', 'pve-ha-lrm', 'watchdog active'],
    ['kernel: usb 1-1: new high-speed USB device', 'kernel', 'usb 1-1: new high-speed USB device'],
    // не префикс-источник: несколько слов / пробелы до двоеточия
    ['app crashed: out of memory', null, 'app crashed: out of memory'],
    ['  leading space: value', null, '  leading space: value'],
    ['no colon at all', null, 'no colon at all'],
];

echo "== system_source_from ==\n";
foreach ($cases as [$message, $expectedSource, $expectedMessage]) {
    [$source, $clean] = system_source_from($message);
    $ok = ($source === $expectedSource) && ($clean === $expectedMessage);
    check(
        $ok,
        'источник ' . ($expectedSource ?? 'null') . ': ' . substr($message, 0, 40),
        "получено source=" . var_export($source, true) . ", message=" . var_export($clean, true)
    );
}

// длинный префикс (>64) не должен считаться именем процесса
[$source, $clean] = system_source_from(str_repeat('a', 65) . ': payload');
check($source === null, 'слишком длинный префикс не источник', var_export($source, true));

echo "\nПройдено: {$passed}, провалено: {$failed}\n";
exit($failed ? 1 : 0);
