<?php
/** Panel-side UPnP probe: same ST/SOAP/SCPD path as the Python agent (one-shot, no GENA). */

function upnp_probe_st_list(): array
{
    return [
        'upnp:rootdevice',
        'ssdp:all',
        'urn:schemas-upnp-org:device:InternetGatewayDevice:1',
        'urn:schemas-upnp-org:device:InternetGatewayDevice:2',
        'urn:schemas-upnp-org:service:WANIPConnection:1',
        'urn:schemas-upnp-org:service:WANIPConnection:2',
        'urn:schemas-upnp-org:service:WANPPPConnection:1',
        'urn:schemas-upnp-org:service:WANCommonInterfaceConfig:1',
        'urn:schemas-upnp-org:service:Layer3Forwarding:1',
        'urn:schemas-upnp-org:service:LANHostConfigManagement:1',
        'urn:schemas-upnp-org:service:WLANConfiguration:1',
        'urn:schemas-upnp-org:device:MediaRenderer:1',
        'urn:schemas-upnp-org:device:MediaServer:1',
        'urn:schemas-upnp-org:device:Printer:1',
        'urn:schemas-upnp-org:device:Basic:1',
    ];
}

function upnp_probe_lan_ips(): array
{
    $ips = [];
    $skip = '/^(lo|docker|veth|br-|virbr|cni|flannel|calico|kube|tun|wg|tailscale)/i';
    if (function_exists('net_get_interfaces')) {
        foreach (net_get_interfaces() ?: [] as $name => $info) {
            if (preg_match($skip, (string)$name)) {
                continue;
            }
            if (empty($info['up'])) {
                continue;
            }
            foreach ($info['unicast'] ?? [] as $addr) {
                $ip = $addr['address'] ?? '';
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    && strpos($ip, '127.') !== 0
                    && strpos($ip, '172.17.') !== 0) {
                    $ips[] = $ip;
                }
            }
        }
    }
    return array_values(array_unique($ips));
}

function upnp_probe_http(string $url, int $timeout = 4, string $method = 'GET', array $headers = [], string $body = ''): array
{
    $hdr = "User-Agent: HostMonitor-UPnP/1.1\r\n";
    foreach ($headers as $k => $v) {
        $hdr .= $k . ': ' . $v . "\r\n";
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'timeout' => $timeout,
            'header' => $hdr,
            'content' => $body,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    return ['body' => $raw === false ? '' : $raw, 'headers' => $http_response_header ?? []];
}

function upnp_probe_xml_text(?SimpleXMLElement $el, string $name): string
{
    if (!$el) {
        return '';
    }
    $n = $el->{$name} ?? null;
    if ($n !== null) {
        return trim((string)$n);
    }
    $el->registerXPathNamespace('u', 'urn:schemas-upnp-org:device-1-0');
    $found = $el->xpath('.//*[local-name()="' . $name . '"]');
    return $found ? trim((string)$found[0]) : '';
}

function upnp_probe_local_name(string $tag): string
{
    $p = strrpos($tag, '}');
    return $p === false ? $tag : substr($tag, $p + 1);
}

function upnp_probe_soap(string $controlUrl, string $serviceType, string $action, array $args = []): array
{
    $inner = '';
    foreach ($args as $k => $v) {
        $inner .= '<' . $k . '>' . htmlspecialchars((string)$v, ENT_XML1) . '</' . $k . '>';
    }
    $envelope = '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"><s:Body>'
        . '<u:' . $action . ' xmlns:u="' . $serviceType . '">' . $inner . '</u:' . $action . '></s:Body></s:Envelope>';
    $res = upnp_probe_http($controlUrl, 5, 'POST', [
        'Content-Type' => 'text/xml; charset="utf-8"',
        'SOAPAction' => '"' . $serviceType . '#' . $action . '"',
    ], $envelope);
    $out = [];
    if ($res['body'] === '') {
        return $out;
    }
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($res['body']);
    libxml_use_internal_errors($prev);
    if (!$xml) {
        return $out;
    }
    foreach ($xml->xpath('//*') ?: [] as $node) {
        $name = upnp_probe_local_name($node->getName());
        $text = trim((string)$node);
        if ($text !== '' && !in_array($name, ['Envelope', 'Body', $action, $action . 'Response', 'Fault'], true)) {
            $out[$name] = $text;
        }
        if (strcasecmp($name, 'NewPortListing') === 0 || strcasecmp($name, 'PortListing') === 0) {
            $out['NewPortListing'] = $text !== '' ? $text : $node->asXML();
        }
    }
    return $out;
}

function upnp_probe_svc_can(array $svc, string $action): bool
{
    $acts = $svc['actions'] ?? [];
    return !$acts || in_array($action, $acts, true);
}

function upnp_probe_soap_if(array $svc, string $action, array $args = []): array
{
    if (($svc['control_url'] ?? '') === '' || !upnp_probe_svc_can($svc, $action)) {
        return [];
    }
    return upnp_probe_soap($svc['control_url'], $svc['service_type'], $action, $args);
}

function upnp_probe_find_svc(array $device, array $needles): ?array
{
    foreach ($device['services'] ?? [] as $svc) {
        $st = strtolower((string)($svc['service_type'] ?? ''));
        foreach ($needles as $n) {
            if (str_contains($st, strtolower($n))) {
                return $svc;
            }
        }
    }
    return null;
}

function upnp_probe_find_svcs(array $device, array $needles): array
{
    $out = [];
    foreach ($device['services'] ?? [] as $svc) {
        $st = strtolower((string)($svc['service_type'] ?? ''));
        foreach ($needles as $n) {
            if (str_contains($st, strtolower($n))) {
                $out[] = $svc;
                break;
            }
        }
    }
    return $out;
}

function upnp_probe_load_scpd(array &$svc): void
{
    $url = $svc['scpd_url'] ?? '';
    if ($url === '') {
        $svc['actions'] = [];
        return;
    }
    $body = upnp_probe_http($url, 4)['body'];
    $names = [];
    if ($body) {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($prev);
        if ($xml) {
            foreach ($xml->xpath('//*[local-name()="action"]/*[local-name()="name"]') ?: [] as $n) {
                $names[] = trim((string)$n);
            }
        }
    }
    $svc['actions'] = array_values(array_unique(array_filter($names)));
}

function upnp_probe_parse_device(SimpleXMLElement $dev, string $base, array $ssdp): array
{
    $deviceType = upnp_probe_xml_text($dev, 'deviceType');
    $pres = upnp_probe_xml_text($dev, 'presentationURL');
    $services = [];
    foreach ($dev->xpath('./*[local-name()="serviceList"]/*[local-name()="service"]') ?: [] as $svc) {
        $cu = upnp_probe_xml_text($svc, 'controlURL');
        $su = upnp_probe_xml_text($svc, 'SCPDURL');
        $eu = upnp_probe_xml_text($svc, 'eventSubURL');
        $services[] = [
            'service_type' => upnp_probe_xml_text($svc, 'serviceType'),
            'service_id' => upnp_probe_xml_text($svc, 'serviceId'),
            'control_url' => $cu !== '' ? upnp_probe_urljoin($base, $cu) : '',
            'scpd_url' => $su !== '' ? upnp_probe_urljoin($base, $su) : '',
            'event_url' => $eu !== '' ? upnp_probe_urljoin($base, $eu) : '',
            'actions' => [],
        ];
    }
    foreach ($dev->xpath('./*[local-name()="deviceList"]/*[local-name()="device"]') ?: [] as $child) {
        $nested = upnp_probe_parse_device($child, $base, $ssdp);
        $services = array_merge($services, $nested['services'] ?? []);
    }
    $host = parse_url($ssdp['location'] ?? '', PHP_URL_HOST) ?: ($ssdp['from'] ?? '');
    $hints = 'internetgatewaydevice wanconnectiondevice wandevice wanipconnection wanpppconnection wancommoninterfaceconfig';
    return [
        'udn' => upnp_probe_xml_text($dev, 'UDN') ?: ($ssdp['usn'] ?? ''),
        'friendly_name' => upnp_probe_xml_text($dev, 'friendlyName'),
        'manufacturer' => upnp_probe_xml_text($dev, 'manufacturer'),
        'manufacturer_url' => upnp_probe_xml_text($dev, 'manufacturerURL'),
        'model_name' => upnp_probe_xml_text($dev, 'modelName'),
        'model_number' => upnp_probe_xml_text($dev, 'modelNumber'),
        'model_description' => upnp_probe_xml_text($dev, 'modelDescription'),
        'serial_number' => upnp_probe_xml_text($dev, 'serialNumber'),
        'hardware_version' => upnp_probe_xml_text($dev, 'hardwareVersion'),
        'device_type' => $deviceType,
        'presentation_url' => $pres !== '' ? upnp_probe_urljoin($base, $pres) : '',
        'software' => upnp_probe_xml_text($dev, 'softwareVersion'),
        'location_url' => $ssdp['location'] ?? '',
        'host' => $host,
        'ssdp_st' => $ssdp['st'] ?? '',
        'ssdp_server' => $ssdp['server'] ?? '',
        'is_igd' => (int)(stripos($hints, strtolower($deviceType)) !== false || stripos($deviceType, 'InternetGatewayDevice') !== false),
        'services' => $services,
        'port_mappings' => [],
        'extra' => [],
    ];
}

function upnp_probe_urljoin(string $base, string $rel): string
{
    if ($rel === '') {
        return $base;
    }
    if (preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    $p = parse_url($base);
    $scheme = ($p['scheme'] ?? 'http') . '://';
    $host = $p['host'] ?? '';
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    if (str_starts_with($rel, '/')) {
        return $scheme . $host . $port . $rel;
    }
    $path = $p['path'] ?? '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    return $scheme . $host . $port . $dir . $rel;
}

function upnp_probe_pick_wan(array &$device): ?array
{
    $l3 = upnp_probe_find_svc($device, ['Layer3Forwarding']);
    if ($l3) {
        $row = upnp_probe_soap_if($l3, 'GetDefaultConnectionService');
        $spec = strtolower($row['NewDefaultConnectionService'] ?? $row['DefaultConnectionService'] ?? '');
        if ($spec !== '') {
            $device['extra']['default_connection'] = $spec;
            foreach ($device['services'] as $svc) {
                $st = strtolower((string)$svc['service_type']);
                if ($st !== '' && str_contains($spec, $st)) {
                    return $svc;
                }
                if (str_contains($spec, 'wanipconnection') && str_contains($st, 'wanipconnection')) {
                    return $svc;
                }
                if (str_contains($spec, 'wanpppconnection') && str_contains($st, 'wanpppconnection')) {
                    return $svc;
                }
            }
        }
    }
    return upnp_probe_find_svc($device, ['WANIPConnection', 'WANPPPConnection']);
}

function upnp_probe_parse_listings(string $xml): array
{
    $maps = [];
    if ($xml === '') {
        return $maps;
    }
    $prev = libxml_use_internal_errors(true);
    $root = simplexml_load_string($xml) ?: simplexml_load_string('<root>' . $xml . '</root>');
    libxml_use_internal_errors($prev);
    if (!$root) {
        return $maps;
    }
    foreach ($root->xpath('//*') ?: [] as $el) {
        $name = strtolower(upnp_probe_local_name($el->getName()));
        if (!in_array($name, ['portmappingentry', 'portmapping', 'entry'], true) && $el !== $root) {
            continue;
        }
        $row = [];
        foreach ($el->xpath('.//*') ?: [] as $c) {
            $t = trim((string)$c);
            if ($t !== '') {
                $row[upnp_probe_local_name($c->getName())] = $t;
            }
        }
        $ext = $row['NewExternalPort'] ?? $row['ExternalPort'] ?? null;
        if (!$ext) {
            continue;
        }
        $maps[] = [
            'remote_host' => $row['NewRemoteHost'] ?? '',
            'external_port' => (int)$ext,
            'protocol' => strtoupper($row['NewProtocol'] ?? 'TCP'),
            'internal_port' => (int)($row['NewInternalPort'] ?? $row['InternalPort'] ?? 0),
            'internal_client' => $row['NewInternalClient'] ?? $row['InternalClient'] ?? '',
            'enabled' => in_array((string)($row['NewEnabled'] ?? '1'), ['1', 'true', 'True'], true) ? 1 : 0,
            'description' => $row['NewPortMappingDescription'] ?? $row['Description'] ?? '',
            'lease_duration' => (int)($row['NewLeaseDuration'] ?? 0),
        ];
    }
    return $maps;
}

function upnp_probe_mappings(array $wan): array
{
    $maps = [];
    if (upnp_probe_svc_can($wan, 'GetListOfPortMappings')) {
        foreach (['TCP', 'UDP', ''] as $proto) {
            $row = upnp_probe_soap_if($wan, 'GetListOfPortMappings', [
                'NewStartPort' => 0,
                'NewEndPort' => 65535,
                'NewProtocol' => $proto,
                'NewManage' => 1,
                'NewNumberOfPorts' => 1000,
            ]);
            $maps = array_merge($maps, upnp_probe_parse_listings($row['NewPortListing'] ?? ''));
        }
    }
    if ($maps) {
        return $maps;
    }
    for ($i = 0; $i < 128; $i++) {
        $row = upnp_probe_soap_if($wan, 'GetGenericPortMappingEntry', ['NewPortMappingIndex' => $i]);
        $ext = $row['NewExternalPort'] ?? $row['ExternalPort'] ?? null;
        if (!$ext) {
            break;
        }
        $maps[] = [
            'remote_host' => $row['NewRemoteHost'] ?? '',
            'external_port' => (int)$ext,
            'protocol' => strtoupper($row['NewProtocol'] ?? 'TCP'),
            'internal_port' => (int)($row['NewInternalPort'] ?? 0),
            'internal_client' => $row['NewInternalClient'] ?? '',
            'enabled' => in_array((string)($row['NewEnabled'] ?? '1'), ['1', 'true', 'True'], true) ? 1 : 0,
            'description' => $row['NewPortMappingDescription'] ?? '',
            'lease_duration' => (int)($row['NewLeaseDuration'] ?? 0),
        ];
    }
    return $maps;
}

function upnp_probe_enrich(array &$device): void
{
    foreach ($device['services'] as &$svc) {
        upnp_probe_load_scpd($svc);
    }
    unset($svc);
    $dt = strtolower((string)($device['device_type'] ?? ''));
    $wan = upnp_probe_pick_wan($device);
    if (str_contains($dt, 'internetgatewaydevice') || $wan) {
        $device['is_igd'] = 1;
        if ($wan) {
            $ip = upnp_probe_soap_if($wan, 'GetExternalIPAddress');
            $device['wan_ip'] = $ip['NewExternalIPAddress'] ?? $ip['ExternalIPAddress'] ?? '';
            $st = upnp_probe_soap_if($wan, 'GetStatusInfo');
            $device['connection_status'] = $st['NewConnectionStatus'] ?? $st['ConnectionStatus'] ?? '';
            $device['uptime'] = (int)($st['NewUptime'] ?? $st['Uptime'] ?? 0);
            $nat = upnp_probe_soap_if($wan, 'GetNATRSIPStatus');
            if ($nat) {
                $device['extra']['nat_enabled'] = $nat['NewNATEnabled'] ?? '';
            }
            $device['port_mappings'] = upnp_probe_mappings($wan);
        }
        $common = upnp_probe_find_svc($device, ['WANCommonInterfaceConfig']);
        if ($common) {
            $link = upnp_probe_soap_if($common, 'GetCommonLinkProperties');
            $device['link_bitrate_down'] = (int)($link['NewLayer1DownstreamMaxBitRate'] ?? 0);
            $device['link_bitrate_up'] = (int)($link['NewLayer1UpstreamMaxBitRate'] ?? 0);
            if (($device['connection_status'] ?? '') === '') {
                $device['connection_status'] = $link['NewPhysicalLinkStatus'] ?? '';
            }
            $sent = upnp_probe_soap_if($common, 'GetTotalBytesSent');
            $recv = upnp_probe_soap_if($common, 'GetTotalBytesReceived');
            $device['bytes_sent'] = (int)($sent['NewTotalBytesSent'] ?? 0);
            $device['bytes_received'] = (int)($recv['NewTotalBytesReceived'] ?? 0);
            $device['extra']['packets_sent'] = (int)(upnp_probe_soap_if($common, 'GetTotalPacketsSent')['NewTotalPacketsSent'] ?? 0);
            $device['extra']['packets_received'] = (int)(upnp_probe_soap_if($common, 'GetTotalPacketsReceived')['NewTotalPacketsReceived'] ?? 0);
        }
        $eth = upnp_probe_find_svc($device, ['WANEthernetLinkConfig']);
        if ($eth) {
            $ls = upnp_probe_soap_if($eth, 'GetEthernetLinkStatus');
            $status = $ls['NewLinkStatus'] ?? $ls['LinkStatus'] ?? '';
            if ($status !== '') {
                $device['wan_link'] = $status;
                if (($device['connection_status'] ?? '') === '') {
                    $device['connection_status'] = $status;
                }
            }
        }
    }
    $lan = upnp_probe_find_svc($device, ['LANHostConfigManagement']);
    if ($lan) {
        $device['extra']['dhcp'] = [
            'subnet_mask' => upnp_probe_soap_if($lan, 'GetSubnetMask')['NewSubnetMask'] ?? '',
            'routers' => upnp_probe_soap_if($lan, 'GetIPRoutersList')['NewIPRouters'] ?? '',
            'domain' => upnp_probe_soap_if($lan, 'GetDomainName')['NewDomainName'] ?? '',
            'range_min' => upnp_probe_soap_if($lan, 'GetAddressRange')['NewMinAddress'] ?? '',
            'range_max' => upnp_probe_soap_if($lan, 'GetAddressRange')['NewMaxAddress'] ?? '',
            'dns' => upnp_probe_soap_if($lan, 'GetDNSServers')['NewDNSServers'] ?? '',
        ];
    }
    $hosts = [];
    foreach (upnp_probe_find_svcs($device, ['Hosts']) as $svc) {
        $n = (int)(upnp_probe_soap_if($svc, 'GetHostNumberOfEntries')['NewHostNumberOfEntries'] ?? 0);
        for ($i = 0; $i < min($n, 128); $i++) {
            $row = upnp_probe_soap_if($svc, 'GetGenericHostEntry', ['NewIndex' => $i]);
            $ip = $row['NewIPAddress'] ?? '';
            $mac = $row['NewMACAddress'] ?? '';
            if ($ip === '' && $mac === '') {
                continue;
            }
            $hosts[] = [
                'ip' => $ip,
                'mac' => $mac,
                'name' => $row['NewHostName'] ?? '',
                'active' => $row['NewActive'] ?? '',
            ];
        }
    }
    if ($hosts) {
        $device['extra']['hosts'] = $hosts;
        $device['lan_hosts'] = $hosts;
    }
    $radios = [];
    $clients = [];
    foreach (upnp_probe_find_svcs($device, ['WLANConfiguration']) as $svc) {
        $ssid = upnp_probe_soap_if($svc, 'GetSSID')['NewSSID'] ?? '';
        $bssid = upnp_probe_soap_if($svc, 'GetBSSID')['NewBSSID'] ?? '';
        $ch = upnp_probe_soap_if($svc, 'GetChannelInfo')['NewChannel'] ?? upnp_probe_soap_if($svc, 'GetChannel')['NewChannel'] ?? '';
        $ncli = (int)(upnp_probe_soap_if($svc, 'GetTotalAssociations')['NewTotalAssociations'] ?? 0);
        $radios[] = ['ssid' => $ssid, 'bssid' => $bssid, 'channel' => $ch, 'clients' => $ncli];
        for ($i = 0; $i < min($ncli, 64); $i++) {
            $row = upnp_probe_soap_if($svc, 'GetGenericAssociatedDeviceInfo', ['NewAssociatedDeviceIndex' => $i]);
            $mac = $row['NewAssociatedDeviceMACAddress'] ?? '';
            if ($mac === '') {
                continue;
            }
            $clients[] = ['mac' => $mac, 'ip' => $row['NewAssociatedDeviceIPAddress'] ?? '', 'ssid' => $ssid];
        }
    }
    if ($radios) {
        $device['extra']['wlan'] = $radios;
        $device['wlan_ssid'] = $radios[0]['ssid'] ?? '';
        $device['wlan_channel'] = (string)($radios[0]['channel'] ?? '');
        $device['wlan_bssid'] = $radios[0]['bssid'] ?? '';
    }
    if ($clients) {
        $device['extra']['wlan_clients'] = $clients;
    }
    $av = upnp_probe_find_svc($device, ['AVTransport']);
    if ($av) {
        $info = upnp_probe_soap_if($av, 'GetTransportInfo', ['InstanceID' => 0]);
        $device['extra']['media'] = [
            'state' => $info['CurrentTransportState'] ?? $info['NewCurrentTransportState'] ?? '',
            'status' => $info['CurrentTransportStatus'] ?? '',
        ];
    }
    $prn = upnp_probe_find_svc($device, ['PrintBasic', 'Printer']);
    if ($prn || str_contains($dt, 'printer')) {
        $info = $prn ? upnp_probe_soap_if($prn, 'GetPrinterAttributes') : [];
        $device['extra']['printer'] = [
            'state' => $info['PrinterState'] ?? $info['NewPrinterState'] ?? '',
            'name' => $device['friendly_name'] ?? '',
        ];
        if (($device['extra']['printer']['state'] ?? '') !== '') {
            $device['connection_status'] = $device['extra']['printer']['state'];
        }
    }
}

function upnp_probe_ssdp(int $timeout = 8): array
{
    if (!function_exists('socket_create')) {
        throw new RuntimeException('PHP sockets extension is required for local UPnP scan');
    }
    $found = [];
    $ips = upnp_probe_lan_ips();
    if (!$ips) {
        $ips = ['0.0.0.0'];
    }
    $socks = [];
    $stList = upnp_probe_st_list();
    $mx = 3;
    foreach ($ips as $ip) {
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$sock) {
            continue;
        }
        socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
        @socket_set_option($sock, IPPROTO_IP, 10, 4); // IP_MULTICAST_TTL
        if ($ip !== '0.0.0.0') {
            $packed = @inet_pton($ip);
            if ($packed) {
                @socket_set_option($sock, IPPROTO_IP, 9, $packed); // IP_MULTICAST_IF on some builds
                @socket_set_option($sock, IPPROTO_IP, 32, $packed);
            }
            @socket_bind($sock, $ip, 0);
        } else {
            @socket_bind($sock, '0.0.0.0', 0);
        }
        socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 350000]);
        $socks[] = $sock;
        foreach ($stList as $st) {
            $msg = "M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nMAN: \"ssdp:discover\"\r\nMX: {$mx}\r\nST: {$st}\r\n\r\n";
            @socket_sendto($sock, $msg, strlen($msg), 0, '239.255.255.250', 1900);
        }
    }
    $end = microtime(true) + max($timeout, $mx + 5);
    while (microtime(true) <= $end) {
        foreach ($socks as $sock) {
            $buf = '';
            $from = '';
            $port = 0;
            $ok = @socket_recvfrom($sock, $buf, 65535, 0, $from, $port);
            if ($ok === false || $buf === '') {
                continue;
            }
            $location = $st = $server = $usn = '';
            foreach (preg_split("/\r\n/", $buf) as $line) {
                if (stripos($line, 'LOCATION:') === 0) {
                    $location = trim(substr($line, 9));
                } elseif (stripos($line, 'ST:') === 0) {
                    $st = trim(substr($line, 3));
                } elseif (stripos($line, 'NT:') === 0 && $st === '') {
                    $st = trim(substr($line, 3));
                } elseif (stripos($line, 'SERVER:') === 0) {
                    $server = trim(substr($line, 7));
                } elseif (stripos($line, 'USN:') === 0) {
                    $usn = trim(substr($line, 4));
                }
            }
            if ($location !== '') {
                $found[$location] = [
                    'location' => $location,
                    'st' => $st,
                    'server' => $server,
                    'usn' => $usn,
                    'from' => $from,
                ];
            }
        }
    }
    foreach ($socks as $sock) {
        socket_close($sock);
    }
    return array_values($found);
}

function upnp_probe_python_scan(int $timeout): ?array
{
    $script = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'agent' . DIRECTORY_SEPARATOR . 'upnp.py';
    if (!is_readable($script)) {
        return null;
    }
    $bins = ['python3', 'python'];
    foreach ($bins as $bin) {
        $redir = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? '2>NUL' : '2>/dev/null';
        $cmd = $bin . ' ' . escapeshellarg($script) . ' --scan --json --timeout ' . (int)$timeout . ' --mx 3 ' . $redir;
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        if ($code !== 0 || !$out) {
            continue;
        }
        $json = json_decode(implode("\n", $out), true);
        if (is_array($json)) {
            return $json;
        }
    }
    return null;
}

function upnp_probe_discover(int $timeout = 8): array
{
    $fromPy = upnp_probe_python_scan($timeout);
    if (is_array($fromPy)) {
        return $fromPy;
    }
    $found = upnp_probe_ssdp($timeout);
    $byUdn = [];
    foreach ($found as $ssdp) {
        $xml = upnp_probe_http($ssdp['location'], 4)['body'];
        if ($xml === '') {
            continue;
        }
        $prev = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if (!$root) {
            continue;
        }
        $devs = $root->xpath('//*[local-name()="device"]');
        if (!$devs) {
            continue;
        }
        $urlBase = upnp_probe_xml_text($root, 'URLBase') ?: $ssdp['location'];
        $parsed = upnp_probe_parse_device($devs[0], $urlBase, $ssdp);
        $dt = strtolower((string)($parsed['device_type'] ?? ''));
        if (preg_match('/wandevice|wanconnectiondevice|landevice/', $dt) && (int)$parsed['is_igd'] !== 1) {
            continue;
        }
        $udn = $parsed['udn'] ?: $parsed['location_url'];
        if ($udn === '') {
            continue;
        }
        $prevDev = $byUdn[$udn] ?? null;
        if ($prevDev && count($parsed['services']) <= count($prevDev['services'])) {
            continue;
        }
        upnp_probe_enrich($parsed);
        $byUdn[$udn] = $parsed;
    }
    return array_values($byUdn);
}
