<?php
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
        case 'POST':
            handleImport($pdo);
            break;
        case 'GET':
            handleList($pdo);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    app_log_error('billing_import.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'debug' => $e->getMessage()]);
}

/* -------------------- helpers -------------------- */

/**
 * Определяет разделитель по первым ~20 непустым строкам.
 * Одиночная строка "Платежи" раньше ломала определение — теперь смотрим суммарно.
 */
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

/**
 * Первая непустая строка, которая при разборе даёт >= 2 полей.
 * Возвращает [index, fields].
 */
function findHeaderRow(array $lines, string $delimiter): array {
    foreach ($lines as $i => $raw) {
        $l = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $l = trim($l);
        if ($l === '') continue;
        $fields = str_getcsv($l, $delimiter);
        if (is_array($fields) && count($fields) >= 2) {
            return [$i, $fields];
        }
    }
    return [-1, []];
}

/** Сумма из строки вида "299.00 RUB", "2 419,30 ₽", "340.00 RUB / Год". */
function parseAmount($val) {
    if ($val === null) return 0.0;
    $s = trim((string)$val);
    if ($s === '') return 0.0;
    $s = str_replace(["\xC2\xA0", ' '], '', $s);
    $s = preg_replace('/[Rr][Uu][Bb].*$/u', '', $s); // убрать RUB и всё после
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

/** Парсит дату из разных форматов: Y-m-d, d.m.Y, Y/m/d, d/m/Y, c fallback на strtotime. */
function parseDate($val): ?string {
    if ($val === null) return null;
    $s = trim((string)$val);
    if ($s === '') return null;
    foreach (['Y-m-d', 'd.m.Y', 'Y/m/d', 'd/m/Y', 'Y-m-d H:i:s', 'd.m.Y H:i:s'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }
    $ts = strtotime($s);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }
    return null;
}

/** Достаёт значение из $data по списку возможных ключей. */
function pick(array $data, array $keys) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '') {
            return $data[$k];
        }
    }
    return null;
}

/* -------------------- import -------------------- */

function handleImport($pdo) {
    $type = $_POST['type'] ?? null;
    if (!in_array($type, ['payments','expenses','domains'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'type must be payments|expenses|domains']);
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
        $h2 = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h));
        $headersNorm[] = mb_strtolower($h2);
    }

    $importStmt = $pdo->prepare("INSERT INTO billing_imports (type, filename, imported_by, total_rows, status) VALUES (?,?,?,?, 'pending')");
    $importStmt->execute([$type, $_FILES['file']['name'] ?? null, $_SESSION['user_id'] ?? null, max(0, count($lines) - $start)]);
    $importId = (int)$pdo->lastInsertId();

    $imported = 0;
    $matched = 0;

    if ($type === 'payments') {
        $pdo->beginTransaction();
        try {
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

                // Попытка матчинга по FBRUS-NNNN или pfx/NNNN в номере/описании/плательщике
                $matchedNodeId = null;
                $haystack = trim(($num ?? '') . ' ' . ($desc ?? '') . ' ' . ($externalId ?? ''));
                if ($haystack !== '' && preg_match('/(FBRUS-\d+|pfx\/\d+)/iu', $haystack, $m)) {
                    $pat = $m[1];
                    $stmt = $pdo->prepare("SELECT id FROM nodes WHERE name LIKE ? OR notes LIKE ? LIMIT 1");
                    $stmt->execute(['%' . $pat . '%', '%' . $pat . '%']);
                    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $matchedNodeId = (int)$r['id'];
                    }
                }

                // Дедупликация: (node_id, amount, payment_date) в пределах суток
                $chk = $pdo->prepare("SELECT id FROM payments WHERE node_id <=> ? AND amount = ? AND payment_date = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1");
                $chk->execute([$matchedNodeId, $amount, $pdate]);
                if ($chk->fetch()) continue;

                // Импортируем ВСЕГДА — даже без ноды, чтобы не терять данные
                $ins = $pdo->prepare("INSERT INTO payments (node_id, amount, currency, payment_date) VALUES (?,?, 'RUB', ?)");
                $ins->execute([$matchedNodeId, $amount, $pdate]);
                $imported++;
                if ($matchedNodeId) $matched++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } elseif ($type === 'expenses') {
        $pdo->beginTransaction();
        try {
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
                // "Оплачено платежами" — это ссылки на платежи (FBRUS-NNNN / pfx/NNNN), логично писать в account_ref
                $account    = pick($data, ['оплачено платежами', 'на счёт', 'на счет', 'account_ref', 'account']);

                if ($amount == 0 && $amountWo == 0) continue;

                $edate = parseDate($dateStr);
                if ($edate === null) continue;

                // Матчинг ноды по account_ref + описанию
                $matchedNodeId = null;
                $matchedDomainId = null;
                $matchType = 'none';

                if ($account && preg_match('/(FBRUS-\d+|pfx\/\d+)/iu', $account . ' ' . $desc, $m)) {
                    $pat = $m[1];
                    $stmt = $pdo->prepare("SELECT id FROM nodes WHERE name LIKE ? OR notes LIKE ? LIMIT 1");
                    $stmt->execute(['%' . $pat . '%', '%' . $pat . '%']);
                    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $matchedNodeId = (int)$r['id'];
                        $matchType = 'auto';
                    }
                }
                if (preg_match('/#(\d+)\s*\(([a-z0-9\.-]+\.[a-z]{2,})\)/iu', (string)$desc, $dm)) {
                    $dname = strtolower($dm[2]);
                    $stmt = $pdo->prepare("SELECT id FROM domains WHERE name = ? LIMIT 1");
                    $stmt->execute([$dname]);
                    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $matchedDomainId = (int)$r['id'];
                        if ($matchType === 'none') $matchType = 'auto';
                    }
                }
                if ($matchedNodeId || $matchedDomainId) $matched++;

                $chk = $pdo->prepare("SELECT id FROM billing_expenses WHERE external_id = ? OR (description = ? AND expense_date = ? AND amount = ?) LIMIT 1");
                $chk->execute([$externalId, $desc, $edate, $amount]);
                if ($chk->fetch()) continue;

                $ins = $pdo->prepare("INSERT INTO billing_expenses (external_id, description, expense_date, amount, amount_without_discount, account_ref, matched_node_id, matched_domain_id, match_type, raw_data) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([
                    $externalId, $desc, $edate, $amount, $amountWo, $account,
                    $matchedNodeId, $matchedDomainId, $matchType,
                    json_encode($data, JSON_UNESCAPED_UNICODE)
                ]);
                $imported++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } elseif ($type === 'domains') {
        $pdo->beginTransaction();
        try {
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
                $dname      = mb_strtolower((string)pick($data, ['имя домена', 'домен', 'domain']));
                if ($dname === '') continue;
                $zone       = pick($data, ['зона', 'zone']);
                $project    = pick($data, ['проект', 'project', 'контакт']);
                $expiresRaw = pick($data, ['действует до', 'expires', 'expires_at']);
                $status     = pick($data, ['статус услуги', 'статус', 'status']);
                $price      = parseAmount(pick($data, ['стоимость', 'цена', 'price_per_year']));

                $expDate = parseDate($expiresRaw);

                $chk = $pdo->prepare("SELECT id FROM domains WHERE name = ? LIMIT 1");
                $chk->execute([$dname]);
                if ($chk->fetch()) {
                    $upd = $pdo->prepare("UPDATE domains SET external_id=COALESCE(?,external_id), zone=COALESCE(?,zone), project=COALESCE(?,project), expires_at=COALESCE(?,expires_at), price_per_year=CASE WHEN ?>0 THEN ? ELSE price_per_year END, status=? WHERE name=?");
                    $upd->execute([$externalId, $zone, $project, $expDate, $price, $price, ($status && stripos($status, 'актив') !== false) ? 'active' : 'active', $dname]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO domains (external_id, name, zone, project, expires_at, price_per_year, status) VALUES (?,?,?,?,?,?,?)");
                    $ins->execute([$externalId, $dname, $zone, $project, $expDate, $price > 0 ? $price : null, 'active']);
                }
                $imported++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    $upd = $pdo->prepare("UPDATE billing_imports SET imported_rows=?, matched_rows=?, status='done', report=? WHERE id=?");
    $upd->execute([$imported, $matched, json_encode(['imported' => $imported, 'matched' => $matched], JSON_UNESCAPED_UNICODE), $importId]);

    echo json_encode([
        'success'   => true,
        'imported'  => $imported,
        'matched'   => $matched,
        'import_id' => $importId,
        'delimiter' => $delimiter,
        'header_row' => $headerIdx,
        'headers'   => $headersNorm,
    ]);
}

function handleList($pdo) {
    $type = $_GET['type'] ?? null;
    if ($type === 'expenses') {
        $stmt = $pdo->query("SELECT e.*, n.name as node_name, d.name as domain_name FROM billing_expenses e LEFT JOIN nodes n ON e.matched_node_id = n.id LEFT JOIN domains d ON e.matched_domain_id = d.id ORDER BY e.expense_date DESC, e.id DESC LIMIT 200");
        echo json_encode(['expenses' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        return;
    }
    if ($type === 'domains') {
        $stmt = $pdo->query("SELECT * FROM domains ORDER BY expires_at ASC");
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