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
} catch (Exception $e) {
    app_log_error('billing_import.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

function detectDelimiter($line) {
    $semi = substr_count($line, ';');
    $comma = substr_count($line, ',');
    $tab = substr_count($line, "\t");
    if ($semi >= $comma && $semi >= $tab) return ';';
    if ($tab >= $comma) return "\t";
    return ',';
}

function parseAmount($val) {
    if ($val === null) return 0.0;
    $s = trim($val);
    if ($s === '') return 0.0;
    $s = str_replace(["\xC2\xA0", ' '], '', $s);
    // RUB suffix
    $s = preg_replace('/[Rr][Uu][Bb]$/u', '', $s);
    // remove non number except , . - 
    // try comma as decimal
    if (strpos($s, ',') !== false && strpos($s, '.') === false) {
        $s = str_replace(',', '.', $s);
    } else {
        // remove thousand separators
        $s = str_replace(',', '', $s);
    }
    $s = preg_replace('/[^0-9\.\-]/', '', $s);
    if ($s === '' || $s === '-' || $s === '.') return 0.0;
    return (float)$s;
}

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
    
    $tmp = $_FILES['file']['tmp_name'];
    $content = file_get_contents($tmp);
    if ($content === false) {
        http_response_code(400);
        echo json_encode(['error' => 'cannot read file']);
        return;
    }
    // try to convert encoding if needed
    if (!mb_check_encoding($content, 'UTF-8')) {
        $converted = @mb_convert_encoding($content, 'UTF-8', 'Windows-1251,CP1251,ISO-8859-5');
        if ($converted) $content = $converted;
    }
    
    $lines = preg_split("/\R/", $content);
    if (count($lines) == 0) {
        http_response_code(400);
        echo json_encode(['error' => 'empty file']);
        return;
    }
    // skip empty first lines
    $start = 0;
    while ($start < count($lines) && trim($lines[$start]) === '') $start++;
    $headerLine = $lines[$start++];
    $delimiter = detectDelimiter($headerLine);
    // remove BOM
    $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);
    $headers = str_getcsv($headerLine, $delimiter);
    // normalize headers
    $headersNorm = [];
    foreach ($headers as $h) {
        $h2 = trim($h);
        $h2 = preg_replace('/^\xEF\xBB\xBF/', '', $h2);
        $headersNorm[] = mb_strtolower($h2);
    }
    
    $importStmt = $pdo->prepare("INSERT INTO billing_imports (type, filename, imported_by, total_rows, status) VALUES (?,?,?,?, 'pending')");
    $importStmt->execute([$type, $_FILES['file']['name'] ?? null, $_SESSION['user_id'] ?? null, max(0, count($lines)-$start)]);
    $importId = $pdo->lastInsertId();
    
    $imported = 0;
    $matched = 0;
    
    if ($type === 'payments') {
        // expect columns like Id, Номер, Дата, Описание, Способ оплаты, Сумма, Сумма к зачислению, Статус, ...
        $pdo->beginTransaction();
        try {
            for ($i = $start; $i < count($lines); $i++) {
                $line = $lines[$i];
                if (trim($line) === '') continue;
                $row = str_getcsv($line, $delimiter);
                if (count($row) == 0) continue;
                // map
                $data = [];
                foreach ($headersNorm as $idx => $hn) {
                    $data[$hn] = isset($row[$idx]) ? trim($row[$idx]) : null;
                }
                $externalId = $data['id'] ?? null;
                $num = $data['номер'] ?? ($data['number'] ?? null);
                $dateStr = $data['дата'] ?? ($data['date'] ?? null);
                $desc = $data['описание'] ?? ($data['description'] ?? '');
                $amount = parseAmount($data['сумма'] ?? ($data['сумма к зачислению'] ?? ($data['amount'] ?? 0)));
                $status = mb_strtolower($data['статус'] ?? '');
                if ($amount <= 0) continue;
                if (strpos($status, 'отмен') !== false || strpos($status, 'возврат') !== false) continue;
                if (!$dateStr) continue;
                // try parse date
                $dt = date_create_from_format('Y-m-d', $dateStr) ?: date_create_from_format('d.m.Y', $dateStr) ?: date_create_from_format('Y/m/d', $dateStr);
                if (!$dt) continue;
                $pdate = $dt->format('Y-m-d');
                
                // try to find matching node by description/number
                $matchedNodeId = null;
                // look for patterns like FBRUS-xxx or pfx/xxx
                if (preg_match('/(FBRUS-\d+|pfx\/\d+)/iu', $num.' '.$desc, $m)) {
                    $pat = $m[1];
                    $stmt = $pdo->prepare("SELECT id FROM nodes WHERE name LIKE ? OR notes LIKE ? LIMIT 1");
                    $stmt->execute(['%'.$pat.'%', '%'.$pat.'%']);
                    if ($rowNode = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $matchedNodeId = $rowNode['id'];
                    } else {
                        // try provider
                        $stmt2 = $pdo->prepare("SELECT id FROM nodes WHERE provider_name LIKE ? LIMIT 5");
                    }
                }
                // insert payment if not exists by external-ish? use description+date+amount
                // check duplicate
                $chk = $pdo->prepare("SELECT id FROM payments WHERE node_id <=> ? AND amount = ? AND payment_date = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1");
                $chk->execute([$matchedNodeId, $amount, $pdate]);
                if ($chk->fetch()) continue;
                if ($matchedNodeId) {
                    $ins = $pdo->prepare("INSERT INTO payments (node_id, amount, currency, payment_date) VALUES (?,?, 'RUB', ?)");
                    $ins->execute([$matchedNodeId, $amount, $pdate]);
                    $imported++;
                    $matched++;
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
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
                if (count($row) == 0) continue;
                $data = [];
                foreach ($headersNorm as $idx => $hn) {
                    $data[$hn] = isset($row[$idx]) ? trim($row[$idx]) : null;
                }
                $externalId = $data['id'] ?? null;
                $desc = $data['описание'] ?? ($data['description'] ?? '');
                $dateStr = $data['дата операции'] ?? ($data['дата'] ?? ($data['date'] ?? null));
                $amount = parseAmount($data['сумма'] ?? ($data['amount'] ?? 0));
                $amountWo = parseAmount($data['без скидки'] ?? ($data['amount_without_discount'] ?? $amount));
                $account = $data['на счёт'] ?? ($data['на счет'] ?? ($data['account'] ?? null));
                if ($amount == 0 && $amountWo == 0) continue;
                if (!$dateStr) continue;
                $dt = date_create_from_format('Y-m-d', $dateStr) ?: date_create_from_format('d.m.Y', $dateStr);
                if (!$dt) continue;
                $edate = $dt->format('Y-m-d');
                
                // find matches
                $matchedNodeId = null;
                $matchedDomainId = null;
                $matchType = 'none';
                // try account ref like FBRUS-xxx, pfx/xxx
                if ($account && preg_match('/(FBRUS-\d+|pfx\/\d+)/iu', $account.' '.$desc, $m)) {
                    $pat = $m[1];
                    $stmt = $pdo->prepare("SELECT id FROM nodes WHERE name LIKE ? OR notes LIKE ? LIMIT 1");
                    $stmt->execute(['%'.$pat.'%', '%'.$pat.'%']);
                    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $matchedNodeId = $r['id'];
                        $matchType = 'auto';
                        $matched++;
                    }
                }
                // domain
                if (preg_match('/#(\d+)\s*\(([a-z0-9\.-]+\.[a-z]{2,})\)/iu', $desc, $dm)) {
                    $dname = strtolower($dm[2]);
                    $stmt = $pdo->prepare("SELECT id FROM domains WHERE name = ? LIMIT 1");
                    $stmt->execute([$dname]);
                    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $matchedDomainId = $r['id'];
                        if ($matchType == 'none') { $matchType = 'auto'; $matched++; }
                    }
                }
                
                $chk = $pdo->prepare("SELECT id FROM billing_expenses WHERE external_id = ? OR (description = ? AND expense_date = ? AND amount = ?) LIMIT 1");
                $chk->execute([$externalId, $desc, $edate, $amount]);
                if ($chk->fetch()) continue;
                
                $ins = $pdo->prepare("INSERT INTO billing_expenses (external_id, description, expense_date, amount, amount_without_discount, account_ref, matched_node_id, matched_domain_id, match_type, raw_data) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([$externalId, $desc, $edate, $amount, $amountWo, $account, $matchedNodeId, $matchedDomainId, $matchType, json_encode($data, JSON_UNESCAPED_UNICODE)]);
                $imported++;
            }
            $pdo->commit();
        } catch (Exception $e) {
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
                if (count($row) == 0) continue;
                $data = [];
                foreach ($headersNorm as $idx => $hn) {
                    $data[$hn] = isset($row[$idx]) ? trim($row[$idx]) : null;
                }
                $externalId = $data['id'] ?? null;
                $dname = strtolower($data['домен'] ?? ($data['domain'] ?? ($data['имя домена'] ?? '')));
                if ($dname === '') continue;
                $zone = $data['зона'] ?? ($data['zone'] ?? null);
                $project = $data['проект'] ?? ($data['project'] ?? null);
                $expires = $data['действует до'] ?? ($data['expires'] ?? null);
                $status = $data['статус'] ?? null;
                $price = parseAmount($data['цена'] ?? ($data['стоимость'] ?? 0));
                
                $expDate = null;
                if ($expires) {
                    $dt = date_create_from_format('Y-m-d', $expires) ?: date_create_from_format('d.m.Y', $expires);
                    if ($dt) $expDate = $dt->format('Y-m-d');
                }
                
                // upsert
                $chk = $pdo->prepare("SELECT id FROM domains WHERE name = ? LIMIT 1");
                $chk->execute([$dname]);
                if ($chk->fetch()) {
                    $upd = $pdo->prepare("UPDATE domains SET external_id=COALESCE(?,external_id), zone=COALESCE(?,zone), project=COALESCE(?,project), expires_at=COALESCE(?,expires_at), price_per_year=CASE WHEN ?>0 THEN ? ELSE price_per_year END, status=? WHERE name=?");
                    $upd->execute([$externalId, $zone, $project, $expDate, $price, $price, ($status && stripos($status,'актив')!==false)?'active':'active', $dname]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO domains (external_id, name, zone, project, expires_at, price_per_year, status) VALUES (?,?,?,?,?,?,?)");
                    $ins->execute([$externalId, $dname, $zone, $project, $expDate, $price>0?$price:null, 'active']);
                }
                $imported++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    $upd = $pdo->prepare("UPDATE billing_imports SET imported_rows=?, matched_rows=?, status='done', report=? WHERE id=?");
    $upd->execute([$imported, $matched, json_encode(['imported'=>$imported,'matched'=>$matched], JSON_UNESCAPED_UNICODE), $importId]);
    
    echo json_encode(['success'=>true, 'imported'=>$imported, 'matched'=>$matched, 'import_id'=>$importId]);
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
    echo json_encode(['ok'=>true]);
}
