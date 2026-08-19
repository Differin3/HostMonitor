/* Shared Cisco / Huawei / MikroTik equipment cards */
(function (global) {
    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    const IDENT_RULES = [
        [/ccr2004-1g-12s\+2xs/i, 'MikroTik', 'CCR2004-1G-12S+2XS'],
        [/ccr2004/i, 'MikroTik', 'CCR2004'],
        [/rb4011/i, 'MikroTik', 'RB4011iGS+RM'],
        [/rb3011/i, 'MikroTik', 'RB3011UiAS-RM'],
        [/crs328-24p-4s\+/i, 'MikroTik', 'CRS328-24P-4S+'],
        [/crs326-24g-2s\+/i, 'MikroTik', 'CRS326-24G-2S+'],
        [/mikrotik|routerboard|routeros|\bccr\d|\brb\d/i, 'MikroTik', ''],
        [/c9200l-24p-4g/i, 'Cisco Systems', 'C9200L-24P-4G'],
        [/c9200l-48p-4x/i, 'Cisco Systems', 'C9200L-48P-4X'],
        [/isr4331/i, 'Cisco Systems', 'ISR4331/K9'],
        [/isr4321/i, 'Cisco Systems', 'ISR4321/K9'],
        [/isr4351/i, 'Cisco Systems', 'ISR4351/K9'],
        [/cisco|meraki|linksys/i, 'Cisco Systems', ''],
        [/s5735-l24p4x/i, 'Huawei', 'S5735-L24P4X-A'],
        [/ar6120/i, 'Huawei', 'AR6120-S'],
        [/huawei/i, 'Huawei', ''],
        [/keenetic/i, 'Keenetic', ''],
        [/tp-?link|archer|tl-w[rs]|tl-sg|deco|mercusys/i, 'TP-Link', ''],
        [/d-?link|dir-\d|dgs-\d/i, 'D-Link', ''],
        [/asus|rt-ax|rt-ac/i, 'ASUS', ''],
        [/zyxel/i, 'Zyxel', ''],
        [/ubiquiti|unifi|edgerouter|\budm\b|\busg\b/i, 'Ubiquiti', ''],
        [/netgear|nighthawk/i, 'NETGEAR', ''],
        [/xiaomi|miwifi|redmi/i, 'Xiaomi', ''],
        [/zte|zxhn/i, 'ZTE', ''],
        [/eltex/i, 'Eltex', ''],
        [/fortinet|fortigate/i, 'Fortinet', ''],
        [/juniper/i, 'Juniper', ''],
        [/aruba|\bhpe\b/i, 'HPE Aruba', ''],
        [/synology/i, 'Synology', ''],
        [/qnap/i, 'QNAP', ''],
        [/avm|fritz/i, 'AVM', ''],
        [/tenda/i, 'Tenda', ''],
        [/openwrt|lede/i, 'OpenWrt', ''],
    ];

    const identEmpty = (value) => {
        const v = String(value ?? '').trim();
        return v === '' || v === '—' || v === '-' || v === 'N/A' || v === 'n/a' || v.toLowerCase() === 'unknown';
    };

    const enrichIdentity = (raw) => {
        const device = { ...raw };
        const blob = `${device.manufacturer || ''} ${device.model_name || ''} ${device.model_number || ''} ${device.friendly_name || ''} ${device.model_description || ''} ${device.ssdp_server || ''} ${device.software || ''}`;
        let matchedMfr = '';
        let matchedModel = '';
        for (const [re, mfr, model] of IDENT_RULES) {
            if (re.test(blob)) {
                matchedMfr = mfr;
                matchedModel = model;
                break;
            }
        }
        if (identEmpty(device.manufacturer) && matchedMfr) device.manufacturer = matchedMfr;
        if (identEmpty(device.model_name)) {
            if (!identEmpty(device.model_number)) device.model_name = String(device.model_number).trim();
            else if (matchedModel) device.model_name = matchedModel;
            else {
                const friendly = String(device.friendly_name || '').trim();
                if (friendly && !/^(router|internet\s*gateway|root\s*device|upnp|gateway|device)$/i.test(friendly)) {
                    device.model_name = friendly;
                }
            }
        }
        if (identEmpty(device.manufacturer) && !identEmpty(device.model_name)) {
            for (const [re, mfr] of IDENT_RULES) {
                if (re.test(String(device.model_name))) {
                    device.manufacturer = mfr;
                    break;
                }
            }
        }
        return device;
    };

    const mfrSlug = (name) => String(name || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '')
        .slice(0, 22);

    const VENDOR_MATCH = [
        [/cisco|linksys|meraki/, 'cisco', 'CISCO'],
        [/huawei|honor|hwtel/, 'huawei', 'HUAWEI'],
        [/mikrotik|routerboard|routeros/, 'mikrotik', 'MikroTik'],
        [/keenetic/, 'keenetic', 'KEENETIC'],
        [/tp-?link|tplink|mercusys/, 'tplink', 'TP-LINK'],
        [/d-?link|dlink/, 'dlink', 'D-LINK'],
        [/asus/, 'asus', 'ASUS'],
        [/zyxel/, 'zyxel', 'ZYXEL'],
        [/ubiquiti|unifi/, 'ubiquiti', 'UBIQUITI'],
        [/netgear/, 'netgear', 'NETGEAR'],
        [/xiaomi|miwifi|redmi/, 'xiaomi', 'XIAOMI'],
        [/zte/, 'zte', 'ZTE'],
        [/eltex/, 'eltex', 'ELTEX'],
        [/fortinet|fortigate/, 'fortinet', 'FORTINET'],
        [/juniper/, 'juniper', 'JUNIPER'],
        [/aruba|\bhpe\b/, 'aruba', 'ARUBA'],
        [/synology/, 'synology', 'SYNOLOGY'],
        [/qnap/, 'qnap', 'QNAP'],
        [/avm|fritz/, 'avm', 'AVM'],
        [/tenda/, 'tenda', 'TENDA'],
        [/openwrt/, 'openwrt', 'OpenWrt'],
    ];

    const brandLabel = Object.fromEntries(VENDOR_MATCH.map(([, id, label]) => [id, label]));
    brandLabel.generic = 'NETWORK';

    const detectVendor = (device) => {
        const filled = enrichIdentity(device);
        const s = `${filled.manufacturer || ''} ${filled.model_name || ''} ${filled.friendly_name || ''} ${filled.model_number || ''} ${filled.brand || ''} ${filled.ssdp_server || ''}`.toLowerCase();
        for (const [re, id] of VENDOR_MATCH) {
            if (re.test(s)) return id;
        }
        const slug = mfrSlug(filled.manufacturer);
        return slug ? `mfr-${slug}` : 'generic';
    };

    const vendorLabel = (device) => {
        const filled = enrichIdentity(device);
        const id = detectVendor(filled);
        if (brandLabel[id]) return brandLabel[id];
        const mfr = String(filled.manufacturer || '').trim();
        if (mfr && !identEmpty(mfr)) return mfr.length > 22 ? mfr.slice(0, 22).toUpperCase() : mfr.toUpperCase();
        return 'NETWORK';
    };

    const listVendors = (devices) => {
        const map = new Map();
        (devices || []).forEach((d) => {
            const filled = enrichIdentity(d);
            map.set(detectVendor(filled), vendorLabel(filled));
        });
        return [...map.entries()].sort((a, b) => a[1].localeCompare(b[1], 'ru'));
    };

    const detectKind = (device) => {
        const s = `${device.device_type || ''} ${device.model_name || ''} ${device.friendly_name || ''} ${device.model_description || ''}`.toLowerCase();
        if (/printer/.test(s)) return 'printer';
        if (/media|renderer|\btv\b|mediaserver/.test(s)) return 'media';
        if (/catalyst|switch|crs\d|s57|s67|c9200|c9300|c2960|c3560|c3750/.test(s)) return 'switch';
        if (/ccr|asr|ne40|ne8000|\bcore\b/.test(s)) return 'core';
        if (/access.?point|\bap\b|\bhap\b|\bwap\b|c9100|cap /.test(s)) return 'ap';
        if (Number(device.is_igd) === 1 || /gateway|igd|router/.test(s)) return 'router';
        return 'device';
    };

    const isOnline = (device) => {
        const v = device?.online;
        if (v === true || v === 1 || v === '1') return true;
        if (v === false || v === 0 || v === '0' || v == null || v === '') return false;
        return Boolean(v);
    };

    const osLabel = (device) => {
        if (device.software) return String(device.software);
        const srv = String(device.ssdp_server || '');
        const mt = srv.match(/MikroTik\/([\d.]+)/i);
        if (mt) return `RouterOS ${mt[1]}`;
        const kn = srv.match(/Keenetic[^\s]*/i);
        if (kn) return kn[0];
        return srv.replace(/\s*UPnP\/[\d.]+\s*/i, ' ').trim();
    };

    const compactSku = (device) => `${device.model_name || ''} ${device.model_number || ''} ${device.friendly_name || ''}`
        .toLowerCase()
        .replace(/\s+/g, '');

    const parseChassis = (device) => {
        const kind = detectKind(device);
        const sku = compactSku(device);
        const maps = (device.port_mappings || []).length;
        const wanUp = /connected|up/i.test(String(device.connection_status || '')) || (Number(device.is_igd) === 1 && isOnline(device) && device.wan_ip);
        const online = isOnline(device);
        const layout = { copper: 0, sfp: 0, xs: 0, console: false, poe: false, dense: false, kind };

        const take = (copper, sfp = 0, xs = 0, extra = {}) => Object.assign(layout, { copper, sfp, xs }, extra);

        if (kind === 'printer' || kind === 'media') {
            return take(0, 0, 0);
        }

        let m = sku.match(/(\d+)g-(\d+)s\+(\d+)xs/);
        if (m) return take(+m[1], +m[2], +m[3], { console: true });
        m = sku.match(/(\d+)g-(\d+)s\+/);
        if (m) return take(+m[1], +m[2], 0, { console: true });
        m = sku.match(/(\d+)s\+-(\d+)xs/);
        if (m) return take(0, +m[1], +m[2], { console: true });

        if (/rb4011/.test(sku)) return take(10, 1);
        if (/rb3011|rb2011/.test(sku)) return take(10, 0);
        if (/\bhap\b|rb952|rb750|hex/.test(sku)) return take(5, 0);
        if (/crs\d+-(\d+)[gp]-(\d+)s\+/.test(sku)) {
            m = sku.match(/crs\d+-(\d+)[gp]-(\d+)s\+/);
            return take(+m[1], +m[2], 0, { poe: /p-/.test(sku), dense: +m[1] >= 24 });
        }

        m = sku.match(/c\d+\w*-(\d+)[pt]-(\d+)([gx])/);
        if (m) return take(+m[1], +m[2], 0, { poe: /p-/.test(sku) || /p\d/.test(sku), dense: +m[1] >= 48, console: true });
        if (/isr4351|isr4331/.test(sku)) return take(3, 0, 0, { console: true });
        if (/isr4321|isr4221/.test(sku)) return take(2, 0, 0, { console: true });

        m = sku.match(/s\d+\w*-l?(\d+)[pt](\d+)x/);
        if (m) return take(+m[1], +m[2], 0, { poe: /p\d/.test(sku), dense: +m[1] >= 48, console: true });
        if (/ar6120/.test(sku)) return take(8, 0, 0, { console: true });
        if (/ar6\d{3}/.test(sku)) return take(8, 2, 0, { console: true });

        m = sku.match(/(\d+)[pt](?:oe)?(?:\+|plus)?-?(\d+)?([sgsx]+)?/);
        if (kind === 'switch' && m && +m[1] >= 8) {
            const sfpN = m[2] ? +m[2] : 4;
            return take(+m[1], sfpN, 0, { poe: /p/.test(m[0]), dense: +m[1] >= 48 });
        }

        if (kind === 'switch') return take(0, 0, 0);
        if (kind === 'core') return take(0, 0, 0);
        if (kind === 'ap') return take(0, 0, 0);
        return take(0, 0, 0);
    };

    const protocolPorts = (device) => {
        let raw = device.ports;
        if (typeof raw === 'string') {
            try { raw = JSON.parse(raw); } catch { raw = []; }
        }
        if (!Array.isArray(raw)) return [];
        return raw.map((p) => {
            const name = String(p.name || p.ifDescr || '').trim();
            const type = p.type || (/sfp28|qsfp|xs/i.test(name) ? 'xs' : /sfp/i.test(name) ? 'sfp' : 'copper');
            const up = p.up === true || p.up === 1 || p.up === '1' || p.oper === 'up' || Number(p.ifOperStatus) === 1;
            return { name, type, up, speed: p.speed || 0 };
        }).filter((p) => p.name);
    };

    const portSpan = (port, dense) => {
        const cls = port.type === 'xs' ? 'gs xs' : port.type === 'sfp' ? 'gs' : `gp${dense ? ' dense' : ''}`;
        return `<span class="${cls}${port.up ? ' up' : ''}" title="${escapeHtml(port.name)}"></span>`;
    };

    const faceHtml = (raw) => {
        const device = enrichIdentity(raw);
        const vendor = detectVendor(device);
        const kind = detectKind(device);
        const model = device.model_name || device.model_number || '';
        const online = isOnline(device);
        const wanUp = /connected|up/i.test(String(device.connection_status || device.wan_link || ''));
        const sku = escapeHtml(model);
        const ports = protocolPorts(device);
        const layout = parseChassis(device);

        let bay = '';
        if (kind === 'printer' || kind === 'media') {
            bay = `<div class="gear-role">${kind === 'printer' ? 'PRN' : 'DLNA'}</div>`;
        } else if (ports.length) {
            const copper = ports.filter((p) => p.type === 'copper' || p.type === 'wan');
            const sfp = ports.filter((p) => p.type === 'sfp');
            const xs = ports.filter((p) => p.type === 'xs');
            const dense = copper.length >= 48;
            const groups = [];
            if (copper.length) {
                groups.push(`<div class="gear-group${copper.length >= 16 ? ' rows-2' : ''}${dense ? ' dense' : ''}">${copper.map((p) => portSpan(p, dense)).join('')}</div>`);
            }
            if (sfp.length) groups.push(`<div class="gear-sfp-group">${sfp.map((p) => portSpan(p, false)).join('')}</div>`);
            if (xs.length) groups.push(`<div class="gear-sfp-group">${xs.map((p) => portSpan(p, false)).join('')}</div>`);
            bay = groups.join('') || `<div class="gear-role">${ports.length} PORT</div>`;
        } else {
            bay = '<div class="gear-role">нет портов</div>';
        }

        const upCount = ports.filter((p) => p.up).length;
        const ledLink = wanUp || upCount ? ' up' : '';
        const ledSys = online ? ' up' : '';
        const ledPwr = online ? ' up' : ' warn';
        const portHint = ports.length ? ` · ${upCount}/${ports.length}` : '';
        return `
            <div class="gear-face vendor-${vendor} kind-${kind}${online ? '' : ' is-offline'}" aria-hidden="true">
                <div class="gear-brand-row">
                    <span class="gear-brand">${escapeHtml(vendorLabel(device))}</span>
                    <span class="gear-sku">${sku}${layout.poe ? ' · PoE' : ''}${portHint}</span>
                    <span class="gear-leds"><span class="led${ledPwr}"></span><span class="led${ledSys}"></span><span class="led${ledLink}"></span></span>
                </div>
                <div class="gear-bay">${bay}</div>
            </div>
        `;
    };

    const formatBytes = (n) => {
        const v = Number(n) || 0;
        if (!v) return '—';
        if (v >= 1099511627776) return `${(v / 1099511627776).toFixed(1)} ТиБ`;
        if (v >= 1073741824) return `${(v / 1073741824).toFixed(v >= 10737418240 ? 0 : 1)} ГиБ`;
        if (v >= 1048576) return `${(v / 1048576).toFixed(v >= 10485760 ? 0 : 1)} МиБ`;
        return `${v} Б`;
    };

    const formatBitrate = (n) => {
        const v = Number(n) || 0;
        if (!v) return '—';
        if (v >= 1000000000) return `${(v / 1000000000).toFixed(1)} Гбит/с`;
        if (v >= 1000000) return `${(v / 1000000).toFixed(0)} Мбит/с`;
        if (v >= 1000) return `${(v / 1000).toFixed(0)} Кбит/с`;
        return `${v} бит/с`;
    };

    const serviceLabels = (device) => (device.services || []).map((s) => {
        const label = typeof s === 'string' ? s : (s.service_type || '').split(':').slice(-2).join(':');
        return escapeHtml(label);
    }).filter(Boolean);

    const cardHtml = (raw, options = {}) => {
        const device = enrichIdentity(raw);
        const vendor = detectVendor(device);
        const kind = detectKind(device);
        const model = device.model_name || device.model_number || '';
        const online = isOnline(device);
        const maps = device.port_mappings || [];
        const services = serviceLabels(device);
        const os = osLabel(device);
        const title = escapeHtml(device.friendly_name || model || device.udn || 'Device');
        const face = faceHtml(device);
        const wanIp = String(device.wan_ip || '').trim();
        const hasWan = wanIp && wanIp !== '—' && wanIp !== '-';
        const wanLink = String(device.wan_link || '').trim();
        const nodeLine = `${device.node_name || 'panel'} · ${device.host || '—'}`;
        const wanLine = hasWan
            ? `WAN ${wanIp} · ${device.connection_status || 'n/a'}${wanLink ? ' · ' + wanLink : ''}`
            : (device.connection_status && device.connection_status !== 'n/a' ? device.connection_status : 'без WAN');
        const linkLine = `↓ ${formatBitrate(device.link_bitrate_down)} · ↑ ${formatBitrate(device.link_bitrate_up)}`;
        const trafficLine = `RX ${formatBytes(device.bytes_received)} · TX ${formatBytes(device.bytes_sent)}`;
        const livePorts = protocolPorts(device);
        const portsLine = livePorts.length
            ? `Порты ${livePorts.filter((p) => p.up).length}/${livePorts.length}`
            : 'Порты — нет SNMP/UPnP link';
        const hw = String(device.hardware_version || '').trim();
        const extra = device.extra && typeof device.extra === 'object' ? device.extra : {};
        const wlan = device.wlan || extra.wlan || [];
        const hosts = device.lan_hosts || extra.hosts || [];
        const media = device.media || extra.media;
        const printer = device.printer || extra.printer;
        const dhcp = extra.dhcp || device.dhcp;
        const ssidLine = wlan.length
            ? wlan.map((r) => `${r.ssid || 'SSID'}${r.channel ? ' ch' + r.channel : ''}${r.clients != null ? ' · ' + r.clients + ' sta' : ''}`).join(' / ')
            : (device.wlan_ssid ? `${device.wlan_ssid}${device.wlan_channel ? ' ch' + device.wlan_channel : ''}` : '');
        const hostsLine = hosts.length
            ? `DHCP ${hosts.length}: ${hosts.slice(0, 4).map((h) => h.name || h.ip || h.mac).filter(Boolean).join(', ')}`
            : (dhcp && (dhcp.range_min || dhcp.routers) ? `DHCP ${dhcp.range_min || '—'}–${dhcp.range_max || '—'} gw ${dhcp.routers || '—'}` : '');
        const mediaLine = media ? `Медиа ${media.state || media.status || media.sink || 'ok'}` : '';
        const printerLine = printer ? `Принтер ${printer.state || printer.name || ''}` : '';
        const hwLine = hw ? `HW ${hw}` : '';
        const extraRows = [
            hwLine ? `<div class="info-row"><i data-lucide="hash"></i><span>${escapeHtml(hwLine)}</span></div>` : '',
            ssidLine ? `<div class="info-row"><i data-lucide="wifi"></i><span>${escapeHtml(ssidLine)}</span></div>` : '',
            hostsLine ? `<div class="info-row"><i data-lucide="users"></i><span>${escapeHtml(hostsLine)}</span></div>` : '',
            mediaLine ? `<div class="info-row"><i data-lucide="play"></i><span>${escapeHtml(mediaLine)}</span></div>` : '',
            printerLine ? `<div class="info-row"><i data-lucide="printer"></i><span>${escapeHtml(printerLine)}</span></div>` : '',
        ].join('');

        if (options.variant === 'panel') {
            const mapRows = maps.map((m) => `
                <tr>
                    <td>${escapeHtml(m.protocol)}</td>
                    <td>${escapeHtml(m.external_port)}</td>
                    <td>${escapeHtml(m.internal_client)}:${escapeHtml(m.internal_port)}</td>
                    <td>${escapeHtml(m.description || '')}</td>
                    <td>
                        ${device.node_id ? `<button class="icon-btn" data-del-map data-id="${device.id}" data-port="${m.external_port}" data-proto="${m.protocol}" title="Удалить"><i data-lucide="trash-2"></i></button>` : ''}
                    </td>
                </tr>
            `).join('');
            return `
                <div class="card gear-card vendor-${vendor}" data-vendor="${vendor}" data-kind="${kind}">
                    ${face}
                    <div class="card-header">
                        <div class="card-title">
                            <span>${title}</span>
                        </div>
                        <span class="status ${online ? 'status-online' : 'status-offline'}">${online ? 'online' : 'offline'}</span>
                    </div>
                    <div class="info-row"><i data-lucide="server"></i><span>${escapeHtml(nodeLine)}</span></div>
                    <div class="info-row"><i data-lucide="cpu"></i><span>${escapeHtml(device.manufacturer || '—')} ${escapeHtml(model)}${os ? ' · ' + escapeHtml(os) : ''}</span></div>
                    <div class="info-row"><i data-lucide="globe"></i><span>${escapeHtml(wanLine)}</span></div>
                    <div class="info-row"><i data-lucide="activity"></i><span>${escapeHtml(linkLine)}</span></div>
                    <div class="info-row"><i data-lucide="hard-drive"></i><span>${escapeHtml(trafficLine)}</span></div>
                    <div class="info-row"><i data-lucide="plug"></i><span>${escapeHtml(portsLine)}</span></div>
                    ${extraRows}
                    <div class="gear-pills">${services.map((s) => `<span class="pill">${s}</span>`).join('') || '<span class="text-muted">нет сервисов</span>'}</div>
                    ${device.node_id ? `<button class="primary" data-add-map="${device.id}"><i data-lucide="plus"></i> Проброс порта</button>` : ''}
                    <div class="table-container compact-table" style="margin-top:12px; max-height:220px;">
                        <table>
                            <thead><tr><th>Proto</th><th>Ext</th><th>Internal</th><th>Desc</th><th></th></tr></thead>
                            <tbody>${mapRows || '<tr><td colspan="5">Нет port mapping</td></tr>'}</tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        const mapRows = maps.map((m) => `
            <tr>
                <td>${escapeHtml(m.protocol)}</td>
                <td>${escapeHtml(m.external_port)}</td>
                <td>${escapeHtml(m.internal_client)}:${escapeHtml(m.internal_port)}</td>
                <td>${escapeHtml(m.description || '')}</td>
            </tr>
        `).join('');
        return `
            <article class="gear-card pt-glass vendor-${vendor}" data-vendor="${vendor}" data-kind="${kind}">
                ${face}
                <h3><span>${title}</span> <span class="${online ? 'mk-badge ok' : 'mk-badge bad'}">${online ? 'online' : 'offline'}</span></h3>
                <p class="gear-meta">${escapeHtml(device.manufacturer || vendorLabel(device))} · ${escapeHtml(model)}${os ? ' · ' + escapeHtml(os) : ''}</p>
                <ul class="gear-kv">
                    <li><span>Узел</span>${escapeHtml(nodeLine)}</li>
                    <li><span>${hasWan ? 'WAN' : 'Роль'}</span>${escapeHtml(hasWan ? wanLine.replace(/^WAN\s/, '') : wanLine)}</li>
                    <li><span>Линк</span>${escapeHtml(linkLine)}</li>
                    <li><span>Трафик</span>${escapeHtml(trafficLine)}</li>
                    <li><span>Порты</span>${escapeHtml(portsLine)}</li>
                    ${hwLine ? `<li><span>HW</span>${escapeHtml(hw)}</li>` : ''}
                    ${ssidLine ? `<li><span>Wi‑Fi</span>${escapeHtml(ssidLine)}</li>` : ''}
                    ${hostsLine ? `<li><span>DHCP</span>${escapeHtml(hostsLine)}</li>` : ''}
                    ${mediaLine ? `<li><span>Медиа</span>${escapeHtml(mediaLine.replace(/^Медиа\s/, ''))}</li>` : ''}
                    ${printerLine ? `<li><span>Принтер</span>${escapeHtml(printerLine.replace(/^Принтер\s/, ''))}</li>` : ''}
                </ul>
                <div class="gear-pills">${services.map((s) => `<span>${s}</span>`).join('') || `<span>${kind}</span>`}</div>
                ${maps.length ? `
                    <table class="mk-table">
                        <thead><tr><th>Proto</th><th>Ext</th><th>Internal</th><th>Desc</th></tr></thead>
                        <tbody>${mapRows}</tbody>
                    </table>
                ` : ''}
            </article>
        `;
    };

    const samplePorts = (prefix, type, count, upList) => {
        const up = new Set(upList);
        return Array.from({ length: count }, (_, i) => ({
            name: `${prefix}${i + 1}`,
            type,
            up: up.has(i + 1),
        }));
    };

    const previewDevices = [
        {
            friendly_name: 'BRANCH-RTR-01',
            manufacturer: 'Cisco Systems',
            model_name: 'ISR4331/K9',
            software: 'IOS XE 17.9.4a',
            host: '10.21.0.1',
            node_name: 'web-pixelz-1',
            wan_ip: '10.0.0.20',
            connection_status: 'Connected',
            link_bitrate_down: 1000000000,
            link_bitrate_up: 1000000000,
            bytes_received: 512 * 1073741824,
            bytes_sent: 94 * 1073741824,
            is_igd: 1,
            online: true,
            device_type: 'InternetGatewayDevice',
            services: ['WANIPConnection', 'WANCommonInterfaceConfig', 'Layer3Forwarding'],
            ports: [
                { name: 'Gi0/0/0', type: 'copper', up: true, speed: 1000 },
                { name: 'Gi0/0/1', type: 'copper', up: true, speed: 1000 },
                { name: 'Gi0/0/2', type: 'copper', up: false, speed: 1000 },
            ],
            port_mappings: [
                { protocol: 'TCP', external_port: 443, internal_client: '10.21.0.20', internal_port: 443, description: 'https' },
                { protocol: 'TCP', external_port: 22, internal_client: '10.21.0.20', internal_port: 22, description: 'ssh' },
            ],
        },
        {
            friendly_name: 'SW-ACC-01',
            manufacturer: 'Cisco Systems',
            model_name: 'C9200L-24P-4G',
            software: 'IOS XE 17.9.5',
            host: '10.21.0.2',
            node_name: 'web-pixelz-1',
            wan_ip: '—',
            connection_status: 'L2',
            link_bitrate_down: 10000000000,
            link_bitrate_up: 10000000000,
            bytes_received: 1.8 * 1099511627776,
            bytes_sent: 1.7 * 1099511627776,
            is_igd: 0,
            online: true,
            device_type: 'Switch',
            services: ['PoE 370W', '24×1G', '4×1G SFP', 'StackWise'],
            ports: [
                ...samplePorts('Gi1/0/', 'copper', 24, [1, 2, 3, 5, 8, 11, 14, 18, 21, 24]),
                ...samplePorts('Gi1/1/', 'sfp', 4, [1, 4]),
            ],
            port_mappings: [],
        },
        {
            friendly_name: 'HQ-GW-01',
            manufacturer: 'Huawei',
            model_name: 'AR6120-S',
            software: 'VRP 8.180',
            host: '192.168.88.1',
            node_name: 'edge-gw',
            wan_ip: '10.0.0.30',
            connection_status: 'Connected',
            link_bitrate_down: 1000000000,
            link_bitrate_up: 200000000,
            bytes_received: 220 * 1073741824,
            bytes_sent: 41 * 1073741824,
            is_igd: 1,
            online: true,
            device_type: 'InternetGatewayDevice',
            services: ['WANPPPConnection', 'WANIPConnection', 'NAT'],
            ports: [
                { name: 'GE0/0/0', type: 'copper', up: true, speed: 1000 },
                { name: 'GE0/0/1', type: 'copper', up: true, speed: 1000 },
                { name: 'GE0/0/2', type: 'copper', up: false, speed: 1000 },
                { name: 'GE0/0/3', type: 'copper', up: true, speed: 1000 },
                { name: 'GE0/0/4', type: 'copper', up: false, speed: 1000 },
                { name: 'GE0/0/5', type: 'copper', up: true, speed: 1000 },
                { name: 'GE0/0/6', type: 'copper', up: false, speed: 1000 },
                { name: 'GE0/0/7', type: 'copper', up: false, speed: 1000 },
            ],
            port_mappings: [
                { protocol: 'TCP', external_port: 8443, internal_client: '192.168.88.10', internal_port: 443, description: 'panel' },
                { protocol: 'UDP', external_port: 500, internal_client: '192.168.88.10', internal_port: 500, description: 'ike' },
            ],
        },
        {
            friendly_name: 'SW-CORE-01',
            manufacturer: 'Huawei',
            model_name: 'S5735-L24P4X-A',
            software: 'VRP 5.170',
            host: '192.168.88.2',
            node_name: 'edge-gw',
            wan_ip: '—',
            connection_status: 'L2',
            link_bitrate_down: 10000000000,
            link_bitrate_up: 10000000000,
            bytes_received: 640 * 1073741824,
            bytes_sent: 612 * 1073741824,
            is_igd: 0,
            online: true,
            device_type: 'Switch',
            services: ['PoE+', '24×1G', '4×10G SFP+', 'VLAN 1/10/88'],
            ports: [
                ...samplePorts('GE0/0/', 'copper', 24, [1, 4, 5, 9, 12, 16, 20]),
                { name: 'XGE0/0/1', type: 'sfp', up: true, speed: 10000 },
                { name: 'XGE0/0/2', type: 'sfp', up: true, speed: 10000 },
                { name: 'XGE0/0/3', type: 'sfp', up: false, speed: 10000 },
                { name: 'XGE0/0/4', type: 'sfp', up: false, speed: 10000 },
            ],
            port_mappings: [],
        },
        {
            friendly_name: 'EDGE-CCR',
            manufacturer: 'MikroTik',
            model_name: 'CCR2004-1G-12S+2XS',
            software: 'RouterOS 7.15.3',
            host: '10.0.0.1',
            node_name: 'edge-gw',
            wan_ip: '185.22.11.1',
            connection_status: 'Connected',
            link_bitrate_down: 10000000000,
            link_bitrate_up: 10000000000,
            bytes_received: 2.4 * 1099511627776,
            bytes_sent: 890 * 1073741824,
            hardware_version: 'r2',
            wan_link: 'Up',
            extra: {
                wlan: [{ ssid: 'Office-5G', channel: 44, clients: 6, bssid: '18:fd:74:00:11:22' }],
                hosts: [
                    { ip: '10.0.0.40', mac: 'aa:bb:cc:dd:ee:01', name: 'k8s-node-3' },
                    { ip: '10.0.0.10', mac: 'aa:bb:cc:dd:ee:02', name: 'LAB-RB4011' },
                ],
                dhcp: { routers: '10.0.0.1', range_min: '10.0.0.50', range_max: '10.0.0.200' },
            },
            is_igd: 1,
            online: true,
            device_type: 'InternetGatewayDevice',
            services: ['WANIPConnection', 'PPP', 'Winbox'],
            ports: [
                { name: 'ether1', type: 'copper', up: true, speed: 1000 },
                ...samplePorts('sfp-sfpplus', 'sfp', 12, [1, 2, 5, 8, 11]),
                { name: 'sfp28-1', type: 'xs', up: true, speed: 25000 },
                { name: 'sfp28-2', type: 'xs', up: false, speed: 25000 },
            ],
            port_mappings: [
                { protocol: 'TCP', external_port: 8291, internal_client: '10.0.0.1', internal_port: 8291, description: 'winbox' },
                { protocol: 'UDP', external_port: 51820, internal_client: '10.0.0.1', internal_port: 51820, description: 'wireguard' },
                { protocol: 'TCP', external_port: 80, internal_client: '10.0.0.20', internal_port: 80, description: 'http' },
            ],
        },
        {
            friendly_name: 'LAB-RB4011',
            manufacturer: 'MikroTik',
            model_name: 'RB4011iGS+RM',
            software: 'RouterOS 7.14.3',
            host: '10.0.0.10',
            node_name: 'web-pixelz-1',
            wan_ip: '—',
            connection_status: 'Bridge',
            link_bitrate_down: 1000000000,
            link_bitrate_up: 1000000000,
            bytes_received: 18 * 1073741824,
            bytes_sent: 6 * 1073741824,
            is_igd: 0,
            online: false,
            device_type: 'Router',
            services: ['10×1G', 'SFP+', 'RouterOS'],
            ports: [
                ...samplePorts('ether', 'copper', 10, []),
                { name: 'sfp-sfpplus1', type: 'sfp', up: false, speed: 10000 },
            ],
            port_mappings: [],
        },
        {
            friendly_name: 'KN-1010',
            manufacturer: 'Keenetic',
            model_name: 'Keenetic Giga',
            software: 'KeeneticOS 4.2',
            host: '192.168.1.1',
            node_name: 'lab-node-02',
            wan_ip: '100.64.12.40',
            connection_status: 'Connected',
            is_igd: 1,
            online: true,
            device_type: 'InternetGatewayDevice',
            services: ['WANIPConnection', 'WLAN'],
            extra: { wlan: [{ ssid: 'Home-5G', channel: 36, clients: 8 }] },
            ports: samplePorts('lan', 'copper', 4, [1, 2, 3]),
            port_mappings: [{ protocol: 'TCP', external_port: 443, internal_client: '192.168.1.20', internal_port: 443, description: 'panel' }],
        },
        {
            friendly_name: 'Archer C6',
            manufacturer: 'TP-Link',
            model_name: 'Archer C6',
            host: '192.168.0.1',
            node_name: 'lab-node-02',
            wan_ip: '10.8.0.2',
            connection_status: 'Connected',
            is_igd: 1,
            online: true,
            device_type: 'InternetGatewayDevice',
            services: ['WANIPConnection'],
            ports: samplePorts('LAN', 'copper', 4, [1, 2]),
            port_mappings: [],
        },
        {
            friendly_name: 'DIR-842',
            manufacturer: 'D-Link',
            model_name: 'DIR-842',
            host: '192.168.0.50',
            node_name: 'web-pixelz-1',
            is_igd: 1,
            online: true,
            device_type: 'InternetGatewayDevice',
            services: ['WANIPConnection'],
            ports: samplePorts('eth', 'copper', 4, [1]),
            port_mappings: [],
        },
        {
            friendly_name: 'UDM-Pro',
            manufacturer: 'Ubiquiti',
            model_name: 'UniFi Dream Machine Pro',
            host: '10.10.0.1',
            node_name: 'db-01',
            wan_ip: '185.12.8.9',
            connection_status: 'Connected',
            is_igd: 1,
            online: true,
            device_type: 'InternetGatewayDevice',
            services: ['WANIPConnection'],
            ports: [...samplePorts('LAN', 'copper', 8, [1, 2, 3, 4]), { name: 'SFP+', type: 'sfp', up: true, speed: 10000 }],
            port_mappings: [],
        },
        {
            friendly_name: 'RT-AX86U',
            manufacturer: 'ASUS',
            model_name: 'RT-AX86U',
            host: '192.168.50.1',
            node_name: 'lab-node-02',
            is_igd: 1,
            online: false,
            device_type: 'InternetGatewayDevice',
            services: ['WANIPConnection', 'WLAN'],
            ports: samplePorts('LAN', 'copper', 4, []),
            port_mappings: [],
        },
        {
            friendly_name: 'Living Room TV',
            manufacturer: 'Samsung Electronics',
            model_name: 'UE55AU7100',
            host: '192.168.1.80',
            node_name: 'lab-node-02',
            online: true,
            device_type: 'MediaRenderer',
            services: ['AVTransport', 'RenderingControl'],
            ports: [],
            port_mappings: [],
        },
        {
            friendly_name: 'Office Printer',
            manufacturer: 'HP',
            model_name: 'LaserJet Pro M404',
            host: '192.168.1.40',
            node_name: 'web-pixelz-1',
            online: true,
            device_type: 'Printer',
            services: ['PrintBasic'],
            ports: [],
            port_mappings: [],
        },
    ];

    const renderPreview = (variant = 'panel') => previewDevices.map((d) => cardHtml(d, { variant })).join('');

    global.HostMonitorGear = {
        detectVendor,
        vendorLabel,
        listVendors,
        detectKind,
        enrichIdentity,
        parseChassis,
        protocolPorts,
        isOnline,
        faceHtml,
        cardHtml,
        previewDevices,
        renderPreview,
        formatBytes,
        formatBitrate,
        escapeHtml,
        osLabel,
    };
})(window);
