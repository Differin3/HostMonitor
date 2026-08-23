const API_BASE = window.MONITORING_API_BASE || '/api';
const STORAGE_KEY = 'hm-dashboard-v1';
const RANGE_SECONDS = { '15m': 900, '1h': 3600, '6h': 21600, '24h': 86400 };
const RANGE_LABEL = { '15m': '15 мин', '1h': '1 ч', '6h': '6 ч', '24h': 'сутки' };
const DEFAULT_LAYOUT = {
    order: ['stat-nodes', 'stat-alerts', 'stat-cpu', 'stat-ram', 'chart-res', 'chart-net', 'list-nodes', 'list-alerts'],
    hidden: ['stat-disk', 'stat-proc', 'stat-ct', 'stat-db'],
    spans: {
        'stat-nodes': 3, 'stat-alerts': 3, 'stat-cpu': 3, 'stat-ram': 3,
        'stat-disk': 3, 'stat-proc': 3, 'stat-ct': 3, 'stat-db': 3,
        'chart-res': 6, 'chart-net': 6, 'list-nodes': 6, 'list-alerts': 6,
    },
    range: '1h',
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
    nodesList: document.getElementById('nodes-list'),
    alertsList: document.getElementById('alerts-list'),
};

let resChart = null;
let netChart = null;
let layout = loadLayout();
let editing = false;
let timers = [];
let sseSource = null;
let sseConnected = false;
const SSE_BASE = window.MONITORING_SSE_BASE || '/sse.php';

const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

const setText = (el, value) => {
    if (el) el.textContent = value;
};

const toneForPct = (value) => {
    const n = Number(value) || 0;
    if (n >= 90) return 'bad';
    if (n >= 75) return 'warn';
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
            range: RANGE_SECONDS[parsed.range] ? parsed.range : '1h',
        };
    } catch (err) {
        return structuredClone(DEFAULT_LAYOUT);
    }
}

function saveLayout() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(layout));
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
}

function paintRange() {
    document.querySelectorAll('#dash-ranges [data-range]').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.range === layout.range);
    });
    const label = RANGE_LABEL[layout.range] || layout.range;
    document.querySelectorAll('[data-range-label]').forEach((el) => {
        el.textContent = `${el.dataset.base || el.textContent} · ${label}`;
    });
}

function resizeCharts() {
    requestAnimationFrame(() => {
        resChart?.resize();
        netChart?.resize();
    });
}

function setEditing(on) {
    editing = on;
    elements.dashboard?.classList.toggle('is-editing', on);
    const editBtn = document.getElementById('dash-edit');
    const resetBtn = document.getElementById('dash-reset');
    const hint = document.getElementById('dash-edit-hint');
    if (editBtn) {
        editBtn.innerHTML = on
            ? '<i data-lucide="check"></i> Готово'
            : '<i data-lucide="sliders-horizontal"></i> Настроить';
    }
    if (resetBtn) resetBtn.hidden = !on;
    if (hint) {
        hint.textContent = on
            ? 'Перетаскивайте карточки, меняйте ширину S/M/L или скрывайте лишние.'
            : 'Клик по карточке открывает раздел. Настроить — порядок, размер и набор виджетов.';
    }
    widgets().forEach((el) => el.setAttribute('draggable', 'false'));
    renderCatalog();
    if (window.lucide) lucide.createIcons();
}

const fetchJson = async (path) => {
    let apiPath = path;
    if (path.startsWith('/summary')) apiPath = `${API_BASE}/summary.php`;
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

const updateStats = (summary) => {
    if (!summary) return;
    const online = summary.nodes_online ?? 0;
    const total = summary.nodes_total ?? 0;
    const alerts = summary.alerts_count ?? 0;
    const cpu = summary.cpu_avg ?? 0;
    const ram = summary.ram_avg ?? 0;
    const disk = summary.disk_avg ?? 0;
    const proc = summary.processes_active ?? 0;
    const ct = summary.containers_running ?? 0;
    const dbOnline = summary.databases_online ?? 0;
    const dbTotal = summary.databases_total ?? 0;

    setText(elements.nodesCount, online);
    setText(elements.nodesTotal, total);
    setText(elements.alertsCount, alerts);
    setText(elements.cpuAvg, `${cpu}%`);
    setText(elements.ramAvg, `${ram}%`);
    setText(elements.diskAvg, `${disk}%`);
    setText(elements.procCount, proc);
    setText(elements.ctCount, ct);
    setText(elements.dbCount, dbOnline);
    setText(elements.dbTotal, dbTotal);
    setMeter(elements.cpuMeter, cpu);
    setMeter(elements.ramMeter, ram);
    setMeter(elements.diskMeter, disk);

    setTone('stat-nodes', online === 0 && total > 0 ? 'bad' : (online < total ? 'warn' : 'ok'));
    setTone('stat-alerts', alerts === 0 ? 'ok' : (alerts >= 5 ? 'bad' : 'warn'));
    setTone('stat-cpu', toneForPct(cpu));
    setTone('stat-ram', toneForPct(ram));
    setTone('stat-disk', toneForPct(disk));
    setTone('stat-proc', proc > 0 ? 'ok' : 'warn');
    setTone('stat-ct', ct > 0 ? 'ok' : 'ok');
    setTone('stat-db', dbTotal === 0 ? 'warn' : (dbOnline < dbTotal ? 'bad' : 'ok'));
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
    const when = alert.timestamp ? new Date(alert.timestamp).toLocaleString('ru-RU', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' }) : '';
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

const colorFill = (hex, a = 0.14) => {
    const n = hex.replace('#', '');
    const r = parseInt(n.slice(0, 2), 16);
    const g = parseInt(n.slice(2, 4), 16);
    const b = parseInt(n.slice(4, 6), 16);
    return `rgba(${r},${g},${b},${a})`;
};

const initCharts = () => {
    if (!window.Chart) return;
    const resCtx = document.getElementById('res-chart');
    const netCtx = document.getElementById('net-chart');
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
    }
};

const asPoints = (payload) => {
    if (Array.isArray(payload)) return payload;
    if (payload && Array.isArray(payload.data)) return payload.data;
    if (payload && Array.isArray(payload.metrics)) return payload.metrics;
    return [];
};

const updateCharts = (payload) => {
    const points = asPoints(payload);
    if (!resChart && !netChart) return;
    if (!points.length) return;

    const dates = points.map((p) => new Date(p.ts || p.timestamp || Date.now()));
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
    const summary = payload.summary ?? payload;
    if (summary && (summary.cpu_avg != null || summary.nodes_total != null)) {
        updateStats(summary);
    }

    const nodesData = payload.nodes ?? payload.data ?? payload.nodes_list ?? [];
    const list = Array.isArray(nodesData) ? nodesData.slice() : [];
    list.sort((a, b) => {
        const aOff = a.status === 'online' ? 1 : 0;
        const bOff = b.status === 'online' ? 1 : 0;
        if (aOff !== bOff) return aOff - bOff;
        return (Number(b.cpu_usage) || 0) - (Number(a.cpu_usage) || 0);
    });
    renderList(elements.nodesList, list.slice(0, 6), renderNodeItem, 'Нет данных о нодах');

    const alertsData = payload.alerts ?? payload.alerts_list ?? [];
    renderList(elements.alertsList, Array.isArray(alertsData) ? alertsData.slice(0, 6) : [], renderAlertItem, 'Алертов нет');
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

const refreshCharts = async () => {
    const seconds = RANGE_SECONDS[layout.range] || 3600;
    const from = Math.floor(Date.now() / 1000) - seconds;
    const limit = layout.range === '24h' ? 160 : (layout.range === '6h' ? 120 : 80);
    const metrics = await fetchJson(`/metrics?from=${from}&limit=${limit}`).catch(() => null);
    if (metrics) updateCharts(metrics);
};

const refreshAll = async () => {
    await Promise.all([refreshOverview(), refreshCharts()]);
};

function stopPolling() {
    timers.forEach((id) => clearInterval(id));
    timers = [];
}

function startPollingFallback() {
    if (timers.length) return;
    timers.push(setInterval(refreshOverview, 8000));
    timers.push(setInterval(refreshCharts, 20000));
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
    const url = `${SSE_BASE}?range=${encodeURIComponent(layout.range)}&_=${Date.now()}`;
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
            if (payload?.range && payload.range !== layout.range) return;
            updateCharts(payload);
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
        widget.draggable = editing && !!e.target.closest('.dash-grip, .dash-widget-bar');
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
        const over = e.target.closest('[data-widget]');
        if (!over || over === dragged) return;
        const rect = over.getBoundingClientRect();
        const before = e.clientX < rect.left + rect.width / 2 || e.clientY < rect.top + rect.height / 2;
        elements.board.insertBefore(dragged, before ? over : over.nextSibling);
    });

    elements.dashboard.addEventListener('click', (e) => {
        const restore = e.target.closest('[data-restore]');
        if (restore) {
            e.preventDefault();
            layout.hidden = layout.hidden.filter((id) => id !== restore.dataset.restore);
            saveLayout();
            applyLayout();
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
            return;
        }
        if (editing) return;
        if (e.target.closest('a')) return;
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

function bindToolbar() {
    document.getElementById('dash-ranges')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-range]');
        if (!btn) return;
        layout.range = btn.dataset.range;
        saveLayout();
        paintRange();
        if (sseConnected) {
            connectSse();
        } else {
            refreshCharts();
        }
    });
    document.getElementById('dash-edit')?.addEventListener('click', () => {
        setEditing(!editing);
        if (!editing) window.showToast?.('Раскладка сохранена', 'success');
    });
    document.getElementById('dash-reset')?.addEventListener('click', () => {
        layout = structuredClone(DEFAULT_LAYOUT);
        saveLayout();
        applyLayout();
        refreshCharts();
        window.showToast?.('Дашборд сброшен к виду по умолчанию', 'info');
    });
    document.getElementById('dash-refresh')?.addEventListener('click', async () => {
        await refreshAll();
        window.showToast?.('Дашборд обновлён', 'success');
    });
}

document.addEventListener('DOMContentLoaded', () => {
    if (!elements.board) return;
    applyLayout();
    bindBoard();
    bindToolbar();
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
