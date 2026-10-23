const STORAGE_KEY = 'hm-dashboard-v2';
const WIDGET_CONFIG_KEY = 'hm-widget-configs';
const LIVE_WINDOW_SECONDS = 3600;
const CHART_HISTORY_SECONDS = 6 * 3600;
const CHART_POINT_LIMIT = 480;
const CHART_FALLBACK_LIMIT = 80;

const WIDGET_TYPES = {
    stat: { label: 'Число', icon: 'hash' },
    'line-chart': { label: 'Линия', icon: 'trending-up' },
    'bar-chart': { label: 'Столбцы', icon: 'bar-chart-2' },
    'pie-chart': { label: 'Круг', icon: 'pie-chart' },
    gauge: { label: 'Шкала', icon: 'gauge' },
    table: { label: 'Таблица', icon: 'table-2' },
};

const METRIC_OPTIONS = {
    cpu: { label: 'CPU %', icon: 'cpu', unit: '%', max: 100, color: '#60a5fa', summaryKey: 'cpu_avg' },
    ram: { label: 'RAM %', icon: 'memory-stick', unit: '%', max: 100, color: '#34d399', summaryKey: 'ram_avg' },
    disk: { label: 'Диск %', icon: 'hard-drive', unit: '%', max: 100, color: '#fbbf24', summaryKey: 'disk_avg' },
    load: { label: 'Load Avg', icon: 'activity', unit: '', max: null, color: '#f472b6', summaryKey: 'load_avg' },
    swap: { label: 'Swap %', icon: 'arrow-right-left', unit: '%', max: 100, color: '#fb923c', summaryKey: 'swap_avg' },
    gpu: { label: 'GPU %', icon: 'monitor', unit: '%', max: 100, color: '#c084fc', summaryKey: 'gpu_avg' },
    net_in: { label: 'Сеть ↓', icon: 'download', unit: '/s', max: null, color: '#38bdf8', summaryKey: 'network_in_avg', format: 'bytes' },
    net_out: { label: 'Сеть ↑', icon: 'upload', unit: '/s', max: null, color: '#818cf8', summaryKey: 'network_out_avg', format: 'bytes' },
    traffic: { label: 'Оборот трафика', icon: 'database-backup', unit: '', max: null, color: '#fbbf24', summaryKey: 'traffic_total', format: 'bytes' },
    nodes: { label: 'Ноды', icon: 'server', unit: '', max: null, color: '#34d399', summaryKey: 'nodes_online' },
    alerts: { label: 'Алерты', icon: 'bell', unit: '', max: null, color: '#fbbf24', summaryKey: 'alerts_count' },
    containers: { label: 'Контейнеры', icon: 'box', unit: '', max: null, color: '#38bdf8', summaryKey: 'containers_running' },
    databases: { label: 'Базы данных', icon: 'database', unit: '', max: null, color: '#a78bfa', summaryKey: 'databases_online' },
    processes: { label: 'Процессы', icon: 'cpu', unit: '', max: null, color: '#818cf8', summaryKey: 'processes_active' },
};

const WIDGET_COLORS = ['#60a5fa', '#34d399', '#fbbf24', '#f472b6', '#fb923c', '#c084fc', '#38bdf8', '#818cf8', '#ef4444', '#22c55e'];

const DEFAULT_METRIC_THRESHOLDS = {
    cpu: { warn: 75, bad: 90, unit: '%' },
    ram: { warn: 75, bad: 90, unit: '%' },
    disk: { warn: 75, bad: 90, unit: '%' },
    swap: { warn: 75, bad: 90, unit: '%' },
    gpu: { warn: 75, bad: 90, unit: '%' },
    load: { warn: 3, bad: 3.6, unit: '' },
    alerts: { warn: 1, bad: 5, unit: 'шт.' },
};

const DEFAULT_LAYOUT = {
    order: ['stat-nodes', 'stat-alerts', 'stat-cpu', 'stat-ram', 'stat-disk', 'stat-load', 'stat-swap', 'stat-gpu', 'stat-net', 'stat-traffic', 'chart-res', 'chart-net', 'chart-net-nodes', 'top-nodes', 'list-nodes', 'list-alerts'],
    hidden: ['stat-proc', 'stat-ct', 'stat-db'],
    spans: {
        'stat-nodes': 3, 'stat-alerts': 3, 'stat-cpu': 3, 'stat-ram': 3,
        'stat-disk': 3, 'stat-load': 3, 'stat-swap': 3, 'stat-gpu': 3, 'stat-net': 3, 'stat-traffic': 3,
        'stat-proc': 3, 'stat-ct': 3, 'stat-db': 3,
        'chart-res': 6, 'chart-net': 6, 'chart-net-nodes': 6, 'top-nodes': 6,
        'list-nodes': 6, 'list-alerts': 6,
    },
    thresholds: {},
};

const elements = {
    board: document.getElementById('dash-board'),
    dashboard: document.getElementById('dashboard'),
    nodesCount: document.getElementById('nodes-count'),
    nodesTotal: document.getElementById('nodes-total'),
    alertsCount: document.getElementById('alerts-count'),
    cpuAvg: document.getElementById('cpu-avg'),
    ramAvg: document.getElementById('ram-avg'),
    diskAvg: document.getElementById('disk-avg'),
    cpuMeter: document.getElementById('cpu-meter'),
    ramMeter: document.getElementById('ram-meter'),
    diskMeter: document.getElementById('disk-meter'),
    procCount: document.getElementById('proc-count'),
    ctCount: document.getElementById('ct-count'),
    dbCount: document.getElementById('db-count'),
    dbTotal: document.getElementById('db-total'),
    loadAvg: document.getElementById('load-avg'),
    swapAvg: document.getElementById('swap-avg'),
    swapMeter: document.getElementById('swap-meter'),
    gpuAvg: document.getElementById('gpu-avg'),
    gpuMeter: document.getElementById('gpu-meter'),
    netTotal: document.getElementById('net-total'),
    trafficTotal: document.getElementById('traffic-total'),
    nodesList: document.getElementById('nodes-list'),
    alertsList: document.getElementById('alerts-list'),
    topNodesBody: document.getElementById('top-nodes-body'),
};

let resChart = null;
let netChart = null;
let netNodesChart = null;
let detailChart = null;
let layout = loadLayout();
let editing = false;
let timers = [];
let sseSource = null;
let sseConnected = false;
let lastSummary = null;
let lastNodes = [];
let lastAlerts = [];
let openConfigWidget = null;
let lastChartsPayload = null;
let chartCache = [];
let chartView = { from: 0, to: 0 };
let chartFollowLive = true;
let chartPan = null;
let chartHistoryLoading = false;
let chartHistoryFloor = 0;
let netNodesCache = { labels: [], nodes: {}, series: {} };
let widgetConfigs = loadWidgetConfigs();
const SSE_BASE = window.MONITORING_SSE_BASE || '/sse.php';

// MySQL отдаёт datetime как «YYYY-MM-DD HH:MM:SS». Safari не парсит такой
// формат через new Date(), поэтому приводим его к ISO-подобному виду.
const parseApiDate = (value) => {
    if (value instanceof Date) return value;
    if (typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value))) {
        const n = Number(value);
        return new Date(n < 1e12 ? n * 1000 : n);
    }
    let s = String(value ?? '').trim();
    if (!s) return new Date(NaN);
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(s)) s = s.replace(' ', 'T');
    return new Date(s);
};

const setText = (el, value) => {
    if (el) el.textContent = value;
};

const toneForPct = (value, thresholds) => {
    const n = Number(value) || 0;
    const warn = Number(thresholds?.warn);
    const bad = Number(thresholds?.bad);
    const warnAt = Number.isFinite(warn) ? warn : 75;
    const badAt = Number.isFinite(bad) ? bad : 90;
    if (n >= badAt) return 'bad';
    if (n >= warnAt) return 'warn';
    return 'ok';
};

const setTone = (id, tone) => {
    const card = document.getElementById(id);
    if (card) card.dataset.tone = tone;
};

const setMeter = (el, value, tone) => {
    if (!el) return;
    const pct = Math.max(0, Math.min(100, Number(value) || 0));
    const bar = el.querySelector('span');
    if (bar) bar.style.width = `${pct}%`;
    el.dataset.tone = tone || toneForPct(pct);
};

const formatBytes = (bytes) => {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${Math.round(n)} Б`;
    const units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
    let v = n / 1024;
    let i = 0;
    while (v >= 1024 && i < units.length - 1) {
        v /= 1024;
        i += 1;
    }
    return `${v.toFixed(v >= 10 ? 0 : 1)} ${units[i]}`;
};

function loadLayout() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return structuredClone(DEFAULT_LAYOUT);
        const parsed = JSON.parse(raw);
        return {
            order: Array.isArray(parsed.order) ? parsed.order : DEFAULT_LAYOUT.order.slice(),
            hidden: Array.isArray(parsed.hidden) ? parsed.hidden : DEFAULT_LAYOUT.hidden.slice(),
            spans: { ...DEFAULT_LAYOUT.spans, ...(parsed.spans || {}) },
            thresholds: (parsed.thresholds && typeof parsed.thresholds === 'object') ? parsed.thresholds : {},
        };
    } catch (err) {
        return structuredClone(DEFAULT_LAYOUT);
    }
}

function saveLayout() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(layout));
}

function normalizeWidgetConfig(cfg) {
    if (!cfg) return { type: 'stat', title: '', series: [{ metric: 'cpu', label: 'CPU %', color: '#60a5fa', axis: 'left' }] };
    const next = JSON.parse(JSON.stringify(cfg));
    if (!Array.isArray(next.series) || next.series.length === 0) {
        const metric = next.metric || 'cpu';
        const m = METRIC_OPTIONS[metric] || {};
        next.series = [{ metric, label: next.title || m.label || metric, color: next.color || '#60a5fa', axis: 'left' }];
    } else {
        next.series = next.series.map((s, i) => {
            const metric = (s && s.metric) || 'cpu';
            const m = METRIC_OPTIONS[metric] || {};
            return { metric, label: (s && s.label) || m.label || metric, color: (s && s.color) || WIDGET_COLORS[i % WIDGET_COLORS.length], axis: (s && s.axis) === 'right' ? 'right' : 'left' };
        });
    }
    if (!next.type) next.type = 'stat';
    if (next.metric) delete next.metric;
    if (next.color) delete next.color;
    if (!next.title) next.title = '';
    return next;
}

function getSeriesFor(config, summary) {
    const c = normalizeWidgetConfig(config);
    return c.series;
}

function loadWidgetConfigs() {
    try {
        const raw = localStorage.getItem(WIDGET_CONFIG_KEY);
        return raw ? JSON.parse(raw) : {};
    } catch { return {}; }
}

function saveWidgetConfigs() {
    localStorage.setItem(WIDGET_CONFIG_KEY, JSON.stringify(widgetConfigs));
}

const originalWidgetHtml = {};

function widgetBarHtml() {
    return `<div class="dash-widget-bar">
        <span class="dash-grip" title="Перетащить"><i data-lucide="grip-vertical"></i></span>
        <div class="dash-span-btns" role="group" aria-label="Ширина">
            <button type="button" data-span="3" title="Узкий">S</button>
            <button type="button" data-span="6" title="Средний">M</button>
            <button type="button" data-span="12" title="На всю ширину">L</button>
        </div>
        <button type="button" class="dash-config-btn" data-config title="Настроить виджет"><i data-lucide="settings"></i></button>
        <button type="button" class="dash-hide" data-hide title="Скрыть"><i data-lucide="eye-off"></i></button>
    </div>`;
}

function getMetricValue(metric, summary) {
    if (!summary) return 0;
    const m = METRIC_OPTIONS[metric] || {};
    const key = m.summaryKey || metric;
    if (summary[key] != null) return summary[key];
    if (metric === 'traffic') return summary.traffic_total ?? 0;
    if (metric === 'net_in') return summary.network_in_avg ?? 0;
    if (metric === 'net_out') return summary.network_out_avg ?? 0;
    return 0;
}

function formatMetricValue(metric, value) {
    const m = METRIC_OPTIONS[metric] || {};
    const num = Number(value) || 0;
    if (m.format === 'bytes') return formatBytes(num);
    if (m.unit === '%') return `${Math.round(num)}%`;
    if (m.max === null) return String(Math.round(num));
    return `${num.toFixed(1)}${m.unit || ''}`;
}

function widgets() {
    return [...(elements.board?.querySelectorAll('[data-widget]') || [])];
}

function applyLayout() {
    if (!elements.board) return;
    const map = new Map(widgets().map((el) => [el.dataset.widget, el]));
    const seen = new Set();
    const order = layout.order.filter((id) => map.has(id));
    map.forEach((_, id) => {
        if (!order.includes(id)) order.push(id);
    });
    layout.order = order;
    order.forEach((id) => {
        const el = map.get(id);
        if (!el) return;
        seen.add(id);
        const span = Number(layout.spans[id] || el.dataset.span || 6);
        el.dataset.span = String([3, 6, 12].includes(span) ? span : 6);
        el.classList.toggle('is-hidden', layout.hidden.includes(id));
        el.querySelectorAll('.dash-span-btns button').forEach((btn) => {
            btn.classList.toggle('active', Number(btn.dataset.span) === Number(el.dataset.span));
        });
        elements.board.appendChild(el);
    });
    renderCatalog();
    paintRange();
    resizeCharts();
    if (window.lucide) lucide.createIcons();
}

function renderCatalog() {
    const host = document.getElementById('dash-catalog');
    const list = document.getElementById('dash-catalog-list');
    if (!host || !list) return;
    const hidden = widgets().filter((el) => layout.hidden.includes(el.dataset.widget));
    host.hidden = !editing || hidden.length === 0;
    list.innerHTML = hidden.map((el) => {
        const id = el.dataset.widget;
        const title = el.dataset.title || id;
        return `<button type="button" class="dash-catalog-item" data-restore="${esc(id)}">${esc(title)}</button>`;
    }).join('');
    if (window.lucide) lucide.createIcons();
}

const nowSeconds = () => Math.floor(Date.now() / 1000);

function chartWindowLabel() {
    if (chartFollowLive || !chartView.from || !chartView.to) return 'live';
    const from = new Date(chartView.from * 1000);
    const to = new Date(chartView.to * 1000);
    if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) return 'live';
    const fromText = from.toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    });
    const toText = to.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    return `${fromText}–${toText}`;
}

function paintRange() {
    const label = chartWindowLabel();
    document.querySelectorAll('[data-range-label]').forEach((el) => {
        el.textContent = `${el.dataset.base || el.textContent} · ${label}`;
    });
}

const pointTimeMs = (point) => parseApiDate(point?.ts ?? point?.timestamp ?? 0).getTime();
const pointsOf = (payload) => {
    if (Array.isArray(payload)) return payload;
    if (payload && Array.isArray(payload.data)) return payload.data;
    if (payload && Array.isArray(payload.metrics)) return payload.metrics;
    return [];
};

const normalizeChartPoints = (payload) => pointsOf(payload)
    .filter((point) => Number.isFinite(pointTimeMs(point)))
    .sort((a, b) => pointTimeMs(a) - pointTimeMs(b));

function mergeChartPoints(base, incoming, replaceFromMs = null) {
    const points = new Map();
    (base || []).forEach((point) => {
        const ts = pointTimeMs(point);
        if (Number.isFinite(ts) && (!replaceFromMs || ts < replaceFromMs)) points.set(ts, point);
    });
    (incoming || []).forEach((point) => {
        const ts = pointTimeMs(point);
        if (Number.isFinite(ts)) points.set(ts, point);
    });
    return [...points.entries()].sort((a, b) => a[0] - b[0]).map((entry) => entry[1]);
}

function setLiveChartWindow() {
    const to = nowSeconds();
    chartView = { from: to - LIVE_WINDOW_SECONDS, to };
}

function chartFetchWindow(history = false) {
    if (history) {
        const to = nowSeconds();
        return { from: to - CHART_HISTORY_SECONDS, to };
    }
    if (chartFollowLive) {
        const to = nowSeconds();
        return { from: to - LIVE_WINDOW_SECONDS, to };
    }
    return { from: Math.floor(chartView.from), to: Math.floor(chartView.to) };
}

const chartCacheStart = () => (chartCache.length ? pointTimeMs(chartCache[0]) / 1000 : 0);

function chartPointsInView() {
    if (!chartView.from || !chartView.to) setLiveChartWindow();
    const fromMs = chartView.from * 1000;
    const toMs = chartView.to * 1000;
    return chartCache.filter((point) => {
        const ts = pointTimeMs(point);
        return ts >= fromMs && ts <= toMs;
    });
}

function normalizeNetNodesPayload(payload) {
    if (!payload || !Array.isArray(payload.labels) || !payload.nodes || typeof payload.nodes !== 'object') {
        return { labels: [], nodes: {}, series: {} };
    }
    const labels = [...new Set(payload.labels.filter((label) => Number.isFinite(parseApiDate(label).getTime())))].sort();
    const series = {};
    Object.keys(payload.nodes).forEach((nodeId) => {
        series[nodeId] = payload.series?.[nodeId] || {};
    });
    return { labels, nodes: { ...payload.nodes }, series };
}

function mergeNetNodesPayload(base, incoming, replaceFromMs = null) {
    const next = normalizeNetNodesPayload(incoming);
    if (!next.labels.length) return base;
    const keep = (base?.labels || []).filter((label) => {
        const ts = parseApiDate(label).getTime();
        return Number.isFinite(ts) && (!replaceFromMs || ts < replaceFromMs);
    });
    const labels = [...new Set([...keep, ...next.labels])].sort();
    const keepSet = new Set(keep);
    const nodes = { ...(base?.nodes || {}), ...next.nodes };
    const series = {};
    Object.keys(nodes).forEach((nodeId) => {
        const oldSeries = base?.series?.[nodeId] || {};
        const newSeries = next.series[nodeId] || {};
        series[nodeId] = {};
        labels.forEach((label) => {
            if (newSeries[label]) series[nodeId][label] = newSeries[label];
            else if (keepSet.has(label) && oldSeries[label]) series[nodeId][label] = oldSeries[label];
        });
    });
    return { labels, nodes, series };
}

function netNodesInView() {
    const fromMs = chartView.from * 1000;
    const toMs = chartView.to * 1000;
    const labels = netNodesCache.labels.filter((label) => {
        const ts = parseApiDate(label).getTime();
        return ts >= fromMs && ts <= toMs;
    });
    const series = {};
    Object.keys(netNodesCache.series).forEach((nodeId) => {
        series[nodeId] = {};
        labels.forEach((label) => {
            const point = netNodesCache.series[nodeId]?.[label];
            if (point) series[nodeId][label] = point;
        });
    });
    return { labels, nodes: netNodesCache.nodes, series };
}

function resizeCharts() {
    requestAnimationFrame(() => {
        resChart?.resize();
        netChart?.resize();
        netNodesChart?.resize();
        Object.values(gaugeCharts).forEach((c) => c?.resize());
        Object.values(pieCharts).forEach((c) => c?.resize());
        Object.values(barCharts).forEach((c) => c?.resize());
        Object.values(lineCharts).forEach((c) => c?.resize());
    });
}

const gaugeCharts = {};
const pieCharts = {};
const barCharts = {};
const lineCharts = {};

function renderGenericStat(el, config, summary) {
    const c = normalizeWidgetConfig(config);
    const s = Array.isArray(c.series) && c.series[0] ? c.series[0] : { metric: 'cpu', label: '', color: '#60a5fa', axis: 'left' };
    const m = METRIC_OPTIONS[s.metric] || {};
    const value = typeof getMetricValue === 'function' ? getMetricValue(s.metric, summary) : (summary?.[s.metric] ?? summary?.[m.summaryKey] ?? 0);
    const num = Number(value) || 0;
    const pct = m.max ? Math.min(100, (num / m.max) * 100) : 0;
    const tone = m.max ? toneForPct(pct, thresholdsFor(el.dataset.widget, s.metric)) : (num > 0 ? 'ok' : 'warn');
    el.innerHTML = widgetBarHtml() + `
        <div class="stat-card-icon" style="background: linear-gradient(180deg, ${esc(s.color || '#60a5fa')}cc, ${esc(shadeColor(s.color || '#60a5fa', -20))}88);">
            <i data-lucide="${esc(m.icon || 'activity')}"></i>
        </div>
        <div class="stat-card-content">
            <h3>${esc(c.title || s.label || m.label || s.metric)}</h3>
            <div class="stat-value">${typeof formatMetricValue === 'function' ? formatMetricValue(s.metric, num) : (num + (m.unit || ''))}</div>
            ${m.max ? `<div class="hm-meter" data-tone="${tone}"><span style="width:${Math.max(0, Math.min(100, pct))}%"></span></div>` : ''}
            ${m.max ? `<p class="stat-subtitle">${Math.round(pct)}%</p>` : ''}
        </div>`;
    el.dataset.tone = tone;
    if (window.lucide) lucide.createIcons();
}

function renderGenericGauge(el, config, summary) {
    const s = (Array.isArray(config.series) && config.series[0]) || { metric: 'cpu', label: '', color: '#60a5fa' };
    const m = METRIC_OPTIONS[s.metric] || {};
    const value = getMetricValue(s.metric, summary);
    const pct = m.max ? Math.min(100, (value / m.max) * 100) : Math.min(100, value);
    const displayPct = m.max ? Math.round(pct) : Math.min(99, Math.round(value));
    el.innerHTML = widgetBarHtml() + `
        <div class="chart-header"><h3>${esc(config.title || s.label || m.label || s.metric)}</h3></div>
        <div class="gauge-body"><canvas id="gauge-${esc(el.dataset.widget)}"></canvas>
            <div class="gauge-value">${formatMetricValue(s.metric, value)}</div>
        </div>`;
    const canvas = document.getElementById(`gauge-${el.dataset.widget}`);
    if (!canvas || !window.Chart) return;
    if (gaugeCharts[el.dataset.widget]) gaugeCharts[el.dataset.widget].destroy();
    const remaining = Math.max(0, 100 - displayPct);
    gaugeCharts[el.dataset.widget] = new Chart(canvas, {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [displayPct, remaining],
                backgroundColor: [s.color || '#60a5fa', 'rgba(255,255,255,0.06)'],
                borderWidth: 0,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '78%',
            rotation: -90,
            circumference: 180,
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            animation: false,
        },
    });
}

function renderGenericPie(el, config, summary) {
    const s = (Array.isArray(config.series) && config.series[0]) || { metric: 'cpu', label: '', color: '#60a5fa' };
    const m = METRIC_OPTIONS[s.metric] || {};
    const value = getMetricValue(s.metric, summary);
    const pct = m.max ? Math.min(100, (value / m.max) * 100) : Math.min(100, value);
    const displayPct = m.max ? Math.round(pct) : Math.min(99, Math.round(value));
    el.innerHTML = widgetBarHtml() + `
        <div class="chart-header"><h3>${esc(config.title || s.label || m.label || s.metric)}</h3></div>
        <div class="pie-body"><canvas id="pie-${esc(el.dataset.widget)}"></canvas>
            <div class="pie-center">${formatMetricValue(s.metric, value)}</div>
        </div>`;
    const canvas = document.getElementById(`pie-${el.dataset.widget}`);
    if (!canvas || !window.Chart) return;
    if (pieCharts[el.dataset.widget]) pieCharts[el.dataset.widget].destroy();
    const remaining = Math.max(0, 100 - displayPct);
    pieCharts[el.dataset.widget] = new Chart(canvas, {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [displayPct, remaining],
                backgroundColor: [s.color || '#60a5fa', 'rgba(255,255,255,0.06)'],
                borderWidth: 0,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            animation: false,
        },
    });
}

function renderGenericBar(el, config, summary) {
    const c = normalizeWidgetConfig(config);
    const series = Array.isArray(c.series) ? c.series : [];
    if (series.length === 0) return;
    const title = c.title || series.map(s => s.label).join(' / ');
    el.innerHTML = widgetBarHtml() + `
        <div class="chart-header"><h3>${esc(title)}</h3></div>
        <div class="bar-body"><canvas id="bar-${esc(el.dataset.widget)}"></canvas></div>`;
    const canvas = document.getElementById(`bar-${el.dataset.widget}`);
    if (!canvas || !window.Chart) return;
    if (barCharts[el.dataset.widget]) barCharts[el.dataset.widget].destroy();
    const labels = series.map(s => s.label);
    const values = series.map((s, idx) => {
        const m = METRIC_OPTIONS[s.metric] || {};
        const v = getMetricValue ? getMetricValue(s.metric, summary) : (summary?.[s.metric] ?? summary?.[m.summaryKey] ?? 0);
        return Number(v) || 0;
    });
    barCharts[el.dataset.widget] = new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: series.map((s, idx) => colorFill(s.color || WIDGET_COLORS[idx % WIDGET_COLORS.length], 0.7)),
                borderColor: series.map((s, idx) => s.color || WIDGET_COLORS[idx % WIDGET_COLORS.length]),
                borderWidth: 1,
                borderRadius: 6,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } },
                x: { grid: { display: false } },
            },
        },
    });
}

function renderGenericLine(el, config, summary) {
    const c = normalizeWidgetConfig(config);
    const series = Array.isArray(c.series) ? c.series : [];
    if (series.length === 0) return;
    const title = c.title || series.map(s => s.label).join(' / ');
    el.innerHTML = widgetBarHtml() + `
        <div class="chart-header"><h3>${esc(title)}</h3></div>
        <div class="chart-body"><canvas id="line-${esc(el.dataset.widget)}"></canvas></div>`;
    const canvas = document.getElementById(`line-${el.dataset.widget}`);
    if (!canvas || !window.Chart) return;
    if (lineCharts[el.dataset.widget]) lineCharts[el.dataset.widget].destroy();
    const datasets = series.map((s, idx) => {
        const m = METRIC_OPTIONS[s.metric] || {};
        return {
            label: s.label || m.label || s.metric,
            borderColor: s.color || WIDGET_COLORS[idx % WIDGET_COLORS.length],
            backgroundColor: colorFill(s.color || WIDGET_COLORS[idx % WIDGET_COLORS.length], 0.15),
            fill: series.length === 1,
            data: [],
            tension: 0.25,
            yAxisID: s.axis === 'right' ? 'y1' : 'y',
        };
    });
    const scales = {
        x: { ticks: { maxTicksLimit: 6 } },
        y: { beginAtZero: true, ticks: {}, grid: { color: 'rgba(255,255,255,0.05)' } },
    };
    const hasRight = series.some(s => s.axis === 'right');
    if (hasRight) {
        scales.y1 = { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: {} };
    }
    const chart = new Chart(canvas, {
        type: 'line',
        data: { labels: [], datasets },
        options: {
            responsive: true, maintainAspectRatio: false, animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: true, position: 'bottom', labels: { color: 'var(--text-secondary)', boxWidth: 10 } } },
            scales,
        },
    });
    lineCharts[el.dataset.widget] = chart;
    bindChartPan(canvas, chart);
    const points = chartCache.length ? chartPointsInView() : pointsOf(lastChartsPayload);
    if (points.length) updateGenericLineCharts({ data: points }, el.dataset.widget);
}

function renderGenericTable(el, config, summary) {
    const s = (Array.isArray(config.series) && config.series[0]) || { metric: 'cpu', label: '', color: '#60a5fa' };
    const m = METRIC_OPTIONS[s.metric] || {};
    const nodes = Array.isArray(lastAllNodes) && lastAllNodes.length ? lastAllNodes : (Array.isArray(lastNodes) ? lastNodes : []);
    const valKey = s.metric === 'cpu' ? 'cpu_usage'
        : s.metric === 'ram' ? 'memory_usage'
        : s.metric === 'disk' ? 'disk_usage'
        : s.metric === 'net_in' ? 'network_in'
        : s.metric === 'net_out' ? 'network_out'
        : s.metric === 'swap' ? 'swap_percent'
        : s.metric === 'load' ? 'load_avg'
        : s.metric === 'gpu' ? 'gpu_usage'
        : 'cpu_usage';
    const sorted = nodes.slice().sort((a, b) => (Number(b[valKey]) || 0) - (Number(a[valKey]) || 0));
    el.innerHTML = widgetBarHtml() + `
        <div class="chart-header"><h3>${esc(config.title || s.label || m.label || s.metric)}</h3></div>
        <div class="table-container"><table><thead><tr><th>Нода</th><th>${esc(m.label || s.metric)}</th><th>Статус</th></tr></thead><tbody>${
        sorted.length ? sorted.slice(0, 8).map((n) => {
            const val = Number(n[valKey]) || 0;
            const isPercent = m.unit === '%' || m.max === 100;
            return `<tr><td>${esc(n.name || n.host || n.ip || '—')}</td><td>${isPercent ? `<span class="hm-meter hm-meter-${esc(s.metric)}" data-tone="${esc(toneForPct(val, thresholdsFor(el.dataset.widget, s.metric)))}"><span style="width:${Math.max(0, Math.min(100, val))}%"></span></span> ` : ''}${isPercent ? Math.round(val) + '%' : formatMetricValue(s.metric, val)}</td><td><span class="pill status ${esc(n.status || '')}">${esc(n.status || '—')}</span></td></tr>`;
        }).join('') : '<tr><td colspan="3" class="text-center">Нет данных</td></tr>'
    }</tbody></table></div>`;
    if (window.lucide) lucide.createIcons();
}

const GENERIC_RENDERERS = {
    stat: renderGenericStat,
    gauge: renderGenericGauge,
    'pie-chart': renderGenericPie,
    'bar-chart': renderGenericBar,
    'line-chart': renderGenericLine,
    table: renderGenericTable,
};

function renderGenericWidget(el, config, summary) {
    const c = normalizeWidgetConfig(config);
    const renderer = GENERIC_RENDERERS[c.type] || GENERIC_RENDERERS[config.type];
    if (!renderer) return;
    const id = el.dataset.widget;
    if (!originalWidgetHtml[id] && !el.classList.contains('has-generic-config')) {
        originalWidgetHtml[id] = el.innerHTML;
    }
    el.classList.add('has-generic-config');
    renderer(el, c, summary);
}

function updateGenericWidgets(summary) {
    const s = summary || lastSummary;
    if (!s) return;
    Object.entries(widgetConfigs).forEach(([widgetId, config]) => {
        const el = document.querySelector(`[data-widget="${widgetId}"]`);
        if (!el) return;
        if (el.classList.contains('is-hidden')) return;
        const c = normalizeWidgetConfig(config);
        if (c.type === 'line-chart') return;
        renderGenericWidget(el, c, s);
    });
}

function updateGenericLineCharts(metrics, onlyId) {
    const points = asPoints(metrics);
    const dates = points.map((p) => parseApiDate(p.ts || p.timestamp || Date.now()));
    const labels = dates.map((d) => Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }));
    Object.entries(widgetConfigs).forEach(([widgetId, config]) => {
        if (onlyId && widgetId !== onlyId) return;
        const chart = lineCharts[widgetId];
        if (!chart) return;
        const c = normalizeWidgetConfig(config);
        if (c.type !== 'line-chart') return;
        const series = Array.isArray(c.series) ? c.series : [];
        if (series.length === 0) return;
        if (!Array.isArray(chart.data.datasets) || chart.data.datasets.length !== series.length) {
            // rebuild datasets if mismatch
            chart.data.datasets = series.map((s, idx) => {
                const m = METRIC_OPTIONS[s.metric] || {};
                return {
                    label: s.label || m.label || s.metric,
                    borderColor: s.color || WIDGET_COLORS[idx % WIDGET_COLORS.length],
                    backgroundColor: colorFill(s.color || WIDGET_COLORS[idx % WIDGET_COLORS.length], 0.15),
                    fill: series.length === 1,
                    data: [],
                    tension: 0.25,
                    yAxisID: s.axis === 'right' ? 'y1' : 'y',
                };
            });
        }
        chart.data.labels = labels;
        chart.data.datasets.forEach((ds, idx) => {
            const s = series[idx] || {};
            const m = METRIC_OPTIONS[s.metric] || {};
            ds.label = s.label || m.label || s.metric;
            ds.borderColor = s.color || WIDGET_COLORS[idx % WIDGET_COLORS.length];
            ds.backgroundColor = colorFill(ds.borderColor, 0.15);
            ds.yAxisID = s.axis === 'right' ? 'y1' : 'y';
            ds.data = points.map((p) => {
                const metric = s.metric || 'cpu';
                if (metric === 'cpu') return p.cpu ?? 0;
                if (metric === 'ram') return p.ram ?? p.memory ?? 0;
                if (metric === 'disk') return p.disk ?? 0;
                if (metric === 'net_in') return p.network_in ?? 0;
                if (metric === 'net_out') return p.network_out ?? 0;
                if (metric === 'load') return p.load_avg ?? p.load ?? 0;
                if (metric === 'swap') return p.swap ?? 0;
                if (metric === 'gpu') return p.gpu ?? 0;
                return p[metric] ?? p[m.summaryKey] ?? 0;
            });
        });
        const hasRight = series.some(s => s.axis === 'right');
        if (hasRight && !chart.options.scales.y1) {
            chart.options.scales.y1 = { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: {} };
        }
        chart.update('none');
    });
}

function openConfigPanel(widgetId, opts = {}) {
    const wasNew = openConfigWidget === widgetId && document.getElementById('dash-config-panel')?.dataset.newWidget === '1';
    const isNewWidget = !!opts.isNew || wasNew;
    closeConfigPanel();
    const el = document.querySelector(`[data-widget="${widgetId}"]`);
    if (!el) return;
    openConfigWidget = widgetId;
    const existing = widgetConfigs[widgetId];
    const config = existing ? normalizeWidgetConfig(existing) : defaultConfigForWidget(widgetId, el);
    const isCustomWidget = widgetId.startsWith('custom-');
    const isChartType = (type) => type === 'line-chart' || type === 'bar-chart';
    const panel = document.createElement('div');
    panel.className = 'dash-config-panel';
    panel.id = 'dash-config-panel';
    if (isNewWidget) panel.dataset.newWidget = '1';
    const backdrop = document.createElement('div');
    backdrop.className = 'dash-editor-backdrop';
    backdrop.id = 'dash-config-backdrop';
    document.body.appendChild(backdrop);
    document.body.classList.add('dash-editor-open');
    backdrop.addEventListener('click', () => closeConfigPanel(true));
    const seriesSection = () => `
        <div class="dash-config-section">
            <label class="dash-config-label">Серии</label>
            <div id="dash-config-series" class="dash-config-series"></div>
            <button type="button" class="btn-outline" id="dash-config-add-series" style="margin-top:8px">Добавить серию</button>
        </div>
    `;
    const thresholdSection = (metric) => {
        const base = defaultThresholdsFor(widgetId, metric);
        if (!base) return '';
        const value = thresholdsFor(widgetId, metric) || base;
        const unit = base.unit ? `, ${esc(base.unit)}` : '';
        const step = base.unit === '%' || base.unit === 'шт.' ? '1' : '0.1';
        return `
        <div class="dash-config-section" id="dash-config-thresholds">
            <label class="dash-config-label">Пороги</label>
            <div class="dash-config-thresholds">
                <label class="dash-config-threshold">
                    <span>Предупреждение${unit}</span>
                    <input type="number" class="dash-config-input" id="dash-config-warn" min="0" step="${step}" value="${esc(value.warn)}">
                </label>
                <label class="dash-config-threshold">
                    <span>Критично${unit}</span>
                    <input type="number" class="dash-config-input" id="dash-config-bad" min="0" step="${step}" value="${esc(value.bad)}">
                </label>
            </div>
            <p class="dash-config-hint">Цвет плашки меняется при достижении порога.</p>
        </div>`;
    };
    const singleSection = () => {
        const metric = (config.series[0] || {}).metric || inferWidgetMetric(widgetId);
        return `
        <div class="dash-config-section">
            <label class="dash-config-label">Метрика</label>
            <select class="dash-config-select" id="dash-config-metric">${Object.entries(METRIC_OPTIONS).map(([k, v]) =>
                `<option value="${esc(k)}"${(config.series[0]||{}).metric === k ? ' selected' : ''}>${esc(v.label)}</option>`
            ).join('')}</select>
        </div>
        <div class="dash-config-section">
            <label class="dash-config-label">Цвет</label>
            <div class="dash-config-colors">${WIDGET_COLORS.map((c) =>
                `<button type="button" class="dash-config-color-btn${((config.series[0]||{}).color === c) ? ' active' : ''}" data-color="${esc(c)}" style="background:${esc(c)}"></button>`
            ).join('')}</div>
        </div>
        <div id="dash-config-thresholds-wrap">${thresholdSection(metric)}</div>
    `;
    };
    const dynamicHtml = (type) => isChartType(type) ? seriesSection() : singleSection();
    const seriesHtml = dynamicHtml(config.type);
    panel.innerHTML = `
        <div class="dash-config-section">
            <label class="dash-config-label">Тип</label>
            <div class="dash-config-types">${Object.entries(WIDGET_TYPES).map(([k, v]) =>
                `<button type="button" class="dash-config-type-btn${config.type === k ? ' active' : ''}" data-type="${esc(k)}" title="${esc(v.label)}"><i data-lucide="${esc(v.icon)}"></i></button>`
            ).join('')}</div>
        </div>
        <div id="dash-config-dynamic">${seriesHtml}</div>
        <div class="dash-config-section">
            <label class="dash-config-label">Заголовок</label>
            <input type="text" class="dash-config-input" id="dash-config-title" value="${esc(config.title || '')}" placeholder="${esc((config.series[0]&&METRIC_OPTIONS[config.series[0].metric]) ? METRIC_OPTIONS[config.series[0].metric].label : 'Заголовок')}">
        </div>
        <div class="dash-config-actions">
            <button type="button" class="dash-config-apply primary" id="dash-config-apply">Применить</button>
            <button type="button" class="btn-outline" id="dash-config-cancel">Отмена</button>
            ${existing?.type || existing?.metric || (existing?.series && existing.series.length) || (layout.thresholds && layout.thresholds[widgetId]) ? '<button type="button" class="btn-outline dash-config-reset" id="dash-config-reset">' + (isCustomWidget ? 'Удалить' : 'Сбросить') + '</button>' : ''}
        </div>
        ${isNewWidget ? '' : `<div class="dash-config-actions secondary">
            <button type="button" class="btn-outline" id="dash-config-up">Выше</button>
            <button type="button" class="btn-outline" id="dash-config-down">Ниже</button>
            <button type="button" class="btn-outline" id="dash-config-duplicate">Дублировать</button>
        </div>`}`;
    document.body.appendChild(panel);
    if (window.lucide) lucide.createIcons();
    function renderSeries() {
        const cont = panel.querySelector('#dash-config-series');
        if (!cont) return;
        const sList = Array.isArray(config.series) ? config.series : [];
        cont.innerHTML = sList.map((s, idx) => `
            <div class="dash-config-series-item" data-idx="${idx}" style="border:1px solid var(--border-color);border-radius:8px;padding:8px;margin-bottom:8px">
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                    <select class="dash-config-select dash-series-metric" style="flex:1;min-width:120px">
                        ${Object.entries(METRIC_OPTIONS).map(([k,v])=>`<option value="${esc(k)}"${s.metric===k?' selected':''}>${esc(v.label)}</option>`).join('')}
                    </select>
                    <input class="dash-config-input dash-series-label" style="flex:1;min-width:100px" value="${esc(s.label||'')}" placeholder="Метка">
                    <select class="dash-config-select dash-series-axis" style="width:90px">
                        <option value="left"${s.axis!=='right'?' selected':''}>Левая</option>
                        <option value="right"${s.axis==='right'?' selected':''}>Правая</option>
                    </select>
                    <button type="button" class="btn-outline dash-series-remove" style="padding:4px 8px">×</button>
                </div>
                <div class="dash-config-colors" style="margin-top:6px">${WIDGET_COLORS.map((c)=>`<button type="button" class="dash-config-color-btn dash-series-color${s.color===c?' active':''}" data-color="${esc(c)}" style="background:${esc(c)}"></button>`).join('')}</div>
            </div>
        `).join('');
        if (window.lucide) lucide.createIcons();
    }
    const renderDynamic = (type) => {
        const cont = panel.querySelector('#dash-config-dynamic');
        if (!cont) return;
        cont.innerHTML = dynamicHtml(type);
        if (isChartType(type)) renderSeries();
        if (window.lucide) lucide.createIcons();
    };
    renderDynamic(config.type);
    panel.addEventListener('click', (e) => {
        const typeBtn = e.target.closest('.dash-config-type-btn');
        if (typeBtn) {
            const nextType = typeBtn.dataset.type || 'stat';
            if (!isChartType(config.type) && isChartType(nextType)) {
                const metric = panel.querySelector('#dash-config-metric')?.value || config.series[0].metric;
                const color = panel.querySelector('.dash-config-color-btn:not(.dash-series-color).active')?.dataset.color || config.series[0].color;
                const m = METRIC_OPTIONS[metric] || {};
                config.series = [{ metric, label: m.label || metric, color, axis: 'left' }];
            }
            config.type = nextType;
            panel.querySelectorAll('.dash-config-type-btn').forEach((b) => b.classList.remove('active'));
            typeBtn.classList.add('active');
            renderDynamic(nextType);
            return;
        }
        if (e.target.closest('#dash-config-add-series')) {
            if (!Array.isArray(config.series)) config.series = [];
            config.series.push({ metric: 'cpu', label: 'CPU %', color: WIDGET_COLORS[config.series.length % WIDGET_COLORS.length], axis: 'left' });
            renderSeries();
            return;
        }
        const rm = e.target.closest('.dash-series-remove');
        if (rm) {
            const idx = Number(rm.closest('.dash-config-series-item')?.dataset.idx);
            if (!isNaN(idx) && Array.isArray(config.series)) {
                config.series.splice(idx, 1);
                if (config.series.length === 0) config.series.push({ metric: 'cpu', label: 'CPU %', color: '#60a5fa', axis: 'left' });
                renderSeries();
            }
            return;
        }
        const seriesColor = e.target.closest('.dash-series-color');
        if (seriesColor) {
            const item = seriesColor.closest('.dash-config-series-item');
            const idx = Number(item?.dataset.idx);
            item?.querySelectorAll('.dash-series-color').forEach((b) => b.classList.remove('active'));
            seriesColor.classList.add('active');
            if (!isNaN(idx) && Array.isArray(config.series)) config.series[idx].color = seriesColor.dataset.color;
            return;
        }
        const colorBtn = e.target.closest('.dash-config-color-btn:not(.dash-series-color)');
        if (colorBtn) {
            panel.querySelectorAll('.dash-config-color-btn:not(.dash-series-color)').forEach((b) => b.classList.remove('active'));
            colorBtn.classList.add('active');
        }
    });
    panel.addEventListener('change', (e) => {
        if (e.target.id === 'dash-config-metric') {
            const wrap = panel.querySelector('#dash-config-thresholds-wrap');
            if (wrap) wrap.innerHTML = thresholdSection(e.target.value);
        }
        const item = e.target.closest('.dash-config-series-item');
        if (!item) return;
        const idx = Number(item.dataset.idx);
        if (isNaN(idx) || !Array.isArray(config.series)) return;
        if (e.target.classList.contains('dash-series-metric')) {
            const prevLabel = (METRIC_OPTIONS[config.series[idx].metric] || {}).label;
            const labelInput = item.querySelector('.dash-series-label');
            if (labelInput && (!labelInput.value || labelInput.value === prevLabel)) {
                labelInput.value = (METRIC_OPTIONS[e.target.value] || {}).label || e.target.value;
            }
            config.series[idx].metric = e.target.value;
            config.series[idx].label = labelInput?.value || '';
        }
        if (e.target.classList.contains('dash-series-axis')) config.series[idx].axis = e.target.value;
        if (e.target.classList.contains('dash-series-label')) config.series[idx].label = e.target.value;
    });
    document.getElementById('dash-config-apply')?.addEventListener('click', () => {
        const type = panel.querySelector('.dash-config-type-btn.active')?.dataset.type || config.type || 'stat';
        const title = document.getElementById('dash-config-title')?.value || '';
        let newConfig = { type, title };
        let metric = '';
        let color = '';
        if (isChartType(type)) {
            const items = [...panel.querySelectorAll('.dash-config-series-item')];
            const series = items.map((item, idx) => ({
                metric: item.querySelector('.dash-series-metric')?.value || 'cpu',
                label: item.querySelector('.dash-series-label')?.value || '',
                color: item.querySelector('.dash-series-color.active')?.dataset.color || WIDGET_COLORS[idx % WIDGET_COLORS.length],
                axis: item.querySelector('.dash-series-axis')?.value === 'right' ? 'right' : 'left',
            }));
            newConfig.series = series.length ? series : [{ metric: 'cpu', label: 'CPU %', color: '#60a5fa', axis: 'left' }];
        } else {
            metric = document.getElementById('dash-config-metric')?.value || config.series[0].metric || 'cpu';
            color = panel.querySelector('.dash-config-color-btn:not(.dash-series-color).active')?.dataset.color || config.series[0].color || '#60a5fa';
            const m = METRIC_OPTIONS[metric] || {};
            newConfig.series = [{ metric, label: title || m.label || metric, color, axis: 'left' }];
        }
        const thresholdDef = defaultThresholdsFor(widgetId, metric || newConfig.series[0]?.metric);
        const warnInput = panel.querySelector('#dash-config-warn');
        const badInput = panel.querySelector('#dash-config-bad');
        if (thresholdDef && warnInput && badInput) {
            const normalized = normalizeThresholds({ warn: warnInput.value, bad: badInput.value }, thresholdDef);
            layout.thresholds = layout.thresholds || {};
            layout.thresholds[widgetId] = { warn: normalized.warn, bad: normalized.bad };
            saveLayout();
        }
        const defaultCfg = defaultConfigForWidget(widgetId, el);
        const sameTitle = title.trim() === String(el.dataset.title || '').trim();
        const keepStandard = !existing && !isCustomWidget
            && type === defaultCfg.type
            && sameTitle
            && (isChartType(type)
                ? newConfig.series.length === defaultCfg.series.length
                    && newConfig.series.every((s, i) => s.metric === defaultCfg.series[i].metric && s.color === defaultCfg.series[i].color)
                : metric === (defaultCfg.series[0] || {}).metric && color === (defaultCfg.series[0] || {}).color);
        if (!keepStandard) {
            widgetConfigs[widgetId] = newConfig;
            saveWidgetConfigs();
        }
        closeConfigPanel();
        if (keepStandard) {
            if (lastSummary) updateStats(lastSummary);
            if (window.lucide) lucide.createIcons();
            return;
        }
        destroyWidgetCharts(widgetId, type);
        const el2 = document.querySelector(`[data-widget="${widgetId}"]`);
        if (el2) {
            el2.dataset.title = title || newConfig.series[0].label || newConfig.series[0].metric;
            if (lastSummary) renderGenericWidget(el2, widgetConfigs[widgetId], lastSummary);
        }
        if (window.lucide) lucide.createIcons();
    });
    document.getElementById('dash-config-cancel')?.addEventListener('click', () => closeConfigPanel(true));
    document.getElementById('dash-config-reset')?.addEventListener('click', () => {
        if (isCustomWidget) {
            removeWidget(widgetId);
            return;
        }
        if (layout.thresholds) delete layout.thresholds[widgetId];
        saveLayout();
        delete widgetConfigs[widgetId];
        saveWidgetConfigs();
        closeConfigPanel();
        destroyWidgetCharts(widgetId);
        const el2 = document.querySelector(`[data-widget="${widgetId}"]`);
        if (el2 && originalWidgetHtml[widgetId]) {
            el2.innerHTML = originalWidgetHtml[widgetId];
            if (!el2.querySelector('.dash-widget-bar')) el2.insertAdjacentHTML('afterbegin', widgetBarHtml());
            el2.classList.remove('has-generic-config');
        }
        applyLayout();
        if (lastSummary) updateStats(lastSummary);
        if (window.lucide) lucide.createIcons();
        if (typeof refreshCharts === 'function') refreshCharts();
    });
    document.getElementById('dash-config-up')?.addEventListener('click', () => moveWidget(widgetId, -1));
    document.getElementById('dash-config-down')?.addEventListener('click', () => moveWidget(widgetId, 1));
    document.getElementById('dash-config-duplicate')?.addEventListener('click', () => {
        const copyId = duplicateWidget(widgetId);
        if (!copyId) return;
        closeConfigPanel();
        setTimeout(() => openConfigPanel(copyId), 0);
    });
}

function closeConfigPanel(discardNew = false) {
    const panel = document.getElementById('dash-config-panel');
    const widgetId = openConfigWidget;
    const isNew = !!panel && panel.dataset.newWidget === '1';
    if (panel) panel.remove();
    document.getElementById('dash-config-backdrop')?.remove();
    document.body.classList.remove('dash-editor-open');
    openConfigWidget = null;
    if (discardNew && isNew && widgetId) removeWidget(widgetId);
}

function destroyWidgetCharts(widgetId, keepType) {
    if (keepType !== 'line-chart' && lineCharts[widgetId]) { lineCharts[widgetId].destroy(); delete lineCharts[widgetId]; }
    if (keepType !== 'bar-chart' && barCharts[widgetId]) { barCharts[widgetId].destroy(); delete barCharts[widgetId]; }
    if (keepType !== 'gauge' && gaugeCharts[widgetId]) { gaugeCharts[widgetId].destroy(); delete gaugeCharts[widgetId]; }
    if (keepType !== 'pie-chart' && pieCharts[widgetId]) { pieCharts[widgetId].destroy(); delete pieCharts[widgetId]; }
}

function removeWidget(widgetId) {
    const el = document.querySelector(`[data-widget="${widgetId}"]`);
    if (el) el.remove();
    closeConfigPanel();
    destroyWidgetCharts(widgetId);
    delete widgetConfigs[widgetId];
    delete originalWidgetHtml[widgetId];
    if (Array.isArray(layout.order)) layout.order = layout.order.filter((id) => id !== widgetId);
    if (Array.isArray(layout.hidden)) layout.hidden = layout.hidden.filter((id) => id !== widgetId);
    if (layout.spans) delete layout.spans[widgetId];
    if (layout.thresholds) delete layout.thresholds[widgetId];
    saveWidgetConfigs();
    saveLayout();
    applyLayout();
    if (window.lucide) lucide.createIcons();
}

function moveWidget(widgetId, delta) {
    const idx = layout.order.indexOf(widgetId);
    if (idx < 0) return;
    const step = delta < 0 ? -1 : 1;
    let next = idx + step;
    while (next >= 0 && next < layout.order.length && layout.hidden.includes(layout.order[next])) {
        next += step;
    }
    if (next < 0 || next >= layout.order.length) return;
    [layout.order[idx], layout.order[next]] = [layout.order[next], layout.order[idx]];
    saveLayout();
    applyLayout();
}

const WIDGET_METRIC_BY_ID = {
    'stat-cpu': 'cpu', 'stat-ram': 'ram', 'stat-disk': 'disk', 'stat-load': 'load',
    'stat-swap': 'swap', 'stat-gpu': 'gpu', 'stat-net': 'net_in', 'stat-traffic': 'traffic',
    'stat-nodes': 'nodes', 'stat-alerts': 'alerts', 'stat-proc': 'processes',
    'stat-ct': 'containers', 'stat-db': 'databases',
    'chart-res': 'cpu', 'chart-net': 'net_in', 'chart-net-nodes': 'net_in',
    'top-nodes': 'cpu', 'list-nodes': 'nodes', 'list-alerts': 'alerts',
};

function inferWidgetType(el) {
    const widgetId = el.dataset.widget || '';
    if (widgetId === 'list-alerts') return 'stat';
    if (widgetId === 'list-nodes' || widgetId === 'top-nodes') return 'table';
    if (widgetId.startsWith('chart-')) return 'line-chart';
    if (el.querySelector('canvas[id^="gauge-"]')) return 'gauge';
    if (el.querySelector('canvas[id^="pie-"]')) return 'pie-chart';
    if (el.querySelector('canvas[id^="bar-"]')) return 'bar-chart';
    if (el.querySelector('canvas[id^="line-"]')) return 'line-chart';
    return 'stat';
}

function inferWidgetMetric(widgetId) {
    return WIDGET_METRIC_BY_ID[widgetId] || 'cpu';
}

const DEFAULT_WIDGET_SERIES = {
    'chart-res': ['cpu', 'ram', 'disk'],
    'chart-net': ['net_in', 'net_out'],
    'chart-net-nodes': ['net_in'],
};

function defaultThresholdsFor(widgetId, metric) {
    const key = metric || inferWidgetMetric(widgetId);
    const base = DEFAULT_METRIC_THRESHOLDS[key];
    return base ? { ...base } : null;
}

function normalizeThresholds(raw, fallback) {
    const base = fallback || { warn: 75, bad: 90, unit: '%' };
    const read = (value, def) => {
        const text = String(value ?? '').trim();
        if (text === '') return def;
        const num = Number(text);
        return Number.isFinite(num) ? num : def;
    };
    const step = base.unit === '%' || base.unit === 'шт.' ? 1 : 0.1;
    const max = base.unit === '%' ? 100 : Infinity;
    let warn = Math.max(0, Math.min(max, read(raw?.warn, base.warn)));
    let bad = Math.max(0, Math.min(max, read(raw?.bad, base.bad)));
    if (bad <= warn) bad = Math.min(max, warn + step);
    return { warn, bad, unit: base.unit || '' };
}

function thresholdsFor(widgetId, metric) {
    const base = defaultThresholdsFor(widgetId, metric);
    if (!base) return null;
    const saved = layout.thresholds?.[widgetId];
    return saved ? normalizeThresholds(saved, base) : base;
}

function defaultConfigForWidget(widgetId, el) {
    const metrics = DEFAULT_WIDGET_SERIES[widgetId] || [inferWidgetMetric(widgetId)];
    const series = metrics.map((metric, idx) => {
        const m = METRIC_OPTIONS[metric] || METRIC_OPTIONS.cpu;
        return {
            metric,
            label: m.label || metric,
            color: m.color || WIDGET_COLORS[idx % WIDGET_COLORS.length],
            axis: 'left',
        };
    });
    const type = el ? inferWidgetType(el) : (widgetId.startsWith('chart-') ? 'line-chart' : 'stat');
    return normalizeWidgetConfig({
        type,
        title: el?.dataset?.title || '',
        series,
    });
}

function duplicateWidget(widgetId) {
    const src = document.querySelector(`[data-widget="${widgetId}"]`);
    if (!src) return null;
    const clone = src.cloneNode(true);
    const id = 'custom-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 6);
    clone.id = id;
    clone.dataset.widget = id;
    clone.classList.remove('is-hidden', 'is-dragging');
    clone.removeAttribute('data-href');
    clone.removeAttribute('data-detail');
    clone.dataset.title = `${src.dataset.title || 'Виджет'} (копия)`;
    clone.querySelectorAll('[id]').forEach((node) => node.removeAttribute('id'));
    const sourceConfig = widgetConfigs[widgetId];
    let cfg;
    if (sourceConfig) {
        cfg = normalizeWidgetConfig(sourceConfig);
        cfg.title = sourceConfig.title || clone.dataset.title;
    } else {
        const metric = inferWidgetMetric(widgetId);
        const m = METRIC_OPTIONS[metric] || {};
        cfg = normalizeWidgetConfig({
            type: inferWidgetType(src),
            title: clone.dataset.title || '',
            series: [{ metric, label: m.label || metric, color: m.color || '#60a5fa', axis: 'left' }],
        });
    }
    widgetConfigs[id] = cfg;
    saveWidgetConfigs();
    const srcIdx = layout.order.indexOf(widgetId);
    layout.order.splice(srcIdx >= 0 ? srcIdx + 1 : layout.order.length, 0, id);
    layout.spans[id] = layout.spans[widgetId] || Number(src.dataset.span) || 3;
    if (layout.thresholds?.[widgetId]) {
        layout.thresholds[id] = { ...layout.thresholds[widgetId] };
    }
    clone.dataset.span = String(layout.spans[id]);
    saveLayout();
    src.parentNode.insertBefore(clone, src.nextSibling);
    applyLayout();
    if (lastSummary) renderGenericWidget(clone, cfg, lastSummary);
    if (window.lucide) lucide.createIcons();
    window.showToast?.('Виджет дублирован', 'success');
    return id;
}

function createWidget(type = 'stat', metric = 'cpu') {
    const id = 'custom-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 6);
    const m = METRIC_OPTIONS[metric] || METRIC_OPTIONS.cpu;
    const className = type === 'stat' ? 'stat-card' : (type === 'table' ? 'card' : 'chart-card');
    const span = type === 'stat' ? 3 : 6;
    widgetConfigs[id] = normalizeWidgetConfig({
        type,
        title: '',
        series: [{ metric, label: m.label || metric, color: m.color || '#60a5fa', axis: 'left' }],
    });
    saveWidgetConfigs();
    const board = document.getElementById('dash-board');
    if (!board) return null;
    const art = document.createElement('article');
    art.className = `dash-widget ${className}`;
    art.id = id;
    art.dataset.widget = id;
    art.dataset.span = String(span);
    art.dataset.title = m.label || 'Новый виджет';
    art.innerHTML = widgetBarHtml() + `<div class="stat-card-content"><h3>${esc(m.label || 'Новый виджет')}</h3><div class="stat-value">—</div></div>`;
    board.appendChild(art);
    layout.order = Array.isArray(layout.order) ? layout.order : [];
    if (!layout.order.includes(id)) layout.order.push(id);
    layout.spans = layout.spans || {};
    layout.spans[id] = span;
    saveLayout();
    applyLayout();
    if (lastSummary) renderGenericWidget(art, widgetConfigs[id], lastSummary);
    if (window.lucide) lucide.createIcons();
    return id;
}

function closeAddPanel() {
    document.getElementById('dash-add-panel')?.remove();
    document.getElementById('dash-add-backdrop')?.remove();
    if (!document.getElementById('dash-config-panel')) document.body.classList.remove('dash-editor-open');
}

function openAddPanel() {
    closeConfigPanel(true);
    closeAddPanel();
    const backdrop = document.createElement('div');
    backdrop.className = 'dash-editor-backdrop';
    backdrop.id = 'dash-add-backdrop';
    const panel = document.createElement('div');
    panel.className = 'dash-config-panel dash-add-panel';
    panel.id = 'dash-add-panel';
    panel.innerHTML = `
        <div class="dash-config-section">
            <label class="dash-config-label">Новый виджет</label>
            <div class="dash-add-grid">${Object.entries(WIDGET_TYPES).map(([k, v]) =>
                `<button type="button" class="dash-add-type" data-add-type="${esc(k)}"><i data-lucide="${esc(v.icon)}"></i><span>${esc(v.label)}</span></button>`
            ).join('')}</div>
        </div>
        <div class="dash-config-actions">
            <button type="button" class="btn-outline" id="dash-add-cancel">Отмена</button>
        </div>`;
    document.body.appendChild(backdrop);
    document.body.appendChild(panel);
    document.body.classList.add('dash-editor-open');
    backdrop.addEventListener('click', closeAddPanel);
    panel.querySelector('#dash-add-cancel')?.addEventListener('click', closeAddPanel);
    panel.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-add-type]');
        if (!btn) return;
        const type = btn.dataset.addType || 'stat';
        closeAddPanel();
        const id = createWidget(type, 'cpu');
        if (id) setTimeout(() => openConfigPanel(id, { isNew: true }), 0);
    });
    if (window.lucide) lucide.createIcons();
}

function setEditing(on) {
    editing = on;
    if (!on) {
        closeConfigPanel(true);
        closeAddPanel();
    }
    elements.dashboard?.classList.toggle('is-editing', on);
    const editBtn = document.getElementById('dash-edit');
    const resetBtn = document.getElementById('dash-reset');
    const hint = document.getElementById('dash-edit-hint');
    const actions = document.getElementById('dash-edit-actions');
    if (editBtn) {
        editBtn.innerHTML = on
            ? '<i data-lucide="check"></i> Готово'
            : '<i data-lucide="sliders-horizontal"></i> Настроить';
    }
    if (resetBtn) resetBtn.hidden = !on;
    if (actions) actions.hidden = !on;
    if (hint) {
        hint.textContent = on
            ? 'Тяните за ручку, меняйте ширину S/M/L, настраивайте, дублируйте и скрывайте виджеты.'
            : 'Клик по метрике открывает подробную статистику. Настроить — порядок, размер и набор виджетов.';
    }
    widgets().forEach((el) => el.setAttribute('draggable', 'false'));
    renderCatalog();
    if (window.lucide) lucide.createIcons();
}

const fetchJson = async (path) => {
    let apiPath = path;
    if (path.startsWith('/summary')) apiPath = `${API_BASE}/summary.php${path.slice('/summary'.length)}`;
    else if (path.startsWith('/metrics')) apiPath = `${API_BASE}/metrics.php${path.slice('/metrics'.length)}`;
    else if (path.startsWith('/alerts')) {
        apiPath = path.startsWith('/alerts/active')
            ? `${API_BASE}/alerts.php?action=active&limit=6`
            : `${API_BASE}/alerts.php${path.slice('/alerts'.length)}`;
    } else if (path.startsWith('/nodes')) apiPath = `${API_BASE}/nodes.php${path.slice('/nodes'.length)}`;
    else apiPath = `${API_BASE}${path}`;

    const res = await fetch(apiPath, { credentials: 'include' });
    if (!res.ok) throw new Error(`API ${res.status}`);
    const text = await res.text();
    return text ? JSON.parse(text) : {};
};

const hotLabel = (hot) => {
    if (!hot || !hot.name) return '';
    return `${hot.name} ${hot.value}%`;
};

const updateStats = (summary) => {
    if (!summary) return;
    lastSummary = summary;
    const online = summary.nodes_online ?? 0;
    const total = summary.nodes_total ?? 0;
    const offline = summary.nodes_offline ?? Math.max(0, total - online);
    const alerts = summary.alerts_count ?? 0;
    const alertsCrit = summary.alerts_critical ?? 0;
    const cpu = summary.cpu_avg ?? 0;
    const ram = summary.ram_avg ?? 0;
    const disk = summary.disk_avg ?? 0;
    const cpuMax = summary.cpu_max ?? cpu;
    const ramMax = summary.ram_max ?? ram;
    const diskMax = summary.disk_max ?? disk;
    const proc = summary.processes_active ?? 0;
    const ct = summary.containers_running ?? 0;
    const dbOnline = summary.databases_online ?? 0;
    const dbTotal = summary.databases_total ?? 0;
    const loadAvg = summary.load_avg ?? 0;
    const swapAvg = summary.swap_avg ?? 0;
    const swapMax = summary.swap_max ?? swapAvg;
    const gpuAvg = summary.gpu_avg ?? 0;
    const gpuMax = summary.gpu_max ?? gpuAvg;
    const gpuCount = summary.gpu_count ?? 0;
    const netIn = summary.network_in_avg ?? 0;
    const netOut = summary.network_out_avg ?? 0;
    const trafficTotal = summary.traffic_total ?? 0;
    const trafficIn = summary.traffic_in_total ?? 0;
    const trafficOut = summary.traffic_out_total ?? 0;

    setText(elements.nodesCount, online);
    setText(elements.nodesTotal, total);
    setText(elements.alertsCount, alerts);
    setText(elements.cpuAvg, `${Math.round(Number(cpu) || 0)}%`);
    setText(elements.ramAvg, `${Math.round(Number(ram) || 0)}%`);
    setText(elements.diskAvg, `${Math.round(Number(disk) || 0)}%`);
    setText(elements.procCount, Number(proc) || 0);
    setText(elements.ctCount, Number(ct) || 0);
    setText(elements.dbCount, Number(dbOnline) || 0);
    setText(elements.dbTotal, Number(dbTotal) || 0);
    setText(elements.loadAvg, (Number(loadAvg) || 0).toFixed(2));
    setText(elements.swapAvg, `${Math.round(Number(swapAvg) || 0)}%`);
    setText(elements.gpuAvg, gpuCount > 0 ? `${Math.round(Number(gpuAvg) || 0)}%` : '—');
    if (elements.netTotal) elements.netTotal.textContent = `${formatBytes(netIn)}/с ↓ ${formatBytes(netOut)}/с ↑`;
    if (elements.trafficTotal) elements.trafficTotal.textContent = formatBytes(trafficTotal);
    const cpuTone = toneForPct(Math.max(cpu, cpuMax), thresholdsFor('stat-cpu', 'cpu'));
    const ramTone = toneForPct(Math.max(ram, ramMax), thresholdsFor('stat-ram', 'ram'));
    const diskTone = toneForPct(Math.max(disk, diskMax), thresholdsFor('stat-disk', 'disk'));
    const swapTone = toneForPct(swapMax, thresholdsFor('stat-swap', 'swap'));
    const gpuTone = gpuCount > 0 ? toneForPct(gpuMax, thresholdsFor('stat-gpu', 'gpu')) : 'ok';
    const loadTone = toneForPct(loadAvg, thresholdsFor('stat-load', 'load'));
    const alertsTone = alerts === 0 ? 'ok' : (alertsCrit > 0 ? 'bad' : toneForPct(alerts, thresholdsFor('stat-alerts', 'alerts')));
    setMeter(elements.cpuMeter, cpu, cpuTone);
    setMeter(elements.ramMeter, ram, ramTone);
    setMeter(elements.diskMeter, disk, diskTone);
    setMeter(elements.swapMeter, swapMax, swapTone);
    setMeter(elements.gpuMeter, gpuCount > 0 ? gpuMax : 0, gpuTone);

    const nodesSub = document.getElementById('nodes-sub');
    const alertsSub = document.getElementById('alerts-sub');
    const cpuSub = document.getElementById('cpu-sub');
    const ramSub = document.getElementById('ram-sub');
    const diskSub = document.getElementById('disk-sub');
    const procSub = document.getElementById('proc-sub');
    const ctSub = document.getElementById('ct-sub');
    const dbSub = document.getElementById('db-sub');
    const loadSub = document.getElementById('load-sub');
    const swapSub = document.getElementById('swap-sub');
    const gpuSub = document.getElementById('gpu-sub');
    const netSub = document.getElementById('net-sub');
    const trafficSub = document.getElementById('traffic-sub');

    if (nodesSub) {
        nodesSub.innerHTML = offline > 0
            ? `из ${esc(total)} · офлайн ${esc(offline)}`
            : `из ${esc(total)} · все онлайн`;
    }
    if (alertsSub) {
        alertsSub.textContent = alerts === 0
            ? 'всё спокойно'
            : (alertsCrit > 0 ? `критичных: ${alertsCrit}` : 'не закрыты');
    }
    if (cpuSub) {
        const hot = hotLabel(summary.cpu_hot);
        cpuSub.textContent = hot ? `сейчас ${hot}` : `макс ${Math.round(Number(cpuMax) || 0)}%`;
    }
    if (ramSub) {
        const hot = hotLabel(summary.ram_hot);
        ramSub.textContent = hot ? `сейчас ${hot}` : `макс ${Math.round(Number(ramMax) || 0)}%`;
    }
    if (diskSub) {
        const hot = hotLabel(summary.disk_hot);
        diskSub.textContent = hot ? `сейчас ${hot}` : `макс ${Math.round(Number(diskMax) || 0)}%`;
    }
    if (procSub) procSub.textContent = proc > 0 ? 'по всем нодам' : 'нет данных';
    if (ctSub) ctSub.textContent = ct > 0 ? 'running' : 'нет running';
    if (dbSub) {
        dbSub.textContent = dbTotal === 0
            ? 'не настроены'
            : `онлайн из ${esc(dbTotal)}`;
    }
    if (loadSub) loadSub.textContent = `макс ${(Number(summary.max_load ?? summary.cpu_max_load ?? loadAvg) || 0).toFixed(2)}`;
    if (swapSub) swapSub.textContent = (Number(swapMax) || 0) > 0 ? `макс ${Math.round(Number(swapMax) || 0)}%` : 'не используется';
    if (gpuSub) gpuSub.textContent = gpuCount > 0 ? `${gpuCount} GPU · макс ${Math.round(Number(gpuMax) || 0)}%` : 'не обнаружены';
    if (netSub) netSub.textContent = `↓${formatBytes(netIn)}/с ↑${formatBytes(netOut)}/с`;
    if (trafficSub) trafficSub.textContent = `↓ ${formatBytes(trafficIn)} · ↑ ${formatBytes(trafficOut)}`;

    setTone('stat-nodes', online === 0 && total > 0 ? 'bad' : (online < total ? 'warn' : 'ok'));
    setTone('stat-alerts', alertsTone);
    setTone('stat-cpu', cpuTone);
    setTone('stat-ram', ramTone);
    setTone('stat-disk', diskTone);
    setTone('stat-proc', proc > 0 ? 'ok' : 'warn');
    setTone('stat-ct', 'ok');
    setTone('stat-db', dbTotal === 0 ? 'warn' : (dbOnline < dbTotal ? 'bad' : 'ok'));
    setTone('stat-load', loadTone);
    setTone('stat-swap', swapTone);
    setTone('stat-gpu', gpuTone);
    setTone('stat-net', 'ok');
    setTone('stat-traffic', 'ok');
};

const meterHtml = (value, kind) => {
    const pct = Math.max(0, Math.min(100, Number(value) || 0));
    return `<span class="hm-meter hm-meter-${kind}" data-tone="${toneForPct(pct)}"><span style="width:${pct}%"></span></span>`;
};

const renderNodeItem = (node) => {
    const li = document.createElement('li');
    li.className = 'list-item dash-node';
    li.dataset.href = 'nodes.php';
    const status = node.status || 'offline';
    const cpu = node.cpu_usage ?? 0;
    const ram = node.memory_usage ?? 0;
    const disk = node.disk_usage ?? 0;
    li.innerHTML = `
        <div class="details">
            <span class="title">${esc(node.name || node.host || '—')}</span>
            <span class="subtitle">${esc(node.host || '')}</span>
            <div class="dash-node-meters">
                ${meterHtml(cpu, 'cpu')}${meterHtml(ram, 'ram')}${meterHtml(disk, 'disk')}
            </div>
        </div>
        <span class="pill status ${esc(status)}">${esc(status)}</span>
    `;
    return li;
};

const renderAlertItem = (alert) => {
    const li = document.createElement('li');
    li.className = 'list-item';
    li.dataset.href = 'logs.php';
    const level = (alert.level || 'info').toLowerCase();
    const whenDate = alert.timestamp ? parseApiDate(alert.timestamp) : null;
    const when = whenDate && !Number.isNaN(whenDate.getTime())
        ? whenDate.toLocaleString('ru-RU', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' })
        : '';
    li.innerHTML = `
        <div class="details">
            <span class="title">${esc(alert.title || 'Алерт')}</span>
            <span class="subtitle">${esc(alert.node || '—')} · ${esc(when)}</span>
        </div>
        <span class="pill pill-${esc(level)}">${esc(level)}</span>
    `;
    return li;
};

const renderList = (container, items, renderer, emptyText) => {
    if (!container) return;
    container.innerHTML = '';
    if (!items || items.length === 0) {
        container.innerHTML = `<li class="list-empty">${emptyText}</li>`;
        return;
    }
    items.forEach((item) => container.appendChild(renderer(item)));
};

let topNodesSort = 'cpu';
const renderTopNodes = (nodes) => {
    if (!elements.topNodesBody || !nodes || !nodes.length) return;
    const sorted = nodes.slice().filter((n) => n.status === 'online');
    if (!sorted.length) {
        elements.topNodesBody.innerHTML = '<div class="list-empty">Нет онлайн нод</div>';
        return;
    }
    const isNet = topNodesSort === 'net';
    const key = topNodesSort === 'cpu' ? 'cpu_usage' : (topNodesSort === 'ram' ? 'memory_usage' : 'disk_usage');
    const valueOf = (n) => isNet
        ? (Number(n.network_in) || 0) + (Number(n.network_out) || 0)
        : (Number(n[key]) || 0);
    sorted.sort((a, b) => valueOf(b) - valueOf(a));
    const top5 = sorted.slice(0, 5);
    const maxVal = Math.max(...top5.map(valueOf), 1);
    elements.topNodesBody.innerHTML = top5.map((n) => {
        const val = valueOf(n);
        const widthPct = isNet ? Math.min(100, (val / maxVal) * 100) : Math.min(100, val);
        const tone = isNet ? 'ok' : toneForPct(val);
        const label = isNet ? `${formatBytes(val)}/s` : `${val.toFixed(1)}%`;
        const meterKind = isNet ? 'net' : topNodesSort;
        return `<div class="top-node-row" data-href="nodes.php">
            <div class="top-node-info">
                <span class="top-node-name">${esc(n.name || n.host || '—')}</span>
                <span class="top-node-val">${label}</span>
            </div>
            <div class="hm-meter hm-meter-${esc(meterKind)}" data-tone="${esc(tone)}"><span style="width:${widthPct}%"></span></div>
        </div>`;
    }).join('');
};

let lastAllNodes = [];
const initNetNodesChart = () => {
    if (!window.Chart) return;
    const canvas = document.getElementById('net-nodes-chart');
    if (!canvas) return;
    netNodesChart = new Chart(canvas, {
        type: 'line',
        data: { labels: [], datasets: [] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: true, position: 'bottom' } },
            scales: {
                x: { ticks: { maxTicksLimit: 6 } },
                y: { beginAtZero: true, ticks: { callback: (v) => formatBytes(v) } },
            },
        },
    });
    bindChartPan(canvas, netNodesChart);
};

const applyNetNodesPayload = (data) => {
    if (!netNodesChart) return;
    if (!data || !Array.isArray(data.labels) || !data.nodes || typeof data.nodes !== 'object') {
        netNodesChart.data.labels = [];
        netNodesChart.data.datasets = [];
        netNodesChart.update('none');
        return;
    }
    const nodeIds = Object.keys(data.nodes);
    const nodeColors = ['#38bdf8', '#818cf8', '#f472b6', '#34d399', '#fbbf24', '#fb923c', '#a78bfa', '#38bdf8'];
    const labels = data.labels.map((ts) => {
        const d = parseApiDate(ts);
        if (Number.isNaN(d.getTime())) return '';
        return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    });
    const datasets = nodeIds.map((nid, i) => {
        const name = data.nodes[nid] || `Node ${nid}`;
        const series = data.series?.[nid] || {};
        return {
            label: name,
            borderColor: nodeColors[i % nodeColors.length],
            backgroundColor: colorFill(nodeColors[i % nodeColors.length]),
            fill: true,
            data: data.labels.map((ts) => (series[ts]?.net_in ?? 0)),
        };
    });
    netNodesChart.data.labels = labels;
    netNodesChart.data.datasets = datasets;
    netNodesChart.update('none');
};

const refreshNetNodesChart = async (opts = {}) => {
    if (!netNodesChart || (!chartFollowLive && !opts.force)) return;
    const win = chartFetchWindow(opts.history === true);
    const query = `/summary?action=network_per_node&range=1h&from=${win.from}&to=${win.to}`;
    const data = await fetchJson(query).catch(() => null);
    if (!data) return;
    netNodesCache = mergeNetNodesPayload(netNodesCache, data);
    if (chartFollowLive) {
        if (!chartView.to) setLiveChartWindow();
        applyNetNodesPayload(netNodesInView());
    } else if (opts.force) {
        applyNetNodesPayload(netNodesInView());
    }
};

const colorFill = (hex, a = 0.14) => {
    const n = hex.replace('#', '');
    const r = parseInt(n.slice(0, 2), 16);
    const g = parseInt(n.slice(2, 4), 16);
    const b = parseInt(n.slice(4, 6), 16);
    return `rgba(${r},${g},${b},${a})`;
};

const shadeColor = (hex, percent = -20) => {
    const n = String(hex || '#60a5fa').replace('#', '');
    if (n.length !== 6) return hex || '#60a5fa';
    const num = parseInt(n, 16);
    if (Number.isNaN(num)) return hex || '#60a5fa';
    const amt = Math.round(255 * (percent / 100));
    const clamp = (v) => Math.max(0, Math.min(255, v));
    const r = clamp((num >> 16) + amt);
    const g = clamp(((num >> 8) & 0xff) + amt);
    const b = clamp((num & 0xff) + amt);
    return `#${((r << 16) | (g << 8) | b).toString(16).padStart(6, '0')}`;
};

const initCharts = () => {
    if (!window.Chart) return;
    const resCtx = document.getElementById('res-chart');
    const netCtx = document.getElementById('net-chart');
    initNetNodesChart();
    const common = {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: true, position: 'bottom' } },
        scales: {
            x: { ticks: { maxTicksLimit: 6 } },
            y: { beginAtZero: true },
        },
    };

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
                ...common,
                scales: {
                    ...common.scales,
                    y: { beginAtZero: true, max: 100, ticks: { callback: (v) => `${Math.round(v)}%` } },
                },
            },
        });
        bindChartPan(resCtx, resChart);
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
                ...common,
                scales: {
                    ...common.scales,
                    y: { beginAtZero: true, ticks: { callback: (v) => formatBytes(v) } },
                },
                plugins: {
                    ...common.plugins,
                    tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${formatBytes(ctx.raw)}` } },
                },
            },
        });
        bindChartPan(netCtx, netChart);
    }
};

function chartPlotWidth(chart, canvas) {
    const area = chart?.chartArea;
    if (area && area.right > area.left) return area.right - area.left;
    return Number(canvas?.clientWidth) || Number(canvas?.width) || 0;
}

function bindChartPan(canvas, chart) {
    if (!canvas || !chart) return;
    canvas.title = 'Тяните мышью по времени · двойной клик — live';
    canvas.addEventListener('pointerdown', (e) => {
        if (e.button !== undefined && e.button !== 0) return;
        const width = chartPlotWidth(chart, canvas);
        if (!width) return;
        chartPan = {
            canvas,
            chart,
            startX: e.clientX,
            width,
            startView: { ...chartView },
            moved: false,
            wasFollow: chartFollowLive,
        };
        canvas.classList.add('is-panning');
        canvas.setPointerCapture?.(e.pointerId);
    });
    canvas.addEventListener('pointermove', (e) => {
        if (!chartPan || chartPan.canvas !== canvas) return;
        const dx = e.clientX - chartPan.startX;
        if (!chartPan.moved && Math.abs(dx) < 3) return;
        chartPan.moved = true;
        chartFollowLive = false;
        const span = chartPan.startView.to - chartPan.startView.from || LIVE_WINDOW_SECONDS;
        const shift = -(dx / chartPan.width) * span;
        const now = nowSeconds();
        let from = chartPan.startView.from + shift;
        let to = chartPan.startView.to + shift;
        if (to > now) {
            to = now;
            from = to - span;
        }
        chartView = { from, to };
        renderChartWindow();
        e.preventDefault();
    });
    const finishPan = (e) => {
        if (!chartPan || chartPan.canvas !== canvas) return;
        const pan = chartPan;
        chartPan = null;
        canvas.classList.remove('is-panning');
        canvas.releasePointerCapture?.(e.pointerId);
        if (!pan.moved) {
            chartFollowLive = pan.wasFollow;
            if (chartFollowLive) {
                setLiveChartWindow();
                renderChartWindow();
            }
            return;
        }
        if (chartView.to >= nowSeconds() - 3) {
            chartFollowLive = true;
            setLiveChartWindow();
            renderChartWindow();
            refreshCharts({ force: true });
            refreshNetNodesChart({ force: true });
            return;
        }
        chartFollowLive = false;
        renderChartWindow();
        loadOlderChartHistory(chartView.from);
    };
    canvas.addEventListener('pointerup', finishPan);
    canvas.addEventListener('pointercancel', finishPan);
    canvas.addEventListener('dblclick', (e) => {
        e.preventDefault();
        chartFollowLive = true;
        setLiveChartWindow();
        renderChartWindow();
        refreshCharts({ force: true });
        refreshNetNodesChart({ force: true });
    });
}

async function loadOlderChartHistory(viewFrom) {
    const start = chartCacheStart();
    if (chartHistoryLoading || !start || viewFrom >= start - 1) return;
    if (chartHistoryFloor && viewFrom >= chartHistoryFloor - 1) return;
    chartHistoryLoading = true;
    const to = Math.floor(start);
    const from = Math.max(Math.floor(viewFrom) - CHART_HISTORY_SECONDS, to - 30 * 86400);
    try {
        const [metrics, nodes] = await Promise.all([
            fetchJson(`/metrics?from=${from}&to=${to}&limit=${CHART_POINT_LIMIT}`).catch(() => null),
            netNodesChart
                ? fetchJson(`/summary?action=network_per_node&range=1h&from=${from}&to=${to}`).catch(() => null)
                : Promise.resolve(null),
        ]);
        if (metrics) {
            const incoming = normalizeChartPoints(metrics);
            if (incoming.length) {
                chartCache = mergeChartPoints(chartCache, incoming);
                chartHistoryFloor = pointTimeMs(chartCache[0]) / 1000;
            } else {
                chartHistoryFloor = Math.floor(viewFrom) - 60;
            }
        }
        if (nodes) netNodesCache = mergeNetNodesPayload(netNodesCache, nodes);
        if (!chartFollowLive) renderChartWindow();
    } finally {
        chartHistoryLoading = false;
    }
}

function renderChartWindow() {
    if (!chartView.from || !chartView.to) setLiveChartWindow();
    const points = chartPointsInView();
    lastChartsPayload = { data: points };
    updateCharts({ data: points });
    updateGenericLineCharts({ data: points });
    applyNetNodesPayload(netNodesInView());
    paintRange();
}

const asPoints = (payload) => {
    if (Array.isArray(payload)) return payload;
    if (payload && Array.isArray(payload.data)) return payload.data;
    if (payload && Array.isArray(payload.metrics)) return payload.metrics;
    return [];
};

const updateCharts = (payload) => {
    const points = pointsOf(payload);
    lastChartsPayload = { data: points };
    if (!resChart && !netChart) return;

    const dates = points.map((p) => parseApiDate(p.ts || p.timestamp || Date.now()));
    const spanHours = dates.length > 1 ? (dates[dates.length - 1] - dates[0]) / 36e5 : 0;
    const labels = dates.map((d) => {
        if (!d || Number.isNaN(d.getTime())) return '';
        if (spanHours <= 24) return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
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
};

const applyOverviewPayload = (payload) => {
    if (!payload) return;
    let summary = payload.summary ?? payload;
    const nodesData = payload.nodes ?? payload.data ?? payload.nodes_list ?? [];
    const list = Array.isArray(nodesData) ? nodesData.slice() : [];
    list.sort((a, b) => {
        const aOff = a.status === 'online' ? 1 : 0;
        const bOff = b.status === 'online' ? 1 : 0;
        if (aOff !== bOff) return bOff - aOff;
        return (Number(b.cpu_usage) || 0) - (Number(a.cpu_usage) || 0);
    });
    if ((!summary || typeof summary !== 'object' || (summary.cpu_avg == null && summary.nodes_total == null && summary.ram_avg == null)) && list.length) {
        const online = list.filter((n) => n.status === 'online').length;
        const total = list.length;
        const cpuAvg = list.reduce((acc, n) => acc + (Number(n.cpu_usage) || 0), 0) / total;
        const ramAvg = list.reduce((acc, n) => acc + (Number(n.memory_usage) || Number(n.ram_usage) || 0), 0) / total;
        const diskAvg = list.reduce((acc, n) => acc + (Number(n.disk_usage) || 0), 0) / total;
        summary = {
            nodes_online: online,
            nodes_total: total,
            nodes_offline: Math.max(0, total - online),
            cpu_avg: cpuAvg,
            ram_avg: ramAvg,
            disk_avg: diskAvg,
            alerts_count: summary?.alerts_count ?? (Array.isArray(payload.alerts) ? payload.alerts.length : 0),
            alerts_critical: summary?.alerts_critical ?? 0,
        };
    }
    if (summary && typeof summary === 'object') {
        if (summary.cpu_avg != null || summary.nodes_total != null || summary.ram_avg != null) {
            lastSummary = summary;
            updateStats(summary);
        }
    }

    lastNodes = list;
    renderList(elements.nodesList, list.slice(0, 6), renderNodeItem, 'Нет данных о нодах');
    renderTopNodes(list);
    lastAllNodes = list;

    const alertsData = payload.alerts ?? payload.alerts_list ?? [];
    lastAlerts = Array.isArray(alertsData) ? alertsData : [];
    renderList(elements.alertsList, lastAlerts.slice(0, 6), renderAlertItem, 'Алертов нет');
    if (lastSummary) updateGenericWidgets(lastSummary);
};

const refreshOverview = async () => {
    const [summary, alerts, nodes] = await Promise.all([
        fetchJson('/summary').catch(() => null),
        fetchJson('/alerts/active').catch(() => null),
        fetchJson('/nodes').catch(() => null),
    ]);
    applyOverviewPayload({
        summary: summary?.data ?? summary,
        nodes: nodes?.data ?? nodes?.nodes ?? [],
        alerts: alerts?.data ?? alerts?.alerts ?? [],
    });
};

const refreshCharts = async (opts = {}) => {
    if (!resChart && !netChart) return;
    if (!chartFollowLive && !opts.force) return;
    const history = opts.history === true || (opts.initial === true && !chartCache.length);
    const win = chartFetchWindow(history);
    const limit = history ? CHART_POINT_LIMIT : CHART_FALLBACK_LIMIT;
    const metrics = await fetchJson(`/metrics?from=${win.from}&to=${win.to}&limit=${limit}`).catch(() => null);
    if (!metrics) return;
    const incoming = normalizeChartPoints(metrics);
    if (!incoming.length && !chartCache.length) return;
    const replaceFrom = !history && chartFollowLive && !opts.force ? (nowSeconds() - LIVE_WINDOW_SECONDS - 60) * 1000 : null;
    chartCache = mergeChartPoints(chartCache, incoming, replaceFrom);
    if (!chartCache.length) return;
    if (chartFollowLive) {
        setLiveChartWindow();
        renderChartWindow();
    } else if (opts.force) {
        renderChartWindow();
    }
};

const refreshAll = async () => {
    const needHistory = !chartCache.length;
    await Promise.all([
        refreshOverview(),
        refreshCharts({ history: needHistory, force: true }),
        refreshNetNodesChart({ history: !netNodesCache.labels.length, force: true }),
    ]);
    renderChartWindow();
};

function stopPolling() {
    timers.forEach((id) => clearInterval(id));
    timers = [];
}

function startPollingFallback() {
    if (timers.length) return;
    timers.push(setInterval(refreshOverview, 8000));
    timers.push(setInterval(refreshCharts, 20000));
    timers.push(setInterval(refreshNetNodesChart, 20000));
}

function disconnectSse() {
    if (sseSource) {
        sseSource.close();
        sseSource = null;
    }
    sseConnected = false;
}

function connectSse() {
    if (!window.EventSource || !elements.board) return;
    disconnectSse();
    const url = `${SSE_BASE}?_=${Date.now()}`;
    const es = new EventSource(url);
    sseSource = es;

    es.addEventListener('overview', (e) => {
        try {
            applyOverviewPayload(JSON.parse(e.data));
        } catch (err) {
            console.warn('SSE overview parse error', err);
        }
    });

    es.addEventListener('charts', (e) => {
        try {
            const payload = JSON.parse(e.data);
            const incoming = normalizeChartPoints(payload);
            if (incoming.length) {
                const replaceFrom = pointTimeMs(incoming[0]);
                chartCache = mergeChartPoints(chartCache, incoming, replaceFrom);
            }
            const netIncoming = normalizeNetNodesPayload(payload?.net_nodes);
            if (netIncoming.labels.length) {
                const replaceFrom = parseApiDate(netIncoming.labels[0]).getTime();
                netNodesCache = mergeNetNodesPayload(netNodesCache, payload.net_nodes, replaceFrom);
            }
            if (chartFollowLive) {
                setLiveChartWindow();
                renderChartWindow();
            }
        } catch (err) {
            console.warn('SSE charts parse error', err);
        }
    });

    es.addEventListener('error', () => {
        if (es.readyState === EventSource.CLOSED) {
            sseConnected = false;
            startPollingFallback();
        }
    });

    es.onopen = () => {
        sseConnected = true;
        stopPolling();
    };
}

function bindBoard() {
    if (!elements.board || !elements.dashboard) return;
    let dragged = null;

    elements.board.addEventListener('mousedown', (e) => {
        const widget = e.target.closest('[data-widget]');
        if (!widget) return;
        widget.draggable = editing && !!e.target.closest('.dash-grip');
    });
    elements.board.addEventListener('dragstart', (e) => {
        if (!editing) {
            e.preventDefault();
            return;
        }
        const widget = e.target.closest('[data-widget]');
        if (!widget || !widget.draggable) {
            e.preventDefault();
            return;
        }
        dragged = widget;
        widget.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', widget.dataset.widget);
    });
    elements.board.addEventListener('dragend', () => {
        dragged?.classList.remove('is-dragging');
        dragged = null;
        layout.order = widgets().map((el) => el.dataset.widget);
        saveLayout();
    });
    elements.board.addEventListener('dragover', (e) => {
        if (!editing || !dragged) return;
        e.preventDefault();
        if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
        const over = e.target.closest('[data-widget]');
        if (!over || over === dragged) return;
        const rect = over.getBoundingClientRect();
        const before = e.clientX < rect.left + rect.width / 2 || e.clientY < rect.top + rect.height / 2;
        elements.board.insertBefore(dragged, before ? over : over.nextSibling);
    });

    elements.dashboard.addEventListener('click', (e) => {
        const configBtn = e.target.closest('[data-config]');
        const configPanel = e.target.closest('.dash-config-panel');
        if (!configPanel && openConfigWidget && editing) {
            const configWidgetId = configBtn?.closest('[data-widget]')?.dataset.widget;
            if (configWidgetId !== openConfigWidget) closeConfigPanel(true);
        }
        const topTab = e.target.closest('.top-tab');
        if (topTab) {
            e.preventDefault();
            topTab.closest('.top-nodes-tabs')?.querySelectorAll('.top-tab').forEach((t) => t.classList.remove('active'));
            topTab.classList.add('active');
            topNodesSort = topTab.dataset.sort || 'cpu';
            renderTopNodes(lastAllNodes);
            return;
        }
        const restore = e.target.closest('[data-restore]');
        if (restore) {
            e.preventDefault();
            const restoredId = restore.dataset.restore;
            layout.hidden = layout.hidden.filter((id) => id !== restoredId);
            saveLayout();
            applyLayout();
            const restoredEl = document.querySelector(`[data-widget="${restoredId}"]`);
            if (widgetConfigs[restoredId] && restoredEl && lastSummary) {
                renderGenericWidget(restoredEl, widgetConfigs[restoredId], lastSummary);
            }
            if (window.lucide) lucide.createIcons();
            return;
        }
        const hideBtn = e.target.closest('[data-hide]');
        if (hideBtn) {
            e.preventDefault();
            e.stopPropagation();
            const widget = hideBtn.closest('[data-widget]');
            if (!widget) return;
        if (!layout.hidden.includes(widget.dataset.widget)) layout.hidden.push(widget.dataset.widget);
        saveLayout();
        applyLayout();
        if (window.lucide) lucide.createIcons();
        return;
        }
        if (configBtn) {
            e.preventDefault();
            e.stopPropagation();
            const widget = configBtn.closest('[data-widget]');
            if (widget) openConfigPanel(widget.dataset.widget);
            return;
        }
        const spanBtn = e.target.closest('.dash-span-btns button');
        if (spanBtn) {
            e.preventDefault();
            e.stopPropagation();
            const widget = spanBtn.closest('[data-widget]');
            if (!widget) return;
        layout.spans[widget.dataset.widget] = Number(spanBtn.dataset.span);
        saveLayout();
        applyLayout();
        if (window.lucide) lucide.createIcons();
        return;
        }
        if (editing) return;
        if (e.target.closest('a')) return;
        const detailWidget = e.target.closest('[data-widget][data-detail]');
        if (detailWidget?.dataset.detail) {
            e.preventDefault();
            openDetailModal(detailWidget.dataset.detail, detailWidget.dataset.title || '', detailWidget.dataset.href || '');
            return;
        }
        const row = e.target.closest('[data-href]');
        if (row?.dataset.href) {
            window.location.href = row.dataset.href;
            return;
        }
        const widget = e.target.closest('[data-widget][data-href]');
        if (widget?.dataset.href) {
            window.location.href = widget.dataset.href;
        }
    });
}

const DETAIL_META = {
    nodes: { title: 'Ноды', link: 'nodes.php', metric: null, topKey: null },
    alerts: { title: 'Алерты', link: 'logs.php', metric: null, topKey: null },
    cpu: { title: 'CPU', link: 'monitoring.php?tab=node', metric: 'cpu', topKey: 'top_cpu', color: '#60a5fa' },
    ram: { title: 'RAM', link: 'monitoring.php?tab=node', metric: 'ram', topKey: 'top_ram', color: '#34d399' },
    disk: { title: 'Диск', link: 'monitoring.php?tab=node', metric: 'disk', topKey: 'top_disk', color: '#fbbf24' },
    proc: { title: 'Процессы', link: 'processes.php', metric: null, topKey: null },
    db: { title: 'Базы данных', link: 'databases.php', metric: null, topKey: null },
    ct: { title: 'Контейнеры', link: 'containers.php', metric: null, topKey: null },
};

function closeDetailModal() {
    const modal = document.getElementById('dash-detail-modal');
    if (!modal) return;
    modal.classList.remove('active');
    setTimeout(() => modal.classList.add('hidden'), 180);
    modal.setAttribute('aria-hidden', 'true');
}

function renderDetailKpis(kind, summary) {
    const host = document.getElementById('dash-detail-kpis');
    if (!host) return;
    const s = summary || {};
    const items = [];
    if (kind === 'cpu') {
        items.push(['Среднее', `${s.cpu_avg ?? 0}%`], ['Макс', `${s.cpu_max ?? 0}%`], ['Лидер сейчас', hotLabel(s.cpu_hot) || '—']);
    } else if (kind === 'ram') {
        items.push(['Среднее', `${s.ram_avg ?? 0}%`], ['Макс', `${s.ram_max ?? 0}%`], ['Лидер сейчас', hotLabel(s.ram_hot) || '—']);
    } else if (kind === 'disk') {
        items.push(['Среднее', `${s.disk_avg ?? 0}%`], ['Макс', `${s.disk_max ?? 0}%`], ['Лидер сейчас', hotLabel(s.disk_hot) || '—']);
    } else if (kind === 'nodes') {
        items.push(['Онлайн', s.nodes_online ?? 0], ['Всего', s.nodes_total ?? 0], ['Офлайн', s.nodes_offline ?? 0]);
    } else if (kind === 'alerts') {
        items.push(['Открыто', s.alerts_count ?? 0], ['Критических', s.alerts_critical ?? 0]);
    } else if (kind === 'proc') {
        items.push(['Активных', s.processes_active ?? 0]);
    } else if (kind === 'ct') {
        items.push(['Running', s.containers_running ?? 0]);
    } else if (kind === 'db') {
        items.push(['Онлайн', s.databases_online ?? 0], ['Всего', s.databases_total ?? 0]);
    }
    host.innerHTML = items.map(([k, v]) => `
        <div class="dash-detail-kpi">
            <span class="dash-detail-kpi-label">${esc(k)}</span>
            <span class="dash-detail-kpi-value">${esc(v)}</span>
        </div>
    `).join('');
}

function renderDetailTable(kind, summary) {
    const tbody = document.getElementById('dash-detail-tbody');
    if (!tbody) return;
    const meta = DETAIL_META[kind] || {};
    let rows = [];
    if (meta.topKey && summary?.[meta.topKey]) {
        rows = summary[meta.topKey];
    } else if (kind === 'nodes') {
        rows = lastNodes.map((n) => ({
            name: n.name, host: n.host, status: n.status,
            value: n.status === 'online' ? 'online' : 'offline',
            cpu: n.cpu_usage, ram: n.memory_usage, disk: n.disk_usage,
        }));
    } else if (kind === 'alerts') {
        rows = lastAlerts.map((a) => ({
            name: a.title || 'Алерт', host: a.node || '', status: a.level || 'info',
            value: a.level || '', cpu: '—', ram: '—', disk: '—',
        }));
    } else {
        rows = lastNodes.map((n) => ({
            name: n.name, host: n.host, status: n.status,
            value: '—', cpu: n.cpu_usage, ram: n.memory_usage, disk: n.disk_usage,
        }));
    }
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">Нет данных</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map((r) => `
        <tr>
            <td>${esc(r.name || r.host || '—')}<div class="muted" style="font-size:12px">${esc(r.host || '')}</div></td>
            <td><span class="pill status ${esc(r.status || '')}">${esc(r.status || '—')}</span></td>
            <td>${esc(r.value ?? '—')}${typeof r.value === 'number' ? '%' : ''}</td>
            <td>${r.cpu != null && r.cpu !== '—' ? `${Number(r.cpu).toFixed(1)}%` : '—'}</td>
            <td>${r.ram != null && r.ram !== '—' ? `${Number(r.ram).toFixed(1)}%` : '—'}</td>
            <td>${r.disk != null && r.disk !== '—' ? `${Number(r.disk).toFixed(1)}%` : '—'}</td>
        </tr>
    `).join('');
}

async function updateDetailChart(kind) {
    const canvas = document.getElementById('dash-detail-chart');
    const wrap = canvas?.closest('.dash-detail-chart');
    const meta = DETAIL_META[kind] || {};
    if (!canvas || !window.Chart || !meta.metric) {
        if (wrap) wrap.style.display = 'none';
        if (detailChart) {
            detailChart.destroy();
            detailChart = null;
        }
        return;
    }
    if (wrap) wrap.style.display = '';
    const win = chartFetchWindow(false);
    const metrics = await fetchJson(`/metrics?from=${win.from}&to=${win.to}&limit=${CHART_FALLBACK_LIMIT}`).catch(() => null);
    const points = asPoints(metrics);
    const labels = points.map((p) => {
        const d = parseApiDate(p.ts || p.timestamp || Date.now());
        return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    });
    const key = meta.metric === 'ram' ? 'ram' : meta.metric;
    const data = points.map((p) => {
        if (key === 'cpu') return p.cpu ?? 0;
        if (key === 'ram') return p.ram ?? p.memory ?? 0;
        return p.disk ?? 0;
    });
    if (detailChart) detailChart.destroy();
    detailChart = new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: meta.title,
                borderColor: meta.color || '#60a5fa',
                backgroundColor: colorFill(meta.color || '#60a5fa'),
                fill: true,
                data,
                tension: 0.25,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, max: 100, ticks: { callback: (v) => `${v}%` } },
            },
        },
    });
}

async function openDetailModal(kind, title, href) {
    const modal = document.getElementById('dash-detail-modal');
    if (!modal) return;
    const meta = DETAIL_META[kind] || { title: title || kind, link: href || '#' };
    const titleEl = document.getElementById('dash-detail-title');
    const linkEl = document.getElementById('dash-detail-link');
    if (titleEl) titleEl.textContent = title || meta.title || 'Статистика';
    if (linkEl) {
        linkEl.href = href || meta.link || '#';
        linkEl.textContent = 'Открыть раздел';
    }
    renderDetailKpis(kind, lastSummary);
    renderDetailTable(kind, lastSummary);
    modal.classList.remove('hidden');
    requestAnimationFrame(() => modal.classList.add('active'));
    modal.setAttribute('aria-hidden', 'false');
    await updateDetailChart(kind);
    if (window.lucide) lucide.createIcons();
}

function bindDetailModal() {
    const modal = document.getElementById('dash-detail-modal');
    if (!modal) return;
    document.getElementById('dash-detail-close')?.addEventListener('click', closeDetailModal);
    document.getElementById('dash-detail-ok')?.addEventListener('click', closeDetailModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeDetailModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) closeDetailModal();
    });
}

function bindToolbar() {
    document.getElementById('dash-refresh')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        if (btn.disabled) return;
        btn.disabled = true;
        try {
            await refreshAll();
            window.showToast?.('Данные дашборда обновлены', 'success');
        } finally {
            btn.disabled = false;
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (openConfigWidget) closeConfigPanel(true);
        closeAddPanel();
    });
    document.getElementById('dash-edit')?.addEventListener('click', () => {
        setEditing(!editing);
        if (!editing) window.showToast?.('Раскладка сохранена', 'success');
    });
    document.getElementById('dash-reset')?.addEventListener('click', () => {
        closeConfigPanel(true);
        closeAddPanel();
        Object.keys(widgetConfigs).forEach((widgetId) => {
            destroyWidgetCharts(widgetId);
            const el = document.querySelector(`[data-widget="${widgetId}"]`);
            if (el && originalWidgetHtml[widgetId]) {
                el.innerHTML = originalWidgetHtml[widgetId];
                el.classList.remove('has-generic-config');
                if (window.lucide) lucide.createIcons();
            }
        });
        widgets().filter((el) => el.dataset.widget.startsWith('custom-')).forEach((el) => el.remove());
        widgetConfigs = {};
        Object.keys(originalWidgetHtml).forEach((k) => delete originalWidgetHtml[k]);
        saveWidgetConfigs();
        layout = structuredClone(DEFAULT_LAYOUT);
        saveLayout();
        applyLayout();
        refreshCharts();
        if (window.lucide) lucide.createIcons();
        window.showToast?.('Дашборд сброшен к виду по умолчанию', 'info');
    });
    document.getElementById('dash-add-widget')?.addEventListener('click', openAddPanel);
}

function applyExistingWidgetConfigs() {
    Object.entries(widgetConfigs).forEach(([widgetId, config]) => {
        const el = document.querySelector(`[data-widget="${widgetId}"]`);
        if (!el) return;
        if (lastSummary) renderGenericWidget(el, config, lastSummary);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    if (!elements.board) return;
    applyLayout();
    applyExistingWidgetConfigs();
    bindBoard();
    bindToolbar();
    bindDetailModal();
    initCharts();
    refreshAll();
    connectSse();
    setTimeout(() => {
        if (!sseConnected) startPollingFallback();
    }, 4000);
    window.addEventListener('resize', resizeCharts);
    window.addEventListener('pagehide', disconnectSse);
    if (window.lucide) lucide.createIcons();
});

window.refreshDashboard = refreshAll;
