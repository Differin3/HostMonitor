// Просмотр логов
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;
let logsPaused = false;
let autoScroll = true;
let logCounter = 0;
let selectedNodeId = null;

async function loadNodes() {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    
    // Показываем индикатор загрузки
    select.disabled = true;
    select.innerHTML = '<option value="">Загрузка нод...</option>';
    
    try {
        const nodesRes = await fetch(`${API_BASE}/nodes.php`, { credentials: 'include' });
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
    document.getElementById('logs-empty').style.display = 'flex';
    document.getElementById('logs-container').style.display = 'none';
}

function showLogsContainer() {
    document.getElementById('logs-empty').style.display = 'none';
    document.getElementById('logs-container').style.display = 'block';
}

async function loadLogs(nodeId, silent = false) {
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

async function clearLogs() {
    const confirmed = await window.showConfirm('Вы уверены, что хотите очистить все логи? Это действие нельзя отменить.', 'Очистка логов', 'warning');
    if (!confirmed) return;
    const container = document.getElementById('logs-content');
    if (container) container.innerHTML = '';
}

async function exportLogs() {
    if (!selectedNodeId) {
        showToast('Выберите ноду для экспорта логов', 'warning');
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}/logs.php?node_id=${selectedNodeId}&limit=10000`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { logs: [] };
        const logs = data.logs || [];
        
        if (logs.length === 0) {
            showToast('Нет логов для экспорта', 'info');
            return;
        }
        
        // Получаем имя ноды
        const nodesRes = await fetch(`${API_BASE}/nodes.php`, { credentials: 'include' });
        const nodesText = await nodesRes.text();
        const nodesData = nodesText ? JSON.parse(nodesText) : {};
        const node = (nodesData.nodes || []).find(n => n.id == selectedNodeId);
        const nodeName = node?.name || `node-${selectedNodeId}`;
        
        // Формируем содержимое файла
        let content = `# Системные логи ноды: ${nodeName}\n`;
        content += `# Дата экспорта: ${new Date().toLocaleString('ru-RU')}\n`;
        content += `# Всего записей: ${logs.length}\n\n`;
        
        logs.forEach(log => {
            const timestamp = new Date(log.timestamp || Date.now()).toLocaleString('ru-RU');
            const level = (log.level || 'INFO').toUpperCase();
            const message = log.message || log.text || '';
            content += `[${timestamp}] [${level}] ${message}\n`;
        });
        
        // Создаем и скачиваем файл
        const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `logs-${nodeName}-${new Date().toISOString().split('T')[0]}.txt`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showToast(`Экспортировано ${logs.length} записей`, 'success');
    } catch (error) {
        console.error('Error exporting logs:', error);
        showToast('Ошибка экспорта логов', 'error');
    }
}

function showToast(message, type = 'info') {
    if (window.showToast) {
        window.showToast(message, type);
    } else {
        // Fallback если window.showToast еще не загружен
        alert(message);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

let currentLogsTab = 'system';

function switchLogsTab(tab) {
    currentLogsTab = tab;
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    document.getElementById('system-logs-tab')?.classList.toggle('active', tab === 'system');
    document.getElementById('auth-logs-tab')?.classList.toggle('active', tab === 'auth');
    
    if (tab === 'auth') {
        loadAuthLogs();
    }
}

window.switchLogsTab = switchLogsTab;

let authLogsCache = [];

async function loadAuthLogs(eventType = '') {
    try {
        const url = eventType ? `${API_BASE}/logs.php?type=auth&event_type=${eventType}` : `${API_BASE}/logs.php?type=auth`;
        const response = await fetch(url, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { logs: [] };
        authLogsCache = data.logs || [];
        renderAuthLogs(authLogsCache);
    } catch (error) {
        console.error('Error loading auth logs:', error);
        const tbody = document.getElementById('auth-logs-tbody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Ошибка загрузки</td></tr>';
        }
    }
}

function filterAuthLogs(searchTerm) {
    const filtered = authLogsCache.filter(log => {
        if (!searchTerm) return true;
        const username = (log.username || '').toLowerCase();
        const ip = (log.ip_address || '').toLowerCase();
        const message = (log.message || '').toLowerCase();
        return username.includes(searchTerm) || ip.includes(searchTerm) || message.includes(searchTerm);
    });
    renderAuthLogs(filtered);
}

function renderAuthLogs(logs) {
    const tbody = document.getElementById('auth-logs-tbody');
    if (!tbody) return;
    
    if (logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">Логи авторизации не найдены</td></tr>';
        return;
    }
    
    tbody.innerHTML = logs.map(log => {
        const eventType = log.event_type || 'unknown';
        const success = log.success;
        const eventTypeText = {
            'login': 'Вход',
            'logout': 'Выход',
            'failed': 'Неудачная попытка'
        }[eventType] || eventType;
        
        return `
            <tr>
                <td>${log.timestamp ? new Date(log.timestamp).toLocaleString('ru-RU') : '-'}</td>
                <td>${log.username || '-'}</td>
                <td>${log.ip_address || '-'}</td>
                <td><span class="status pill ${eventType}">${eventTypeText}</span></td>
                <td><span class="status ${success ? 'status-online' : 'status-offline'}">${success ? 'Успешно' : 'Ошибка'}</span></td>
                <td>${log.message || '-'}</td>
            </tr>
        `;
    }).join('');
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
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
    
    // Фильтры для логов авторизации
    document.getElementById('authEventFilter')?.addEventListener('change', (e) => {
        const eventType = e.target.value;
        loadAuthLogs(eventType);
    });
    document.getElementById('authLogSearch')?.addEventListener('input', (e) => {
        const searchTerm = e.target.value.toLowerCase();
        filterAuthLogs(searchTerm);
    });
    
    setInterval(() => {
        if (selectedNodeId && !logsPaused && currentLogsTab === 'system') {
            loadLogs(selectedNodeId, true);
        } else if (currentLogsTab === 'auth') {
            const eventType = document.getElementById('authEventFilter')?.value || '';
            loadAuthLogs(eventType);
        }
    }, 2000);
});

