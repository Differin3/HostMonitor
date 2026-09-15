const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = `${API_BASE}/nodes.php`;
const escapeHtml = (v) => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

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

const loadTraffic = async (silent = false) => {
    try {
        if (!silent && window.toggleTableLoader) window.toggleTableLoader('traffic-tbody', true);
        const res = await fetch(API_URL, { credentials: 'include' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = JSON.parse(await res.text() || '{}');
        const nodes = data?.nodes ?? data?.data ?? [];

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
    loadTraffic();
    setInterval(() => loadTraffic(true), 10000);
});
