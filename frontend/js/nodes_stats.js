// JavaScript для страницы статистики нод
const API_BASE = window.MONITORING_API_BASE || '/api'; // базовый URL API
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
        document.getElementById('total-nodes').textContent = total; // выводим всего
        document.getElementById('online-nodes').textContent = online; // выводим онлайн
        document.getElementById('offline-nodes').textContent = offline; // выводим оффлайн
        document.getElementById('avg-load').textContent = avgCpu + '%'; // выводим средний CPU
        renderStatsTable(nodes); // рендерим таблицу
        renderCpuChart(nodes); // рендерим график CPU
        renderUsageChart(nodes); // рендерим график RAM/диск
    } catch (error) {
        console.error('Ошибка загрузки статистики:', error); // лог ошибки
        document.getElementById('total-nodes').textContent = '0'; // сброс значений
        document.getElementById('online-nodes').textContent = '0';
        document.getElementById('offline-nodes').textContent = '0';
        document.getElementById('avg-load').textContent = '0%';
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
            tbody.innerHTML = '<tr><td colspan="7" class="text-center">Нет данных</td></tr>';
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
        tr.innerHTML = `
            <td>${node.name || '-'}</td>
            <td><span class="status pill ${node.status || 'offline'}">${node.status || 'unknown'}</span></td>
            <td>${node.cpu_usage || 0}%</td>
            <td>${node.memory_usage || 0}%</td>
            <td>${node.disk_usage || 0}%</td>
            <td>${formatBytes(node.network_in || 0)} / ${formatBytes(node.network_out || 0)}</td>
            <td>${node.last_seen ? new Date(node.last_seen).toLocaleString('ru-RU') : '-'}</td>
        `;
    });
    
    if (typeof lucide !== 'undefined') lucide.createIcons(); // обновляем иконки если lucide есть
};

const formatBytes = (bytes) => { // форматирование байтов в читаемый вид
    if (!bytes) return '0 Б'; // если 0 байт
    const k = 1024; // основание
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ']; // единицы
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
