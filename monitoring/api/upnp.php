<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/upnp_probe.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();
$auth = require_api_auth($pdo);
$nodeInfo = $auth['node'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function upnp_ensure_schema(PDO $pdo): void
{
    if (function_exists('schema_marker_fresh') && schema_marker_fresh('upnp')) {
        return;
    }
    if (function_exists('schema_short_lock')) {
        schema_short_lock($pdo);
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS upnp_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        node_id INT NULL,
        udn VARCHAR(255) NOT NULL,
        friendly_name VARCHAR(255),
        manufacturer VARCHAR(255),
        manufacturer_url VARCHAR(500),
        model_name VARCHAR(255),
        model_number VARCHAR(100),
        model_description VARCHAR(500),
        serial_number VARCHAR(255),
        device_type VARCHAR(255),
        presentation_url VARCHAR(500),
        location_url VARCHAR(1000),
        host VARCHAR(255),
        ssdp_st VARCHAR(255),
        ssdp_server VARCHAR(255),
        is_igd TINYINT(1) DEFAULT 0,
        connection_status VARCHAR(50),
        wan_ip VARCHAR(45),
        uptime INT DEFAULT 0,
        link_bitrate_up BIGINT DEFAULT 0,
        link_bitrate_down BIGINT DEFAULT 0,
        bytes_sent BIGINT DEFAULT 0,
        bytes_received BIGINT DEFAULT 0,
        last_seen TIMESTAMP NULL,
        software VARCHAR(255) DEFAULT NULL,
        ports TEXT DEFAULT NULL,
        hardware_version VARCHAR(255) DEFAULT NULL,
        wan_link VARCHAR(50) DEFAULT NULL,
        extra TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_node_udn (node_id, udn),
        INDEX idx_node_id (node_id),
        INDEX idx_last_seen (last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $pdo->exec("ALTER TABLE upnp_devices ADD COLUMN software VARCHAR(255) DEFAULT NULL AFTER ssdp_server");
    } catch (Exception $e) {
        // exists
    }
    try {
        $pdo->exec("ALTER TABLE upnp_devices ADD COLUMN ports TEXT DEFAULT NULL AFTER software");
    } catch (Exception $e) {
        // exists
    }
    try {
        $pdo->exec("ALTER TABLE upnp_devices ADD COLUMN hardware_version VARCHAR(255) DEFAULT NULL AFTER ports");
    } catch (Exception $e) {
        // exists
    }
    try {
        $pdo->exec("ALTER TABLE upnp_devices ADD COLUMN wan_link VARCHAR(50) DEFAULT NULL AFTER hardware_version");
    } catch (Exception $e) {
        // exists
    }
    try {
        $pdo->exec("ALTER TABLE upnp_devices ADD COLUMN extra TEXT DEFAULT NULL AFTER wan_link");
    } catch (Exception $e) {
        // exists
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS upnp_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL,
        service_type VARCHAR(255),
        service_id VARCHAR(255),
        control_url VARCHAR(1000),
        scpd_url VARCHAR(1000),
        event_url VARCHAR(1000),
        INDEX idx_device_id (device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS upnp_port_mappings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL,
        remote_host VARCHAR(255) DEFAULT '',
        external_port INT,
        protocol VARCHAR(10),
        internal_port INT,
        internal_client VARCHAR(45),
        enabled TINYINT(1) DEFAULT 1,
        description VARCHAR(255),
        lease_duration INT DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_map (device_id, external_port, protocol, remote_host),
        INDEX idx_device_id (device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (function_exists('schema_marker_touch')) {
        schema_marker_touch('upnp');
    }
}

function upnp_ident_empty(?string $value): bool
{
    $v = trim((string)$value);
    return $v === '' || in_array($v, ['—', '-', 'N/A', 'n/a', 'unknown'], true);
}

function upnp_enrich_identity(array $device): array
{
    $blob = strtolower(trim(implode(' ', array_filter([
        $device['manufacturer'] ?? '',
        $device['model_name'] ?? '',
        $device['model_number'] ?? '',
        $device['friendly_name'] ?? '',
        $device['model_description'] ?? '',
        $device['ssdp_server'] ?? '',
        $device['software'] ?? '',
    ], static fn($v) => $v !== '' && $v !== null))));

    $rules = [
        ['ccr2004-1g-12s\\+2xs', 'MikroTik', 'CCR2004-1G-12S+2XS'],
        ['ccr2004', 'MikroTik', 'CCR2004'],
        ['rb4011', 'MikroTik', 'RB4011iGS+RM'],
        ['rb3011', 'MikroTik', 'RB3011UiAS-RM'],
        ['crs328-24p-4s\\+', 'MikroTik', 'CRS328-24P-4S+'],
        ['crs326-24g-2s\\+', 'MikroTik', 'CRS326-24G-2S+'],
        ['mikrotik|routerboard|routeros|\\bccr\\d|\\brb\\d', 'MikroTik', ''],
        ['c9200l-24p-4g', 'Cisco Systems', 'C9200L-24P-4G'],
        ['c9200l-48p-4x', 'Cisco Systems', 'C9200L-48P-4X'],
        ['isr4331', 'Cisco Systems', 'ISR4331/K9'],
        ['isr4321', 'Cisco Systems', 'ISR4321/K9'],
        ['isr4351', 'Cisco Systems', 'ISR4351/K9'],
        ['cisco|meraki|linksys', 'Cisco Systems', ''],
        ['s5735-l24p4x', 'Huawei', 'S5735-L24P4X-A'],
        ['ar6120', 'Huawei', 'AR6120-S'],
        ['huawei', 'Huawei', ''],
        ['keenetic', 'Keenetic', ''],
        ['tp-?link|archer', 'TP-Link', ''],
    ];

    $matchedMfr = '';
    $matchedModel = '';
    foreach ($rules as [$re, $mfr, $model]) {
        if ($blob !== '' && preg_match('/' . $re . '/i', $blob)) {
            $matchedMfr = $mfr;
            $matchedModel = $model;
            break;
        }
    }

    if (upnp_ident_empty($device['manufacturer'] ?? null) && $matchedMfr !== '') {
        $device['manufacturer'] = $matchedMfr;
    }
    if (upnp_ident_empty($device['model_name'] ?? null)) {
        if (!upnp_ident_empty($device['model_number'] ?? null)) {
            $device['model_name'] = trim((string)$device['model_number']);
        } elseif ($matchedModel !== '') {
            $device['model_name'] = $matchedModel;
        } else {
            $friendly = trim((string)($device['friendly_name'] ?? ''));
            if ($friendly !== '' && !preg_match('/^(router|internet\\s*gateway|root\\s*device|upnp|gateway|device)$/i', $friendly)) {
                $device['model_name'] = $friendly;
            }
        }
    }
    if (upnp_ident_empty($device['manufacturer'] ?? null) && !upnp_ident_empty($device['model_name'] ?? null)) {
        $modelBlob = strtolower((string)$device['model_name']);
        foreach ($rules as [$re, $mfr]) {
            if (preg_match('/' . $re . '/i', $modelBlob)) {
                $device['manufacturer'] = $mfr;
                break;
            }
        }
    }
    return $device;
}

function upnp_save_devices(PDO $pdo, ?int $nodeId, array $devices): int
{
    $saved = 0;
    $upsert = $pdo->prepare("INSERT INTO upnp_devices
        (node_id, udn, friendly_name, manufacturer, manufacturer_url, model_name, model_number, model_description,
         serial_number, device_type, presentation_url, location_url, host, ssdp_st, ssdp_server, software, is_igd,
         connection_status, wan_ip, uptime, link_bitrate_up, link_bitrate_down, bytes_sent, bytes_received, ports,
         hardware_version, wan_link, extra, last_seen)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            friendly_name=VALUES(friendly_name), manufacturer=VALUES(manufacturer), manufacturer_url=VALUES(manufacturer_url),
            model_name=VALUES(model_name), model_number=VALUES(model_number), model_description=VALUES(model_description),
            serial_number=VALUES(serial_number), device_type=VALUES(device_type), presentation_url=VALUES(presentation_url),
            location_url=VALUES(location_url), host=VALUES(host), ssdp_st=VALUES(ssdp_st), ssdp_server=VALUES(ssdp_server),
            software=VALUES(software), is_igd=VALUES(is_igd), connection_status=VALUES(connection_status), wan_ip=VALUES(wan_ip),
            uptime=VALUES(uptime), link_bitrate_up=VALUES(link_bitrate_up), link_bitrate_down=VALUES(link_bitrate_down),
            bytes_sent=VALUES(bytes_sent), bytes_received=VALUES(bytes_received), ports=VALUES(ports),
            hardware_version=VALUES(hardware_version), wan_link=VALUES(wan_link), extra=VALUES(extra), last_seen=NOW()");

    $svcIns = $pdo->prepare("INSERT INTO upnp_services (device_id, service_type, service_id, control_url, scpd_url, event_url)
        VALUES (?,?,?,?,?,?)");
    $mapIns = $pdo->prepare("INSERT INTO upnp_port_mappings
        (device_id, remote_host, external_port, protocol, internal_port, internal_client, enabled, description, lease_duration)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE internal_port=VALUES(internal_port), internal_client=VALUES(internal_client),
            enabled=VALUES(enabled), description=VALUES(description), lease_duration=VALUES(lease_duration), updated_at=NOW()");

    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }
        $udn = trim((string)($device['udn'] ?? ''));
        if ($udn === '') {
            continue;
        }
        // uuid:xxx::urn:schemas... → uuid:xxx (иначе одно устройство даёт много ключей)
        if (strpos($udn, '::') !== false) {
            $udn = explode('::', $udn, 2)[0];
        }
        $device = upnp_enrich_identity($device);
        $portsJson = null;
        if (!empty($device['ports']) && is_array($device['ports'])) {
            $portsJson = json_encode($device['ports'], JSON_UNESCAPED_UNICODE);
        } elseif (!empty($device['ports']) && is_string($device['ports'])) {
            $portsJson = $device['ports'];
        }
        $extra = $device['extra'] ?? [];
        if (is_string($extra) && $extra !== '') {
            $decoded = json_decode($extra, true);
            $extra = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($extra)) {
            $extra = [];
        }
        if (!empty($device['lan_hosts']) && empty($extra['hosts'])) {
            $extra['hosts'] = $device['lan_hosts'];
        }
        if (!empty($device['wlan']) && empty($extra['wlan'])) {
            $extra['wlan'] = $device['wlan'];
        }
        $extraJson = $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null;
        $upsert->execute([
            $nodeId,
            $udn,
            mb_substr((string)($device['friendly_name'] ?? ''), 0, 255),
            mb_substr((string)($device['manufacturer'] ?? ''), 0, 255),
            mb_substr((string)($device['manufacturer_url'] ?? ''), 0, 500),
            mb_substr((string)($device['model_name'] ?? ''), 0, 255),
            mb_substr((string)($device['model_number'] ?? ''), 0, 100),
            mb_substr((string)($device['model_description'] ?? ''), 0, 500),
            mb_substr((string)($device['serial_number'] ?? ''), 0, 255),
            mb_substr((string)($device['device_type'] ?? ''), 0, 255),
            mb_substr((string)($device['presentation_url'] ?? ''), 0, 500),
            mb_substr((string)($device['location_url'] ?? ''), 0, 1000),
            mb_substr((string)($device['host'] ?? ''), 0, 255),
            mb_substr((string)($device['ssdp_st'] ?? ''), 0, 255),
            mb_substr((string)($device['ssdp_server'] ?? ''), 0, 255),
            mb_substr((string)($device['software'] ?? $device['softwareVersion'] ?? ''), 0, 255),
            !empty($device['is_igd']) ? 1 : 0,
            mb_substr((string)($device['connection_status'] ?? ''), 0, 50),
            mb_substr((string)($device['wan_ip'] ?? ''), 0, 45),
            (int)($device['uptime'] ?? 0),
            (int)($device['link_bitrate_up'] ?? 0),
            (int)($device['link_bitrate_down'] ?? 0),
            (int)($device['bytes_sent'] ?? 0),
            (int)($device['bytes_received'] ?? 0),
            $portsJson,
            mb_substr((string)($device['hardware_version'] ?? ''), 0, 255) ?: null,
            mb_substr((string)($device['wan_link'] ?? ''), 0, 50) ?: null,
            $extraJson,
        ]);

        $idStmt = $pdo->prepare("SELECT id FROM upnp_devices WHERE udn = ? AND ((node_id <=> ?))");
        $idStmt->execute([$udn, $nodeId]);
        $deviceId = (int)$idStmt->fetchColumn();
        if (!$deviceId) {
            continue;
        }

        $pdo->prepare("DELETE FROM upnp_services WHERE device_id = ?")->execute([$deviceId]);
        foreach ($device['services'] ?? [] as $svc) {
            if (!is_array($svc)) {
                continue;
            }
            $svcIns->execute([
                $deviceId,
                $svc['service_type'] ?? '',
                $svc['service_id'] ?? '',
                $svc['control_url'] ?? '',
                $svc['scpd_url'] ?? '',
                $svc['event_url'] ?? '',
            ]);
        }

        $seenMaps = [];
        foreach ($device['port_mappings'] ?? [] as $map) {
            if (!is_array($map)) {
                continue;
            }
            $ext = (int)($map['external_port'] ?? 0);
            $proto = strtoupper((string)($map['protocol'] ?? 'TCP'));
            $remote = (string)($map['remote_host'] ?? '');
            $mapIns->execute([
                $deviceId,
                $remote,
                $ext,
                $proto,
                (int)($map['internal_port'] ?? 0),
                $map['internal_client'] ?? '',
                !empty($map['enabled']) ? 1 : 0,
                $map['description'] ?? '',
                (int)($map['lease_duration'] ?? 0),
            ]);
            $seenMaps[] = $deviceId . ':' . $ext . ':' . $proto . ':' . $remote;
        }
        if (!empty($device['port_mappings'])) {
            $existing = $pdo->prepare("SELECT id, external_port, protocol, remote_host FROM upnp_port_mappings WHERE device_id = ?");
            $existing->execute([$deviceId]);
            $del = $pdo->prepare("DELETE FROM upnp_port_mappings WHERE id = ?");
            while ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
                $key = $deviceId . ':' . $row['external_port'] . ':' . strtoupper($row['protocol']) . ':' . $row['remote_host'];
                if (!in_array($key, $seenMaps, true)) {
                    $del->execute([$row['id']]);
                }
            }
        }
        $saved++;
    }
    return $saved;
}

function upnp_prune_missing(PDO $pdo, int $nodeId, array $devices): void
{
    $udns = [];
    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }
        $udn = trim((string)($device['udn'] ?? ''));
        if ($udn !== '') {
            $udns[] = $udn;
        }
    }
    if (!$udns) {
        $pdo->prepare('DELETE FROM upnp_devices WHERE node_id = ?')->execute([$nodeId]);
        return;
    }
    $in = implode(',', array_fill(0, count($udns), '?'));
    $params = array_merge([$nodeId], $udns);
    $pdo->prepare("DELETE FROM upnp_devices WHERE node_id = ? AND udn NOT IN ($in)")->execute($params);
}

function upnp_ssdp_local(int $timeout = 8): array
{
    return upnp_probe_discover($timeout);
}

function upnp_unpack_extra(array $device): array
{
    $extra = $device['extra'] ?? [];
    if (is_string($extra) && $extra !== '') {
        $decoded = json_decode($extra, true);
        $extra = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($extra)) {
        $extra = [];
    }
    $device['extra'] = $extra;
    $device['lan_hosts'] = $extra['hosts'] ?? $device['lan_hosts'] ?? [];
    $device['wlan'] = $extra['wlan'] ?? [];
    $device['wlan_clients'] = $extra['wlan_clients'] ?? [];
    $device['media'] = $extra['media'] ?? null;
    $device['printer'] = $extra['printer'] ?? null;
    $device['dhcp'] = $extra['dhcp'] ?? null;
    if (empty($device['wlan_ssid']) && !empty($extra['wlan'][0]['ssid'])) {
        $device['wlan_ssid'] = $extra['wlan'][0]['ssid'];
        $device['wlan_channel'] = $extra['wlan'][0]['channel'] ?? '';
        $device['wlan_bssid'] = $extra['wlan'][0]['bssid'] ?? '';
    }
    return $device;
}

try {
    upnp_ensure_schema($pdo);
    $action = $_GET['action'] ?? null;

    if ($method === 'GET') {
        $nodeId = isset($_GET['node_id']) ? (int)$_GET['node_id'] : null;
        $sql = "SELECT d.*, COALESCE(n.name, 'panel') as node_name
                FROM upnp_devices d
                LEFT JOIN nodes n ON n.id = d.node_id
                WHERE 1=1";
        $params = [];
        if ($nodeId) {
            $sql .= " AND d.node_id = ?";
            $params[] = $nodeId;
        }
        $sql .= " ORDER BY d.is_igd DESC, d.last_seen DESC, d.friendly_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $svcStmt = $pdo->prepare("SELECT * FROM upnp_services WHERE device_id = ?");
        $mapStmt = $pdo->prepare("SELECT * FROM upnp_port_mappings WHERE device_id = ? ORDER BY external_port");

        $rowsByDevice = [];
        foreach ($devices as $row) {
            $device = $row;
            $svcStmt->execute([$device['id']]);
            $device['services'] = $svcStmt->fetchAll(PDO::FETCH_ASSOC);
            $mapStmt->execute([$device['id']]);
            $device['port_mappings'] = $mapStmt->fetchAll(PDO::FETCH_ASSOC);
            $last = $device['last_seen'] ? strtotime((string)$device['last_seen']) : 0;
            $device['online'] = $last > 0 && (time() - $last) < 600;
            $device = upnp_enrich_identity($device);
            $device = upnp_unpack_extra($device);
            if (!empty($device['ports']) && is_string($device['ports'])) {
                $decoded = json_decode($device['ports'], true);
                $device['ports'] = is_array($decoded) ? $decoded : [];
            } elseif (!is_array($device['ports'] ?? null)) {
                $device['ports'] = [];
            }
            $dt = (string)($device['device_type'] ?? '');
            if (preg_match('/WANDevice|WANConnectionDevice|LANDevice/i', $dt) && (int)($device['is_igd'] ?? 0) !== 1) {
                continue;
            }

            $host = trim((string)($device['host'] ?? ''));
            $udn = trim((string)($device['udn'] ?? ''));
            if (strpos($udn, '::') !== false) {
                $udn = explode('::', $udn, 2)[0];
            }
            $udn = strtolower($udn);
            // При общем списке схлопываем одно устройство с разных нод
            if ($nodeId) {
                $dedupKey = (string)$device['id'];
            } elseif ($udn !== '') {
                $dedupKey = 'udn:' . $udn;
            } elseif ($host !== '') {
                $dedupKey = 'host:' . strtolower($host);
            } else {
                $dedupKey = 'id:' . (string)$device['id'];
            }

            if (!isset($rowsByDevice[$dedupKey])) {
                $device['seen_from_nodes'] = [];
                if (!empty($device['node_name'])) {
                    $device['seen_from_nodes'][] = $device['node_name'];
                }
                $rowsByDevice[$dedupKey] = $device;
            } else {
                $existing = &$rowsByDevice[$dedupKey];
                if (!empty($device['node_name']) && !in_array($device['node_name'], $existing['seen_from_nodes'], true)) {
                    $existing['seen_from_nodes'][] = $device['node_name'];
                }
                $currLast = $existing['last_seen'] ? strtotime((string)$existing['last_seen']) : 0;
                $newLast = $device['last_seen'] ? strtotime((string)$device['last_seen']) : 0;
                if ($newLast > $currLast) {
                    foreach ($device as $k => $v) {
                        if ($k === 'id' || $k === 'node_id' || $k === 'node_name' || $k === 'seen_from_nodes') {
                            continue;
                        }
                        if ($v !== null && $v !== '' && $v !== [] && $v !== 0 && $v !== '0') {
                            $existing[$k] = $v;
                        }
                    }
                }
                $existing['online'] = !empty($existing['online']) || !empty($device['online']);
                unset($existing);
            }
        }

        $clean = array_values($rowsByDevice);
        usort($clean, static function ($a, $b) {
            $iga = (int)($a['is_igd'] ?? 0);
            $igb = (int)($b['is_igd'] ?? 0);
            if ($iga !== $igb) return $igb - $iga;
            $la = $a['last_seen'] ? strtotime((string)$a['last_seen']) : 0;
            $lb = $b['last_seen'] ? strtotime((string)$b['last_seen']) : 0;
            if ($la !== $lb) return $lb - $la;
            return strcasecmp((string)($a['friendly_name'] ?? ''), (string)($b['friendly_name'] ?? ''));
        });

        echo json_encode(['devices' => $clean]);
        exit;
    }

    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if ($action === 'scan') {
            if ($nodeInfo) {
                json_error('Only panel users can queue a scan', 403);
            }
            $nodeId = (int)($data['node_id'] ?? $_GET['node_id'] ?? 0);
            if (!$nodeId) {
                json_error('node_id required');
            }
            $stmt = $pdo->prepare("UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW() WHERE id = ?");
            $stmt->execute(['upnp scan', $nodeId]);
            echo json_encode(['success' => true, 'message' => 'UPnP scan queued']);
            exit;
        }

        if ($action === 'scan-local') {
            if ($nodeInfo) {
                json_error('Only panel users can scan locally', 403);
            }
            $devices = upnp_ssdp_local(8);
            $saved = upnp_save_devices($pdo, 0, $devices);
            echo json_encode(['success' => true, 'found' => count($devices), 'saved' => $saved, 'devices' => $devices]);
            exit;
        }

        if ($action === 'add-mapping' || $action === 'delete-mapping') {
            if ($nodeInfo) {
                json_error('Only panel users can change mappings', 403);
            }
            $deviceId = (int)($data['device_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM upnp_devices WHERE id = ?");
            $stmt->execute([$deviceId]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$device || !$device['node_id']) {
                json_error('Device is not attached to an agent node');
            }
            $udn = $device['udn'];
            if ($action === 'add-mapping') {
                $ext = (int)($data['external_port'] ?? 0);
                $intIp = preg_replace('/[^0-9a-fA-F.:]/', '', (string)($data['internal_client'] ?? ''));
                $intPort = (int)($data['internal_port'] ?? 0);
                $proto = strtoupper($data['protocol'] ?? 'TCP');
                $desc = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($data['description'] ?? 'HostMonitor'));
                if (!$ext || !$intIp || !$intPort) {
                    json_error('external_port, internal_client and internal_port required');
                }
                $command = "upnp addmap {$ext} {$intIp} {$intPort} {$proto} {$desc} --udn {$udn}";
            } else {
                $ext = (int)($data['external_port'] ?? 0);
                $proto = strtoupper($data['protocol'] ?? 'TCP');
                if (!$ext) {
                    json_error('external_port required');
                }
                $command = "upnp delmap {$ext} {$proto} --udn {$udn}";
            }
            $upd = $pdo->prepare("UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW() WHERE id = ?");
            $upd->execute([$command, $device['node_id']]);
            echo json_encode(['success' => true, 'command' => $command]);
            exit;
        }

        // Agent snapshot
        if (!$nodeInfo) {
            json_error('Unauthorized', 401);
        }
        if (!empty($data['gone'])) {
            $udns = is_array($data['gone']) ? $data['gone'] : [$data['gone']];
            $stmt = $pdo->prepare("UPDATE upnp_devices SET last_seen = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE node_id = ? AND udn = ?");
            foreach ($udns as $udn) {
                $udn = trim((string)$udn);
                if ($udn === '') {
                    continue;
                }
                $stmt->execute([(int)$nodeInfo['id'], $udn]);
            }
            echo json_encode(['success' => true, 'gone' => count($udns)]);
            exit;
        }
        $devices = $data['devices'] ?? [];
        if (!is_array($devices)) {
            json_error('devices array required');
        }
        $saved = upnp_save_devices($pdo, (int)$nodeInfo['id'], $devices);
        upnp_prune_missing($pdo, (int)$nodeInfo['id'], $devices);
        echo json_encode(['success' => true, 'saved' => $saved]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    json_exception($e, true);
}
