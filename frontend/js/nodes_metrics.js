// JavaScript для страницы метрик нод
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = `${API_BASE}/nodes.php`;
let selectedNodeId = null;

async function loadNodes() {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    
    // Показываем индикатор загрузки
    select.disabled = true;
    select.innerHTML = '<option value="">Загрузка нод...</option>';
    
    try {
        const nodesRes = await fetch(API_URL, { credentials: 'include' });
        if (!nodesRes.ok) throw new Error(`HTTP ${nodesRes.status}`);
        const nodesText = await nodesRes.text();
        const nodesData = nodesText ? JSON.parse(nodesText) : {};
        const nodes = nodesData.nodes || [];
        populateNodeFilter(nodes);
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
    
    nodes.forEach(node => {
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
}

const loadMetrics = async (nodeId, silent = false) => {
    if (!nodeId) {
        showEmptyState();
        return;
    }
    
    try {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('metrics-tbody', true);
        }
        const res = await fetch(`${API_URL}?id=${nodeId}`, { credentials: 'include' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const text = await res.text();
        const data = text ? JSON.parse(text) : {};
        const node = data?.node || null;
        
        if (node) {
            document.getElementById('avg-cpu').textContent = (node.cpu_usage || 0).toFixed(1) + '%';
            document.getElementById('avg-ram').textContent = (node.memory_usage || 0).toFixed(1) + '%';
            document.getElementById('avg-disk').textContent = (node.disk_usage || 0).toFixed(1) + '%';
            document.getElementById('node-status').textContent = node.status || 'offline';
            
            renderMetricsTable(node);
            showMetricsData();
        } else {
            showEmptyState();
        }
    } catch (error) {
        console.error('Ошибка загрузки метрик:', error);
        showEmptyState();
    } finally {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('metrics-tbody', false);
        }
    }
};

const renderMetricsTable = (node) => {
    const tbody = document.getElementById('metrics-tbody');
    if (!tbody) return;
    
    const metrics = [
        { name: 'CPU', value: node.cpu_usage || 0, unit: '%', color: 'var(--accent)' },
        { name: 'RAM', value: node.memory_usage || 0, unit: '%', color: 'var(--info)' },
        { name: 'Диск', value: node.disk_usage || 0, unit: '%', color: 'var(--warning)' },
        { name: 'Сеть (входящая)', value: formatBytes(node.network_in || 0), unit: '', color: 'var(--success)' },
        { name: 'Сеть (исходящая)', value: formatBytes(node.network_out || 0), unit: '', color: 'var(--success)' },
    ];
    
    tbody.innerHTML = metrics.map(m => `
        <tr>
            <td><strong>${m.name}</strong></td>
            <td>${m.value}${m.unit}</td>
            <td>
                ${m.unit === '%' ? `<div class="progress-bar"><div style="width: ${m.value}%; background: ${m.color}"></div><span>${m.value}%</span></div>` : '-'}
            </td>
        </tr>
    `).join('');
    
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
    loadNodes();
    showEmptyState();
    
    document.getElementById('nodeFilter')?.addEventListener('change', (e) => {
        selectedNodeId = e.target.value;
        if (selectedNodeId) {
            loadMetrics(selectedNodeId);
        } else {
            showEmptyState();
        }
    });
    
    document.getElementById('refresh-metrics')?.addEventListener('click', () => {
        if (selectedNodeId) {
            loadMetrics(selectedNodeId);
        }
    });
    
    setInterval(() => {
        if (selectedNodeId) {
            loadMetrics(selectedNodeId, true);
        }
    }, 5000);
});

