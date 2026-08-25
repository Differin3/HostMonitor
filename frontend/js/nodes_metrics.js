const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = `${API_BASE}/nodes.php`;
const METRICS_API = `${API_BASE}/metrics.php`;
const RANGE_SECONDS = { '15m': 900, '1h': 3600, '6h': 21600, '24h': 86400, '7d': 604800 };
const RANGE_LABEL = { '15m': '15 мин', '1h': '1 ч', '6h': '6 ч', '24h': 'сутки', '7d': '7 дней' };

let selectedNodeId = null;
let metricsRange = '1h';
let resChart = null;
let netChart = null;
let loadChart = null;
let chartsInited = false;

const toneForPct = (value) => {
    const n = Number(value) || 0;
    if (n >= 90) return 'bad';
    if (n >= 75) return 'warn';
    return 'ok';
};

const setMeter = (id, value) => {
    const el = document.getElementById(id);
    if (!el) return;
    const pct = Math.max(0, Math.min(100, Number(value) || 0));
    const bar = el.querySelector('span');
    if (bar) bar.style.width = `${pct}%`;
    el.dataset.tone = toneForPct(pct);
};

const formatBytes = (bytes) => {
    if (!bytes) return '0 Б';
    const k = 1024;
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    const i = Math.min(sizes.length - 1, Math.floor(Math.log(Math.abs(bytes)) / Math.log(k)));
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
};

const colorFill = (hex, a = 0.14) => {
    const n = hex.replace('#', '');
    const r = parseInt(n.slice(0, 2), 16);
    const g = parseInt(n.slice(2, 4), 16);
    const b = parseInt(n.slice(4, 6), 16);
    return `rgba(${r},${g},${b},${a})`;
};

async function loadNodes() {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    select.disabled = true;
    select.innerHTML = '<option value="">Загрузка нод...</option>';
    try {
        const nodesRes = await fetch(API_URL, { credentials: 'include' });
        if (!nodesRes.ok) throw new Error(`HTTP ${nodesRes.status}`);
        const nodesData = JSON.parse(await nodesRes.text() || '{}');
        populateNodeFilter(nodesData.nodes || []);
    } catch (error) {
        console.error('Error loading nodes:', error);
        select.innerHTML = '<option value="">Ошибка загрузки нод</option>';
    } finally {
        select.disabled = false;
    }
}

function populateNodeFilter(nodes) {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    select.innerHTML = '<option value="">Выберите ноду</option>';
    if (nodes.length === 0) {
        select.innerHTML = '<option value="">Ноды не найдены</option>';
        return;
    }
    nodes.forEach((node) => {
        const option = document.createElement('option');
        option.value = node.id;
        option.textContent = node.name;
        select.appendChild(option);
    });
}

function showEmptyState() {
    document.getElementById('metrics-empty').style.display = 'flex';
    document.getElementById('metrics-data').style.display = 'none';
}

function showMetricsData() {
    document.getElementById('metrics-empty').style.display = 'none';
    document.getElementById('metrics-data').style.display = 'block';
    initCharts();
    requestAnimationFrame(() => {
        resChart?.resize();
        netChart?.resize();
        loadChart?.resize();
    });
}

function paintRangeButtons() {
    document.querySelectorAll('#metrics-ranges [data-range]').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.range === metricsRange);
    });
    const label = RANGE_LABEL[metricsRange] || metricsRange;
    ['range-res', 'range-net', 'range-load'].forEach((id) => {
        const el = document.getElementById(id);
        if (!el) return;
        const base = el.dataset.base || el.textContent.split(' · ')[0] && el.textContent;
        const bases = {
            'range-res': 'CPU · RAM · диск',
            'range-net': 'вход · выход',
            'range-load': 'load · swap',
        };
        el.textContent = `${bases[id]} · ${label}`;
    });
}

function commonChartOptions(yTicks) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: true, position: 'bottom' } },
        scales: {
            x: { ticks: { maxTicksLimit: 7 } },
            y: { beginAtZero: true, ticks: yTicks || {} },
        },
    };
}

function initCharts() {
    if (chartsInited || !window.Chart) return;
    chartsInited = true;
    const resCtx = document.getElementById('metrics-res-chart');
    const netCtx = document.getElementById('metrics-net-chart');
    const loadCtx = document.getElementById('metrics-load-chart');

    if (resCtx) {
        resChart = new Chart(resCtx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    { label: 'CPU', borderColor: '#60a5fa', backgroundColor: colorFill('#60a5fa'), fill: true, data: [] },
                    { label: 'RAM', borderColor: '#34d399', backgroundColor: colorFill('#34d399'), fill: true, data: [] },
                    { label: 'Диск', borderColor: '#fbbf24', backgroundColor: colorFill('#fbbf24'), fill: true, data: [] },
                ],
            },
            options: {
                ...commonChartOptions({ callback: (v) => `${v}%` }),
                scales: {
                    x: { ticks: { maxTicksLimit: 7 } },
                    y: { beginAtZero: true, max: 100, ticks: { callback: (v) => `${v}%` } },
                },
            },
        });
    }
    if (netCtx) {
        netChart = new Chart(netCtx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    { label: 'Вход', borderColor: '#38bdf8', backgroundColor: colorFill('#38bdf8'), fill: true, data: [] },
                    { label: 'Выход', borderColor: '#818cf8', backgroundColor: colorFill('#818cf8'), fill: true, data: [] },
                ],
            },
            options: {
                ...commonChartOptions({ callback: (v) => formatBytes(v) }),
                scales: {
                    x: { ticks: { maxTicksLimit: 7 } },
                    y: { beginAtZero: true, ticks: { callback: (v) => formatBytes(v) } },
                },
                plugins: {
                    legend: { display: true, position: 'bottom' },
                    tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${formatBytes(ctx.raw)}` } },
                },
            },
        });
    }
    if (loadCtx) {
        loadChart = new Chart(loadCtx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    { label: 'Load avg', borderColor: '#fb7185', backgroundColor: colorFill('#fb7185'), fill: true, yAxisID: 'y', data: [] },
                    { label: 'Swap %', borderColor: '#a78bfa', backgroundColor: colorFill('#a78bfa'), fill: true, yAxisID: 'y1', data: [] },
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
                    y: { beginAtZero: true, position: 'left', title: { display: true, text: 'load' } },
                    y1: {
                        beginAtZero: true,
                        max: 100,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: { callback: (v) => `${v}%` },
                    },
                },
            },
        });
    }
}

function setChartEmpty(id, empty) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('hidden', !empty);
}

function asPoints(payload) {
    if (Array.isArray(payload)) return payload;
    if (payload && Array.isArray(payload.data)) return payload.data;
    if (payload && Array.isArray(payload.metrics)) return payload.metrics;
    return [];
}

function updateCharts(payload) {
    const points = asPoints(payload);
    const empty = points.length === 0;
    setChartEmpty('empty-res', empty);
    setChartEmpty('empty-net', empty);
    setChartEmpty('empty-load', empty);
    if (empty) {
        [resChart, netChart, loadChart].forEach((ch) => {
            if (!ch) return;
            ch.data.labels = [];
            ch.data.datasets.forEach((ds) => { ds.data = []; });
            ch.update('none');
        });
        return;
    }

    const dates = points.map((p) => new Date(p.ts || p.timestamp || Date.now()));
    const spanHours = dates.length > 1 ? (dates[dates.length - 1] - dates[0]) / 36e5 : 0;
    const labels = dates.map((d) => {
        if (!d || Number.isNaN(d.getTime())) return '';
        if (spanHours <= 36) return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    });

    if (resChart) {
        resChart.data.labels = labels;
        resChart.data.datasets[0].data = points.map((p) => p.cpu ?? 0);
        resChart.data.datasets[1].data = points.map((p) => p.ram ?? p.memory ?? 0);
        resChart.data.datasets[2].data = points.map((p) => p.disk ?? 0);
        resChart.update('none');
    }
    if (netChart) {
        netChart.data.labels = labels;
        netChart.data.datasets[0].data = points.map((p) => p.network_in ?? 0);
        netChart.data.datasets[1].data = points.map((p) => p.network_out ?? 0);
        netChart.update('none');
    }
    if (loadChart) {
        loadChart.data.labels = labels;
        loadChart.data.datasets[0].data = points.map((p) => p.load_avg ?? 0);
        loadChart.data.datasets[1].data = points.map((p) => p.swap_percent ?? 0);
        loadChart.update('none');
    }
}

async function loadChartHistory(nodeId) {
    if (!nodeId) return;
    initCharts();
    paintRangeButtons();
    const seconds = RANGE_SECONDS[metricsRange] || 3600;
    const from = Math.floor(Date.now() / 1000) - seconds;
    const limit = metricsRange === '7d' ? 280 : (metricsRange === '24h' ? 180 : (metricsRange === '6h' ? 120 : 80));
    try {
        const res = await fetch(`${METRICS_API}?node_id=${encodeURIComponent(nodeId)}&from=${from}&limit=${limit}`, {
            credentials: 'include',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = JSON.parse(await res.text() || '{}');
        updateCharts(data);
    } catch (error) {
        console.error('Ошибка загрузки графиков:', error);
        updateCharts({ data: [] });
    }
}

const loadMetrics = async (nodeId) => {
    if (!nodeId) {
        showEmptyState();
        return;
    }
    try {
        const res = await fetch(`${API_URL}?id=${encodeURIComponent(nodeId)}`, { credentials: 'include' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = JSON.parse(await res.text() || '{}');
        const node = data?.node || null;
        if (!node) {
            showEmptyState();
            return;
        }
        const cpu = Number(node.cpu_usage) || 0;
        const ram = Number(node.memory_usage) || 0;
        const disk = Number(node.disk_usage) || 0;
        document.getElementById('avg-cpu').textContent = cpu.toFixed(1) + '%';
        document.getElementById('avg-ram').textContent = ram.toFixed(1) + '%';
        document.getElementById('avg-disk').textContent = disk.toFixed(1) + '%';
        document.getElementById('node-status').textContent = node.status || 'offline';
        document.getElementById('node-net').textContent =
            `${formatBytes(node.network_in || 0)} ↓  ${formatBytes(node.network_out || 0)} ↑`;
        const cpuSub = document.getElementById('cpu-sub');
        if (cpuSub) {
            const load = Number(node.load_avg) || 0;
            const cores = Number(node.cpu_count) || 0;
            cpuSub.textContent = cores ? `load ${load.toFixed(2)} · ${cores} CPU` : `load ${load.toFixed(2)}`;
        }
        const ramSub = document.getElementById('ram-sub');
        if (ramSub) {
            const used = Number(node.memory_used) || 0;
            const total = Number(node.memory_total) || 0;
            const swap = Number(node.swap_percent) || 0;
            ramSub.textContent = total
                ? `${formatBytes(used)} / ${formatBytes(total)} · swap ${swap.toFixed(0)}%`
                : `swap ${swap.toFixed(0)}%`;
        }
        const diskSub = document.getElementById('disk-sub');
        if (diskSub) {
            const used = Number(node.disk_used) || 0;
            const total = Number(node.disk_total) || 0;
            diskSub.textContent = total ? `${formatBytes(used)} / ${formatBytes(total)}` : '—';
        }
        setMeter('meter-cpu', cpu);
        setMeter('meter-ram', ram);
        setMeter('meter-disk', disk);
        document.getElementById('metric-cpu').dataset.tone = toneForPct(cpu);
        document.getElementById('metric-ram').dataset.tone = toneForPct(ram);
        document.getElementById('metric-disk').dataset.tone = toneForPct(disk);
        document.getElementById('metric-status').dataset.tone = node.status === 'online' ? 'ok' : 'bad';
        const gpuCard = document.getElementById('metric-gpu');
        const gpu = node.gpu_usage;
        if (gpuCard && gpu != null && gpu !== '') {
            gpuCard.style.display = '';
            const gpuN = Number(gpu) || 0;
            document.getElementById('avg-gpu').textContent = gpuN.toFixed(1) + '%';
            setMeter('meter-gpu', gpuN);
            gpuCard.dataset.tone = toneForPct(gpuN);
            const gpus = Array.isArray(node.gpu) ? node.gpu : [];
            const names = gpus.map((g) => g.gpu_name || g.name).filter(Boolean);
            const temp = gpus[0] && gpus[0].temperature != null ? `${Number(gpus[0].temperature).toFixed(0)}°C` : '';
            const mem = gpus[0] && gpus[0].memory_total
                ? `${formatBytes((gpus[0].memory_used || 0) * 1024 * 1024)} / ${formatBytes(gpus[0].memory_total * 1024 * 1024)}`
                : '';
            document.getElementById('gpu-sub').textContent = [names[0] || 'GPU', temp, mem].filter(Boolean).join(' · ');
        } else if (gpuCard) {
            gpuCard.style.display = 'none';
        }
        showMetricsData();
        if (typeof lucide !== 'undefined') lucide.createIcons();
        await loadChartHistory(nodeId);
    } catch (error) {
        console.error('Ошибка загрузки метрик:', error);
        showEmptyState();
    }
};

document.addEventListener('DOMContentLoaded', () => {
    loadNodes();
    showEmptyState();
    paintRangeButtons();
    document.getElementById('nodeFilter')?.addEventListener('change', (e) => {
        selectedNodeId = e.target.value;
        if (selectedNodeId) loadMetrics(selectedNodeId);
        else showEmptyState();
    });
    document.getElementById('refresh-metrics')?.addEventListener('click', () => {
        if (selectedNodeId) loadMetrics(selectedNodeId);
    });
    document.getElementById('metrics-ranges')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-range]');
        if (!btn) return;
        metricsRange = btn.dataset.range;
        paintRangeButtons();
        if (selectedNodeId) loadChartHistory(selectedNodeId);
    });
    setInterval(() => {
        if (selectedNodeId) loadMetrics(selectedNodeId);
    }, 15000);
});
