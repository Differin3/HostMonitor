<?php
// API для SMART мониторинга дисков
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDbConnection();

$nodeInfo = null;
if ($method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    session_write_close();
} else {
    $auth = require_api_auth($pdo);
    $nodeInfo = $auth['node'];
}

/**
 * Приводит значение SMART к int или null.
 *
 * Агенты разных платформ могут прислать строку, массив с упакованными
 * Min/Max байтами или человекочитаемую строку — всё это не должно ронять
 * вставку в числовую колонку (иначе 500 и потерянные данные).
 */
function smart_int_or_null($value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return (int)$value;
    }
    if (is_string($value) && preg_match('/-?\d+/', $value, $m) === 1) {
        return (int)$m[0];
    }
    return null;
}

/**
 * Приводит текстовое SMART-поле к строке, массивы/объекты — к null.
 *
 * Smartmontools ≥7.4 отдаёт часть полей объектом {"string": "...", "value": ...}
 * (например sata_version). Такой массив уходил в PDOStatement::execute(),
 * PHP конвертировал его в строку "Array" и писал notice
 * «Array to string conversion», а в колонку попадало мусорное "Array".
 * Берём string, затем value; всё остальное — null.
 */
function smart_str_or_null($value): ?string
{
    if (is_string($value)) {
        return $value;
    }
    if (is_array($value)) {
        foreach (['string', 'value'] as $key) {
            if (array_key_exists($key, $value) && $value[$key] !== null && $value[$key] !== '') {
                return smart_str_or_null($value[$key]);
            }
        }
        return null;
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    return null;
}

function smart_temperature_or_null($value): ?int
{
    $int = smart_int_or_null($value);
    if ($int === null) {
        return null;
    }
    if ($int < -20 || $int > 200) {
        // Упакованное значение (Min/Max в старших байтах) — берём младший байт.
        $int &= 0xFF;
        if ($int < -20 || $int > 200) {
            return null;
        }
    }
    return $int;
}

/**
 * Нормализует raw-значение атрибута для отображения/графиков.
 *
 * Для температуры (ATA id 194) старые агенты присылали упакованное
 * значение raw (старшие байты — Min/Max), поэтому приводим его к °C.
 */
function smart_normalize_attr_raw(int $attributeId, int $raw): int
{
    if ($attributeId === 194 && ($raw > 200 || $raw < -20)) {
        return $raw & 0xFF;
    }
    return $raw;
}

function ensure_smart_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    if (function_exists('schema_marker_fresh') && schema_marker_fresh('smart')) {
        $done = true;
        return;
    }
    if (function_exists('schema_short_lock')) {
        schema_short_lock($pdo);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS smart_drives (
        id INT AUTO_INCREMENT PRIMARY KEY,
        node_id INT NOT NULL,
        device_name VARCHAR(100) NOT NULL,
        model VARCHAR(255) DEFAULT NULL,
        serial_number VARCHAR(255) DEFAULT NULL,
        firmware_version VARCHAR(100) DEFAULT NULL,
        capacity_bytes BIGINT DEFAULT NULL,
        rotation_rate INT DEFAULT NULL,
        interface_type VARCHAR(50) DEFAULT NULL,
        sata_version VARCHAR(50) DEFAULT NULL,
        temperature SMALLINT DEFAULT NULL,
        power_on_hours BIGINT DEFAULT NULL,
        health_status VARCHAR(20) DEFAULT 'unknown',
        bay_number INT DEFAULT NULL,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_node_device (node_id, device_name),
        INDEX idx_node_id (node_id),
        INDEX idx_health (health_status),
        INDEX idx_last_seen (last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS smart_metrics (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        node_id INT NOT NULL,
        device_name VARCHAR(100) NOT NULL,
        attribute_id SMALLINT NOT NULL,
        attribute_name VARCHAR(100) NOT NULL,
        attribute_value BIGINT DEFAULT NULL,
        worst_value BIGINT DEFAULT NULL,
        threshold_value BIGINT DEFAULT NULL,
        raw_value BIGINT DEFAULT NULL,
        flags VARCHAR(20) DEFAULT NULL,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_node_device (node_id, device_name),
        INDEX idx_attribute (attribute_id),
        INDEX idx_timestamp (timestamp),
        INDEX idx_node_ts (node_id, timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (function_exists('schema_marker_touch')) {
        schema_marker_touch('smart');
    }
    $done = true;
}

try {
    ensure_smart_tables($pdo);

    switch ($method) {
        case 'GET':
            handleSmartGet($pdo);
            break;
        case 'POST':
            handleSmartPost($pdo);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    json_exception($e, false);
}

function handleSmartGet(PDO $pdo): void
{
    $nodeId = $_GET['node_id'] ?? null;
    $deviceName = $_GET['device'] ?? null;
    $action = $_GET['action'] ?? null;
    $range = $_GET['range'] ?? '7d';

    // Строка без модели и без единого SMART-показателя — это passthrough/
    // виртуальное устройство (SCSI от гипервизора, USB-мост), а не диск.
    // Держим такие строки вне выдачи, даже если агент старой версии их записал.
    $hasData = "(COALESCE(sd.model, '') <> '' OR sd.temperature IS NOT NULL OR sd.power_on_hours IS NOT NULL)";

    // Простой список приводов (для селекта)
    if ($action === 'drives') {
        $sql = "SELECT sd.*, COALESCE(n.name, CONCAT('Node ', sd.node_id)) as node_name
                FROM smart_drives sd
                LEFT JOIN nodes n ON sd.node_id = n.id
                WHERE $hasData";
        $params = [];
        if ($nodeId) {
            $sql .= " AND sd.node_id = ?";
            $params[] = $nodeId;
        }
        $sql .= " ORDER BY sd.node_id, sd.device_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['drives' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // История атрибутов для графиков
    if ($action === 'history') {
        if (!$nodeId || !$deviceName) {
            json_error('node_id and device are required for history');
        }

        $rangeMap = [
            '1h' => 3600, '6h' => 21600, '24h' => 86400,
            '7d' => 604800, '30d' => 2592000,
        ];
        $fromSec = $rangeMap[$range] ?? 604800;
        $fromTime = date('Y-m-d H:i:s', time() - $fromSec);

        $stmt = $pdo->prepare(
            "SELECT attribute_id, attribute_name, attribute_value, raw_value, timestamp
             FROM smart_metrics
             WHERE node_id = ? AND device_name = ? AND timestamp >= ?
             ORDER BY timestamp ASC"
        );
        $stmt->execute([$nodeId, $deviceName, $fromTime]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $key = $row['attribute_name'] ?: ('attr_' . $row['attribute_id']);
            $grouped[$key][] = [
                'ts' => $row['timestamp'],
                'value' => (int)$row['attribute_value'],
                'raw' => smart_normalize_attr_raw((int)$row['attribute_id'], (int)$row['raw_value']),
            ];
        }
        echo json_encode(['history' => $grouped]);
        exit;
    }

    // Сводка по всем нодам (для таблицы)
    $sql = "SELECT sd.*, COALESCE(n.name, CONCAT('Node ', sd.node_id)) as node_name
            FROM smart_drives sd
            LEFT JOIN nodes n ON sd.node_id = n.id
            WHERE $hasData";
    $params = [];
    if ($nodeId) {
        $sql .= " AND sd.node_id = ?";
        $params[] = $nodeId;
    }
    $sql .= " ORDER BY sd.health_status = 'failed' DESC, sd.health_status = 'warning' DESC, sd.node_id, sd.device_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $drives = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Получаем последние атрибуты для каждого привода
    $attrMap = [];
    if ($drives) {
        $drivePlaceholders = [];
        $driveParams = [];
        foreach ($drives as $d) {
            $drivePlaceholders[] = '(node_id = ? AND device_name = ?)';
            $driveParams[] = $d['node_id'];
            $driveParams[] = $d['device_name'];
        }
        $attrSql = "SELECT sm.*
                    FROM smart_metrics sm
                    INNER JOIN (
                        SELECT node_id, device_name, attribute_id, MAX(timestamp) as max_ts
                        FROM smart_metrics
                        WHERE " . implode(' OR ', $drivePlaceholders) . "
                        GROUP BY node_id, device_name, attribute_id
                    ) latest ON sm.node_id = latest.node_id
                            AND sm.device_name = latest.device_name
                            AND sm.attribute_id = latest.attribute_id
                            AND sm.timestamp = latest.max_ts
                    ORDER BY sm.node_id, sm.device_name, sm.attribute_id";
        $attrStmt = $pdo->prepare($attrSql);
        $attrStmt->execute($driveParams);
        $attrs = $attrStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($attrs as $a) {
            $key = $a['node_id'] . ':' . $a['device_name'];
            if (!isset($attrMap[$key])) {
                $attrMap[$key] = [];
            }
            $attrMap[$key][] = [
                'id' => (int)$a['attribute_id'],
                'name' => $a['attribute_name'],
                'value' => (int)$a['attribute_value'],
                'worst' => (int)$a['worst_value'],
                'threshold' => (int)$a['threshold_value'],
                'raw' => smart_normalize_attr_raw((int)$a['attribute_id'], (int)$a['raw_value']),
                'flags' => $a['flags'],
            ];
        }
    }

    $result = [];
    foreach ($drives as $d) {
        $key = $d['node_id'] . ':' . $d['device_name'];
        $d['attributes'] = $attrMap[$key] ?? [];
        $result[] = $d;
    }

    echo json_encode(['drives' => $result]);
}

function handleSmartPost(PDO $pdo): void
{
    global $nodeInfo;

    $raw = file_get_contents('php://input');
    $data = $raw !== '' ? json_decode($raw, true) : null;

    if ($data === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }

    $nodeId = $nodeInfo ? $nodeInfo['id'] : ($data['node_id'] ?? null);
    if (!$nodeId) {
        http_response_code(400);
        echo json_encode(['error' => 'node_id is required']);
        return;
    }

    $drives = $data['drives'] ?? [];
    if (!is_array($drives)) {
        http_response_code(400);
        echo json_encode(['error' => 'drives must be an array']);
        return;
    }

    $updatedCount = 0;
    $metricsCount = 0;

    $upsertDrive = $pdo->prepare(
        "INSERT INTO smart_drives
            (node_id, device_name, model, serial_number, firmware_version,
             capacity_bytes, rotation_rate, interface_type, sata_version,
             temperature, power_on_hours, health_status, bay_number, last_seen)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            model = VALUES(model),
            serial_number = VALUES(serial_number),
            firmware_version = VALUES(firmware_version),
            capacity_bytes = VALUES(capacity_bytes),
            rotation_rate = VALUES(rotation_rate),
            interface_type = VALUES(interface_type),
            sata_version = VALUES(sata_version),
            temperature = VALUES(temperature),
            power_on_hours = VALUES(power_on_hours),
            health_status = VALUES(health_status),
            bay_number = VALUES(bay_number),
            last_seen = NOW()"
    );

    $insertMetric = $pdo->prepare(
        "INSERT INTO smart_metrics
            (node_id, device_name, attribute_id, attribute_name, attribute_value,
             worst_value, threshold_value, raw_value, flags)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($drives as $drive) {
        $deviceName = $drive['device'] ?? null;
        if (!$deviceName) continue;

        $health = $drive['health'] ?? 'unknown';
        if (!is_string($health)) {
            $health = 'unknown';
        }
        $temperature = smart_temperature_or_null($drive['temperature'] ?? null);
        $powerOnHours = smart_int_or_null($drive['power_on_hours'] ?? null);

        $upsertDrive->execute([
            $nodeId,
            $deviceName,
            smart_str_or_null($drive['model'] ?? null),
            smart_str_or_null($drive['serial'] ?? null),
            smart_str_or_null($drive['firmware'] ?? null),
            smart_int_or_null($drive['capacity'] ?? null),
            smart_int_or_null($drive['rotation_rate'] ?? null),
            smart_str_or_null($drive['interface'] ?? null),
            smart_str_or_null($drive['sata_version'] ?? null),
            $temperature,
            $powerOnHours,
            $health,
            smart_int_or_null($drive['bay'] ?? null),
        ]);
        $updatedCount++;

        // Удаляем старые метрики для этого привода (оставляем последние 1000 записей на привод)
        $pdo->prepare(
            "DELETE FROM smart_metrics WHERE node_id = ? AND device_name = ?
             AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM smart_metrics
                    WHERE node_id = ? AND device_name = ?
                    ORDER BY timestamp DESC LIMIT 1000
                ) t
             )"
        )->execute([$nodeId, $deviceName, $nodeId, $deviceName]);

        // Вставляем атрибуты SMART
        $attributes = $drive['attributes'] ?? [];
        foreach ($attributes as $attr) {
            $attrId = $attr['id'] ?? 0;
            $attrName = $attr['name'] ?? '';
            if (!$attrId && !$attrName) continue;

            $insertMetric->execute([
                $nodeId,
                $deviceName,
                smart_int_or_null($attrId) ?? 0,
                is_string($attrName) ? $attrName : '',
                smart_int_or_null($attr['value'] ?? null),
                smart_int_or_null($attr['worst'] ?? null),
                smart_int_or_null($attr['threshold'] ?? null),
                smart_int_or_null($attr['raw'] ?? null),
                is_string($attr['flags'] ?? null) ? $attr['flags'] : null,
            ]);
            $metricsCount++;
        }
    }

    echo json_encode([
        'success' => true,
        'drives_updated' => $updatedCount,
        'metrics_inserted' => $metricsCount,
    ]);
}
