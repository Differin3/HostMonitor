<?php
/**
 * Тесты настроек панели.
 *
 * Главная регрессия, ради которой тест и появился: фронтенд (settings.js)
 * отправлял ключи lldp_* и snmp_*, которых не было в settings_defaults().
 * settings_upsert() бросал InvalidArgumentException, API отдавал 400, и
 * кнопка «Сохранить» падала ВСЕГДА. Тест сверяет множества ключей UI и
 * defaults, чтобы такой рассинхрон снова не проскочил, плюс проверяет
 * нормализацию новых ключей и политику пароля.
 *
 * Запуск:  php tests/test_settings.php
 * Выход:   0 — все проверки прошли, 1 — есть падения.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/monitoring/includes/db/settings.php';
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

function expect_throws(callable $fn, string $name): void
{
    try {
        $fn();
        check(false, $name, 'ожидалось исключение, but returned');
    } catch (InvalidArgumentException $e) {
        check(true, $name);
    } catch (Throwable $e) {
        check(false, $name, 'неожиданный тип: ' . get_class($e) . ': ' . $e->getMessage());
    }
}

// —— 1. Ключи UI обязаны существовать в defaults ——
echo "== settings.js <-> settings_defaults() ==\n";

$jsPath = dirname(__DIR__) . '/frontend/js/settings.js';
$js = (string)file_get_contents($jsPath);

function extract_map_keys(string $js, string $mapName): array
{
    if (!preg_match('/const ' . preg_quote($mapName, '/') . '\s*=\s*\{(.*?)\};/s', $js, $m)) {
        return [];
    }
    $keys = [];
    // Записи вида `key: 'value'` (ключ может быть закавычен, если содержит
    // дефис). Берём правую часть — это имя ключа, отправляемое на сервер.
    if (preg_match_all('/(?:[\'"][^\'"]+[\'"]|[\w$]+)\s*:\s*[\'"]([^\'"]+)[\'"]/', $m[1], $kv)) {
        $keys = $kv[1];
    }
    return $keys;
}

$uiKeys = array_merge(
    extract_map_keys($js, 'TEXT_FIELDS'),
    extract_map_keys($js, 'CHECK_FIELDS')
);
// Секретные поля добавляются в payload вручную, в картах их нет.
$uiKeys[] = 'smtp_password';
$uiKeys[] = 'telegram_bot_token';
$uiKeys = array_values(array_unique($uiKeys));

check(count($uiKeys) >= 25, 'нашли ключи UI в settings.js (' . count($uiKeys) . ')');

$defaults = settings_defaults();
foreach ($uiKeys as $key) {
    check(array_key_exists($key, $defaults), "defaults содержит UI-ключ '{$key}'");
}

// —— 2. Нормализация новых LLDP/SNMP-ключей ——
echo "\n== settings_normalize: lldp/snmp ==\n";

check(settings_normalize('lldp_passive', 'on') === 'true', 'lldp_passive on -> true');
check(settings_normalize('lldp_passive', '0') === 'false', 'lldp_passive 0 -> false');
check(settings_normalize('lldp_active_poll_known', true) === 'true', 'lldp_active_poll_known bool -> true');
check(settings_normalize('snmp_enabled', 'yes') === 'true', 'snmp_enabled yes -> true');
check(settings_normalize('lldp_listen_interface', 'eth0.100') === 'eth0.100', 'lldp_listen_interface валидный');
check(settings_normalize('lldp_listen_interface', '') === '', 'lldp_listen_interface пусто');
expect_throws(fn() => settings_normalize('lldp_listen_interface', 'bad iface!'), 'lldp_listen_interface мусор -> throw');
check(settings_normalize('snmp_community', '') === 'public', 'snmp_community пусто -> public');
check(settings_normalize('snmp_timeout', '1.50') === '1.5', 'snmp_timeout 1.50 -> 1.5');
check(settings_normalize('snmp_targets', '10.0.0.1, 10.0.0.2') === '10.0.0.1,10.0.0.2', 'snmp_targets чистка пробелов');
check(settings_normalize('snmp_targets', '') === '', 'snmp_targets пусто');
expect_throws(fn() => settings_normalize('snmp_targets', 'not-an-ip'), 'snmp_targets мусор -> throw');

// —— 3. Нормализация webhook ——
echo "\n== settings_normalize: webhook ==\n";

check(settings_normalize('notify_webhook_enabled', 'on') === '1', 'webhook enabled on -> 1');
check(settings_normalize('notify_webhook_enabled', '0') === '0', 'webhook enabled 0 -> 0');
check(settings_normalize('notify_webhook_url', '') === '', 'webhook url пусто');
check(
    settings_normalize('notify_webhook_url', 'https://hooks.example.com/x') === 'https://hooks.example.com/x',
    'webhook url https проходит'
);
expect_throws(fn() => settings_normalize('notify_webhook_url', 'ftp://x'), 'webhook url ftp -> throw');
expect_throws(fn() => settings_normalize('notify_webhook_url', 'не-url'), 'webhook url мусор -> throw');

// —— 4. Политика пароля (ранее min_password_length не применялся) ——
echo "\n== password_policy_error ==\n";

check(password_policy_error('abcdef', 6) === null, 'пароль 6 символов при минимуме 6 — ок');
check(is_string(password_policy_error('abc', 6)), 'пароль 3 символа при минимуме 6 — ошибка');
check(password_policy_error('a', 8) !== null, 'пароль 1 символ при минимуме 8 — ошибка');
check(password_policy_error(str_repeat('a', 201), 8) !== null, 'пароль >200 символов — ошибка');

echo "\nПройдено: {$passed}, провалено: {$failed}\n";
exit($failed ? 1 : 0);
