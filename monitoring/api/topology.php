<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();
require_api_auth($pdo);

function topo_ip(string $ip): ?string
{
    $ip = trim($ip);
    if ($ip === '' || $ip === 'N/A' || $ip === '—' || $ip === '-') {
        return null;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: null;
}

function topo_private(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function topo_mask_long(?string $mask): int
{
    if (!$mask || $mask === 'N/A') {
        return ip2long('255.255.255.0') ?: 0;
    }
    $long = ip2long($mask);
    return $long === false ? (ip2long('255.255.255.0') ?: 0) : $long;
}

function topo_same_subnet(string $a, string $b, ?string $mask = null): bool
{
    $ia = ip2long($a);
    $ib = ip2long($b);
    if ($ia === false || $ib === false) {
        return false;
    }
    $m = topo_mask_long($mask);
    return ($ia & $m) === ($ib & $m);
}

function topo_cidr(string $ip, ?string $mask = null): string
{
    $m = topo_mask_long($mask);
    $net = ip2long($ip);
    if ($net === false) {
        return $ip;
    }
    $network = long2ip($net & $m);
    $bits = substr_count(decbin((int)$m & 0xFFFFFFFF), '1');
    if ($bits < 8) {
        $bits = 24;
    }
    return $network . '/' . $bits;
}

function topo_vendor(string $haystack): string
{
    $s = strtolower($haystack);
    if (preg_match('/cisco|linksys|meraki/', $s)) {
        return 'cisco';
    }
    if (preg_match('/huawei|honor/', $s)) {
        return 'huawei';
    }
    if (preg_match('/mikrotik|routerboard|routeros/', $s)) {
        return 'mikrotik';
    }
    if (preg_match('/keenetic/', $s)) {
        return 'keenetic';
    }
    return 'generic';
}

function topo_kind_upnp(array $d): string
{
    $s = strtolower(($d['device_type'] ?? '') . ' ' . ($d['model_name'] ?? '') . ' ' . ($d['friendly_name'] ?? ''));
    if (preg_match('/ccr|asr9k|\bne40\b|\bne8000\b|core.?router/', $s)) {
        return 'core';
    }
    if ((int)($d['is_igd'] ?? 0) === 1 || preg_match('/gateway|igd|router/', $s)) {
        return 'router';
    }
    if (preg_match('/catalyst|switch|crs\d|s57|s67|c9200/', $s)) {
        return 'switch';
    }
    if (preg_match('/access.?point|\bap\b|hap /', $s)) {
        return 'ap';
    }
    return 'device';
}

function topo_ip6(string $ip): ?string
{
    $ip = strtolower(trim(explode('%', $ip, 2)[0]));
    if ($ip === '' || $ip === 'n/a' || $ip === '::' || $ip === '::1' || strpos($ip, 'fe80:') === 0) {
        return null;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ?: null;
}

function topo_any_ip(string $ip): ?string
{
    return topo_ip($ip) ?: topo_ip6($ip);
}

function topo_skip_iface(string $name): bool
{
    return (bool)preg_match('/^(lo(\d+)?$|docker|veth|br-|virbr|cni|flannel|calico|kube)/i', $name);
}

function topo_ports_up($json): int
{
    if (is_array($json)) {
        $ports = $json;
    } else {
        $ports = json_decode((string)$json, true);
    }
    if (!is_array($ports)) {
        return 0;
    }
    $n = 0;
    foreach ($ports as $p) {
        if (!is_array($p)) {
            continue;
        }
        $up = $p['up'] ?? null;
        if ($up === true || $up === 1 || $up === '1' || $up === 'true') {
            $n++;
            continue;
        }
        $oper = strtolower((string)($p['oper'] ?? $p['status'] ?? $p['ifOperStatus'] ?? ''));
        if ($oper === 'up' || $oper === '1') {
            $n++;
        }
    }
    return $n;
}

function topo_node_busy(array $n): bool
{
    $kind = $n['kind'] ?? '';
    if ($kind === 'wan' || $kind === 'subnet' || ($n['id'] ?? '') === 'wan') {
        return false;
    }
    if (($n['status'] ?? '') !== 'online') {
        return false;
    }
    return ((float)($n['network_in'] ?? 0) + (float)($n['network_out'] ?? 0)) >= 8192;
}

function topo_node_online(array $n): bool
{
    $kind = $n['kind'] ?? '';
    if ($kind === 'wan' || $kind === 'subnet' || ($n['id'] ?? '') === 'wan') {
        return true;
    }
    return ($n['status'] ?? '') === 'online';
}

function topo_best_parent(array $ids, array $graph): ?string
{
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) {
        return null;
    }
    $rank = ['core' => 4, 'router' => 3, 'switch' => 2, 'ap' => 1];
    $ids = array_values(array_filter($ids, static function ($id) use ($graph, $rank) {
        return isset($rank[$graph[$id]['kind'] ?? '']);
    }));
    if (!$ids) {
        return null;
    }
    usort($ids, static function ($a, $b) use ($graph, $rank) {
        return ($rank[$graph[$b]['kind'] ?? ''] ?? 0) <=> ($rank[$graph[$a]['kind'] ?? ''] ?? 0);
    });
    return $ids[0];
}

function topo_unique_switch(array $graph, array $hostNode, string $parentId): ?string
{
    $parent = $graph[$parentId] ?? null;
    if (!$parent || !in_array($parent['kind'] ?? '', ['router', 'core'], true)) {
        return null;
    }
    $probeIps = [];
    foreach ($hostNode['lan_ips'] ?? [] as $lan) {
        if (!empty($lan['ip']) && (int)($lan['family'] ?? 4) !== 6) {
            $probeIps[] = $lan;
        }
    }
    $host = topo_ip((string)($hostNode['host'] ?? ''));
    if ($host) {
        $probeIps[] = ['ip' => $host, 'mask' => '255.255.255.0'];
    }
    $found = [];
    foreach ($graph as $dev) {
        if (($dev['kind'] ?? '') !== 'switch' || empty($dev['host'])) {
            continue;
        }
        foreach ($probeIps as $lan) {
            if (topo_same_subnet((string)$lan['ip'], (string)$dev['host'], $lan['mask'] ?? null)) {
                $found[$dev['id']] = true;
                break;
            }
        }
    }
    if (count($found) === 1) {
        return array_key_first($found);
    }
    return null;
}

try {
    $nodes = [];
    try {
        $nodes = $pdo->query("SELECT id, name, host, status, last_seen FROM nodes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $nodes = [];
    }

    $metrics = [];
    try {
        $stmt = $pdo->query("SELECT m.node_id, m.cpu_percent, m.memory_percent, m.disk_percent, m.network_in, m.network_out
            FROM metrics m
            INNER JOIN (
                SELECT node_id, MAX(timestamp) AS ts FROM metrics GROUP BY node_id
            ) t ON t.node_id = m.node_id AND t.ts = m.timestamp");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metrics[(int)$row['node_id']] = $row;
        }
    } catch (Exception $e) {
        // metrics optional
    }

    $ifacesByNode = [];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS network_interfaces (
            id INT AUTO_INCREMENT PRIMARY KEY,
            node_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            ip VARCHAR(45),
            ipv6 VARCHAR(45) DEFAULT NULL,
            netmask VARCHAR(45),
            ipv6_netmask VARCHAR(45) DEFAULT NULL,
            gateway VARCHAR(45) DEFAULT NULL,
            gateway6 VARCHAR(45) DEFAULT NULL,
            status VARCHAR(20),
            speed INT DEFAULT 0,
            rx_bytes BIGINT DEFAULT 0,
            tx_bytes BIGINT DEFAULT 0,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        foreach ([
            'ALTER TABLE network_interfaces ADD COLUMN gateway VARCHAR(45) DEFAULT NULL AFTER netmask',
            'ALTER TABLE network_interfaces ADD COLUMN ipv6 VARCHAR(45) DEFAULT NULL AFTER ip',
            'ALTER TABLE network_interfaces ADD COLUMN ipv6_netmask VARCHAR(45) DEFAULT NULL AFTER netmask',
            'ALTER TABLE network_interfaces ADD COLUMN gateway6 VARCHAR(45) DEFAULT NULL AFTER gateway',
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Exception $e) {
                // exists
            }
        }
        $stmt = $pdo->query("SELECT * FROM network_interfaces");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ifacesByNode[(int)$row['node_id']][] = $row;
        }
    } catch (Exception $e) {
        $ifacesByNode = [];
    }

    $neighByNode = [];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS network_neighbors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            node_id INT NOT NULL,
            ip VARCHAR(45) NOT NULL,
            mac VARCHAR(32) DEFAULT NULL,
            iface VARCHAR(100) DEFAULT NULL,
            family TINYINT DEFAULT 4,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $stmt = $pdo->query("SELECT node_id, ip FROM network_neighbors");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $neighByNode[(int)$row['node_id']][] = (string)$row['ip'];
        }
    } catch (Exception $e) {
        $neighByNode = [];
    }

    $upnp = [];
    try {
        $upnp = $pdo->query("SELECT * FROM upnp_devices")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $upnp = [];
    }

    $graph = [];
    $links = [];
    $parentOf = [];
    $seenLink = [];

    $addNode = function (array $n) use (&$graph): void {
        $graph[$n['id']] = $n;
    };
    $addLink = function (string $from, string $to, string $label, string $kind = 'lan') use (&$links, &$seenLink, &$parentOf): void {
        if ($from === $to) {
            return;
        }
        $key = $from < $to ? "$from|$to" : "$to|$from";
        if (isset($seenLink[$key])) {
            return;
        }
        $seenLink[$key] = true;
        if (!isset($parentOf[$to])) {
            $parentOf[$to] = $from;
        }
        $links[] = [
            'from' => $from,
            'to' => $to,
            'label' => $label,
            'kind' => $kind,
        ];
    };

    $addNode([
        'id' => 'wan',
        'kind' => 'wan',
        'vendor' => 'generic',
        'name' => 'WAN',
        'host' => '',
        'status' => 'online',
        'subnet' => '',
        'detail' => 'Интернет / ISP',
    ]);

    foreach ($nodes as $n) {
        $id = 'node-' . (int)$n['id'];
        $host = topo_ip((string)($n['host'] ?? '')) ?: (string)($n['host'] ?? '');
        $met = $metrics[(int)$n['id']] ?? [];
        $lanIps = [];
        $gateway = null;
        $gateway6 = null;
        $ipv6 = null;
        $mask = '255.255.255.0';
        $ifacesUp = 0;
        $rxBytes = 0;
        $txBytes = 0;
        foreach ($ifacesByNode[(int)$n['id']] ?? [] as $iface) {
            if (topo_skip_iface((string)$iface['name'])) {
                continue;
            }
            $ip = topo_ip((string)($iface['ip'] ?? ''));
            if ($ip) {
                $lanIps[] = ['ip' => $ip, 'mask' => $iface['netmask'] ?? $mask, 'name' => $iface['name'], 'family' => 4];
            }
            $ip6 = topo_ip6((string)($iface['ipv6'] ?? ''));
            if ($ip6) {
                $lanIps[] = ['ip' => $ip6, 'mask' => $iface['ipv6_netmask'] ?? '', 'name' => $iface['name'], 'family' => 6];
                if (!$ipv6) {
                    $ipv6 = $ip6;
                }
            }
            $gw = topo_ip((string)($iface['gateway'] ?? ''));
            if ($gw) {
                $gateway = $gw;
                $mask = $iface['netmask'] ?? $mask;
            }
            $gw6 = topo_ip6((string)($iface['gateway6'] ?? ''));
            if ($gw6) {
                $gateway6 = $gw6;
            }
            $st = strtolower((string)($iface['status'] ?? ''));
            if ($st === 'up' || $st === '1') {
                $ifacesUp++;
            }
            $rxBytes += (int)($iface['rx_bytes'] ?? 0);
            $txBytes += (int)($iface['tx_bytes'] ?? 0);
        }
        $addNode([
            'id' => $id,
            'kind' => 'server',
            'vendor' => 'generic',
            'name' => $n['name'] ?: $id,
            'host' => $host,
            'status' => ($n['status'] ?? '') === 'online' ? 'online' : 'offline',
            'subnet' => $host && topo_private((string)$host) ? topo_cidr((string)$host, $mask) : '',
            'gateway' => $gateway,
            'gateway6' => $gateway6,
            'ipv6' => $ipv6,
            'lan_ips' => $lanIps,
            'neighbors' => $neighByNode[(int)$n['id']] ?? [],
            'cpu_usage' => $met['cpu_percent'] ?? null,
            'memory_usage' => $met['memory_percent'] ?? null,
            'disk_usage' => $met['disk_percent'] ?? null,
            'network_in' => (float)($met['network_in'] ?? 0),
            'network_out' => (float)($met['network_out'] ?? 0),
            'ifaces_up' => $ifacesUp,
            'rx_bytes' => $rxBytes,
            'tx_bytes' => $txBytes,
            'raw_id' => (int)$n['id'],
            'detail' => 'Агент HostMonitor',
        ]);
    }

    foreach ($upnp as $d) {
        $id = 'upnp-' . (int)$d['id'];
        $host = topo_ip((string)($d['host'] ?? '')) ?: (string)($d['host'] ?? '');
        $wanIp = topo_ip((string)($d['wan_ip'] ?? ''));
        $last = !empty($d['last_seen']) ? strtotime((string)$d['last_seen']) : 0;
        $online = $last > 0 && (time() - $last) < 180;
        $vendor = topo_vendor(($d['manufacturer'] ?? '') . ' ' . ($d['model_name'] ?? '') . ' ' . ($d['friendly_name'] ?? ''));
        $kind = topo_kind_upnp($d);
        $extra = $d['extra'] ?? [];
        if (is_string($extra) && $extra !== '') {
            $decoded = json_decode($extra, true);
            $extra = is_array($decoded) ? $decoded : [];
        }
        $dhcpIps = [];
        foreach (($extra['hosts'] ?? []) as $h) {
            if (!is_array($h)) {
                continue;
            }
            $hip = topo_any_ip((string)($h['ip'] ?? ''));
            if ($hip) {
                $dhcpIps[] = $hip;
            }
        }
        $addNode([
            'id' => $id,
            'kind' => $kind,
            'vendor' => $vendor,
            'name' => $d['friendly_name'] ?: ($d['model_name'] ?: 'UPnP'),
            'host' => $host,
            'wan_ip' => $wanIp,
            'status' => $online ? 'online' : 'offline',
            'subnet' => $host ? topo_cidr((string)$host) : '',
            'manufacturer' => $d['manufacturer'] ?? '',
            'model_name' => $d['model_name'] ?? '',
            'is_igd' => (int)($d['is_igd'] ?? 0),
            'discovered_by' => (int)($d['node_id'] ?? 0),
            'ports_up' => topo_ports_up($d['ports'] ?? null),
            'bytes_sent' => (int)($d['bytes_sent'] ?? 0),
            'bytes_received' => (int)($d['bytes_received'] ?? 0),
            'wan_link' => $d['wan_link'] ?? '',
            'connection_status' => $d['connection_status'] ?? '',
            'dhcp_ips' => $dhcpIps,
            'detail' => trim(($d['manufacturer'] ?? '') . ' ' . ($d['model_name'] ?? '')),
        ]);
    }

    $ipIndex = [];
    $dhcpIndex = [];
    foreach ($graph as $nid => $n) {
        foreach ([(string)($n['host'] ?? ''), (string)($n['wan_ip'] ?? ''), (string)($n['ipv6'] ?? '')] as $cand) {
            $ip = topo_any_ip($cand);
            if ($ip) {
                $ipIndex[$ip][] = $nid;
            }
        }
        foreach ($n['lan_ips'] ?? [] as $lan) {
            $ip = topo_any_ip((string)($lan['ip'] ?? ''));
            if ($ip) {
                $ipIndex[$ip][] = $nid;
            }
        }
        foreach ($n['dhcp_ips'] ?? [] as $hip) {
            $dhcpIndex[$hip] = $nid;
        }
    }

    $igd = array_filter($graph, static fn($n) => in_array($n['kind'] ?? '', ['router', 'core'], true) || !empty($n['is_igd']));

    foreach ($igd as $dev) {
        if ($dev['id'] === 'wan') {
            continue;
        }
        $wanIp = $dev['wan_ip'] ?? null;
        $uplink = 'wan';
        $label = $wanIp ? 'WAN ' . $wanIp : 'uplink';
        $kind = 'wan';
        if ($wanIp && topo_private($wanIp)) {
            foreach ($igd as $other) {
                if ($other['id'] === $dev['id'] || empty($other['host'])) {
                    continue;
                }
                if (topo_same_subnet($wanIp, (string)$other['host'])) {
                    $uplink = $other['id'];
                    $label = $wanIp . ' → ' . $other['host'];
                    $kind = 'lan';
                    break;
                }
            }
        }
        $addLink($uplink, $dev['id'], $label, $kind);
    }

    foreach ($graph as $dev) {
        if (!in_array($dev['kind'] ?? '', ['device', 'switch', 'ap'], true) && empty($dev['is_igd'])) {
            continue;
        }
        if (($dev['kind'] ?? '') === 'router' || ($dev['kind'] ?? '') === 'core' || !empty($dev['is_igd'])) {
            continue;
        }
        if (isset($parentOf[$dev['id']])) {
            continue;
        }
        $host = $dev['host'] ?? '';
        $attached = false;
        if ($host) {
            foreach ($igd as $router) {
                if (!empty($router['host']) && topo_same_subnet((string)$host, (string)$router['host'])) {
                    $addLink($router['id'], $dev['id'], $dev['subnet'] ?: 'LAN', 'lan');
                    $attached = true;
                    break;
                }
            }
        }
        if (!$attached && !empty($dev['discovered_by'])) {
            $addLink('node-' . (int)$dev['discovered_by'], $dev['id'], 'SSDP', 'lan');
        }
    }

    foreach ($graph as $node) {
        if (($node['kind'] ?? '') !== 'server') {
            continue;
        }
        $candidates = [];
        foreach ([(string)($node['gateway'] ?? ''), (string)($node['gateway6'] ?? '')] as $gw) {
            $ip = topo_any_ip($gw);
            if ($ip && !empty($ipIndex[$ip])) {
                $candidates = array_merge($candidates, $ipIndex[$ip]);
            }
        }
        foreach ($node['neighbors'] ?? [] as $nip) {
            $ip = topo_any_ip((string)$nip);
            if ($ip && !empty($ipIndex[$ip])) {
                $candidates = array_merge($candidates, $ipIndex[$ip]);
            }
        }
        $candidates = array_values(array_filter($candidates, static fn($id) => $id !== $node['id']));
        $parent = topo_best_parent($candidates, $graph);
        $label = 'lan';
        $kind = 'lan';
        if ($parent && (($node['gateway'] ?? '') !== '' || ($node['gateway6'] ?? '') !== '')) {
            $g4 = $node['gateway'] ?? '';
            $g6 = $node['gateway6'] ?? '';
            $label = 'gw ' . ($g4 ?: $g6);
        }
        if (!$parent) {
            foreach ($node['lan_ips'] ?? [] as $lan) {
                $ip = topo_any_ip((string)($lan['ip'] ?? ''));
                if ($ip && !empty($dhcpIndex[$ip])) {
                    $parent = $dhcpIndex[$ip];
                    $label = 'DHCP ' . $ip;
                    break;
                }
            }
        }
        if ($parent) {
            $switchId = topo_unique_switch($graph, $node, $parent);
            if ($switchId) {
                $parent = $switchId;
                $label = ($node['subnet'] ?: 'access') . ' · L2';
            }
            $addLink($parent, $node['id'], $label, $kind);
            continue;
        }
        $host = topo_ip((string)($node['host'] ?? ''));
        if ($host && !topo_private($host)) {
            $addLink('wan', $node['id'], $host, 'wan');
        }
    }

    foreach ($graph as $node) {
        if ($node['id'] === 'wan' || isset($parentOf[$node['id']])) {
            continue;
        }
        if (($node['kind'] ?? '') === 'server') {
            $host = topo_ip((string)($node['host'] ?? ''));
            if ($host && !topo_private($host)) {
                $addLink('wan', $node['id'], $host, 'wan');
            }
        }
    }

    foreach ($links as &$link) {
        $a = $graph[$link['from']] ?? [];
        $b = $graph[$link['to']] ?? [];
        $online = topo_node_online($a) && topo_node_online($b);
        $busy = $online && (topo_node_busy($a) || topo_node_busy($b));
        $link['busy'] = $busy;
        $link['activity'] = !$online ? 'down' : ($busy ? 'busy' : 'idle');
    }
    unset($link);

    echo json_encode([
        'nodes' => array_values($graph),
        'links' => $links,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_exception($e);
}
