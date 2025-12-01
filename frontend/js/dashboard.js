// JavaScript для дашборда
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;
let cpuChart = null;
let memoryChart = null;
const refreshIntervalMs = 5000;

const elements = {
    nodesCount: document.getElementById('nodes-count'),
    nodesTotal: document.getElementById('nodes-total'),
    processes: document.getElementById('processes-count'),
    containers: document.getElementById('containers-count'),
    cpuAvg: document.getElementById('cpu-avg'),
    nodesList: document.getElementById('nodes-list'),
    alertsList: document.getElementById('alerts-list'),
};

const setText = (el, value) => {
    if (el) {
        el.textContent = value;
    }
};

const fetchJson = async (path) => {
    try {
        // Определяем правильный путь к API
        let apiPath = path;
        if (path.startsWith('/summary')) {
            apiPath = `${API_BASE}/summary.php`;
        } else if (path.startsWith('/metrics')) {
            const query = path.substring('/metrics'.length);
            apiPath = `${API_BASE}/metrics.php${query}`;
        } else if (path.startsWith('/alerts')) {
            // Преобразуем /alerts/active в /api/alerts.php?action=active
            const rest = path.substring('/alerts'.length);
            if (rest.startsWith('/active')) {
                apiPath = `${API_BASE}/alerts.php?action=active`;
            } else {
                apiPath = `${API_BASE}/alerts.php${rest}`;
            }
        } else if (path.startsWith('/nodes')) {
            const query = path.substring('/nodes'.length);
            apiPath = `${API_BASE}/nodes.php${query}`;
        } else {
            apiPath = `${API_URL}${path}`;
        }
        
        const res = await fetch(apiPath, { credentials: 'include' });
        if (!res.ok) {
            throw new Error(`API error ${res.status}`);
        }
        const text = await res.text();
        return text ? JSON.parse(text) : {};
    } catch (error) {
        console.error('API недоступен:', error);
        throw error;
    }
};

const updateStats = (summary) => {
    if (!summary) return;
    setText(elements.nodesCount, summary.nodes_online ?? 0);
    setText(elements.nodesTotal, summary.nodes_total ?? 0);
    setText(elements.processes, summary.processes_active ?? 0);
    setText(elements.containers, summary.containers_running ?? 0);
    setText(elements.cpuAvg, `${summary.cpu_avg ?? 0}%`);
};

const renderList = (container, items, renderer, emptyText) => {
    if (!container) return;
    container.innerHTML = '';
    if (!items || items.length === 0) {
        container.innerHTML = `<li class="list-empty">${emptyText}</li>`;
        return;
    }
    items.forEach((item) => {
        container.appendChild(renderer(item));
    });
};

const renderNodeItem = (node) => {
    const li = document.createElement('li');
    li.className = 'list-item';
    li.innerHTML = `
        <div class="details">
            <span class="title">${node.name ?? node.host ?? '—'}</span>
            <span class="subtitle">${node.host ?? ''}</span>
        </div>
        <span class="pill status ${node.status ?? 'offline'}">${node.status ?? 'unknown'}</span>
    `;
    return li;
};

const renderAlertItem = (alert) => {
    const li = document.createElement('li');
    li.className = 'list-item';
    li.innerHTML = `
        <div class="details">
            <span class="title">${alert.title ?? 'Алерт'}</span>
            <span class="subtitle">${alert.node ?? '—'} · ${alert.timestamp ?? ''}</span>
        </div>
        <span class="pill">${alert.level ?? 'info'}</span>
    `;
    return li;
};

const initCharts = () => {
    if (!window.Chart) return;

    const baseOptions = {
        type: 'line',
        options: {
            responsive: true,
            animation: false,
            scales: {
                x: { ticks: { maxTicksLimit: 6 } },
                y: { beginAtZero: true },
            },
            plugins: {
                legend: { display: false },
            },
        },
    };

    const cpuCtx = document.getElementById('cpu-chart');
    const memCtx = document.getElementById('memory-chart');

    if (cpuCtx) {
        cpuChart = new Chart(cpuCtx, {
            ...baseOptions,
            data: {
                labels: [],
                datasets: [{
                    label: 'CPU %',
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,0.2)',
                    tension: 0.3,
                    data: [],
                }],
            },
        });
    }

    if (memCtx) {
        memoryChart = new Chart(memCtx, {
            ...baseOptions,
            data: {
                labels: [],
                datasets: [{
                    label: 'RAM %',
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.2)',
                    tension: 0.3,
                    data: [],
                }],
            },
        });
    }
};

const updateCharts = (points) => {
    // Проверяем что points - массив
    if (!points) return;
    let pointsArray = null;
    if (Array.isArray(points)) {
        pointsArray = points;
    } else if (points && typeof points === 'object') {
        if (Array.isArray(points.data)) {
            pointsArray = points.data;
        } else if (Array.isArray(points.metrics)) {
            pointsArray = points.metrics;
        }
    }
    if (!pointsArray || !Array.isArray(pointsArray) || pointsArray.length === 0) return;
    
    const labels = pointsArray.map((p) => new Date(p.ts || p.timestamp || Date.now()).toLocaleTimeString());
    if (cpuChart) {
        cpuChart.data.labels = labels;
        cpuChart.data.datasets[0].data = pointsArray.map((p) => p.cpu ?? 0);
        cpuChart.update('none');
    }
    if (memoryChart) {
        memoryChart.data.labels = labels;
        memoryChart.data.datasets[0].data = pointsArray.map((p) => p.ram ?? p.memory ?? 0);
        memoryChart.update('none');
    }
};

const refreshDashboard = async () => {
    try {
        const [summary, metrics, alerts, nodes] = await Promise.all([
            fetchJson('/summary').catch(() => null),
            fetchJson('/metrics?range=1h&group=5m').catch(() => null),
            fetchJson('/alerts/active').catch(() => null),
            fetchJson('/nodes?size=5').catch(() => null),
        ]);
        
        // Обработка summary
        if (summary) {
            const summaryData = summary?.data ?? summary;
            updateStats(summaryData);
        }
        
        // Обработка metrics - убеждаемся что это массив
        if (metrics) {
            let metricsData = null;
            if (Array.isArray(metrics)) {
                metricsData = metrics;
            } else if (metrics.data && Array.isArray(metrics.data)) {
                metricsData = metrics.data;
            } else if (metrics.metrics && Array.isArray(metrics.metrics)) {
                metricsData = metrics.metrics;
            }
            updateCharts(metricsData);
        }
        
        // Обработка nodes
        if (nodes) {
            const nodesData = nodes?.data ?? nodes?.nodes ?? [];
            renderList(elements.nodesList, Array.isArray(nodesData) ? nodesData : [], renderNodeItem, 'Нет данных о нодах');
        }
        
        // Обработка alerts
        if (alerts) {
            const alertsData = alerts?.data ?? alerts?.alerts ?? [];
            renderList(elements.alertsList, Array.isArray(alertsData) ? alertsData : [], renderAlertItem, 'Алертов нет');
        }
    } catch (error) {
        console.error('Dashboard update failed', error);
    } finally {
        setTimeout(refreshDashboard, refreshIntervalMs);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    if (!elements.nodesCount) return;
    initCharts();
    refreshDashboard();
});

