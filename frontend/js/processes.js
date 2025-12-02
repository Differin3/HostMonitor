// Управление процессами
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;
let allProcesses = [];
let filteredProcesses = [];
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

async function loadProcesses(nodeId, silent = false) {
    if (!nodeId) {
        showEmptyState();
        return;
    }
    
    try {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('processes-tbody', true);
        }
        const processesRes = await fetch(`${API_BASE}/processes.php?node_id=${nodeId}`, { credentials: 'include' });
        if (!processesRes.ok) throw new Error(`HTTP ${processesRes.status}`);
        const text = await processesRes.text();
        const processesData = text ? JSON.parse(text) : { processes: [] };
        allProcesses = processesData.processes || [];
        applyFilters();
    } catch (error) {
        console.error('Error loading processes:', error);
        allProcesses = [];
        applyFilters();
    } finally {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('processes-tbody', false);
        }
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
    document.getElementById('processes-empty').style.display = 'flex';
    document.getElementById('processes-table').style.display = 'none';
}

function showProcessesTable() {
    document.getElementById('processes-empty').style.display = 'none';
    document.getElementById('processes-table').style.display = 'block';
}

function applyFilters() {
    const statusFilter = document.getElementById('statusFilter')?.value || '';
    const searchTerm = document.getElementById('processSearch')?.value.toLowerCase() || '';
    
    filteredProcesses = allProcesses.filter(p => {
        if (statusFilter && p.status !== statusFilter) return false;
        if (searchTerm && !p.name.toLowerCase().includes(searchTerm)) return false;
        return true;
    });
    
    renderProcesses(filteredProcesses);
}

function renderProcesses(processes) {
    const tbody = document.getElementById('processes-tbody');
    if (!tbody) return;
    
    if (processes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">Процессы не найдены</td></tr>';
        showProcessesTable();
        return;
    }
    
    // Сортируем по использованию CPU (по убыванию)
    processes.sort((a, b) => (b.cpu_percent || 0) - (a.cpu_percent || 0));
    
    tbody.innerHTML = processes.map(p => `
        <tr>
            <td>${p.pid}</td>
            <td><strong>${p.name}</strong></td>
            <td>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${Math.min(p.cpu_percent || 0, 100)}%; background: ${getCpuColor(p.cpu_percent || 0)};"></div>
                    <span class="progress-text">${p.cpu_percent?.toFixed(1) || 0}%</span>
                </div>
            </td>
            <td>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${Math.min(p.memory_percent || 0, 100)}%; background: ${getMemoryColor(p.memory_percent || 0)};"></div>
                    <span class="progress-text">${p.memory_percent?.toFixed(1) || 0}%</span>
                </div>
            </td>
            <td><span class="status ${p.status}">${p.status}</span></td>
            <td>
                <button onclick="viewProcessLog(${p.pid}, ${selectedNodeId})" class="btn-outline" title="Лог процесса">
                    <i data-lucide="file-text"></i>
                </button>
                <button onclick="killProcess(${p.pid}, ${selectedNodeId})" class="btn-danger" title="Завершить">
                    <i data-lucide="x"></i>
                </button>
            </td>
        </tr>
    `).join('');
    
    showProcessesTable();
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function getCpuColor(percent) {
    if (percent > 80) return 'var(--danger)';
    if (percent > 50) return 'var(--warning)';
    return 'var(--success)';
}

function getMemoryColor(percent) {
    if (percent > 80) return 'var(--danger)';
    if (percent > 50) return 'var(--warning)';
    return 'var(--info)';
}

function refreshProcesses() {
    if (selectedNodeId) {
        loadProcesses(selectedNodeId);
    }
}

let processLogsPaused = false;
let currentProcessTab = 'processes';

function switchProcessTab(tab) {
    currentProcessTab = tab;
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    document.querySelectorAll('.processes-tabs .tab-content').forEach(content => {
        content.classList.toggle('active', content.id === (tab === 'logs' ? 'processes-logs-tab' : 'processes-tab'));
    });
    
    if (tab === 'logs' && selectedNodeId) {
        loadProcessLogs(selectedNodeId);
    }
}

async function loadProcessLogs(nodeId) {
    if (!nodeId) {
        document.getElementById('processes-logs-empty').style.display = 'flex';
        document.getElementById('processes-logs-container').style.display = 'none';
        return;
    }
    
    document.getElementById('processes-logs-empty').style.display = 'none';
    document.getElementById('processes-logs-container').style.display = 'block';
    
    if (processLogsPaused) return;
    
    try {
        const response = await fetch(`${API_BASE}/logs.php?node_id=${nodeId}&type=processes`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { logs: [] };
        appendProcessLogs(data.logs || []);
    } catch (error) {
        console.error('Error loading process logs:', error);
    }
}

function appendProcessLogs(logs) {
    const container = document.getElementById('processes-logs-content');
    if (!container) return;
    
    // Фильтруем логи по PID если указан
    const currentPid = window.currentProcessPid;
    let filteredLogs = logs;
    if (currentPid) {
        filteredLogs = logs.filter(log => {
            const logPid = log.pid || log.process_id;
            return logPid == currentPid || (log.message && log.message.includes(`pid=${currentPid}`));
        });
    }
    
    filteredLogs.forEach(log => {
        const entry = document.createElement('div');
        entry.className = 'log-entry';
        entry.innerHTML = `
            <span class="log-time">${new Date(log.timestamp || Date.now()).toLocaleTimeString('ru-RU')}</span>
            <span class="log-level ${log.level || 'info'}">${(log.level || 'info').toUpperCase()}</span>
            <span class="log-source">${log.process || 'system'}</span>
            <span class="log-message">${log.message || log.text || ''}</span>
        `;
        container.appendChild(entry);
    });
    
    const autoScroll = document.getElementById('autoScrollProcessLogs');
    if (autoScroll && autoScroll.checked) {
        container.scrollTop = container.scrollHeight;
    }
}

function pauseProcessLogs() {
    processLogsPaused = !processLogsPaused;
    const btn = document.getElementById('pauseProcessLogsBtn');
    if (btn) {
        btn.innerHTML = processLogsPaused 
            ? '<i data-lucide="play"></i> Продолжить'
            : '<i data-lucide="pause"></i> Пауза';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

async function clearProcessLogs() {
    const confirmed = await window.showConfirm('Вы уверены, что хотите очистить все логи процессов?', 'Очистка логов', 'warning');
    if (!confirmed) return;
    const container = document.getElementById('processes-logs-content');
    if (container) container.innerHTML = '';
}

async function exportProcessLogs() {
    if (!selectedNodeId) {
        showToast('Выберите ноду для экспорта логов', 'warning');
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}/logs.php?node_id=${selectedNodeId}&type=processes&limit=10000`, { credentials: 'include' });
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
        let content = `# Логи процессов ноды: ${nodeName}\n`;
        content += `# Дата экспорта: ${new Date().toLocaleString('ru-RU')}\n`;
        content += `# Всего записей: ${logs.length}\n\n`;
        
        logs.forEach(log => {
            const timestamp = new Date(log.timestamp || Date.now()).toLocaleString('ru-RU');
            const level = (log.level || 'INFO').toUpperCase();
            const process = log.process || 'system';
            const message = log.message || log.text || '';
            content += `[${timestamp}] [${level}] [${process}] ${message}\n`;
        });
        
        // Создаем и скачиваем файл
        const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `process-logs-${nodeName}-${new Date().toISOString().split('T')[0]}.txt`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showToast(`Экспортировано ${logs.length} записей`, 'success');
    } catch (error) {
        console.error('Error exporting process logs:', error);
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

function viewProcessLog(pid, nodeId) {
    switchProcessTab('logs');
    if (selectedNodeId) {
        // Фильтруем логи по PID процесса
        const logsContent = document.getElementById('processes-logs-content');
        if (logsContent) {
            // Сохраняем PID для фильтрации
            window.currentProcessPid = pid;
            // Очищаем и загружаем логи
            logsContent.innerHTML = '';
        loadProcessLogs(selectedNodeId);
        }
    }
}

window.switchProcessTab = switchProcessTab;
window.pauseProcessLogs = pauseProcessLogs;
window.clearProcessLogs = clearProcessLogs;
window.exportProcessLogs = exportProcessLogs;
window.viewProcessLog = viewProcessLog;

async function killProcess(pid, nodeId) {
    if (!pid || !nodeId) return;
    
    const confirmed = await window.showConfirm(
        `Вы уверены, что хотите завершить процесс ${pid}? Это действие нельзя отменить.`,
        'Завершение процесса',
        'danger'
    );
    if (!confirmed) return;
    
    try {
        const response = await fetch(`${API_BASE}/processes.php?node_id=${nodeId}&pid=${pid}&action=kill`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'kill', pid: pid })
        });
        
        if (!response.ok) {
            const text = await response.text();
            let error;
            try {
                error = JSON.parse(text);
            } catch {
                error = { error: `HTTP ${response.status}` };
            }
            throw new Error(error.error || `Ошибка ${response.status}`);
        }
        
        const text = await response.text();
        const data = text ? JSON.parse(text) : {};
        if (window.showToast) {
            window.showToast(data.message || `Процесс ${pid} завершен`, 'success');
        }
        
        // Обновляем список процессов
        setTimeout(() => loadProcesses(nodeId), 1000);
    } catch (error) {
        console.error('Error killing process:', error);
        if (window.showToast) {
            window.showToast(error.message || 'Ошибка завершения процесса', 'error');
}
    }
}

window.killProcess = killProcess;

document.addEventListener('DOMContentLoaded', () => {
    loadNodes();
    showEmptyState();
    
    // Обработчик выбора ноды
    document.getElementById('nodeFilter')?.addEventListener('change', (e) => {
        selectedNodeId = e.target.value;
        if (selectedNodeId) {
            if (currentProcessTab === 'processes') {
            loadProcesses(selectedNodeId);
            } else if (currentProcessTab === 'logs') {
                loadProcessLogs(selectedNodeId);
            }
        } else {
            showEmptyState();
            const logsContainer = document.getElementById('processes-logs-container');
            const logsEmpty = document.getElementById('processes-logs-empty');
            if (logsContainer) logsContainer.style.display = 'none';
            if (logsEmpty) logsEmpty.style.display = 'flex';
        }
    });
    
    // Обработчики фильтров
    document.getElementById('statusFilter')?.addEventListener('change', applyFilters);
    document.getElementById('processSearch')?.addEventListener('input', applyFilters);
    
    // Автообновление при выбранной ноде
    setInterval(() => {
        if (selectedNodeId) {
            if (currentProcessTab === 'processes') {
                loadProcesses(selectedNodeId, true);
            } else if (currentProcessTab === 'logs' && !processLogsPaused) {
                loadProcessLogs(selectedNodeId);
            }
        }
    }, 5000);
});

