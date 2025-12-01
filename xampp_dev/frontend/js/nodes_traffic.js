// JavaScript для страницы расхода трафика
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = `${API_BASE}/nodes.php`;

const loadTraffic = async () => {
    try {
        const res = await fetch(API_URL, { credentials: 'include' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        const nodes = data?.nodes ?? data?.data ?? [];
        
        let totalDownload = 0;
        let totalUpload = 0;
        
        nodes.forEach(node => {
            totalDownload += parseFloat(node.network_in || 0);
            totalUpload += parseFloat(node.network_out || 0);
        });
        
        document.getElementById('total-download').textContent = formatBytes(totalDownload);
        document.getElementById('total-upload').textContent = formatBytes(totalUpload);
        document.getElementById('avg-speed').textContent = '0 Мбит/с';
        document.getElementById('active-nodes').textContent = nodes.filter(n => n.status === 'online').length;
        
        renderTrafficTable(nodes);
    } catch (error) {
        console.error('Ошибка загрузки трафика:', error);
        document.getElementById('total-download').textContent = '0 Б';
        document.getElementById('total-upload').textContent = '0 Б';
        document.getElementById('avg-speed').textContent = '0 Мбит/с';
        document.getElementById('active-nodes').textContent = '0';
        renderTrafficTable([]);
    }
};

const renderTrafficTable = (nodes) => {
    const tbody = document.getElementById('traffic-tbody');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    if (nodes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">Нет данных</td></tr>';
        return;
    }
    
    nodes.forEach(node => {
        const download = parseFloat(node.network_in || 0);
        const upload = parseFloat(node.network_out || 0);
        const total = download + upload;
        
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${node.name || '-'}</td>
            <td>${formatBytes(download)}</td>
            <td>${formatBytes(upload)}</td>
            <td>${formatBytes(total)}</td>
            <td>0 Мбит/с</td>
            <td><div class="traffic-bar"><div style="width: ${Math.min(100, (total / 1024 / 1024 / 1024) * 10)}%"></div></div></td>
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
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

document.addEventListener('DOMContentLoaded', () => {
    loadTraffic();
    setInterval(loadTraffic, 10000);
    
    document.getElementById('traffic-period')?.addEventListener('change', (e) => {
        loadTraffic();
    });
});

