// JavaScript для страницы статистики нод
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = `${API_BASE}/nodes.php`;

const loadStats = async () => {
    try {
        const res = await fetch(API_URL, { credentials: 'include' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        const nodes = data?.nodes ?? data?.data ?? [];
        
        const total = nodes.length;
        const online = nodes.filter(n => n.status === 'online').length;
        const offline = total - online;
        
        const avgCpu = nodes.length > 0 
            ? Math.round(nodes.reduce((sum, n) => sum + (parseFloat(n.cpu_usage) || 0), 0) / nodes.length)
            : 0;
        
        document.getElementById('total-nodes').textContent = total;
        document.getElementById('online-nodes').textContent = online;
        document.getElementById('offline-nodes').textContent = offline;
        document.getElementById('avg-load').textContent = avgCpu + '%';
        
        renderStatsTable(nodes);
    } catch (error) {
        console.error('Ошибка загрузки статистики:', error);
        document.getElementById('total-nodes').textContent = '0';
        document.getElementById('online-nodes').textContent = '0';
        document.getElementById('offline-nodes').textContent = '0';
        document.getElementById('avg-load').textContent = '0%';
        renderStatsTable([]);
    }
};

const renderStatsTable = (nodes) => {
    const tbody = document.getElementById('stats-tbody');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    if (nodes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center">Нет данных</td></tr>';
        return;
    }
    
    nodes.forEach(node => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${node.name || '-'}</td>
            <td><span class="status pill ${node.status || 'offline'}">${node.status || 'unknown'}</span></td>
            <td>${node.cpu_usage || 0}%</td>
            <td>${node.memory_usage || 0}%</td>
            <td>${node.disk_usage || 0}%</td>
            <td>${formatBytes(node.network_in || 0)} / ${formatBytes(node.network_out || 0)}</td>
            <td>${node.last_seen ? new Date(node.last_seen).toLocaleString('ru-RU') : '-'}</td>
        `;
        tbody.appendChild(tr);
    });
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
};

const formatBytes = (bytes) => {
    if (!bytes) return '0 Б';
    const k = 1024;
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

document.addEventListener('DOMContentLoaded', () => {
    loadStats();
    setInterval(loadStats, 10000);
});

