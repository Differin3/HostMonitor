<?php
/**
 * Слепок API панельного db_config.php: какие функции объявлены, с какими
 * сигнатурами и кого они вызывают.
 *
 * Нужен, чтобы разбиение файла на модули можно было доказать, а не
 * проверить на глаз: перенос функций обязан сохранить и набор имён,
 * и сигнатуры, и граф внутренних вызовов. Номера строк и имена файлов
 * намеренно НЕ сверяются — их изменение и есть смысл разбиения.
 *
 * Запуск:  php tests/db_config_api.php capture <out.json>
 *          php tests/db_config_api.php compare <golden.json>
 * Выход:   0 — совпадает, 1 — есть расхождения или ошибка.
 */
declare(strict_types=1);

const DB_API_PREFIXES = ['db_', 'web_', 'setting_', 'settings_'];
const DB_API_POLYFILLS = ['str_contains', 'str_starts_with', 'str_ends_with'];

/** Выражения require/include из файла. Через токены: комментарии отсекаются. */
function db_api_include_targets(string $file): array
{
    $tokens = token_get_all((string)file_get_contents($file));
    $n = count($tokens);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || !in_array($t[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            continue;
        }
        $buf = '';
        for ($k = $i + 1; $k < $n; $k++) {
            $tk = $tokens[$k];
            $txt = is_array($tk) ? $tk[1] : $tk;
            if ($txt === ';') { break; }
            $buf .= $txt;
        }
        $buf = trim($buf, "() \t\n");
        if ($buf !== '') { $out[] = $buf; }
    }
    return $out;
}

/** Все PHP-файлы, которые точка входа подключает (включая самодостаточные). */
function db_api_included_files(string $entry): array
{
    $seen = [];
    $queue = [$entry];
    while ($queue) {
        $file = array_shift($queue);
        $real = realpath($file);
        if ($real === false || isset($seen[$real])) {
            continue;
        }
        $seen[$real] = true;
        foreach (db_api_include_targets($real) as $expr) {
            if (!preg_match('#^(__DIR__|dirname\(__DIR__\)|dirname\(__FILE__\))\s*\.#', $expr)) {
                continue;
            }
            $tail = substr($expr, strpos($expr, '.') + 1);
            $tail = trim($tail, " \t\n'\"");
            $base = strpos($expr, 'dirname(__DIR__)') === 0 ? dirname(dirname($real)) : dirname($real);
            $queue[] = $base . '/' . $tail;
        }
    }
    return array_keys($seen);
}

/** Имена функций, объявленных в исходнике (ловит полифилы под if). */
function db_api_declared(array $files): array
{
    $names = [];
    foreach ($files as $file) {
        $src = (string)file_get_contents($file);
        if (preg_match_all('/^\s*(?:public\s+|private\s+|protected\s+|static\s+|final\s+|abstract\s+)*function\s+&?([A-Za-z_][A-Za-z0-9_]*)\s*\(/mi', $src, $m)) {
            foreach ($m[1] as $n) {
                $names[$n] = true;
            }
        }
    }
    $names = array_keys($names);
    sort($names, SORT_STRING);
    return $names;
}

function db_api_is_ours(string $fn): bool
{
    if (in_array($fn, DB_API_POLYFILLS, true)) {
        return true;
    }
    foreach (DB_API_PREFIXES as $p) {
        if (strncmp($fn, $p, strlen($p)) === 0) {
            return true;
        }
    }
    return false;
}

function db_api_param(ReflectionParameter $p): string
{
    $s = '';
    if ($p->hasType()) {
        $s .= (string)$p->getType() . ' ';
    }
    if ($p->isPassedByReference()) {
        $s .= '&';
    }
    if ($p->isVariadic()) {
        $s .= '...';
    }
    $s .= '$' . $p->getName();
    if ($p->isDefaultValueAvailable()) {
        try {
            $d = $p->getDefaultValue();
        } catch (Throwable $e) {
            $d = '?';
        }
        $s .= ' = ' . str_replace(["\n", '  '], '', var_export($d, true));
    }
    return $s;
}

function db_api_capture(string $entry): array
{
    if (!is_file($entry)) {
        fwrite(STDERR, "нет файла: {$entry}\n");
        exit(1);
    }
    require_once $entry;
    clearstatcache();

    $files = db_api_included_files($entry);
    $declared = db_api_declared($files);

    $sigs = [];
    $calls = [];
    $native = [];

    foreach ($declared as $name) {
        if (!function_exists($name)) {
            continue; // нативная функция PHP 8, полифил не сработал
        }
        $rf = new ReflectionFunction($name);
        if (!$rf->isInternal()) {
            $params = [];
            foreach ($rf->getParameters() as $p) {
                $params[] = db_api_param($p);
            }
            $sigs[$name] = [
                'params' => $params,
                'return' => $rf->hasReturnType() ? (string)$rf->getReturnType() : null,
                'by_ref_return' => $rf->returnsReference(),
            ];
        } else {
            $native[] = $name;
        }

        // Граф вызовов внутри тела функции
        $file = $rf->getFileName();
        $callees = [];
        if ($file !== false && is_file($file)) {
            $lines = file($file);
            if ($lines === false) {
                $lines = [];
            }
            $lines = array_slice(
                $lines,
                $rf->getStartLine() - 1,
                $rf->getEndLine() - $rf->getStartLine() + 1
            );
            $body = implode('', $lines);
            if (preg_match_all('/(?<![\w$>:])([a-z_][a-z0-9_]*)\s*\(/i', $body, $m)) {
                foreach ($m[1] as $c) {
                    if (db_api_is_ours($c) && $c !== $name) {
                        $callees[$c] = true;
                    }
                }
            }
        }
        $callees = array_keys($callees);
        sort($callees, SORT_STRING);
        $calls[$name] = $callees;
    }

    $sigNames = array_keys($sigs);
    sort($sigNames, SORT_STRING);
    $callNames = array_keys($calls);
    sort($callNames, SORT_STRING);
    sort($native, SORT_STRING);

    return [
        'declared' => $declared,
        'signatures' => $sigs,
        'calls' => $calls,
        'native_at_runtime' => $native,
        'included_files' => count($files),
    ];
}

function db_api_flat_signatures(array $sigs): array
{
    $out = [];
    foreach ($sigs as $fn => $s) {
        $out[] = $fn . '(' . implode(', ', $s['params']) . ')'
            . ($s['return'] !== null ? ': ' . $s['return'] : '');
    }
    sort($out, SORT_STRING);
    return $out;
}

$cmd = $argv[1] ?? '';
$path = $argv[2] ?? '';
$entry = dirname(__DIR__) . '/monitoring/includes/db_config.php';

if ($cmd === 'capture') {
    $snap = db_api_capture($entry);
    file_put_contents($path, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    printf(
        "  слепок: функций=%d сигнатур=%d вызовов=%d файлов=%d\n",
        count($snap['declared']),
        count(db_api_flat_signatures($snap['signatures'])),
        count($snap['calls']),
        $snap['included_files']
    );
    exit(0);
}

if ($cmd === 'compare') {
    if (!is_file($path)) {
        fwrite(STDERR, "нет эталона: {$path}\n");
        exit(1);
    }
    $want = json_decode((string)file_get_contents($path), true);
    $got = db_api_capture($entry);
    $bad = 0;

    $a = $want['declared'] ?? [];
    $b = $got['declared'];
    $lost = array_values(array_diff($a, $b));
    $new = array_values(array_diff($b, $a));
    foreach ($lost as $f) {
        echo "  ПОТЕРЯНА функция: {$f}\n";
        $bad++;
    }
    foreach ($new as $f) {
        echo "  ЛИШНЯЯ функция: {$f}\n";
        $bad++;
    }

    $a = db_api_flat_signatures($want['signatures'] ?? []);
    $b = db_api_flat_signatures($got['signatures']);
    foreach (array_diff($a, $b) as $d) {
        echo "  СИГНАТУРА изменилась / пропала: {$d}\n";
        $bad++;
    }
    foreach (array_diff($b, $a) as $d) {
        echo "  СИГНАТУРА появилась/иная:      {$d}\n";
        $bad++;
    }

    $acalls = $want['calls'] ?? [];
    foreach ($got['calls'] as $fn => $callees) {
        $old = $acalls[$fn] ?? null;
        if ($old === null) {
            echo "  вызовы не в эталоне: {$fn}\n";
            $bad++;
        } elseif (array_values(array_diff($old, $callees)) !== [] || array_values(array_diff($callees, $old)) !== []) {
            echo "  вызовы изменились в {$fn}: " . implode(',', array_diff($old, $callees)) . "\n";
            $bad++;
        }
    }
    foreach (array_diff(array_keys($acalls), array_keys($got['calls'])) as $fn) {
        echo "  вызовы пропали: {$fn}\n";
        $bad++;
    }

    if ($bad === 0) {
        printf("  Совпадает: функций=%d сигнатур=%d вызовов=%d файлов=%d\n",
            count($got['declared']),
            count(db_api_flat_signatures($got['signatures'])),
            count($got['calls']),
            $got['included_files']);
        exit(0);
    }
    echo "  РАСХОЖДЕНИЙ: {$bad}\n";
    exit(1);
}

fwrite(STDERR, "usage: php tests/db_config_api.php capture|compare <file.json>\n");
exit(2);
