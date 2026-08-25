if (window.__HOSTMONITOR_NETMAP_INIT) {
    // duplicate <script> include (preview has relative + absolute paths)
} else {
window.__HOSTMONITOR_NETMAP_INIT = true;

const API_BASE = window.MONITORING_API_BASE || '/api';
const POS_KEY = 'hostmonitor.netmap.positions';

const state = {
    nodes: [],
    links: [],
    selectedId: null,
    tool: 'select',
    paused: false,
    scale: 1,
    panX: 0,
    panY: 0,
    dragging: null,
    panning: null,
    booted: false,
    loading: false,
};

const els = {
    stage: document.getElementById('netmap-stage'),
    links: document.getElementById('netmap-links'),
    nodes: document.getElementById('netmap-nodes'),
    empty: document.getElementById('netmap-empty'),
    loading: document.getElementById('netmap-loading'),
    selected: document.getElementById('netmap-selected'),
    neighbors: document.getElementById('netmap-neighbors'),
    alerts: document.getElementById('netmap-alerts'),
    stats: document.getElementById('netmap-stats'),
    refresh: document.getElementById('netmap-refresh'),
};

if (!els.stage) {
    // Preview or other page without map canvas
}

const iconFor = (node) => {
    if (node.kind === 'wan' || node.id === 'wan') return 'cloud';
    if (node.kind === 'subnet') return 'share-2';
    if (node.kind === 'switch') return 'network';
    if (node.kind === 'ap') return 'wifi';
    if (node.kind === 'core') return 'git-fork';
    if (node.kind === 'router') return 'router';
    if (node.kind === 'printer') return 'printer';
    if (node.kind === 'media') return 'tv';
    const key = `${node.name || ''} ${node.host || ''} ${node.manufacturer || ''} ${node.device_type || ''}`.toLowerCase();
    if (/printer/.test(key)) return 'printer';
    if (/media|renderer|\btv\b|dlna/.test(key)) return 'tv';
    if (/db|sql|postgres|mysql|mongo/.test(key)) return 'database';
    if (/redis|cache|memcache/.test(key)) return 'layers';
    if (/k8s|kube|worker/.test(key)) return 'box';
    if (/web|nginx|proxy|app/.test(key)) return 'monitor';
    if (node.kind === 'device') return 'radio';
    return 'server';
};

const vendorOf = (node) => {
    if (node.vendor && node.vendor !== 'generic') return node.vendor;
    if (window.HostMonitorGear) {
        return HostMonitorGear.detectVendor({
            manufacturer: node.manufacturer || node.provider_name,
            model_name: node.model_name || node.name,
            friendly_name: node.name,
        });
    }
    return 'generic';
};

const loadPositions = () => {
    if (window.NETMAP_TOPOLOGY) return {};
    try {
        return JSON.parse(localStorage.getItem(POS_KEY) || '{}');
    } catch {
        return {};
    }
};

const savePositions = (positions) => {
    if (window.NETMAP_TOPOLOGY) return;
    localStorage.setItem(POS_KEY, JSON.stringify(positions));
};

const formatUptime = (seconds) => {
    if (!seconds) return '—';
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    if (days > 0) return `${days}д ${hours}ч`;
    const minutes = Math.floor((seconds % 3600) / 60);
    if (hours > 0) return `${hours}ч ${minutes}м`;
    return `${minutes}м`;
};

const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

const fetchTopology = async () => {
    if (window.NETMAP_TOPOLOGY) {
        return JSON.parse(JSON.stringify(window.NETMAP_TOPOLOGY));
    }
    const res = await fetch(`${API_BASE}/topology.php`, { credentials: 'include' });
    if (!res.ok) throw new Error(`API ${res.status}`);
    return res.json();
};

const nodeIsOnline = (node) => {
    if (!node) return false;
    if (node.kind === 'wan' || node.kind === 'subnet' || node.id === 'wan') return true;
    return node.status === 'online';
};

const nodeHasTraffic = (node) => {
    if (!node || !nodeIsOnline(node)) return false;
    if (node.kind === 'wan' || node.kind === 'subnet' || node.id === 'wan') return false;
    return (Number(node.network_in) || 0) + (Number(node.network_out) || 0) >= 8192;
};

const linkIsBusy = (link, a, b) => {
    if (!nodeIsOnline(a) || !nodeIsOnline(b)) return false;
    if (link.activity === 'down') return false;
    if (link.busy === true || link.activity === 'busy') return true;
    if (link.busy === false || link.activity === 'idle') return false;
    return nodeHasTraffic(a) || nodeHasTraffic(b);
};

const SVG_NS = 'http://www.w3.org/2000/svg';

const svgEl = (name, attrs = {}) => {
    const el = document.createElementNS(SVG_NS, name);
    Object.entries(attrs).forEach(([key, value]) => {
        if (value == null || value === '') return;
        el.setAttribute(key, value);
    });
    return el;
};

const quadAt = (t, x0, y0, cx, cy, x1, y1) => {
    const u = 1 - t;
    return {
        x: u * u * x0 + 2 * u * t * cx + t * t * x1,
        y: u * u * y0 + 2 * u * t * cy + t * t * y1,
    };
};

const packets = [];
let pktRaf = 0;

const placePacket = (pkt, now) => {
    let t = ((now + pkt.shift) / pkt.dur) % 1;
    if (t < 0) t += 1;
    if (pkt.reverse) t = 1 - t;
    const pt = quadAt(t, pkt.x0, pkt.y0, pkt.cx, pkt.cy, pkt.x1, pkt.y1);
    pkt.el.setAttribute('cx', String(pt.x));
    pkt.el.setAttribute('cy', String(pt.y));
};

const tickPackets = (now) => {
    if (!state.paused) {
        packets.forEach((pkt) => placePacket(pkt, now));
    }
    pktRaf = requestAnimationFrame(tickPackets);
};

const ensurePacketLoop = () => {
    if (!pktRaf) pktRaf = requestAnimationFrame(tickPackets);
};

const addMovingPacket = (svg, curve, { durMs, shiftMs, reverse, cls }) => {
    const start = quadAt(reverse ? 1 : 0, curve.x0, curve.y0, curve.cx, curve.cy, curve.x1, curve.y1);
    const circle = svgEl('circle', {
        r: reverse ? '3' : '3.4',
        class: `netmap-pkt ${cls || ''} ${reverse ? 'netmap-pkt-rev' : ''}`.trim(),
        cx: String(start.x),
        cy: String(start.y),
    });
    svg.appendChild(circle);
    packets.push({
        el: circle,
        ...curve,
        dur: durMs,
        shift: shiftMs,
        reverse: !!reverse,
    });
};

const unclashLayer = (items, minGap, minX, maxX) => {
    if (!items.length) return items;
    items.sort((a, b) => a.x - b.x);
    for (let i = 1; i < items.length; i += 1) {
        if (items[i].x - items[i - 1].x < minGap) {
            items[i].x = items[i - 1].x + minGap;
        }
    }
    if (items[items.length - 1].x > maxX) {
        const overflow = items[items.length - 1].x - maxX;
        items.forEach((it) => { it.x -= overflow; });
    }
    if (items[0].x < minX) {
        const shift = minX - items[0].x;
        items.forEach((it) => { it.x += shift; });
    }
    if (items.length > 1 && items[items.length - 1].x > maxX) {
        const span = items[items.length - 1].x - items[0].x;
        const room = Math.max(maxX - minX, 1);
        if (span > 0) {
            const origin = items[0].x;
            items.forEach((it) => {
                it.x = minX + ((it.x - origin) / span) * room;
            });
        }
    }
    return items;
};

const hierarchyLayout = (nodes, links, width, height) => {
    const saved = loadPositions();
    const children = {};
    const parentOf = {};
    (links || []).forEach((link) => {
        const from = String(link.from);
        const to = String(link.to);
        (children[from] ||= []).push(to);
        if (!parentOf[to]) parentOf[to] = from;
    });

    const layers = [];
    const visited = new Set();
    const walk = (id, depth) => {
        if (visited.has(id)) return;
        visited.add(id);
        (layers[depth] ||= []).push(id);
        (children[id] || []).forEach((cid) => walk(cid, depth + 1));
    };
    walk('wan', 0);
    nodes.forEach((node) => {
        const id = String(node.id);
        if (!visited.has(id)) walk(id, Math.max(layers.length, 1));
    });

    const padX = 118;
    const padY = 92;
    const minGap = 168;
    const usableH = Math.max(height - padY * 2, 200);
    const maxDepth = Math.max(layers.length - 1, 1);
    const xs = {};

    layers.forEach((layer, depth) => {
        const y = padY + (depth / maxDepth) * usableH;
        const groups = new Map();
        layer.forEach((id) => {
            const parent = parentOf[id] || `__root_${depth}`;
            if (!groups.has(parent)) groups.set(parent, []);
            groups.get(parent).push(id);
        });
        const items = [];
        groups.forEach((siblings, parent) => {
            const parentX = xs[parent] != null ? xs[parent] : width / 2;
            const spread = Math.max(siblings.length - 1, 0) * minGap;
            siblings.forEach((id, si) => {
                items.push({
                    id,
                    x: siblings.length === 1 ? parentX : parentX - spread / 2 + si * minGap,
                });
            });
        });
        unclashLayer(items, minGap, padX, width - padX).forEach((it) => {
            xs[it.id] = it.x;
            const node = nodes.find((n) => String(n.id) === it.id);
            if (node) {
                node._lx = it.x;
                node._ly = y;
            }
        });
    });

    return nodes.map((node) => {
        const custom = saved[node.id];
        if (custom && Number.isFinite(custom.x) && Number.isFinite(custom.y)) {
            return { ...node, x: custom.x, y: custom.y };
        }
        return { ...node, x: node._lx ?? width / 2, y: node._ly ?? height / 2 };
    });
};

const kindLabelOf = (node) => ({
    wan: 'Интернет',
    core: 'Ядро / CCR',
    router: 'Шлюз / роутер',
    switch: 'Коммутатор',
    ap: 'Точка доступа',
    device: 'UPnP-устройство',
    subnet: 'Сегмент LAN',
    server: 'Сервер / агент',
}[node?.kind] || node?.kind || 'Узел');

const formatBytes = (value) => {
    const n = Number(value) || 0;
    if (n < 1024) return `${Math.round(n)} Б`;
    if (n < 1048576) return `${(n / 1024).toFixed(1)} КБ`;
    if (n < 1073741824) return `${(n / 1048576).toFixed(1)} МБ`;
    return `${(n / 1073741824).toFixed(1)} ГБ`;
};

const kvRow = (label, value) => {
    if (value == null || value === '' || value === 'N/A' || value === '—') return '';
    const text = String(value);
    return `<div><span>${escapeHtml(label)}</span><b title="${escapeHtml(text)}">${escapeHtml(text)}</b></div>`;
};

const meterRow = (label, pct) => {
    if (pct == null || pct === '' || Number.isNaN(Number(pct))) return '';
    const v = Math.max(0, Math.min(100, Number(pct)));
    const tone = v >= 85 ? 'hot' : v >= 70 ? 'warn' : 'ok';
    return `<div class="netmap-meter ${tone}"><span>${escapeHtml(label)}</span><div class="bar"><span class="fill" style="width:${v}%"></span></div><b>${v.toFixed(0)}%</b></div>`;
};

const renderSelected = (node) => {
    if (!els.selected) return;
    if (!node) {
        els.selected.innerHTML = '<p class="muted">Выберите узел на карте</p>';
        return;
    }
    const online = nodeIsOnline(node);
    const kindLabel = kindLabelOf(node);
    const model = [node.manufacturer, node.model_name].filter(Boolean).join(' ');
    const lan = (node.lan_ips || []).map((row) => row.ip).filter(Boolean).join(', ');
    els.selected.innerHTML = `
        <div class="netmap-sel-head">
            <h3 title="${escapeHtml(node.name)}">${escapeHtml(node.name)}</h3>
            <span class="status ${online ? 'status-online' : 'status-offline'}">${online ? 'online' : 'offline'}</span>
        </div>
        <p class="muted">${escapeHtml(node.detail || model || kindLabel)}</p>
        <div class="netmap-kv">
            ${kvRow('Роль', kindLabel)}
            ${kvRow('Host', node.host)}
            ${kvRow('WAN', node.wan_ip)}
            ${kvRow('Шлюз', node.gateway)}
            ${kvRow('Шлюз v6', node.gateway6)}
            ${kvRow('Сеть', node.subnet)}
            ${kvRow('LAN', lan)}
            ${kvRow('IPv6', node.ipv6)}
            ${kvRow('WAN link', node.wan_link || node.connection_status)}
            ${kvRow('Порты up', node.ports_up)}
            ${kvRow('Интерфейсы up', node.ifaces_up)}
            ${(Number(node.network_in) || Number(node.network_out))
                ? kvRow('Трафик', `${formatBytes(node.network_in)} ↓ · ${formatBytes(node.network_out)} ↑`)
                : ''}
            ${(Number(node.bytes_received) || Number(node.bytes_sent))
                ? kvRow('Счётчики', `${formatBytes(node.bytes_received)} RX · ${formatBytes(node.bytes_sent)} TX`)
                : ''}
        </div>
        ${meterRow('CPU', node.cpu_usage)}
        ${meterRow('RAM', node.memory_usage)}
        ${meterRow('Диск', node.disk_usage)}
    `;
};

const neighborsOf = (node) => {
    if (!node) return [];
    const id = String(node.id);
    const index = byId();
    const rows = [];
    state.links.forEach((link) => {
        let peer = null;
        if (String(link.from) === id) peer = index.get(String(link.to));
        else if (String(link.to) === id) peer = index.get(String(link.from));
        if (peer) rows.push({ peer, link });
    });
    return rows;
};

const renderNeighbors = (node) => {
    if (!els.neighbors) return;
    const rows = neighborsOf(node);
    if (!rows.length) {
        els.neighbors.innerHTML = '<p class="muted">Нет связей</p>';
        return;
    }
    els.neighbors.innerHTML = rows.map(({ peer, link }) => {
        const online = nodeIsOnline(peer);
        const busy = linkIsBusy(link, node, peer);
        return `
            <button type="button" class="netmap-nbor" data-select-node="${escapeHtml(peer.id)}">
                <span class="dot ${online ? (busy ? 'busy' : 'on') : 'off'}"></span>
                <span class="txt">
                    <b>${escapeHtml(peer.name)}</b>
                    <small>${escapeHtml(link.label || kindLabelOf(peer))}</small>
                </span>
            </button>
        `;
    }).join('');
};

const collectAlerts = (nodes) => {
    const alerts = [];
    (nodes || []).forEach((node) => {
        if (node.id === 'wan' || node.kind === 'subnet') return;
        if (!nodeIsOnline(node)) {
            alerts.push({ level: 'crit', id: node.id, name: node.name, text: 'Не в сети' });
            return;
        }
        if ((Number(node.cpu_usage) || 0) > 85) {
            alerts.push({ level: 'warn', id: node.id, name: node.name, text: `CPU ${Number(node.cpu_usage).toFixed(0)}%` });
        }
        if ((Number(node.memory_usage) || 0) > 90) {
            alerts.push({ level: 'warn', id: node.id, name: node.name, text: `RAM ${Number(node.memory_usage).toFixed(0)}%` });
        }
        if ((Number(node.disk_usage) || 0) > 85) {
            alerts.push({ level: 'warn', id: node.id, name: node.name, text: `Диск ${Number(node.disk_usage).toFixed(0)}%` });
        }
        const cs = String(node.connection_status || '').toLowerCase();
        const wl = String(node.wan_link || '').toLowerCase();
        if (node.is_igd && (cs === 'disconnected' || wl === 'down')) {
            alerts.push({ level: 'crit', id: node.id, name: node.name, text: 'WAN down' });
        }
    });
    return alerts;
};

const renderAlerts = (nodes) => {
    if (!els.alerts) return;
    const alerts = collectAlerts(nodes);
    if (!alerts.length) {
        els.alerts.innerHTML = '<p class="muted">Проблем нет</p>';
        return;
    }
    els.alerts.innerHTML = alerts.map((alert) => `
        <button type="button" class="netmap-alert ${alert.level}" data-select-node="${escapeHtml(alert.id)}">
            <b>${escapeHtml(alert.name)}</b>
            <span>${escapeHtml(alert.text)}</span>
        </button>
    `).join('');
};

const renderStats = (nodes) => {
    if (!els.stats) return;
    const real = (nodes || []).filter((n) => n.id !== 'wan' && n.kind !== 'subnet');
    const online = real.filter(nodeIsOnline).length;
    const index = byId();
    const busy = state.links.filter((link) => {
        const a = index.get(String(link.from));
        const b = index.get(String(link.to));
        return a && b && linkIsBusy(link, a, b);
    }).length;
    els.stats.innerHTML = `
        <span><b>${online}</b> online</span>
        <span><b>${Math.max(real.length - online, 0)}</b> offline</span>
        <span><b>${busy}</b> активных линий</span>
    `;
};

const renderInspector = (node) => {
    renderSelected(node);
    renderNeighbors(node);
    renderAlerts(state.nodes);
    renderStats(state.nodes);
};

const byId = () => {
    const map = new Map();
    state.nodes.forEach((n) => map.set(String(n.id), n));
    return map;
};

const drawLinks = () => {
    if (!els.links) return;
    const { width, height } = stageSize();
    els.links.setAttribute('viewBox', `0 0 ${Math.round(width)} ${Math.round(height)}`);
    els.links.setAttribute('preserveAspectRatio', 'none');
    els.links.setAttribute('overflow', 'visible');
    packets.length = 0;
    while (els.links.firstChild) els.links.removeChild(els.links.firstChild);

    const defs = svgEl('defs');
    const grad = svgEl('linearGradient', { id: 'netmapBlue', x1: '0%', y1: '0%', x2: '0%', y2: '100%' });
    grad.appendChild(svgEl('stop', { offset: '0%', 'stop-color': '#60a5fa' }));
    grad.appendChild(svgEl('stop', { offset: '100%', 'stop-color': '#22c55e' }));
    defs.appendChild(grad);
    els.links.appendChild(defs);

    const index = byId();
    const selected = state.selectedId;

    state.links.forEach((link, i) => {
        const a = index.get(String(link.from));
        const b = index.get(String(link.to));
        if (!a || !b || a.x == null || b.x == null) return;
        const online = nodeIsOnline(a) && nodeIsOnline(b);
        const busy = linkIsBusy(link, a, b);
        const isActive = selected && (String(selected) === String(a.id) || String(selected) === String(b.id));
        const cls = [
            'netmap-link',
            link.kind || 'lan',
            online ? '' : 'offline',
            busy ? 'busy' : '',
            isActive ? 'active' : '',
        ].filter(Boolean).join(' ');
        const mx = (a.x + b.x) / 2;
        const my = (a.y + b.y) / 2;
        const cx = mx;
        const cy = Math.min(a.y, b.y) + Math.abs(a.y - b.y) * 0.35;
        const pathId = `nl-${i}`;
        const d = `M ${a.x} ${a.y} Q ${cx} ${cy} ${b.x} ${b.y}`;
        els.links.appendChild(svgEl('path', {
            id: pathId,
            class: cls,
            d,
            fill: 'none',
        }));
        els.links.appendChild(svgEl('circle', {
            class: `netmap-dot ${online ? '' : 'off'}`,
            cx: String(a.x),
            cy: String(a.y),
            r: '3.5',
        }));
        els.links.appendChild(svgEl('circle', {
            class: `netmap-dot ${online ? '' : 'off'}`,
            cx: String(b.x),
            cy: String(b.y),
            r: '3.5',
        }));
        if (link.label) {
            const text = svgEl('text', {
                class: 'netmap-llabel',
                x: String(mx),
                y: String(my - 8),
            });
            text.textContent = String(link.label);
            els.links.appendChild(text);
        }
        if (!busy) return;
        const wan = (link.kind || '') === 'wan';
        const traffic = (Number(a.network_in) || 0) + (Number(a.network_out) || 0)
            + (Number(b.network_in) || 0) + (Number(b.network_out) || 0);
        const hot = traffic > 512 * 1024;
        const curve = { x0: a.x, y0: a.y, cx, cy, x1: b.x, y1: b.y };
        const durMs = wan ? (hot ? 1050 : 1350) : (hot ? 1350 : 1800);
        const pktCls = wan ? 'wan' : '';
        addMovingPacket(els.links, curve, { durMs, shiftMs: 0, reverse: false, cls: pktCls });
        addMovingPacket(els.links, curve, { durMs, shiftMs: hot ? 350 : 550, reverse: false, cls: pktCls });
        addMovingPacket(els.links, curve, { durMs: wan ? 1550 : 2050, shiftMs: 250, reverse: true, cls: pktCls });
        if (hot) {
            addMovingPacket(els.links, curve, { durMs: 1150, shiftMs: 700, reverse: true, cls: pktCls });
        }
    });
    ensurePacketLoop();
};

const applyPan = () => {
    const t = `translate(${state.panX}px, ${state.panY}px)`;
    if (els.nodes) els.nodes.style.transform = t;
    if (els.links) els.links.style.transform = t;
};

const syncPacketPause = () => {
    els.links?.classList.toggle('is-paused', !!state.paused);
};

const nodeIconHtml = (node) => (
    `<div class="icon"><i data-lucide="${iconFor(node)}"></i></div>`
);

const renderNodes = () => {
    if (!els.nodes) return;
    els.nodes.innerHTML = state.nodes.map((node) => {
        const vendor = vendorOf(node);
        const selected = String(node.id) === String(state.selectedId) ? 'selected' : '';
        const offline = nodeIsOnline(node) ? '' : 'offline';
        return `
            <div class="netmap-node ${selected} ${offline} kind-${node.kind || 'server'} vendor-${vendor}" data-id="${node.id}" style="left:${node.x}px;top:${node.y}px">
                ${nodeIconHtml(node)}
                <div class="name">${escapeHtml(node.name)}</div>
                <div class="meta">${escapeHtml(node.host || node.subnet || '')}</div>
            </div>
        `;
    }).join('');
    if (typeof lucide !== 'undefined') lucide.createIcons();
};

const persistLayout = () => {
    const positions = {};
    state.nodes.forEach((node) => {
        positions[node.id] = { x: node.x, y: node.y };
    });
    savePositions(positions);
};

const render = ({ geometry = true, panel = true } = {}) => {
    // Пока идёт первая загрузка — не показываем «Нет узлов»
    if (!state.booted || state.loading) {
        els.empty?.classList.add('hidden');
        return;
    }
    const real = state.nodes.filter((n) => n.id !== 'wan' && n.kind !== 'subnet');
    if (!real.length) {
        packets.length = 0;
        els.empty?.classList.remove('hidden');
        if (els.links) while (els.links.firstChild) els.links.removeChild(els.links.firstChild);
        if (els.nodes) els.nodes.innerHTML = '';
        if (panel) {
            renderInspector(null);
        }
        return;
    }
    els.empty?.classList.add('hidden');
    if (geometry) {
        drawLinks();
        renderNodes();
        syncPacketPause();
    }
    applyPan();
    if (panel) {
        renderInspector(state.nodes.find((n) => String(n.id) === String(state.selectedId)));
    }
};

const stageSize = () => {
    const rect = els.stage?.getBoundingClientRect() || { width: 0, height: 0 };
    return {
        width: rect.width > 40 ? rect.width : 900,
        height: rect.height > 40 ? rect.height : 560,
    };
};

const layoutFresh = (payload) => {
    const { width, height } = stageSize();
    if (els.links) {
        els.links.setAttribute('viewBox', `0 0 ${Math.round(width)} ${Math.round(height)}`);
        els.links.setAttribute('preserveAspectRatio', 'none');
    }
    const nodes = payload.nodes || payload;
    const links = payload.links || state.links;
    state.links = links;
    state.nodes = hierarchyLayout(nodes, links, width, height);
};

const stripLayout = (nodes) => nodes.map(({ x, y, _lx, _ly, ...rest }) => rest);

const relayout = () => {
    if (!els.stage || !state.nodes.length) return;
    layoutFresh({ nodes: stripLayout(state.nodes), links: state.links });
    render();
};

const setLoading = (on, message = 'Загрузка карты узлов…') => {
    state.loading = !!on;
    els.stage?.classList.toggle('is-loading', on);
    if (els.loading) {
        const label = els.loading.querySelector('p');
        if (label && message) label.textContent = message;
        els.loading.classList.toggle('hidden', !on);
        els.loading.setAttribute('aria-busy', on ? 'true' : 'false');
    }
    // Пока грузим — всегда прячем empty (даже без оверлея в старом HTML)
    if (on) els.empty?.classList.add('hidden');
    if (els.refresh) {
        els.refresh.classList.toggle('is-loading', on);
        els.refresh.disabled = !!on;
    }
    if (els.stats && on && !state.booted) {
        els.stats.innerHTML = '<span class="muted">Загрузка…</span>';
    }
};

const resetEmptyCopy = () => {
    const title = els.empty?.querySelector('h3');
    const text = els.empty?.querySelector('p');
    if (title) title.textContent = 'Нет узлов';
    if (text) text.textContent = 'Добавьте ноды, чтобы построить карту сети';
};

const load = async (silent = false) => {
    if (!els.stage) return;
    if (state.loading && silent) return;
    setLoading(true, silent && state.booted ? 'Обновление карты…' : 'Загрузка карты узлов…');
    // Silent refresh: лёгкий индикатор только на кнопке, оверлей — если ещё не booted
    if (silent && state.booted && els.loading) {
        els.loading.classList.add('hidden');
    }
    try {
        const payload = await fetchTopology();
        const prev = new Map(state.nodes.map((n) => [String(n.id), n]));
        layoutFresh(payload);
        state.nodes = state.nodes.map((node) => {
            const old = prev.get(String(node.id));
            if (old && loadPositions()[node.id]) {
                return { ...node, x: old.x, y: old.y };
            }
            return node;
        });
        if (!state.selectedId) {
            const first = state.nodes.find((n) => n.kind === 'core' || n.kind === 'router') || state.nodes.find((n) => n.id !== 'wan');
            state.selectedId = first ? first.id : 'wan';
        }
        state.booted = true;
        resetEmptyCopy();
        state.loading = false;
        render();
    } catch (error) {
        console.error('Карта сети:', error);
        if (els.stats && !state.booted) {
            els.stats.innerHTML = '<span class="muted">Ошибка загрузки</span>';
        }
        if (window.showToast) window.showToast('Не удалось загрузить топологию', 'error');
        if (!state.booted) {
            state.booted = true;
            state.loading = false;
            els.empty?.classList.remove('hidden');
            const title = els.empty?.querySelector('h3');
            const text = els.empty?.querySelector('p');
            if (title) title.textContent = 'Не удалось загрузить';
            if (text) text.textContent = 'Проверьте API topology.php и обновите карту';
        }
    } finally {
        state.loading = false;
        els.stage?.classList.remove('is-loading');
        if (els.loading) {
            els.loading.classList.add('hidden');
            els.loading.setAttribute('aria-busy', 'false');
        }
        if (els.refresh) {
            els.refresh.classList.remove('is-loading');
            els.refresh.disabled = false;
        }
    }
};

window.HostMonitorNetmap = { load, relayout };

window.addEventListener('hm-panel-toggle', (event) => {
    if (event.detail?.id === 'netmap-inspector') relayout();
});

if (els.stage) {
    const nodeFromEvent = (event) => event.target.closest('.netmap-node');
    const stageVisible = () => {
        const page = els.stage.closest('.mk-page');
        if (page && !page.classList.contains('active')) return false;
        const rect = els.stage.getBoundingClientRect();
        return rect.width > 8 && rect.height > 8;
    };

    els.stage.addEventListener('pointerdown', (event) => {
        if (!stageVisible()) return;
        const nodeEl = nodeFromEvent(event);
        if (state.tool === 'pan' || (!nodeEl && event.button === 0)) {
            state.panning = { x: event.clientX - state.panX, y: event.clientY - state.panY };
            els.stage.classList.add('is-panning');
            return;
        }
        if (nodeEl && state.tool === 'select') {
            state.selectedId = nodeEl.dataset.id;
            state.dragging = {
                id: nodeEl.dataset.id,
                dx: event.clientX,
                dy: event.clientY,
                ox: state.nodes.find((n) => String(n.id) === nodeEl.dataset.id)?.x || 0,
                oy: state.nodes.find((n) => String(n.id) === nodeEl.dataset.id)?.y || 0,
            };
            render();
        }
    });

    window.addEventListener('pointermove', (event) => {
        if (!stageVisible() && !state.panning && !state.dragging) return;
        if (state.panning) {
            state.panX = event.clientX - state.panning.x;
            state.panY = event.clientY - state.panning.y;
            applyPan();
            return;
        }
        if (!state.dragging) return;
        const node = state.nodes.find((n) => String(n.id) === String(state.dragging.id));
        if (!node) return;
        node.x = state.dragging.ox + (event.clientX - state.dragging.dx);
        node.y = state.dragging.oy + (event.clientY - state.dragging.dy);
        render({ geometry: true, panel: false });
    });

    window.addEventListener('pointerup', () => {
        if (state.dragging) persistLayout();
        state.dragging = null;
        state.panning = null;
        els.stage.classList.remove('is-panning');
    });

    document.querySelectorAll('.netmap-tool[data-tool]').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.netmap-tool[data-tool]').forEach((el) => el.classList.remove('active'));
            btn.classList.add('active');
            state.tool = btn.dataset.tool;
            els.stage.classList.toggle('is-panning', state.tool === 'pan');
        });
    });

    document.querySelector('.netmap-panel')?.addEventListener('click', (event) => {
        const row = event.target.closest('[data-select-node]');
        if (!row) return;
        state.selectedId = row.dataset.selectNode;
        render();
    });

    document.getElementById('netmap-refresh')?.addEventListener('click', () => load());
    document.getElementById('netmap-fit')?.addEventListener('click', () => {
        localStorage.removeItem(POS_KEY);
        state.panX = 0;
        state.panY = 0;
        layoutFresh({ nodes: stripLayout(state.nodes), links: state.links });
        persistLayout();
        render();
    });
    document.getElementById('netmap-reset')?.addEventListener('click', () => {
        localStorage.removeItem(POS_KEY);
        state.panX = 0;
        state.panY = 0;
        layoutFresh({ nodes: stripLayout(state.nodes), links: state.links });
        render();
    });
    document.getElementById('netmap-zoom-in')?.addEventListener('click', () => {
        const cx = (els.stage.getBoundingClientRect().width || 900) / 2;
        const cy = (els.stage.getBoundingClientRect().height || 560) / 2;
        state.nodes.forEach((node) => {
            node.x = cx + (node.x - cx) * 1.12;
            node.y = cy + (node.y - cy) * 1.12;
        });
        persistLayout();
        render();
    });
    document.getElementById('netmap-zoom-out')?.addEventListener('click', () => {
        const cx = (els.stage.getBoundingClientRect().width || 900) / 2;
        const cy = (els.stage.getBoundingClientRect().height || 560) / 2;
        state.nodes.forEach((node) => {
            node.x = cx + (node.x - cx) / 1.12;
            node.y = cy + (node.y - cy) / 1.12;
        });
        persistLayout();
        render();
    });
    document.getElementById('netmap-pause')?.addEventListener('click', (event) => {
        state.paused = !state.paused;
        event.currentTarget.classList.toggle('active', state.paused);
        event.currentTarget.title = state.paused ? 'Включить анимацию трафика' : 'Пауза анимации трафика';
        event.currentTarget.innerHTML = state.paused
            ? '<i data-lucide="play"></i>'
            : '<i data-lucide="pause"></i>';
        if (typeof lucide !== 'undefined') lucide.createIcons();
        syncPacketPause();
    });

    window.addEventListener('resize', () => {
        if (window.NETMAP_TOPOLOGY) relayout();
        else render();
    });

    if (typeof ResizeObserver !== 'undefined') {
        let lastBox = { w: 0, h: 0 };
        new ResizeObserver(() => {
            if (!state.booted || state.loading) return;
            if (state.dragging || state.panning) return;
            const box = els.stage.getBoundingClientRect();
            if (box.width < 40 || box.height < 40) return;
            if (Math.abs(box.width - lastBox.w) < 8 && Math.abs(box.height - lastBox.h) < 8) return;
            lastBox = { w: box.width, h: box.height };
            if (window.NETMAP_TOPOLOGY) {
                relayout();
                return;
            }
            const saved = loadPositions();
            if (!Object.keys(saved).length) relayout();
            else render();
        }).observe(els.stage);
    }

    // Сразу loading, empty скрыт — до ответа topology.php
    els.empty?.classList.add('hidden');
    setLoading(true);
    load();
    setInterval(() => {
        if (!window.NETMAP_TOPOLOGY) load(true);
    }, 10000);
}
}
