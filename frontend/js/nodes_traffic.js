const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = `${API_BASE}/nodes.php`;
const escapeHtml = (v) => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

const formatBytes = (bytes) => {
    const n = Number(bytes) || 0;
    if (!n) return '0 Б';
    const k = 1024;
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    const i = Math.min(sizes.length - 1, Math.floor(Math.log(n) / Math.log(k)));
    return Math.round(n / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const loadTraffic = async (silent = false) => {
    try {
        if (!silent && window.toggleTableLoader) window.toggleTableLoader('traffic-tbody', true);
        const res = await fetch(API_URL, { credentials: 'include' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = JSON.parse(await res.text() || '{}');
        const nodes = data?.nodes ?? data?.data ?? [];

        let totalDownload = 0;
        let totalUpload = 0;
        nodes.forEach((node) => {
            totalDownload += parseFloat(node.network_in || 0);
            totalUpload += parseFloat(node.network_out || 0);
        });

        document.getElementById('total-download').textContent = formatBytes(totalDownload);
        document.getElementById('total-upload').textContent = formatBytes(totalUpload);
        document.getElementById('total-traffic').textContent = formatBytes(totalDownload + totalUpload);
        document.getElementById('active-nodes').textContent = nodes.filter((n) => n.status === 'online').length;
        renderTrafficTable(nodes, totalDownload + totalUpload);
    } catch (error) {
        console.error('Ошибка загрузки трафика:', error);
        document.getElementById('total-download').textContent = '0 Б';
        document.getElementById('total-upload').textContent = '0 Б';
        document.getElementById('total-traffic').textContent = '0 Б';
        document.getElementById('active-nodes').textContent = '0';
        renderTrafficTable([], 0);
    } finally {
        if (!silent && window.toggleTableLoader) window.toggleTableLoader('traffic-tbody', false);
    }
};

const renderTrafficTable = (nodes, grandTotal) => {
    const tbody = document.getElementById('traffic-tbody');
    if (!tbody) return;
    if (!nodes.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center">Нет данных</td></tr>';
        return;
    }
    const max = Math.max(grandTotal, 1);
    tbody.innerHTML = nodes.map((node) => {
        const download = parseFloat(node.network_in || 0);
        const upload = parseFloat(node.network_out || 0);
        const total = download + upload;
        const share = Math.round((total / max) * 100);
        return `<tr>
            <td>${escapeHtml(node.name || '-')}</td>
            <td>${formatBytes(download)}</td>
            <td>${formatBytes(upload)}</td>
            <td class="meter-cell">
                <div class="traffic-split">
                    <span style="width:${max ? (download / max) * 100 : 0}%"></span>
                    <span style="width:${max ? (upload / max) * 100 : 0}%"></span>
                </div>
                <small class="meter-label">${share}%</small>
            </td>
        </tr>`;
    }).join('');
};

document.addEventListener('DOMContentLoaded', () => {
    loadTraffic();
    setInterval(() => loadTraffic(true), 10000);
});
