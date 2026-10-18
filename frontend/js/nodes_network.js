// Сеть нод: интерфейсы с историей, соседи, порты и firewall.
(function () {
    'use strict';

    const PORTS_API = `${API_BASE}/ports.php`;
    const METRICS_API = `${API_BASE}/metrics.php`;
    const NODES_API = `${API_BASE}/nodes.php`;
    const RANGE_LABEL = { '15m': '15 мин', '1h': '1 ч', '6h': '6 ч', '24h': 'сутки', '7d': '7 дней' };

    let nodes = [];
    let interfaces = [];
    let ifaceRates = {};
    let neighbors = [];
    let ports = [];
    let currentTab = 'interfaces';
    let range = '1h';
    let selectedIface = null;
    let ifaceChart = null;

    const formatBytes = (bytes) => {
        const n = Number(bytes) || 0;
        if (!n) return '0 Б';
        const k = 1024;
        const sizes = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        const i = Math.min(sizes.length - 1, Math.floor(Math.log(Math.abs(n)) / Math.log(k)));
        return Math.round((n / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
    };

    const colorFill = (hex, a = 0.14) => {
        const n = hex.replace('#', '');
        return `rgba(${parseInt(n.slice(0, 2), 16)},${parseInt(n.slice(2, 4), 16)},${parseInt(n.slice(4, 6), 16)},${a})`;
    };

    const nodeName = (id) => {
        const node = nodes.find((n) => Number(n.id) === Number(id));
        return node ? node.name : `Node ${id}`;
    };

    const formatSpeed = (mbps) => {
        const n = Number(mbps) || 0;
        if (!n) return '—';
        if (n >= 1000) return `${(n / 1000).toFixed(n % 1000 ? 1 : 0)} Гбит/с`;
        return `${n} Мбит/с`;
    };

    function refreshIcons() {
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    async function fetchJson(url) {
        const res = await fetch(url, { credentials: 'include' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const text = await res.text();
        return text ? JSON.parse(text) : {};
    }

    function populateSelects() {
        const filters = [
            document.getElementById('net-node-filter'),
            document.getElementById('net-neighbors-node-filter'),
            document.getElementById('net-ports-node-filter'),
        ];
        filters.forEach((select) => {
            if (!select) return;
            const previous = select.value;
            select.innerHTML = '<option value="">Все ноды</option>';
            nodes.forEach((node) => {
                const option = document.createElement('option');
                option.value = node.id;
                option.textContent = node.name;
                select.appendChild(option);
            });
            if (previous) select.value = previous;
        });
    }

    async function loadNodes() {
        try {
            const data = await fetchJson(NODES_API);
            nodes = data.nodes || [];
        } catch (error) {
            console.error('Сеть: не удалось загрузить ноды', error);
            nodes = [];
        }
        populateSelects();
    }

    function currentTabValue() {
        return document.getElementById('net-node-filter')?.value || '';
    }

    async function loadRates() {
        ifaceRates = {};
        const ids = currentTabValue() ? [currentTabValue()] : nodes.map((n) => Number(n.id));
        await Promise.all(ids.map(async (id) => {
            try {
                const data = await fetchJson(`${METRICS_API}?interfaces=1&node_id=${encodeURIComponent(id)}`);
                (data.interfaces || []).forEach((row) => {
                    ifaceRates[`${id}:${row.name}`] = row;
                });
            } catch (error) {
                console.error(`Сеть: не удалось загрузить счётчики ноды ${id}`, error);
            }
        }));
    }

    function rateFor(iface) {
        return ifaceRates[`${iface.node_id}:${iface.name}`] || null;
    }

    async function loadInterfaces() {
        const tbody = document.getElementById('net-iface-tbody');
        if (tbody && window.toggleTableLoader) window.toggleTableLoader('net-iface-tbody', true);
        try {
            await loadRates();
            const nodeId = currentTabValue();
            const url = nodeId ? `${PORTS_API}?action=interfaces&node_id=${encodeURIComponent(nodeId)}` : `${PORTS_API}?action=interfaces`;
            const data = await fetchJson(url);
            const seen = new Set();
            interfaces = (data.interfaces || []).filter((iface) => {
                const key = `${iface.node_id}:${iface.name}`;
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            });
        } catch (error) {
            console.error('Сеть: не удалось загрузить интерфейсы', error);
            interfaces = [];
        } finally {
            if (tbody && window.toggleTableLoader) window.toggleTableLoader('net-iface-tbody', false);
        }
        renderInterfaces();
    }

    function renderInterfaces() {
        const tbody = document.getElementById('net-iface-tbody');
        if (!tbody) return;
        const search = (document.getElementById('net-iface-search')?.value || '').toLowerCase();
        const rows = interfaces.filter((iface) => {
            if (!search) return true;
            return `${iface.name} ${iface.mac || ''} ${iface.ip || ''}`.toLowerCase().includes(search);
        });

        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center">Интерфейсы не найдены</td></tr>';
            refreshIcons();
            return;
        }

        tbody.innerHTML = rows.map((iface) => {
            const idx = interfaces.indexOf(iface);
            const rate = rateFor(iface);
            const ip = [iface.ip, iface.ipv6].filter(Boolean).join(' · ') || '—';
            const gateway = [iface.gateway, iface.gateway6].filter(Boolean).join(' · ') || '—';
            const errors = rate ? (Number(rate.errin) || 0) + (Number(rate.errout) || 0) : 0;
            const drops = rate ? (Number(rate.dropin) || 0) + (Number(rate.dropout) || 0) : 0;
            const errorCell = (errors || drops) ? `err ${errors} · drop ${drops}` : '—';
            const rateSuffix = rate ? ` <span class="text-muted">(${formatBytes(rate.rx_sec)}/с)</span>` : '';
            const txSuffix = rate ? ` <span class="text-muted">(${formatBytes(rate.tx_sec)}/с)</span>` : '';
            return `
                <tr>
                    <td><strong>${escapeHtml(iface.name || '—')}</strong></td>
                    <td><span class="node-badge">${escapeHtml(nodeName(iface.node_id))}</span></td>
                    <td>${escapeHtml(iface.mac || '—')}</td>
                    <td>${escapeHtml(ip)}</td>
                    <td>${escapeHtml(gateway)}</td>
                    <td>${escapeHtml(formatSpeed(iface.speed))}</td>
                    <td><span class="status ${escapeHtml(iface.status || 'unknown')}">${escapeHtml(iface.status || 'unknown')}</span></td>
                    <td>${formatBytes(iface.rx_bytes)}${rateSuffix}</td>
                    <td>${formatBytes(iface.tx_bytes)}${txSuffix}</td>
                    <td>${errorCell}</td>
                    <td>
                        <button class="icon-btn" type="button" data-iface-idx="${idx}" title="История интерфейса">
                            <i data-lucide="line-chart"></i>
                        </button>
                    </td>
                </tr>`;
        }).join('');
        refreshIcons();
    }

    function ifaceChartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: true, position: 'bottom' } },
            scales: {
                x: { ticks: { maxTicksLimit: 7 } },
                y: { beginAtZero: true, position: 'left', ticks: { callback: (v) => formatBytes(v) } },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { precision: 0 },
                },
            },
        };
    }

    function initChart() {
        if (ifaceChart || !window.Chart) return;
        const ctx = document.getElementById('net-iface-chart');
        if (!ctx) return;
        ifaceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    { label: 'Вход, Б/с', borderColor: '#38bdf8', backgroundColor: colorFill('#38bdf8'), fill: true, yAxisID: 'y', data: [] },
                    { label: 'Выход, Б/с', borderColor: '#818cf8', backgroundColor: colorFill('#818cf8'), fill: true, yAxisID: 'y', data: [] },
                    { label: 'Ошибки', borderColor: '#f87171', backgroundColor: colorFill('#f87171'), fill: false, yAxisID: 'y1', data: [] },
                    { label: 'Дропы', borderColor: '#fbbf24', backgroundColor: colorFill('#fbbf24'), fill: false, yAxisID: 'y1', data: [] },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: true, position: 'bottom' } },
                scales: {
                    x: { ticks: { maxTicksLimit: 7 } },
                    y: { beginAtZero: true, position: 'left', ticks: { callback: (v) => formatBytes(v) } },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: { precision: 0 },
                    },
                },
            },
        });
    }

    function paintRangeLabel() {
        const el = document.getElementById('net-iface-chart-range');
        if (el) el.textContent = `вход · выход · ошибки · ${RANGE_LABEL[range] || range}`;
    }

    async function loadIfaceHistory() {
        if (!selectedIface) return;
        const card = document.getElementById('net-iface-chart-card');
        const empty = document.getElementById('net-iface-empty');
        if (card) card.style.display = '';
        if (!ifaceChart) initChart();
        paintRangeLabel();
        let series = [];
        try {
            const url = `${METRICS_API}?interfaces=1&node_id=${encodeURIComponent(selectedIface.node_id)}`
                + `&name=${encodeURIComponent(selectedIface.name)}&range=${encodeURIComponent(range)}&limit=400`;
            const data = await fetchJson(url);
            series = data.series || [];
        } catch (error) {
            console.error('Сеть: не удалось загрузить историю интерфейса', error);
        }
        updateIfaceChart(series);
        if (empty) empty.classList.toggle('hidden', series.length > 0);
    }

    function updateIfaceChart(series) {
        if (!ifaceChart) return;
        const labels = series.map((p) => {
            const d = new Date(p.ts || p.timestamp || Date.now());
            return Number.isNaN(d.getTime()) ? '' : d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        });
        ifaceChart.data.labels = labels;
        ifaceChart.data.datasets[0].data = series.map((p) => Number(p.rx_sec) || 0);
        ifaceChart.data.datasets[1].data = series.map((p) => Number(p.tx_sec) || 0);
        ifaceChart.data.datasets[2].data = series.map((p) => Number(p.errors) || 0);
        ifaceChart.data.datasets[3].data = series.map((p) => Number(p.drops) || 0);
        ifaceChart.update('none');
        ifaceChart.resize();
    }

    async function loadNeighbors() {
        try {
            const data = await fetchJson(`${PORTS_API}?action=neighbors`);
            neighbors = data.neighbors || [];
        } catch (error) {
            console.error('Сеть: не удалось загрузить соседей', error);
            neighbors = [];
        }
        renderNeighbors();
    }

    function renderNeighbors() {
        const tbody = document.getElementById('net-neighbors-tbody');
        if (!tbody) return;
        const nodeId = document.getElementById('net-neighbors-node-filter')?.value || '';
        const search = (document.getElementById('net-neighbors-search')?.value || '').toLowerCase();
        const rows = neighbors.filter((n) => {
            if (nodeId && String(n.node_id) !== String(nodeId)) return false;
            if (!search) return true;
            return `${n.ip} ${n.mac || ''} ${n.iface || ''}`.toLowerCase().includes(search);
        });

        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Соседи не найдены</td></tr>';
            refreshIcons();
            return;
        }

        tbody.innerHTML = rows.map((n) => `
            <tr>
                <td><strong>${escapeHtml(n.ip || '—')}</strong></td>
                <td>${escapeHtml(n.mac || '—')}</td>
                <td>${escapeHtml(n.iface || '—')}</td>
                <td><span class="badge">IPv${escapeHtml(String(n.family || 4))}</span></td>
                <td><span class="node-badge">${escapeHtml(nodeName(n.node_id))}</span></td>
                <td>${escapeHtml(n.timestamp || '—')}</td>
            </tr>`).join('');
        refreshIcons();
    }

    async function loadPorts() {
        try {
            const data = await fetchJson(PORTS_API);
            ports = data.ports || [];
        } catch (error) {
            console.error('Сеть: не удалось загрузить порты', error);
            ports = [];
        }
        renderPorts();
    }

    function renderPorts() {
        const tbody = document.getElementById('net-ports-tbody');
        if (!tbody) return;
        const nodeId = document.getElementById('net-ports-node-filter')?.value || '';
        const type = document.getElementById('net-ports-type-filter')?.value || '';
        const search = (document.getElementById('net-ports-search')?.value || '').toLowerCase();
        const rows = ports.filter((p) => {
            if (nodeId && String(p.node_id) !== String(nodeId)) return false;
            if (type && String(p.type).toLowerCase() !== type) return false;
            if (!search) return true;
            return `${p.port} ${p.process_name || ''} ${nodeName(p.node_id)}`.toLowerCase().includes(search);
        });

        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center">Порты не найдены</td></tr>';
            refreshIcons();
            return;
        }

        tbody.innerHTML = rows.map((p) => {
            const idx = ports.indexOf(p);
            const isOpen = p.status === 'open';
            const actionLabel = isOpen ? 'Закрыть' : 'Открыть';
            const actionIcon = isOpen ? 'lock' : 'unlock';
            return `
                <tr>
                    <td><strong>${escapeHtml(String(p.port))}</strong></td>
                    <td><span class="badge">${escapeHtml(String(p.type || 'tcp').toUpperCase())}</span></td>
                    <td><span class="node-badge">${escapeHtml(nodeName(p.node_id))}</span></td>
                    <td>${escapeHtml(p.process_name || '—')}</td>
                    <td>${escapeHtml(String(p.pid || '—'))}</td>
                    <td><span class="status ${escapeHtml(p.status || 'unknown')}">${escapeHtml(p.status || 'unknown')}</span></td>
                    <td>
                        <button class="icon-btn" type="button" data-port-idx="${idx}" title="${actionLabel} порт">
                            <i data-lucide="${actionIcon}"></i>
                        </button>
                    </td>
                </tr>`;
        }).join('');
        refreshIcons();
    }

    async function togglePort(idx) {
        const port = ports[idx];
        if (!port) return;
        const isOpen = port.status === 'open';
        const action = isOpen ? 'deny' : 'allow';
        const verb = isOpen ? 'закрыть' : 'открыть';
        if (window.showConfirm) {
            const ok = await window.showConfirm(`Вы уверены, что хотите ${verb} порт ${port.port}/${String(port.type || 'tcp').toUpperCase()}?`, 'Управление портом', 'warning');
            if (!ok) return;
        }
        try {
            const res = await fetch(`${PORTS_API}?node_id=${encodeURIComponent(port.node_id)}&action=${encodeURIComponent(action)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ port: Number(port.port), proto: String(port.type || 'tcp').toLowerCase(), action }),
            });
            if (!res.ok) {
                const text = await res.text();
                let message = `Ошибка ${res.status}`;
                try { message = JSON.parse(text).error || message; } catch (e) { /* не JSON */ }
                throw new Error(message);
            }
            if (window.showToast) window.showToast(`Правило «${action}» для порта ${port.port} отправлено`, 'success');
            setTimeout(loadPorts, 1200);
        } catch (error) {
            console.error('Сеть: не удалось применить правило firewall', error);
            if (window.showToast) window.showToast(error.message || 'Ошибка управления портом', 'error');
        }
    }

    function switchTab(tab) {
        currentTab = tab;
        document.querySelectorAll('.tab-btn').forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });
        document.querySelectorAll('.tab-content').forEach((content) => {
            content.classList.toggle('active', content.id === `net-tab-${tab}`);
        });
        if (tab === 'interfaces') loadInterfaces();
        else if (tab === 'neighbors') loadNeighbors();
        else if (tab === 'ports') loadPorts();
    }

    function wireEvents() {
        document.querySelectorAll('.tab-btn').forEach((btn) => {
            btn.addEventListener('click', () => switchTab(btn.dataset.tab));
        });
        document.getElementById('net-node-filter')?.addEventListener('change', loadInterfaces);
        document.getElementById('net-iface-search')?.addEventListener('input', renderInterfaces);
        document.getElementById('net-neighbors-node-filter')?.addEventListener('change', renderNeighbors);
        document.getElementById('net-neighbors-search')?.addEventListener('input', renderNeighbors);
        document.getElementById('net-ports-node-filter')?.addEventListener('change', renderPorts);
        document.getElementById('net-ports-type-filter')?.addEventListener('change', renderPorts);
        document.getElementById('net-ports-search')?.addEventListener('input', renderPorts);

        document.getElementById('net-iface-tbody')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-iface-idx]');
            if (!btn) return;
            const iface = interfaces[Number(btn.dataset.ifaceIdx)];
            if (!iface) return;
            selectedIface = { node_id: iface.node_id, name: iface.name };
            const title = document.getElementById('net-iface-chart-title');
            if (title) title.textContent = `История ${iface.name} · ${nodeName(iface.node_id)}`;
            loadIfaceHistory();
        });

        document.getElementById('net-ports-tbody')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-port-idx]');
            if (btn) togglePort(Number(btn.dataset.portIdx));
        });

        document.getElementById('net-ranges')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-range]');
            if (!btn) return;
            range = btn.dataset.range;
            document.querySelectorAll('#net-ranges [data-range]').forEach((b) => {
                b.classList.toggle('active', b === btn);
            });
            if (selectedIface) loadIfaceHistory();
        });
    }

    document.addEventListener('DOMContentLoaded', async () => {
        wireEvents();
        await loadNodes();
        loadInterfaces();
        setInterval(() => {
            if (document.hidden || selectedIface) return;
            if (currentTab === 'interfaces') loadInterfaces();
        }, 15000);
    });
})();
