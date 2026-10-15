<?php
/**
 * Тесты классификатора SSH-событий (ssh_event_type_from).
 *
 * Зеркалит разбор агента: используется как fallback для старых агентов и
 * при бэкфилле уже накопленных записей ssh_auth_logs.
 *
 * Запуск:  php tests/test_ssh_event_type.php
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
    [1, 'SUCCESS user=root ip=1.2.3.4 port=22', 'accepted'],
    [true, 'Accepted password for root from 1.2.3.4 port 22', 'accepted'],
    [0, 'FAIL user=root ip=1.2.3.4 port=22', 'failed'],
    [0, 'Failed password for root from 1.2.3.4 port 22', 'failed'],
    [0, 'FAIL user=invalid admin ip=1.2.3.4 port=22', 'invalid_user'],
    [0, 'Failed password for invalid user admin from 1.2.3.4 port 22', 'invalid_user'],
    [0, 'error: maximum authentication attempts exceeded for root from 1.2.3.4 port 22', 'max_attempts'],
    [0, 'authentication failure; rhost=5.6.7.8 user=root', 'pam_failure'],
    [null, 'INFO disconnected user=root ip=1.2.3.4 port=22', 'disconnect'],
    [null, 'INFO connection ip=1.2.3.4 port=22', 'connection_closed'],
    [null, 'Connection closed by authenticating user root 1.2.3.4 port 22', 'connection_closed'],
    [null, 'INFO session opened for user root by (uid=0)', 'session'],
    [null, 'нечто непонятное', 'other'],
];

echo "== ssh_event_type_from ==\n";
foreach ($cases as [$success, $message, $expected]) {
    $got = ssh_event_type_from($success, $message);
    check($got === $expected, "{$expected}: " . substr($message, 0, 46), "получено {$got}");
}

echo "\nПройдено: {$passed}, провалено: {$failed}\n";
exit($failed ? 1 : 0);
