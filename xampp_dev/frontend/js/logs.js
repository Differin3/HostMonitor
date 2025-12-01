// Просмотр логов
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;
let logsPaused = false;
let autoScroll = true;
let logCounter = 0;
let selectedNodeId = null;

async function loadNodes() {
    try {
        const nodesRes = await fetch(`${API_BASE}/nodes.php`, { credentials: 'include' });
        if (!nodesRes.ok) throw new Error(`HTTP ${nodesRes.status}`);
        const nodesData = await nodesRes.json();
        const nodes = nodesData.nodes || [];
        populateNodeFilter(nodes);
    } catch (error) {
        console.error('Error loading nodes:', error);
        populateNodeFilter([]);
    }
}

function populateNodeFilter(nodes) {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    
    select.innerHTML = '<option value="">Выберите ноду</option>';
    nodes.forEach(node => {
        const option = document.createElement('option');
        option.value = node.id;
        option.textContent = node.name;
        select.appendChild(option);
    });
}

function showEmptyState() {
    document.getElementById('logs-empty').style.display = 'flex';
    document.getElementById('logs-container').style.display = 'none';
}

function showLogsContainer() {
    document.getElementById('logs-empty').style.display = 'none';
    document.getElementById('logs-container').style.display = 'block';
}

async function loadLogs(nodeId) {
    if (!nodeId) {
        showEmptyState();
        return;
    }
    
    if (logsPaused) return;
    
    try {
        const response = await fetch(`${API_BASE}/logs.php?node_id=${nodeId}`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { logs: [] };
        appendLogs(data.logs || []);
    } catch (error) {
        console.error('Error loading logs:', error);
    }
}

function applyFilters() {
    const levelFilter = document.getElementById('levelFilter')?.value || '';
    const searchTerm = document.getElementById('logSearch')?.value.toLowerCase() || '';
    
    const logs = document.querySelectorAll('.log-entry');
    logs.forEach(log => {
        const level = log.querySelector('.log-level')?.textContent.toLowerCase() || '';
        const message = log.querySelector('.log-message')?.textContent.toLowerCase() || '';
        
        let show = true;
        if (levelFilter && level !== levelFilter.toLowerCase()) show = false;
        if (searchTerm && !message.includes(searchTerm)) show = false;
        
        log.style.display = show ? 'flex' : 'none';
    });
}

function appendLogs(logs) {
    const container = document.getElementById('logs-content');
    if (!container) return;
    
    showLogsContainer();
    
    logs.forEach(log => {
        const entry = document.createElement('div');
        entry.className = 'log-entry';
        entry.innerHTML = `
            <span class="log-time">${new Date(log.timestamp || Date.now()).toLocaleTimeString()}</span>
            <span class="log-level ${log.level}">${log.level?.toUpperCase() || 'INFO'}</span>
            <span class="log-message">${escapeHtml(log.message)}</span>
        `;
        container.appendChild(entry);
    });
    
    if (autoScroll) {
        container.scrollTop = container.scrollHeight;
    }
    
    applyFilters();
}

function pauseLogs() {
    logsPaused = !logsPaused;
    const btn = document.getElementById('pauseBtn');
    btn.innerHTML = logsPaused 
        ? '<i data-lucide="play"></i> Продолжить'
        : '<i data-lucide="pause"></i> Пауза';
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function clearLogs() {
    if (!confirm('Очистить все логи?')) return;
    const container = document.getElementById('logs-content');
    if (container) container.innerHTML = '';
}

function exportLogs() {
    if (!selectedNodeId) {
        alert('Выберите ноду для экспорта логов');
        return;
    }
    // TODO: Экспорт логов
    console.log('Exporting logs for node', selectedNodeId);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.addEventListener('DOMContentLoaded', () => {
    loadNodes();
    showEmptyState();
    
    document.getElementById('nodeFilter')?.addEventListener('change', (e) => {
        selectedNodeId = e.target.value;
        const container = document.getElementById('logs-content');
        if (container) container.innerHTML = '';
        if (selectedNodeId) {
            loadLogs(selectedNodeId);
        } else {
            showEmptyState();
        }
    });
    
    document.getElementById('levelFilter')?.addEventListener('change', applyFilters);
    document.getElementById('logSearch')?.addEventListener('input', applyFilters);
    
    document.getElementById('autoScroll')?.addEventListener('change', (e) => {
        autoScroll = e.target.checked;
    });
    
    setInterval(() => {
        if (selectedNodeId && !logsPaused) {
            loadLogs(selectedNodeId);
        }
    }, 2000);
});

