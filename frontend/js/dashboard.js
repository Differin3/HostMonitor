// JavaScript для дашборда
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;
let cpuChart = null;
let memoryChart = null;
let diskChart = null; // график диска
let netChart = null; // график суммарного сетевого трафика
const refreshIntervalMs = 5000;

const elements = {
    nodesCount: document.getElementById('nodes-count'),
    nodesTotal: document.getElementById('nodes-total'),
    processes: document.getElementById('processes-count'),
    containers: document.getElementById('containers-count'),
    cpuAvg: document.getElementById('cpu-avg'),
    uptimeAvg: document.getElementById('uptime-avg'),
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

const formatUptime = (seconds) => {
    if (!seconds || seconds === 0) return '-';
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    if (days > 0) return `${days}д ${hours}ч`;
    if (hours > 0) return `${hours}ч ${minutes}м`;
    return `${minutes}м`;
};

const updateStats = (summary) => {
    if (!summary) return;
    setText(elements.nodesCount, summary.nodes_online ?? 0);
    setText(elements.nodesTotal, summary.nodes_total ?? 0);
    setText(elements.processes, summary.processes_active ?? 0);
    setText(elements.containers, summary.containers_running ?? 0);
    setText(elements.cpuAvg, `${summary.cpu_avg ?? 0}%`);
    setText(elements.uptimeAvg, formatUptime(summary.uptime_avg ?? 0));
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
    const uptime = formatUptime(node.uptime ?? 0);
    li.innerHTML = `
        <div class="details">
            <span class="title">${node.name ?? node.host ?? '—'}</span>
            <span class="subtitle">${node.host ?? ''} · Uptime: ${uptime}</span>
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
    const diskCtx = document.getElementById('disk-chart'); // холст диска
    const netCtx = document.getElementById('net-chart'); // холст сети

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

    if (diskCtx) {
        diskChart = new Chart(diskCtx, {
            ...baseOptions,
            data: {
                labels: [],
                datasets: [{
                    label: 'Disk %',
                    borderColor: '#eab308',
                    backgroundColor: 'rgba(234,179,8,0.2)',
                    tension: 0.3,
                    data: [],
                }],
            },
        });
    }

    if (netCtx) {
        netChart = new Chart(netCtx, {
            ...baseOptions,
            data: {
                labels: [],
                datasets: [{
                    label: 'Сеть (in+out)',
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249,115,22,0.2)',
                    tension: 0.3,
                    data: [],
                }],
            },
        });
    }
};

const updateCharts = (points) => {
    if (!points) return; // нет данных
    let pointsArray = null; // нормализуем массив
    if (Array.isArray(points)) {
        pointsArray = points;
    } else if (points && typeof points === 'object') {
        if (Array.isArray(points.data)) {
            pointsArray = points.data;
        } else if (Array.isArray(points.metrics)) {
            pointsArray = points.metrics;
        }
    }
    if (!pointsArray || !Array.isArray(pointsArray) || pointsArray.length === 0) {
        [cpuChart, memoryChart, diskChart, netChart].forEach(chart => { // очищаем графики
            if (chart) {
                chart.data.labels = [];
                chart.data.datasets.forEach(ds => ds.data = []);
                chart.update('none');
            }
        });
        return;
    }
    
    const dates = pointsArray.map((p) => new Date(p.ts || p.timestamp || Date.now())); // даты для оси X
    const spanMs = dates.length > 1 ? dates[dates.length - 1] - dates[0] : 0; // длительность
    const spanHours = spanMs / 36e5;
    const formatLabel = (d) => { // адаптивные подписи оси X
        if (!d || isNaN(d.getTime())) return '';
        if (spanHours <= 24) return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        if (spanHours <= 24 * 7) return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
    };
    const labels = dates.map(formatLabel);
    const cpuValues = pointsArray.map((p) => p.cpu ?? 0);
    const ramValues = pointsArray.map((p) => p.ram ?? p.memory ?? 0);
    const diskValues = pointsArray.map((p) => p.disk ?? 0);
    const netValues = pointsArray.map((p) => (p.network_in ?? 0) + (p.network_out ?? 0));

    if (cpuChart) {
        cpuChart.data.labels = labels;
        cpuChart.data.datasets[0].data = cpuValues;
        cpuChart.update('none');
    }
    if (memoryChart) {
        memoryChart.data.labels = labels;
        memoryChart.data.datasets[0].data = ramValues;
        memoryChart.update('none');
    }
    if (diskChart) {
        diskChart.data.labels = labels;
        diskChart.data.datasets[0].data = diskValues;
        diskChart.update('none');
    }
    if (netChart) {
        netChart.data.labels = labels;
        netChart.data.datasets[0].data = netValues;
        netChart.update('none');
    }
};

const initDashboardChartsLayout = () => { // d&d + разворачивание графиков дашборда
    const container = document.querySelector('.charts-dashboard');
    if (!container) return;
    let draggedCard = null;
    container.querySelectorAll('.chart-card').forEach(card => {
        card.setAttribute('draggable', 'true');
        card.addEventListener('dragstart', (e) => {
            draggedCard = card;
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        card.addEventListener('dragend', () => {
            if (draggedCard) draggedCard.classList.remove('dragging');
            draggedCard = null;
        });
        card.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (!draggedCard || draggedCard === card) return;
            const rect = card.getBoundingClientRect();
            const before = e.clientY < rect.top + rect.height / 2;
            if (before) {
                container.insertBefore(draggedCard, card);
            } else {
                container.insertBefore(draggedCard, card.nextSibling);
            }
        });
        const toggleMax = () => { card.classList.toggle('maximized'); };
        const btn = card.querySelector('.chart-expand-btn');
        if (btn) {
            btn.addEventListener('click', (e) => { e.stopPropagation(); toggleMax(); });
        }
    });
};

const refreshDashboard = async () => {
    try {
        const [summary, metrics, alerts, nodes] = await Promise.all([
            fetchJson('/summary').catch((e) => { console.error('Summary error:', e); return null; }),
            fetchJson('/metrics?range=1h').catch((e) => { console.error('Metrics error:', e); return null; }),
            fetchJson('/alerts/active').catch((e) => { console.error('Alerts error:', e); return null; }),
            fetchJson('/nodes').catch((e) => { console.error('Nodes error:', e); return null; }),
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
            if (metricsData && metricsData.length > 0) {
                updateCharts(metricsData);
            } else {
                // Если данных нет, показываем пустые графики
                if (cpuChart) {
                    cpuChart.data.labels = [];
                    cpuChart.data.datasets[0].data = [];
                    cpuChart.update('none');
                }
                if (memoryChart) {
                    memoryChart.data.labels = [];
                    memoryChart.data.datasets[0].data = [];
                    memoryChart.update('none');
                }
            }
        }
        
        // Обработка nodes
        if (nodes) {
            const nodesData = nodes?.data ?? nodes?.nodes ?? [];
            renderList(elements.nodesList, Array.isArray(nodesData) ? nodesData.slice(0, 5) : [], renderNodeItem, 'Нет данных о нодах');
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
    initDashboardChartsLayout();
    refreshDashboard();
});

