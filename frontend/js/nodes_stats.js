// JavaScript для страницы статистики нод
const API_URL = `${API_BASE}/nodes.php`; // эндпоинт статистики нод

let nodesCpuChart = null; // график CPU по нодам
let nodesUsageChart = null; // график RAM/диск

const loadStats = async (silent = false) => { // загрузка агрегированной статистики, таблицы и графиков
    try {
        if (!silent && window.toggleTableLoader) window.toggleTableLoader('stats-tbody', true); // показываем лоадер только при ручной загрузке
        const res = await fetch(API_URL, { credentials: 'include' }); // запрос к API
        if (!res.ok) throw new Error(`HTTP ${res.status}`); // проверка статуса
        const text = await res.text(); // получаем текст ответа
        const data = text ? JSON.parse(text) : {}; // парсим JSON безопасно
        const nodes = data?.nodes ?? data?.data ?? []; // массив нод
        const total = nodes.length; // всего нод
        const online = nodes.filter(n => n.status === 'online').length; // онлайн ноды
        const offline = total - online; // оффлайн ноды
        const avgCpu = nodes.length > 0 // средняя загрузка CPU
            ? Math.round(nodes.reduce((sum, n) => sum + (parseFloat(n.cpu_usage) || 0), 0) / nodes.length)
            : 0;
        const avgRam = nodes.length > 0
            ? Math.round(nodes.reduce((sum, n) => sum + (parseFloat(n.memory_usage) || 0), 0) / nodes.length)
            : 0;
        const avgDisk = nodes.length > 0
            ? Math.round(nodes.reduce((sum, n) => sum + (parseFloat(n.disk_usage) || 0), 0) / nodes.length)
            : 0;
        const totalTraffic = nodes.reduce((sum, n) => sum + (parseFloat(n.network_in_total) || 0) + (parseFloat(n.network_out_total) || 0), 0);
        document.getElementById('total-nodes').textContent = total;
        document.getElementById('online-nodes').textContent = online;
        document.getElementById('offline-nodes').textContent = offline;
        document.getElementById('avg-load').textContent = avgCpu + '%';
        document.getElementById('avg-ram-stat').textContent = avgRam + '%';
        document.getElementById('avg-disk-stat').textContent = avgDisk + '%';
        document.getElementById('traffic-total-stat').textContent = formatBytes(totalTraffic);
        const onlineCard = document.getElementById('stat-online');
        const offlineCard = document.getElementById('stat-offline');
        const loadCard = document.getElementById('stat-load');
        const ramCard = document.getElementById('stat-ram');
        const diskCard = document.getElementById('stat-disk');
        if (onlineCard) onlineCard.dataset.tone = (offline > 0 && online === 0) ? 'bad' : 'ok';
        if (offlineCard) offlineCard.dataset.tone = offline === 0 ? 'ok' : (offline > 2 ? 'bad' : 'warn');
        if (loadCard) loadCard.dataset.tone = avgCpu >= 90 ? 'bad' : (avgCpu >= 75 ? 'warn' : 'ok');
        if (ramCard) ramCard.dataset.tone = avgRam >= 90 ? 'bad' : (avgRam >= 75 ? 'warn' : 'ok');
        if (diskCard) diskCard.dataset.tone = avgDisk >= 90 ? 'bad' : (avgDisk >= 75 ? 'warn' : 'ok');
        renderStatsTable(nodes);
    } catch (error) {
        console.error('Ошибка загрузки статистики:', error); // лог ошибки
        document.getElementById('total-nodes').textContent = '0'; // сброс значений
        document.getElementById('online-nodes').textContent = '0';
        document.getElementById('offline-nodes').textContent = '0';
        document.getElementById('avg-load').textContent = '0%';
        document.getElementById('avg-ram-stat').textContent = '0%';
        document.getElementById('avg-disk-stat').textContent = '0%';
        document.getElementById('traffic-total-stat').textContent = '0 Б';
        renderStatsTable([]); // очищаем таблицу
    } finally {
        if (!silent && window.toggleTableLoader) window.toggleTableLoader('stats-tbody', false); // скрываем лоадер
    }
};

const renderStatsTable = (nodes) => { // рендер таблицы по нодам
    const tbody = document.getElementById('stats-tbody'); // тело таблицы
    if (!tbody) return; // выходим если таблицы нет
    
    // Оптимизация: обновляем только измененные строки
    const existingRows = tbody.querySelectorAll('tr[data-node-id]');
    const existingIds = new Set(Array.from(existingRows).map(tr => tr.dataset.nodeId));
    const newIds = new Set(nodes.map(n => String(n.id)));
    
    // Удаляем строки, которых больше нет
    existingRows.forEach(tr => {
        if (!newIds.has(tr.dataset.nodeId)) {
            tr.remove();
        }
    });
    
        if (nodes.length === 0) { // если данных нет
        if (tbody.children.length === 0 || !tbody.querySelector('.text-center')) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center">Нет данных</td></tr>';
        }
        return; // выходим
    }
    
    // Удаляем заглушку "Нет данных" если она есть
    const emptyRow = tbody.querySelector('.text-center');
    if (emptyRow) emptyRow.remove();
    
    nodes.forEach(node => { // проходим по нодам
        const nodeId = String(node.id);
        let tr = tbody.querySelector(`tr[data-node-id="${nodeId}"]`);
        
        if (!tr) {
            // Создаем новую строку только если её нет
            tr = document.createElement('tr');
            tr.dataset.nodeId = nodeId;
            tbody.appendChild(tr);
        }
        
        // Обновляем содержимое строки
        const cpu = parseFloat(node.cpu_usage) || 0;
        const ram = parseFloat(node.memory_usage) || 0;
        const disk = parseFloat(node.disk_usage) || 0;
        const swap = parseFloat(node.swap_percent) || 0;
        const cumulative = (parseFloat(node.network_in_total) || 0) + (parseFloat(node.network_out_total) || 0);
        const tone = (v) => v >= 90 ? 'bad' : (v >= 75 ? 'warn' : 'ok');
        const meter = (v, kind) => `<div class="hm-meter hm-meter-${kind}" data-tone="${tone(v)}"><span style="width:${Math.min(100, v)}%"></span></div><small class="meter-label">${v.toFixed(0)}%</small>`;
        // Платформа
        const platformBadges = [];
        if (node.is_truenas) platformBadges.push('<span class="status pill" style="background:#1e40af;color:#fff;font-size:10px;">NAS</span>');
        else if (node.is_proxmox) platformBadges.push('<span class="status pill" style="background:#059669;color:#fff;font-size:10px;">PVE</span>');
        else if (node.is_synology) platformBadges.push('<span class="status pill" style="background:#7c3aed;color:#fff;font-size:10px;">DSM</span>');
        if (node.is_freebsd) platformBadges.push('<span class="status pill" style="background:#dc2626;color:#fff;font-size:10px;">BSD</span>');
        if (node.has_zfs) platformBadges.push('<span class="status pill" style="background:#0891b2;color:#fff;font-size:10px;">ZFS</span>');
        const platformHtml = platformBadges.length ? ' ' + platformBadges.join(' ') : '';
        const osInfo = [node.os_name, node.arch, node.kernel].filter(Boolean).join(' · ');
        tr.innerHTML = `
            <td><span title="${esc(osInfo)}">${esc(node.name || '-')}${platformHtml}</span></td>
            <td><span class="status pill ${esc(node.status || 'offline')}">${esc(node.status || 'unknown')}</span></td>
            <td class="meter-cell">${meter(cpu, 'cpu')}</td>
            <td class="meter-cell">${meter(ram, 'ram')}</td>
            <td class="meter-cell">${meter(disk, 'disk')}</td>
            <td>${formatBytes(node.network_in || 0)}/с / ${formatBytes(node.network_out || 0)}/с</td>
            <td class="meter-cell">${meter(swap, 'swap')}</td>
            <td>${cumulative > 0 ? formatBytes(cumulative) : '—'}</td>
        `;
    });
    
    if (typeof lucide !== 'undefined') lucide.createIcons(); // обновляем иконки если lucide есть
};

const formatBytes = (bytes) => { // форматирование байтов в читаемый вид
    if (!bytes) return '0 Б'; // если 0 байт
    const k = 1024; // основание
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ', 'ПБ']; // единицы
    const i = Math.floor(Math.log(bytes) / Math.log(k)); // индекс единицы
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]; // возвращаем строку
};

const renderCpuChart = (nodes) => { // график CPU по нодам (bar)
    const ctx = document.getElementById('nodes-cpu-chart'); // холст
    if (!ctx || typeof Chart === 'undefined') return; // выходим если нет Chart.js
    const labels = nodes.map(n => n.name || `node-${n.id}`); // имена нод
    const data = nodes.map(n => parseFloat(n.cpu_usage) || 0); // CPU
    if (nodesCpuChart) nodesCpuChart.destroy(); // пересоздаём график
    nodesCpuChart = new Chart(ctx, { // создаём bar chart
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'CPU %',
                data,
                backgroundColor: 'rgba(59,130,246,0.7)',
                borderRadius: 6,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, max: 100 },
            },
        },
    });
};

const renderUsageChart = (nodes) => { // комбинированный график RAM/диск
    const ctx = document.getElementById('nodes-usage-chart'); // холст
    if (!ctx || typeof Chart === 'undefined') return; // нет графика
    const labels = nodes.map(n => n.name || `node-${n.id}`); // имена
    const ram = nodes.map(n => parseFloat(n.memory_usage) || 0); // RAM
    const disk = nodes.map(n => parseFloat(n.disk_usage) || 0); // диск
    if (nodesUsageChart) nodesUsageChart.destroy(); // пересоздаём
    nodesUsageChart = new Chart(ctx, { // создаём stacked bar
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'RAM %',
                    data: ram,
                    backgroundColor: 'rgba(16,185,129,0.7)',
                    borderRadius: 6,
                },
                {
                    label: 'Диск %',
                    data: disk,
                    backgroundColor: 'rgba(234,179,8,0.7)',
                    borderRadius: 6,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { labels: { color: '#e5e7eb', font: { size: 11 } } } },
            scales: {
                x: { stacked: true, ticks: { color: '#9ca3af', font: { size: 10 } } },
                y: { stacked: true, beginAtZero: true, max: 100, ticks: { color: '#9ca3af' } },
            },
        },
    });
};

document.addEventListener('DOMContentLoaded', () => { // инициализация после загрузки DOM
    loadStats(); // первая загрузка с анимацией
    setInterval(() => loadStats(true), 30000); // автообновление каждые 30 секунд без анимации
});
