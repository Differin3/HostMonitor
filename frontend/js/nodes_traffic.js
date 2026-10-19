const API_URL = `${API_BASE}/nodes.php`;
const METRICS_URL = `${API_BASE}/metrics.php`;

const formatBytes = (bytes) => {
    const n = Number(bytes) || 0;
    if (!n) return '0 Б';
    const k = 1024;
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ', 'ПБ'];
    const i = Math.min(sizes.length - 1, Math.floor(Math.log(n) / Math.log(k)));
    return Math.round(n / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const formatRate = (bytesPerSec) => `${formatBytes(bytesPerSec)}/с`;

const formatClock = (date) => date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

const nodeRate = (node) => Number(node.network_in || 0) + Number(node.network_out || 0);
const nodeTotal = (node) => Number(node.network_in_total || 0) + Number(node.network_out_total || 0);

let trafficNodes = [];
let ifaceRows = [];
let ifaceSort = 'node-asc';

const renderTrafficTops = (nodes) => {
    const host = document.getElementById('traffic-tops');
    if (!host) return;
    const ranked = [...nodes].sort((a, b) => nodeRate(b) - nodeRate(a)).slice(0, 6);
    const max = Math.max(...ranked.map(nodeRate), 1);
    if (!ranked.length) {
        host.innerHTML = '<p class="list-empty">Нет данных</p>';
        return;
    }
    host.innerHTML = ranked.map((node) => {
        const inRate = Number(node.network_in || 0);
        const outRate = Number(node.network_out || 0);
        const total = inRate + outRate;
        const share = Math.round((total / max) * 100);
        return `
            <div class="traffic-top">
                <div class="traffic-top-head">
                    <span class="traffic-top-name">${escapeHtml(node.name || '-')}</span>
                    <span class="traffic-top-value">${formatRate(total)}</span>
                </div>
                <div class="traffic-top-bar" title="вход ${formatRate(inRate)} · выход ${formatRate(outRate)}">
                    <span class="traffic-top-in" style="width:${(inRate / max) * 100}%"></span>
                    <span class="traffic-top-out" style="width:${(outRate / max) * 100}%"></span>
                </div>
                <div class="traffic-top-meta">
                    <span>↓ ${formatRate(inRate)}</span>
                    <span>↑ ${formatRate(outRate)}</span>
                    <span>${share}%</span>
                </div>
            </div>`;
    }).join('');
};

const renderTrafficTable = (nodes, cumulativeTotal, rateTotal) => {
    const tbody = document.getElementById('traffic-tbody');
    if (!tbody) return;
    if (!nodes.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">Нет данных</td></tr>';
        return;
    }
    const ranked = [...nodes].sort((a, b) => nodeTotal(b) - nodeTotal(a) || nodeRate(b) - nodeRate(a));
    const total = cumulativeTotal > 0 ? cumulativeTotal : rateTotal;
    const online = (node) => node.status === 'online';
    tbody.innerHTML = ranked.map((node) => {
        const inRate = Number(node.network_in || 0);
        const outRate = Number(node.network_out || 0);
        const cumulative = nodeTotal(node);
        const inPart = cumulative > 0 ? Number(node.network_in_total || 0) : inRate;
        const outPart = cumulative > 0 ? Number(node.network_out_total || 0) : outRate;
        const share = total > 0 ? Math.round(((inPart + outPart) / total) * 100) : 0;
        return `<tr>
            <td>${escapeHtml(node.name || '-')}</td>
            <td><span class="status ${online(node) ? 'status-online' : 'status-offline'}">${online(node) ? 'online' : 'offline'}</span></td>
            <td>${formatRate(inRate)}</td>
            <td>${formatRate(outRate)}</td>
            <td>${cumulative > 0 ? formatBytes(cumulative) : '—'}</td>
            <td class="meter-cell">
                <div class="traffic-split">
                    <span style="width:${total ? (inPart / total) * 100 : 0}%"></span>
                    <span style="width:${total ? (outPart / total) * 100 : 0}%"></span>
                </div>
                <small class="meter-label">${share}%</small>
            </td>
        </tr>`;
    }).join('');
};

const ifaceRate = (row) => Number(row.rx_sec || 0) + Number(row.tx_sec || 0);

const trafficNodeById = (id) => trafficNodes.find((node) => Number(node.id) === Number(id)) || null;

const ifaceNodeGroups = () => {
    const groups = new Map();
    ifaceRows.forEach((row) => {
        const key = String(row.node_id ?? row.node_name ?? '-');
        if (!groups.has(key)) {
            const node = trafficNodeById(row.node_id);
            groups.set(key, {
                label: row.node_name || (row.node_id ? `#${row.node_id}` : '-'),
                known: Boolean(node),
                online: Boolean(node && node.status === 'online'),
                total: 0,
                rows: [],
            });
        }
        const group = groups.get(key);
        group.total += ifaceRate(row);
        group.rows.push(row);
    });
    return [...groups.values()];
};

const ifaceGroupSort = (groups) => {
    const byName = (a, b) => String(a.label).localeCompare(String(b.label), 'ru');
    const list = [...groups];
    if (ifaceSort === 'node-desc') list.sort((a, b) => byName(b, a));
    else if (ifaceSort === 'rate-desc') list.sort((a, b) => b.total - a.total || byName(a, b));
    else if (ifaceSort === 'status') list.sort((a, b) => Number(b.online) - Number(a.online) || byName(a, b));
    else list.sort(byName);
    return list;
};

const ifaceBadge = (value, kind) => {
    const count = Number(value || 0);
    return `<span class="iface-badge${count > 0 ? ` iface-badge--${kind}` : ''}">${count}</span>`;
};

const ifaceGroupHead = (group) => {
    const statusClass = group.online ? 'is-online' : 'is-offline';
    const statusText = group.known ? (group.online ? 'online' : 'offline') : 'нет данных';
    return `<tr class="iface-group">
        <td colspan="6">
            <div class="iface-group-head">
                <span class="iface-node">
                    <span class="iface-dot ${statusClass}"></span>${escapeHtml(group.label)}
                    <span class="iface-group-count">${group.rows.length}</span>
                </span>
                <span class="iface-group-meta">
                    <span class="iface-group-total">${formatRate(group.total)}</span>
                    <span class="iface-group-status ${statusClass}">${statusText}</span>
                </span>
            </div>
        </td>
    </tr>`;
};

const ifaceRowHtml = (row, groupTotal) => {
    const rx = Number(row.rx_sec || 0);
    const tx = Number(row.tx_sec || 0);
    const own = rx + tx;
    const inPct = own > 0 ? (rx / own) * 100 : 0;
    const share = groupTotal > 0 ? Math.round((own / groupTotal) * 100) : 0;
    const errors = Number(row.errin || 0) + Number(row.errout || 0);
    const drops = Number(row.dropin || 0) + Number(row.dropout || 0);
    return `<tr class="iface-row">
        <td><span class="iface-name">${escapeHtml(row.name || '-')}</span></td>
        <td class="num iface-in">${formatRate(rx)}</td>
        <td class="num iface-out">${formatRate(tx)}</td>
        <td class="col-share">
            <div class="iface-bar" title="вход ${Math.round(inPct)}% · выход ${Math.round(100 - inPct)}%">
                <span class="iface-bar-in" style="width:${inPct}%"></span>
                <span class="iface-bar-out" style="width:${100 - inPct}%"></span>
            </div>
            <small class="iface-share-label">${share}%</small>
        </td>
        <td class="num">${ifaceBadge(errors, 'err')}</td>
        <td class="num">${ifaceBadge(drops, 'drop')}</td>
    </tr>`;
};

const renderTrafficIfaces = () => {
    const tbody = document.getElementById('traffic-ifaces-tbody');
    if (!tbody) return;
    const count = document.getElementById('traffic-ifaces-count');
    if (!ifaceRows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">Нет данных</td></tr>';
        if (count) count.textContent = '0';
        return;
    }
    const groups = ifaceGroupSort(ifaceNodeGroups());
    let total = 0;
    tbody.innerHTML = groups.map((group) => {
        const rows = [...group.rows].sort((a, b) => ifaceRate(b) - ifaceRate(a) || String(a.name).localeCompare(String(b.name)));
        total += rows.length;
        return ifaceGroupHead({ ...group, rows }) + rows.map((row) => ifaceRowHtml(row, group.total)).join('');
    }).join('');
    if (count) count.textContent = String(total);
};

const loadTrafficIfaces = async () => {
    try {
        const res = await fetch(`${METRICS_URL}?interfaces=1`, { credentials: 'include' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = JSON.parse(await res.text() || '{}');
        ifaceRows = Array.isArray(data.interfaces) ? data.interfaces : [];
        renderTrafficIfaces();
        const stamp = document.getElementById('traffic-ifaces-updated');
        if (stamp) stamp.textContent = formatClock(new Date());
    } catch (error) {
        console.error('Ошибка загрузки интерфейсов:', error);
        ifaceRows = [];
        renderTrafficIfaces();
    }
};

const bindIfaceSort = () => {
    const select = document.getElementById('traffic-ifaces-sort');
    if (!select || select.dataset.ifaceBound === '1') return;
    select.dataset.ifaceBound = '1';
    ifaceSort = select.value || 'node-asc';
    select.addEventListener('change', () => {
        ifaceSort = select.value || 'node-asc';
        renderTrafficIfaces();
    });
};

const loadTraffic = async (silent = false) => {
    try {
        if (!silent && window.toggleTableLoader) window.toggleTableLoader('traffic-tbody', true);
        const res = await fetch(API_URL, { credentials: 'include' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = JSON.parse(await res.text() || '{}');
        const nodes = data?.nodes ?? data?.data ?? [];
        trafficNodes = nodes;

        let rateIn = 0;
        let rateOut = 0;
        let cumIn = 0;
        let cumOut = 0;
        nodes.forEach((node) => {
            rateIn += Number(node.network_in || 0);
            rateOut += Number(node.network_out || 0);
            cumIn += Number(node.network_in_total || 0);
            cumOut += Number(node.network_out_total || 0);
        });
        const cumulativeTotal = cumIn + cumOut;
        const rateTotal = rateIn + rateOut;

        document.getElementById('total-download').textContent = formatRate(rateIn);
        document.getElementById('total-upload').textContent = formatRate(rateOut);
        document.getElementById('total-traffic').textContent = formatRate(rateTotal);
        document.getElementById('traffic-total-cumulative').textContent = formatBytes(cumulativeTotal);
        document.getElementById('active-nodes').textContent = nodes.filter((n) => n.status === 'online').length;
        document.getElementById('traffic-nodes-total').textContent = nodes.length;
        document.getElementById('traffic-updated').textContent = formatClock(new Date());

        renderTrafficTops(nodes);
        renderTrafficTable(nodes, cumulativeTotal, rateTotal);
        loadTrafficIfaces();
    } catch (error) {
        console.error('Ошибка загрузки трафика:', error);
        document.getElementById('total-download').textContent = '0 Б';
        document.getElementById('total-upload').textContent = '0 Б';
        document.getElementById('total-traffic').textContent = '0 Б';
        document.getElementById('traffic-total-cumulative').textContent = '0 Б';
        document.getElementById('active-nodes').textContent = '0';
        renderTrafficTops([]);
        renderTrafficTable([], 0, 0);
    } finally {
        if (!silent && window.toggleTableLoader) window.toggleTableLoader('traffic-tbody', false);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    bindIfaceSort();
    loadTraffic();
    setInterval(() => loadTraffic(true), 10000);
});
