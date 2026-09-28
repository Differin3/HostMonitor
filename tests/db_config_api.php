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

/**
 * Строковое представление типа, одинаковое на PHP 7.4 и 8.x.
 *
 * ReflectionType::__toString() версионно-зависим: на 8.x nullable-тип
 * печатается как "?string", на 7.4 — как "string" с allowsNull() == true.
 * Без нормализации эталон отражает версию PHP, а не форму кода, и сверка
 * падает на 7.4 сама по себе.
 */
function db_api_type(?ReflectionType $type): ?string
{
    if ($type === null) {
        return null;
    }
    $s = (string)$type;
    if (!$type->allowsNull()) {
        return $s;
    }
    // Уже nullable, либо нечего префиксовать: смешанный тип, сам null,
    // объединение (в PHP 8 null входит в перечисление членов).
    if (strpos($s, '?') === 0 || $s === 'mixed' || $s === 'null' || strpos($s, '|') !== false) {
        return $s;
    }
    return '?' . $s;
}

function db_api_param(ReflectionParameter $p): string
{
    $s = '';
    $t = db_api_type($p->getType());
    if ($t !== null) {
        $s .= $t . ' ';
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

/**
 * Функции, объявляемые условно: на PHP 8+ они нативные, на 7.4 их
 * объявляет polyfill.php. Их наличие — свойство версии PHP, а не часть
 * контракта db_config, поэтому в эталон они не попадают: иначе слепок
 * проверял бы версию раннера, а не код. Взамен их поведение проверяется
 * напрямую — и на 7.4, и на нативных 8.x.
 */
function db_api_conditional(): array
{
    return ['str_contains', 'str_starts_with', 'str_ends_with'];
}

/** Проверяет поведение полифилов на текущей версии PHP. */
function db_api_assert_polyfills(): int
{
    $cases = [
        'str_contains'    => [['foobar', 'oba', true], ['foobar', 'nope', false]],
        'str_starts_with' => [['foobar', 'foo', true], ['foobar', 'bar', false]],
        'str_ends_with'   => [['foobar', 'bar', true], ['foobar', 'foo', false]],
    ];
    $bad = 0;
    foreach ($cases as $fn => $args) {
        if (!function_exists($fn)) {
            fwrite(STDERR, "ПОЛИФИЛ ОТСУТСТВУЕТ: {$fn}()\n");
            $bad++;
            continue;
        }
        foreach ($args as $i => $case) {
            $got = $fn($case[0], $case[1]);
            if ($got !== $case[2]) {
                fwrite(STDERR, sprintf(
                    "ПОЛИФИЛ ДАЁТ НЕВЕРНЫЙ РЕЗУЛЬТАТ: %s(%s, %s) = %s, ожидалось %s\n",
                    $fn,
                    var_export($case[0], true),
                    var_export($case[1], true),
                    var_export($got, true),
                    var_export($case[2], true)
                ));
                $bad++;
            }
        }
    }
    if ($bad === 0) {
        echo "Полифилы: нативные или корректные, проверено " . count($cases) . "\n";
    }
    return $bad;
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
  $conditional = db_api_conditional();

  foreach ($declared as $name) {
      if (!function_exists($name)) {
          continue; // нативная функция PHP 8, полифил не сработал
      }
      $rf = new ReflectionFunction($name);
      if (in_array($name, $conditional, true)) {
          // Наличие полифила — свойство версии, сигнатура в эталон не идёт.
          // Граф вызовов ниже по-прежнему пишется: он одинаков на обеих
          // версиях и реально защищает код вызывающей стороны.
      } elseif (!$rf->isInternal()) {
          $params = [];
          foreach ($rf->getParameters() as $p) {
              $params[] = db_api_param($p);
          }
          $sigs[$name] = [
              'params' => $params,
              'return' => db_api_type($rf->getReturnType()),
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
      // Полифилы проверяются всегда и на любой версии: без этого их
      // исключение из эталона оставило бы 7.4 без покрытия вовсе.
      $bad = db_api_assert_polyfills();

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
