<?php
/**
 * api/billing_import.php
 *
 * Импорт CSV: payments | expenses | domains | vds
 *
 * Дедупликация:
 *  - payments  → external_id | (amount, payment_date, currency)
 *  - expenses  → external_id | (description, expense_date, amount)
 *  - domains   → UNIQUE по domain_name (upsert)
 *  - vds       → UNIQUE по name (upsert), поиск сначала по external_id
 *
 * Матчинг расходов к нодам (по приоритету):
 *  1) #NNN → nodes.external_id
 *  2) #NNN (domain.tld) → domains.domain_name / nodes.name
 *  3) IP в описании → nodes.host
 *  4) FBRUS-NNNN/pfx/NNNN в account → nodes.name
 *  5) Второй проход: group-match по account_ref — все услуги одного
 *     платежа FBRUS-NNNN привязываются к той же ноде, что уже
 *     привязанная услуга с этим же FBRUS.
 *
 * Пост-обработка после любого импорта:
 *  - provider_name расхода: по account_ref (та же группа списаний),
 *    иначе по описанию (домен провайдера в тексте);
 *  - matched_payment_id: последний платёж ноды с payment_date <= даты
 *    расхода, иначе ближайший следующий;
 *  - domains.provider_id: провайдер того расхода, что сопоставлен домену.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_csrf();
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'POST': handleImport($pdo); break;
        case 'GET':  handleList($pdo);   break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    app_log_error('billing_import.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'debug' => $e->getMessage()]);
}

/* ================================================================
 * Helpers: парсинг CSV
 * ================================================================ */

function detectDelimiter($content) {
    $lines = preg_split("/\R/", (string)$content);
    $semi = 0; $comma = 0; $tab = 0; $checked = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $semi  += substr_count($line, ';');
        $comma += substr_count($line, ',');
        $tab   += substr_count($line, "\t");
        if (++$checked >= 20) break;
    }
    if ($semi === 0 && $comma === 0 && $tab === 0) return ';';
    if ($semi >= $comma && $semi >= $tab) return ';';
    if ($tab >= $comma) return "\t";
    return ',';
}

function findHeaderRow(array $lines, string $delimiter): array {
    foreach ($lines as $i => $raw) {
        $l = trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw));
        if ($l === '') continue;
        $fields = str_getcsv($l, $delimiter);
        if (is_array($fields) && count($fields) >= 2) return [$i, $fields];
    }
    return [-1, []];
}

function parseAmount($val) {
    if ($val === null) return 0.0;
    $s = trim((string)$val);
    if ($s === '') return 0.0;
    $s = str_replace(["\xC2\xA0", ' '], '', $s);
    $s = preg_replace('/[Rr][Uu][Bb].*$/u', '', $s);
    $s = preg_replace('/[₽].*$/u', '', $s);
    if (strpos($s, ',') !== false && strpos($s, '.') === false) {
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '', $s);
    }
    $s = preg_replace('/[^0-9\.\-]/', '', $s);
    if ($s === '' || $s === '-' || $s === '.') return 0.0;
    return (float)$s;
}

function parseDate($val): ?string {
    if ($val === null) return null;
    $s = trim((string)$val);
    if ($s === '') return null;
    foreach (['Y-m-d', 'd.m.Y', 'Y/m/d', 'd/m/Y', 'Y-m-d H:i:s', 'd.m.Y H:i:s'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt instanceof DateTime) return $dt->format('Y-m-d');
    }
    $ts = strtotime($s);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

function pick(array $data, array $keys) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '') return $data[$k];
    }
    return null;
}

function safeJson($data): ?string {
    $j = json_encode($data, JSON_UNESCAPED_UNICODE);
    return $j === false ? null : $j;
}

/* ================================================================
 * Helpers: эвристики
 * ================================================================ */

function detectCountry(string $text): ?string {
    $map = [
        'росси' => 'RU', 'russia' => 'RU', 'москва' => 'RU', 'moscow' => 'RU', 'спб' => 'RU',
        'нидерланд' => 'NL', 'netherlands' => 'NL', 'holland' => 'NL', 'amsterdam' => 'NL',
        'германи' => 'DE', 'germany' => 'DE', 'deutschland' => 'DE', 'frankfurt' => 'DE',
        'сша' => 'US', 'usa' => 'US', 'united states' => 'US', 'america' => 'US',
        'сингапур' => 'SG', 'singapore' => 'SG',
        'финлянди' => 'FI', 'finland' => 'FI', 'helsinki' => 'FI',
        'польш' => 'PL', 'poland' => 'PL', 'warsaw' => 'PL',
        'великобритани' => 'GB', 'united kingdom' => 'GB', 'london' => 'GB',
        'франци' => 'FR', 'france' => 'FR', 'paris' => 'FR',
        'япони' => 'JP', 'japan' => 'JP', 'tokyo' => 'JP',
    ];
    $lower = mb_strtolower($text, 'UTF-8');
    foreach ($map as $pattern => $code) {
        if (mb_strpos($lower, $pattern) !== false) return $code;
    }
    return null;
}

function guessProvider(PDO $pdo, string $domainName): array {
    $name = null; $url = null;
    if ($domainName !== '') {
        $parts = explode('.', $domainName);
        $hints = array_filter($parts, function ($p) {
            return !preg_match('/^(vm|vds|srv|host|node|\d+|ru|com|net|org|club|io|de|nl|us|sg)$/i', $p);
        });
        if (!empty($hints)) {
            $hint = reset($hints);
            $stmt = $pdo->prepare("SELECT name, url FROM providers WHERE name LIKE ? LIMIT 1");
            $stmt->execute([$hint . '%']);
            if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $name = $r['name'];
                $url  = $r['url'];
            }
        }
    }
    return [$name, $url];
}

/**
 * Токены провайдера для сравнения с текстом расхода.
 * Имя без зоны и хост URL без зоны: "firstbyte.ru" / "https://billing.firstbyte.ru"
 * → ['firstbyte'].
 */
function providerTokens(?string $name, ?string $url): array {
    $skip = ['www', 'com', 'net', 'org', 'club', 'billing', 'panel', 'ru', 'su', 'io', 'de', 'nl', 'us', 'sg', 'info', 'biz'];
    $tokens = [];
    if ($name !== null && $name !== '') {
        foreach (preg_split('/[\s\.\/\-_,]+/u', mb_strtolower(trim($name))) as $p) {
            if (mb_strlen($p) >= 4 && !in_array($p, $skip, true)) $tokens[$p] = true;
        }
    }
    if ($url !== null && $url !== '') {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) $host = preg_replace('~^https?://~i', '', $url);
        $host = preg_replace('~[/?#].*$~', '', (string)$host);
        foreach (explode('.', mb_strtolower($host)) as $p) {
            if (mb_strlen($p) >= 4 && !in_array($p, $skip, true)) $tokens[$p] = true;
        }
    }
    return array_keys($tokens);
}

/**
 * Провайдер по произвольному тексту (описание расхода): самое длинное
 * совпадение токена имени/хоста провайдера.
 *
 * @return array{id:int,name:string,url:?string}|null
 */
function findProviderByText(PDO $pdo, string $text): ?array {
    $lower = mb_strtolower(trim($text));
    if ($lower === '') return null;

    static $providers = null;
    if ($providers === null) {
        $providers = $pdo->query("SELECT id, name, url FROM providers")->fetchAll(PDO::FETCH_ASSOC);
    }

    $best = null; $bestLen = 0;
    foreach ($providers as $p) {
        foreach (providerTokens((string)$p['name'], $p['url'] !== null ? (string)$p['url'] : null) as $t) {
            if (mb_strpos($lower, $t) !== false && mb_strlen($t) > $bestLen) {
                $best = ['id' => (int)$p['id'], 'name' => (string)$p['name'], 'url' => $p['url']];
                $bestLen = mb_strlen($t);
            }
        }
    }
    return $best;
}

/**
 * Провайдер расхода: сначала по уже известному account_ref (та же группа
 * списаний — тот же провайдер), затем по описанию.
 * Внутри одного импорта результат кэшируется, чтобы не бить БД 600 раз.
 */
function resolveExpenseProvider(PDO $pdo, string $desc, ?string $account): ?string {
    static $cache = [];
    $key = ($account ?? '') . '|' . $desc;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $name = null;
    if ($account !== null && $account !== '') {
        foreach (explode(',', $account) as $ref) {
            $ref = trim($ref);
            if ($ref === '') continue;
            $stmt = $pdo->prepare("SELECT provider_name FROM billing_expenses
                WHERE account_ref LIKE ? AND provider_name IS NOT NULL AND provider_name <> ''
                GROUP BY provider_name ORDER BY COUNT(*) DESC LIMIT 1");
            $stmt->execute(['%' . $ref . '%']);
            $found = $stmt->fetchColumn();
            if ($found) { $name = (string)$found; break; }
        }
    }
    if ($name === null) {
        $p = findProviderByText($pdo, $desc);
        if ($p) $name = $p['name'];
    }

    $cache[$key] = $name;
    return $name;
}

/**
 * Заполняет billing_expenses.provider_name там, где пусто.
 * Проход 1 — по описанию (в нём обычно есть домен провайдера),
 * проход 2 — по account_ref (когда описание без провайдера),
 * проход 3 — по префиксу account_ref (FBRUS-NNNN → FBRUS): номер счёта
 *            бывает уникальным, но префикс общий для всех услуг провайдера.
 *
 * @return int количество обновлённых строк
 */
function applyExpenseProviderBackfill(PDO $pdo): int {
    $updated = 0;
    $upd = $pdo->prepare("UPDATE billing_expenses SET provider_name = ?, updated_at = NOW() WHERE id = ?");

    $rows = $pdo->query("SELECT id, description FROM billing_expenses
        WHERE provider_name IS NULL OR provider_name = ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $p = findProviderByText($pdo, (string)$r['description']);
        if ($p) {
            $upd->execute([$p['name'], $r['id']]);
            $updated++;
        }
    }

    $resolved = $pdo->query("SELECT account_ref, provider_name FROM billing_expenses
        WHERE provider_name IS NOT NULL AND provider_name <> ''
          AND account_ref IS NOT NULL AND account_ref <> ''")->fetchAll(PDO::FETCH_ASSOC);

    $byAccount = []; $byPrefix = [];
    foreach ($resolved as $r) {
        foreach (explode(',', (string)$r['account_ref']) as $ref) {
            $ref = trim($ref);
            if ($ref === '') continue;
            if (!isset($byAccount[$ref])) $byAccount[$ref] = (string)$r['provider_name'];

            $dash = strpos($ref, '-');
            $prefix = $dash === false ? '' : substr($ref, 0, $dash);
            if ($prefix !== '' && mb_strlen($prefix) >= 4 && !ctype_digit($prefix) && !isset($byPrefix[$prefix])) {
                $byPrefix[$prefix] = (string)$r['provider_name'];
            }
        }
    }

    if ($byAccount || $byPrefix) {
        $still = $pdo->query("SELECT id, account_ref FROM billing_expenses
            WHERE (provider_name IS NULL OR provider_name = '')
              AND account_ref IS NOT NULL AND account_ref <> ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($still as $r) {
            $found = null;
            $refs = array_filter(array_map('trim', explode(',', (string)$r['account_ref'])), 'strlen');

            foreach ($refs as $ref) {
                if (isset($byAccount[$ref])) { $found = $byAccount[$ref]; break; }
            }
            if ($found === null) {
                foreach ($refs as $ref) {
                    $dash = strpos($ref, '-');
                    $prefix = $dash === false ? '' : substr($ref, 0, $dash);
                    if ($prefix !== '' && isset($byPrefix[$prefix])) { $found = $byPrefix[$prefix]; break; }
                }
            }
            if ($found !== null) {
                $upd->execute([$found, $r['id']]);
                $updated++;
            }
        }
    }
    return $updated;
}

/**
 * Связка расход ↔ платёж той же ноды.
 * Берём последний платёж с payment_date <= даты расхода — это предоплата,
 * которая покрывала списание. Если платежей до расхода нет — ближайший
 * следующий (оплата задним числом).
 *
 * @return int количество обновлённых строк
 */
function applyExpensePaymentMatching(PDO $pdo): int {
    $prev = $pdo->prepare("SELECT id FROM payments WHERE node_id = ? AND payment_date <= ?
        ORDER BY payment_date DESC, id DESC LIMIT 1");
    $next = $pdo->prepare("SELECT id FROM payments WHERE node_id = ? AND payment_date >= ?
        ORDER BY payment_date ASC, id ASC LIMIT 1");
    $upd = $pdo->prepare("UPDATE billing_expenses SET matched_payment_id = ?, updated_at = NOW() WHERE id = ?");

    $updated = 0;
    $rows = $pdo->query("SELECT id, matched_node_id, expense_date FROM billing_expenses
        WHERE matched_payment_id IS NULL AND matched_node_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $prev->execute([$r['matched_node_id'], $r['expense_date']]);
        $pid = $prev->fetchColumn();
        if (!$pid) {
            $next->execute([$r['matched_node_id'], $r['expense_date']]);
            $pid = $next->fetchColumn();
        }
        if ($pid) {
            $upd->execute([(int)$pid, $r['id']]);
            $updated++;
        }
    }
    return $updated;
}

/**
 * Привязка домена к провайдеру по его расходам: если расходы, сопоставленные
 * с доменом, принадлежат провайдеру X, то и домен обслуживается у X.
 *
 * @return int количество обновлённых строк
 */
function applyDomainProviderMatching(PDO $pdo): int {
    $updated = 0;
    $domains = $pdo->query("SELECT id FROM domains WHERE provider_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    if (!$domains) return 0;

    $byExpense = $pdo->prepare("SELECT provider_name FROM billing_expenses
        WHERE matched_domain_id = ? AND provider_name IS NOT NULL AND provider_name <> ''
        GROUP BY provider_name ORDER BY COUNT(*) DESC LIMIT 1");
    $byName = $pdo->prepare("SELECT id FROM providers WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $upd = $pdo->prepare("UPDATE domains SET provider_id = ? WHERE id = ?");

    foreach ($domains as $d) {
        $byExpense->execute([$d['id']]);
        $pname = $byExpense->fetchColumn();
        if (!$pname) continue;
        $byName->execute([$pname]);
        $pid = $byName->fetchColumn();
        if ($pid) {
            $upd->execute([(int)$pid, $d['id']]);
            $updated++;
        }
    }
    return $updated;
}

/* ================================================================
 * Helpers: матчинг к нодам
 * ================================================================ */

/**
 * По FBRUS-NNNN или pfx/NNNN в произвольной строке → nodes.name LIKE.
 */
function findNodeByPattern(PDO $pdo, string $haystack): ?int {
    if ($haystack === '') return null;
    if (!preg_match('/(FBRUS-\d+|pfx\/\d+)/iu', $haystack, $m)) return null;
    $pat = $m[1];
    $stmt = $pdo->prepare("SELECT id FROM nodes WHERE name LIKE ? LIMIT 1");
    $stmt->execute(['%' . $pat . '%']);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ? (int)$r['id'] : null;
}

/**
 * По IP-адресу в строке → nodes.host.
 */
function findNodeByIp(PDO $pdo, string $haystack): ?int {
    if ($haystack === '') return null;
    if (!preg_match('/\b(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/', $haystack, $m)) return null;
    $ip = $m[1];
    $stmt = $pdo->prepare("SELECT id FROM nodes WHERE host = ? LIMIT 1");
    $stmt->execute([$ip]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ? (int)$r['id'] : null;
}

/**
 * По ID услуги из "#NNN" в описании.
 * Сначала по nodes.external_id, потом fallback по nodes.name LIKE.
 */
function findNodeByServiceId(PDO $pdo, string $haystack): ?int {
    if ($haystack === '') return null;
    if (!preg_match('/#(\d{4,})/u', $haystack, $m)) return null;
    $sid = $m[1];

    $stmt = $pdo->prepare("SELECT id FROM nodes WHERE external_id = ? LIMIT 1");
    $stmt->execute([$sid]);
    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) return (int)$r['id'];

    $stmt = $pdo->prepare("SELECT id FROM nodes WHERE name LIKE ? LIMIT 1");
    $stmt->execute(['%' . $sid . '%']);
    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) return (int)$r['id'];

    return null;
}

/**
 * По "#NNN (domain.tld)" в описании.
 * Возвращает [nodeId, domainId] — оба могут быть null.
 */
function findDomainByName(PDO $pdo, string $haystack): array {
    if ($haystack === '') return [null, null];
    if (!preg_match('/#(\d+)\s*\(([a-z0-9\.-]+\.[a-z]{2,})/iu', $haystack, $dm)) return [null, null];
    $dname = strtolower($dm[2]);

    $domainId = null;
    $stmt = $pdo->prepare("SELECT id FROM domains WHERE domain_name = ? LIMIT 1");
    $stmt->execute([$dname]);
    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $domainId = (int)$r['id'];

    $nodeId = null;
    $stmt = $pdo->prepare("SELECT id FROM nodes WHERE name = ? OR name LIKE ? LIMIT 1");
    $stmt->execute([$dname, '%' . $dname . '%']);
    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $nodeId = (int)$r['id'];

    return [$nodeId, $domainId];
}

/**
 * Второй проход: групповой матчинг по account_ref.
 *
 * Логика: если в billing_expenses есть хотя бы одна строка с
 * account_ref = 'FBRUS-NNNN' и matched_node_id != NULL, то все
 * остальные строки с этим же FBRUS-NNNN (без node_id) привязываются
 * к той же ноде. Это нужно, потому что один платёж оплачивает
 * несколько услуг одной ноды (VDS + FTP-бэкап и т.д.), но не у
 * каждой услуги в описании есть #NNN / домен / IP.
 *
 * @return int количество обновлённых строк
 */
function applyPaymentGroupMatching(PDO $pdo): int {
    // Шаг 1: собрать map FBRUS-NNNN → matched_node_id из уже привязанных
    $rows = $pdo->query("
        SELECT account_ref, matched_node_id
        FROM billing_expenses
        WHERE matched_node_id IS NOT NULL
          AND account_ref IS NOT NULL
          AND account_ref <> ''
    ")->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
        // account_ref может быть списком "FBRUS-1,FBRUS-2"
        foreach (explode(',', $r['account_ref']) as $s) {
            $s = trim($s);
            if ($s !== '' && !isset($map[$s])) {
                $map[$s] = (int)$r['matched_node_id'];
            }
        }
    }
    if (empty($map)) return 0;

    // Шаг 2: пройтись по всем ещё непривязанным и попробовать найти в map
    $still = $pdo->query("
        SELECT id, account_ref
        FROM billing_expenses
        WHERE matched_node_id IS NULL
          AND account_ref IS NOT NULL
          AND account_ref <> ''
    ")->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare("
        UPDATE billing_expenses
        SET matched_node_id = ?,
            match_type = CASE WHEN match_type = 'none' THEN 'auto' ELSE match_type END
        WHERE id = ?
    ");

    $updated = 0;
    foreach ($still as $row) {
        foreach (explode(',', $row['account_ref']) as $s) {
            $s = trim($s);
            if (isset($map[$s])) {
                $upd->execute([$map[$s], $row['id']]);
                $updated++;
                break;
            }
        }
    }
    return $updated;
}

/* ================================================================
 * Import
 * ================================================================ */

function handleImport($pdo) {
    // Пересчёт связей без загрузки файла: провайдеры расходов,
    // расход ↔ платёж, домен ↔ провайдер.
    if (($_POST['action'] ?? '') === 'recompute') {
        $providersFilled = applyExpenseProviderBackfill($pdo);
        $paymentsLinked  = applyExpensePaymentMatching($pdo);
        $domainsLinked   = applyDomainProviderMatching($pdo);
        echo json_encode([
            'success'          => true,
            'providers_filled' => $providersFilled,
            'payments_linked'  => $paymentsLinked,
            'domains_linked'   => $domainsLinked,
        ]);
        return;
    }

    $type = $_POST['type'] ?? null;
    if (!in_array($type, ['payments','expenses','domains','vds'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'type must be payments|expenses|domains|vds']);
        return;
    }
    if (empty($_FILES['file']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'file required']);
        return;
    }

    $content = file_get_contents($_FILES['file']['tmp_name']);
    if ($content === false) {
        http_response_code(400);
        echo json_encode(['error' => 'cannot read file']);
        return;
    }
    if (!mb_check_encoding($content, 'UTF-8')) {
        $converted = @mb_convert_encoding($content, 'UTF-8', 'Windows-1251,CP1251,ISO-8859-5');
        if ($converted) $content = $converted;
    }

    $lines = preg_split("/\R/", $content);
    if (count($lines) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'empty file']);
        return;
    }

    $delimiter = detectDelimiter($content);
    [$headerIdx, $headers] = findHeaderRow($lines, $delimiter);
    if ($headerIdx < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'header row not found', 'delimiter' => $delimiter]);
        return;
    }
    $start = $headerIdx + 1;

    $headersNorm = [];
    foreach ($headers as $h) {
        $headersNorm[] = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h)));
    }

    $importStmt = $pdo->prepare("INSERT INTO billing_imports (type, filename, imported_by, total_rows, status) VALUES (?,?,?,?, 'pending')");
    $importStmt->execute([$type, $_FILES['file']['name'] ?? null, $_SESSION['user_id'] ?? null, max(0, count($lines) - $start)]);
    $importId = (int)$pdo->lastInsertId();

    $imported = 0;
    $matched  = 0;
    $skipped  = 0;
    $updated  = 0;

    // Внутренний дедуп: если в CSV две одинаковые строки — вторую пропускаем
    $seenKeys = [];

    try {
        if ($type === 'payments') {
            $pdo->beginTransaction();
            for ($i = $start; $i < count($lines); $i++) {
                $line = $lines[$i];
                if (trim($line) === '') continue;
                $row = str_getcsv($line, $delimiter);
                if (!is_array($row) || count($row) === 0) continue;

                $data = [];
                foreach ($headersNorm as $idx => $hn) {
                    $data[$hn] = isset($row[$idx]) ? trim((string)$row[$idx]) : null;
                }

                $externalId = pick($data, ['id']);
                $num        = pick($data, ['номер', 'number', 'внешний id']);
                $dateStr    = pick($data, ['дата', 'date', 'дата операции', 'дата платежа']);
                $desc       = pick($data, ['описание', 'description', 'плательщик', 'назначение платежа']);
                $status     = mb_strtolower((string)pick($data, ['статус', 'status']));
                $amount     = parseAmount(pick($data, [
                    'сумма', 'amount', 'сумма к зачислению',
                    'сумма в валюте платежа', 'итого',
                ]));

                if ($amount <= 0) continue;
                if ($status !== '' && (
                    strpos($status, 'отмен') !== false ||
                    strpos($status, 'возврат') !== false ||
                    strpos($status, 'ошиб') !== false
                )) continue;

                $pdate = parseDate($dateStr);
                if ($pdate === null) continue;

                // Внутренний дедуп
                $dedupKey = ($externalId !== null && $externalId !== '')
                    ? 'ext:' . $externalId
                    : 'amt:' . $amount . '|' . $pdate;
                if (isset($seenKeys[$dedupKey])) { $skipped++; continue; }
                $seenKeys[$dedupKey] = true;

                // Матчинг ноды
                $haystack = trim(($num ?? '') . ' ' . ($desc ?? '') . ' ' . ($externalId ?? ''));
                $matchedNodeId = findNodeByPattern($pdo, $haystack);

                // Ищем существующий платёж
                $existing = null;
                if ($externalId !== null && $externalId !== '') {
                    $chk = $pdo->prepare("SELECT id, node_id FROM payments WHERE external_id = ? LIMIT 1");
                    $chk->execute([$externalId]);
                    $existing = $chk->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                if (!$existing) {
                    $chk = $pdo->prepare("SELECT id, node_id FROM payments WHERE amount = ? AND payment_date = ? AND currency = 'RUB' LIMIT 1");
                    $chk->execute([$amount, $pdate]);
                    $existing = $chk->fetch(PDO::FETCH_ASSOC) ?: null;
                }

                if ($existing) {
                    $sets = []; $params = [];
                    if ($externalId !== null && $externalId !== '') {
                        $sets[] = "external_id = ?"; $params[] = $externalId;
                    }
                    if ($existing['node_id'] === null && $matchedNodeId !== null) {
                        $sets[] = "node_id = ?"; $params[] = $matchedNodeId;
                    }
                    if ($sets) {
                        $params[] = $existing['id'];
                        $upd = $pdo->prepare("UPDATE payments SET " . implode(', ', $sets) . " WHERE id = ?");
                        $upd->execute($params);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                    if ($matchedNodeId !== null) $matched++;
                    continue;
                }

                // Новый
                $ins = $pdo->prepare("INSERT INTO payments (external_id, node_id, amount, currency, payment_date) VALUES (?,?,?,?,?)");
                $ins->execute([$externalId ?: null, $matchedNodeId, $amount, 'RUB', $pdate]);
                $imported++;
                if ($matchedNodeId !== null) $matched++;
            }
            $pdo->commit();

        } elseif ($type === 'expenses') {
            $pdo->beginTransaction();
            for ($i = $start; $i < count($lines); $i++) {
                $line = $lines[$i];
                if (trim($line) === '') continue;
                $row = str_getcsv($line, $delimiter);
                if (!is_array($row) || count($row) === 0) continue;

                $data = [];
                foreach ($headersNorm as $idx => $hn) {
                    $data[$hn] = isset($row[$idx]) ? trim((string)$row[$idx]) : null;
                }

                $externalId = pick($data, ['id']);
                $desc       = pick($data, ['наименование', 'описание', 'description']);
                $dateStr    = pick($data, ['дата списания', 'дата операции', 'дата', 'date']);
                $amount     = parseAmount(pick($data, ['сумма', 'amount']));
                $amountWo   = parseAmount(pick($data, ['без скидки', 'amount_without_discount'])) ?: $amount;
                $account    = pick($data, ['оплачено платежами', 'на счёт', 'на счет', 'account_ref', 'account']);

                if ($amount == 0 && $amountWo == 0) continue;
                $edate = parseDate($dateStr);
                if ($edate === null) continue;

                // Внутренний дедуп
                $dedupKey = ($externalId !== null && $externalId !== '')
                    ? 'ext:' . $externalId
                    : 'amt:' . $amount . '|' . $edate . '|' . md5((string)$desc);
                if (isset($seenKeys[$dedupKey])) { $skipped++; continue; }
                $seenKeys[$dedupKey] = true;

                // Матчинг ноды / домена по приоритету
                $matchedNodeId = null;
                $matchedDomainId = null;
                $matchType = 'none';
                $descStr = (string)$desc;

                // Провайдер расхода: по account_ref (та же группа списаний), иначе по описанию
                $providerName = resolveExpenseProvider($pdo, $descStr, $account !== null ? (string)$account : null);

                // 1) #NNN → nodes.external_id (самый надёжный для VDS)
                if (!$matchedNodeId) {
                    $bySid = findNodeByServiceId($pdo, $descStr);
                    if ($bySid) { $matchedNodeId = $bySid; $matchType = 'auto'; }
                }
                // 2) #NNN (domain.tld) → domains + nodes
                [$byDomNode, $byDomDomain] = findDomainByName($pdo, $descStr);
                if ($byDomDomain) $matchedDomainId = $byDomDomain;
                if (!$matchedNodeId && $byDomNode) {
                    $matchedNodeId = $byDomNode;
                    $matchType = 'auto';
                }
                if ($matchType === 'none' && $matchedDomainId) $matchType = 'auto';
                // 3) IP → nodes.host
                if (!$matchedNodeId) {
                    $byIp = findNodeByIp($pdo, $descStr);
                    if ($byIp) { $matchedNodeId = $byIp; $matchType = 'auto'; }
                }
                // 4) FBRUS-NNNN/pfx в account → nodes.name (fallback)
                if (!$matchedNodeId && $account) {
                    $byAcc = findNodeByPattern($pdo, $account . ' ' . $descStr);
                    if ($byAcc) { $matchedNodeId = $byAcc; $matchType = 'auto'; }
                }

                if ($matchedNodeId || $matchedDomainId) $matched++;

                // Ищем существующий расход
                $existing = null;
                if ($externalId !== null && $externalId !== '') {
                    $chk = $pdo->prepare("SELECT id, matched_node_id, matched_domain_id, match_type, provider_name
                        FROM billing_expenses WHERE external_id = ? LIMIT 1");
                    $chk->execute([$externalId]);
                    $existing = $chk->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                if (!$existing) {
                    $chk = $pdo->prepare("SELECT id, matched_node_id, matched_domain_id, match_type, provider_name
                        FROM billing_expenses WHERE description = ? AND expense_date = ? AND amount = ? LIMIT 1");
                    $chk->execute([$desc, $edate, $amount]);
                    $existing = $chk->fetch(PDO::FETCH_ASSOC) ?: null;
                }

                if ($existing) {
                    $sets = []; $params = [];
                    if ($externalId !== null && $externalId !== '') {
                        $sets[] = "external_id = ?"; $params[] = $externalId;
                    }
                    if ($existing['matched_node_id'] === null && $matchedNodeId !== null) {
                        $sets[] = "matched_node_id = ?"; $params[] = $matchedNodeId;
                    }
                    if ($existing['matched_domain_id'] === null && $matchedDomainId !== null) {
                        $sets[] = "matched_domain_id = ?"; $params[] = $matchedDomainId;
                    }
                    if ($existing['match_type'] === 'none' && $matchType !== 'none') {
                        $sets[] = "match_type = ?"; $params[] = $matchType;
                    }
                    if (($existing['provider_name'] === null || $existing['provider_name'] === '') && $providerName !== null) {
                        $sets[] = "provider_name = ?"; $params[] = $providerName;
                    }
                    if ($sets) {
                        $params[] = $existing['id'];
                        $upd = $pdo->prepare("UPDATE billing_expenses SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = ?");
                        $upd->execute($params);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                // Новый
                $rawJson = safeJson($data);
                $ins = $pdo->prepare("INSERT INTO billing_expenses
                    (external_id, description, expense_date, amount, amount_without_discount,
                     account_ref, provider_name, matched_node_id, matched_domain_id, match_type, raw_data)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([
                    $externalId ?: null, $desc, $edate, $amount, $amountWo,
                    $account, $providerName, $matchedNodeId, $matchedDomainId, $matchType, $rawJson,
                ]);
                $imported++;
            }

            // Второй проход: групповой матчинг по account_ref (FBRUS-NNNN).
            // Ловит услуги без #NNN/домена/IP — например, "Резервное копирование FTP",
            // у которых в описании только #4195030, а в account — FBRUS-201340,
            // но есть другие услуги с этим же FBRUS, уже привязанные к ноде.
            $groupMatched = applyPaymentGroupMatching($pdo);
            $matched += $groupMatched;
            $updated += $groupMatched;

            $pdo->commit();

        } elseif ($type === 'domains') {
            $pdo->beginTransaction();
            for ($i = $start; $i < count($lines); $i++) {
                $line = $lines[$i];
                if (trim($line) === '') continue;
                $row = str_getcsv($line, $delimiter);
                if (!is_array($row) || count($row) === 0) continue;

                $data = [];
                foreach ($headersNorm as $idx => $hn) {
                    $data[$hn] = isset($row[$idx]) ? trim((string)$row[$idx]) : null;
                }

                $externalId = pick($data, ['id']);
                $dname      = mb_strtolower((string)pick($data, ['имя домена', 'домен', 'domain', 'domain_name']));
                if ($dname === '') continue;

                $dedupKey = 'dom:' . $dname;
                if (isset($seenKeys[$dedupKey])) { $skipped++; continue; }
                $seenKeys[$dedupKey] = true;

                $tld        = pick($data, ['тариф', 'зона', 'zone', 'tld']);
                $contact    = pick($data, ['контакт', 'contact', 'проект', 'project']);
                $expiresRaw = pick($data, ['действует до', 'expires', 'expires_at']);
                $status     = pick($data, ['статус услуги', 'статус', 'status']);
                $priceRaw   = pick($data, ['стоимость', 'цена', 'price']);

                $price = 0.0; $pricePeriod = 'year';
                if ($priceRaw) {
                    $price = parseAmount($priceRaw);
                    if (preg_match('/\/\s*(\S+)/u', (string)$priceRaw, $pm)) {
                        $p = mb_strtolower($pm[1]);
                        if (strpos($p, 'год') !== false || strpos($p, 'year') !== false) $pricePeriod = 'year';
                        elseif (strpos($p, 'мес') !== false || strpos($p, 'month') !== false) $pricePeriod = 'month';
                        else $pricePeriod = $pm[1];
                    }
                }

                $expDate = parseDate($expiresRaw);
                $externalKey = ($externalId !== null && $externalId !== '') ? (string)$externalId : $dname;

                $chk = $pdo->prepare("SELECT id FROM domains WHERE domain_name = ? LIMIT 1");
                $chk->execute([$dname]);
                if ($chk->fetch()) {
                    $upd = $pdo->prepare("UPDATE domains
                        SET external_key = ?, tld = COALESCE(?, tld), contact = COALESCE(?, contact),
                            expires_at = COALESCE(?, expires_at),
                            price = CASE WHEN ? > 0 THEN ? ELSE price END,
                            price_period = ?, status = COALESCE(?, status)
                        WHERE domain_name = ?");
                    $upd->execute([$externalKey, $tld, $contact, $expDate, $price, $price, $pricePeriod, $status, $dname]);
                    $updated++;
                } else {
                    $ins = $pdo->prepare("INSERT INTO domains
                        (external_key, domain_name, tld, contact, expires_at, price, price_period, status, source, currency)
                        VALUES (?,?,?,?,?,?,?,?, 'csv', 'RUB')");
                    $ins->execute([$externalKey, $dname, $tld, $contact, $expDate, $price > 0 ? $price : null, $pricePeriod, $status]);
                    $imported++;
                }
            }
            $pdo->commit();

        } elseif ($type === 'vds') {
            // CSV: Id; Доменное имя; IP-адрес; Шаблон ОС; Тариф; Дата-центр;
            //      Действует до; Статус услуги; Стоимость
            $pdo->beginTransaction();
            for ($i = $start; $i < count($lines); $i++) {
                $line = $lines[$i];
                if (trim($line) === '') continue;
                $row = str_getcsv($line, $delimiter);
                if (!is_array($row) || count($row) === 0) continue;

                $data = [];
                foreach ($headersNorm as $idx => $hn) {
                    $data[$hn] = isset($row[$idx]) ? trim((string)$row[$idx]) : null;
                }

                $externalId = pick($data, ['id']);
                $domainName = mb_strtolower((string)pick($data, ['доменное имя', 'домен', 'domain', 'domain_name']));
                $ip         = pick($data, ['ip-адрес', 'ip', 'ip_address', 'host']);
                $datacenter = pick($data, ['дата-центр', 'датацентр', 'datacenter', 'dc', 'location']);
                $statusStr  = pick($data, ['статус услуги', 'статус', 'status']);
                $priceRaw   = pick($data, ['стоимость', 'цена', 'price']);

                if ($domainName === '' && $ip === '' && !$externalId) continue;

                $name = $domainName !== ''
                    ? $domainName
                    : ($externalId ? 'VDS-' . $externalId : 'VDS-' . str_replace('.', '-', (string)$ip));
                if ($name === '') continue;

                $dedupKey = 'node:' . $name;
                if (isset($seenKeys[$dedupKey])) { $skipped++; continue; }
                $seenKeys[$dedupKey] = true;

                $host = $ip !== '' ? $ip : $domainName;
                if ($host === '') continue;

                $country = $datacenter ? detectCountry((string)$datacenter) : null;
                [$providerName, $providerUrl] = guessProvider($pdo, (string)$domainName);

                $billingAmount = 0.0; $billingCurrency = 'RUB'; $billingPeriod = 30;
                if ($priceRaw) {
                    $billingAmount = parseAmount($priceRaw);
                    if (preg_match('/\b([A-Z]{3})\b/u', (string)$priceRaw, $cm)) $billingCurrency = $cm[1];
                    if (preg_match('/\/\s*(\S+)/u', (string)$priceRaw, $pm)) {
                        $p = mb_strtolower($pm[1]);
                        if (strpos($p, 'год') !== false || strpos($p, 'year') !== false) $billingPeriod = 365;
                        elseif (strpos($p, 'мес') !== false || strpos($p, 'month') !== false) $billingPeriod = 30;
                        elseif (strpos($p, 'дн') !== false || strpos($p, 'day') !== false) $billingPeriod = 1;
                    }
                }

                $status = ($statusStr && mb_stripos($statusStr, 'актив') !== false) ? 'online' : 'offline';

                // Ищем существующую ноду: сначала по external_id, потом по name.
                $existing = null;
                if ($externalId !== null && $externalId !== '') {
                    $chk = $pdo->prepare("SELECT id, host FROM nodes WHERE external_id = ? LIMIT 1");
                    $chk->execute([$externalId]);
                    $existing = $chk->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                if (!$existing) {
                    $chk = $pdo->prepare("SELECT id, host FROM nodes WHERE name = ? LIMIT 1");
                    $chk->execute([$name]);
                    $existing = $chk->fetch(PDO::FETCH_ASSOC) ?: null;
                }

                if ($existing) {
                    // host не перезаписываем, если он уже заполнен вручную
                    // (например, msk-gate-01 = 185.247.143.115, а в CSV 185.139.69.143)
                    $upd = $pdo->prepare("UPDATE nodes
                        SET external_id = COALESCE(?, external_id),
                            host = CASE WHEN host IS NULL OR host = '' THEN ? ELSE host END,
                            status = ?,
                            country = COALESCE(?, country),
                            provider_name = COALESCE(?, provider_name),
                            provider_url = COALESCE(?, provider_url),
                            billing_amount = CASE WHEN ? > 0 THEN ? ELSE billing_amount END,
                            billing_currency = ?,
                            billing_period = ?
                        WHERE id = ?");
                    $upd->execute([
                        $externalId ?: null, $host, $status, $country, $providerName, $providerUrl,
                        $billingAmount, $billingAmount, $billingCurrency, $billingPeriod,
                        $existing['id']
                    ]);
                    $updated++;
                } else {
                    $ins = $pdo->prepare("INSERT INTO nodes
                        (name, external_id, host, port, status, country, provider_name, provider_url,
                         billing_amount, billing_currency, billing_period)
                        VALUES (?,?,?,22,?,?,?,?,?,?,?)");
                    $ins->execute([
                        $name, $externalId ?: null, $host, $status, $country, $providerName, $providerUrl,
                        $billingAmount > 0 ? $billingAmount : null,
                        $billingCurrency, $billingPeriod
                    ]);
                    $imported++;
                }
            }
            $pdo->commit();
        }

        // Пост-обработка связей: провайдеры расходов, расход ↔ платёж, домен ↔ провайдер.
        // Запускается после любого из импортов: порядок файлов не важен.
        $providersFilled = 0; $paymentsLinked = 0; $domainsLinked = 0;
        if (in_array($type, ['payments', 'expenses', 'domains'], true)) {
            $providersFilled = applyExpenseProviderBackfill($pdo);
            $paymentsLinked  = applyExpensePaymentMatching($pdo);
            $domainsLinked   = applyDomainProviderMatching($pdo);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $reportJson = safeJson([
        'imported' => $imported,
        'matched'  => $matched,
        'skipped'  => $skipped,
        'updated'  => $updated,
        'providers_filled' => $providersFilled,
        'payments_linked'  => $paymentsLinked,
        'domains_linked'   => $domainsLinked,
    ]);
    $upd = $pdo->prepare("UPDATE billing_imports SET imported_rows=?, matched_rows=?, status='done', report=? WHERE id=?");
    $upd->execute([$imported, $matched, $reportJson, $importId]);

    echo json_encode([
        'success'    => true,
        'imported'   => $imported,
        'matched'    => $matched,
        'skipped'    => $skipped,
        'updated'    => $updated,
        'providers_filled' => $providersFilled,
        'payments_linked'  => $paymentsLinked,
        'domains_linked'   => $domainsLinked,
        'import_id'  => $importId,
        'delimiter'  => $delimiter,
        'header_row' => $headerIdx,
    ]);
}

/* ================================================================
 * List (GET)
 * ================================================================ */

function handleList($pdo) {
    $type = $_GET['type'] ?? null;
    if ($type === 'expenses') {
        $stmt = $pdo->query("SELECT e.*, n.name AS node_name, d.domain_name AS domain_name,
                p.payment_date AS payment_date, p.amount AS payment_amount, p.external_id AS payment_external_id
            FROM billing_expenses e
            LEFT JOIN nodes n ON e.matched_node_id = n.id
            LEFT JOIN domains d ON e.matched_domain_id = d.id
            LEFT JOIN payments p ON e.matched_payment_id = p.id
            ORDER BY e.expense_date DESC, e.id DESC LIMIT 200");
        echo json_encode(['expenses' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        return;
    }
    if ($type === 'domains') {
        $stmt = $pdo->query("SELECT d.*, p.name AS provider_name, p.url AS provider_url
            FROM domains d
            LEFT JOIN providers p ON d.provider_id = p.id
            ORDER BY d.expires_at ASC");
        echo json_encode(['domains' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        return;
    }
    if ($type === 'imports') {
        $stmt = $pdo->query("SELECT * FROM billing_imports ORDER BY id DESC LIMIT 50");
        echo json_encode(['imports' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        return;
    }
    echo json_encode(['ok' => true]);
}