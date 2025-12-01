// Управление контейнерами
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;
let selectedNodeId = null;
let allContainers = [];
let filteredContainers = [];
const MAX_CONTAINER_LOGS = 400;
// Определяем текущую вкладку из URL
const urlParams = new URLSearchParams(window.location.search);
let currentTab = urlParams.get('tab') || 'containers';

async function loadNodes() {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    
    // Показываем индикатор загрузки
    select.disabled = true;
    select.innerHTML = '<option value="">Загрузка нод...</option>';
    
    try {
        const nodesRes = await fetch(`${API_BASE}/nodes.php`, { credentials: 'include' });
        if (!nodesRes.ok) throw new Error(`HTTP ${nodesRes.status}`);
        const nodesData = await nodesRes.json();
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
    document.getElementById('containers-empty').style.display = 'flex';
    document.getElementById('containers-grid').style.display = 'none';
}

function showContainersGrid() {
    document.getElementById('containers-empty').style.display = 'none';
    document.getElementById('containers-grid').style.display = 'grid';
}

async function loadContainers(nodeId, silent = false) {
    if (!nodeId) {
        showEmptyState();
        return;
    }
    
    try {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('containers-grid', true);
        }
        const response = await fetch(`${API_BASE}/containers.php?node_id=${nodeId}`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { containers: [] };
        allContainers = data.containers || [];
        applyFilters();
    } catch (error) {
        console.error('Error loading containers:', error);
        allContainers = [];
        applyFilters();
    } finally {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('containers-grid', false);
        }
    }
}

function applyFilters() {
    const statusFilter = document.getElementById('statusFilter')?.value || '';
    const searchTerm = document.getElementById('containerSearch')?.value.toLowerCase() || '';
    
    filteredContainers = allContainers.filter(c => {
        if (statusFilter && c.status !== statusFilter) return false;
        if (searchTerm && !c.name?.toLowerCase().includes(searchTerm) && 
            !c.image?.toLowerCase().includes(searchTerm)) return false;
        return true;
    });
    
    renderContainers(filteredContainers);
}

function renderContainers(containers) {
    const grid = document.getElementById('containers-grid');
    if (!grid) return;
    
    if (containers.length === 0) {
        grid.innerHTML = '<div class="text-center" style="grid-column: 1/-1; padding: 40px; color: var(--text-muted);">Контейнеры не найдены</div>';
        showContainersGrid();
        return;
    }
    
    grid.innerHTML = containers.map(c => `
        <div class="container-card">
            <div class="container-card-header">
                <div class="container-name">${c.name || c.container_id.substring(0, 12)}</div>
                <span class="status ${c.status}">${c.status}</span>
            </div>
            <div class="container-info">
                <div class="info-row">
                    <i data-lucide="package"></i>
                    <span><strong>Образ:</strong> ${c.image}</span>
                </div>
                <div class="info-row">
                    <i data-lucide="cpu"></i>
                    <span><strong>CPU:</strong> ${c.cpu_percent?.toFixed(1) || 0}%</span>
                </div>
                <div class="info-row">
                    <i data-lucide="hard-drive"></i>
                    <span><strong>Память:</strong> ${c.memory_percent?.toFixed(1) || 0}%</span>
                </div>
                ${c.networks ? `
                <div class="info-row">
                    <i data-lucide="network"></i>
                    <span><strong>Сети:</strong> ${c.networks.join(', ')}</span>
                </div>
                ` : ''}
                ${c.ports ? `
                <div class="info-row">
                    <i data-lucide="link"></i>
                    <span><strong>Порты:</strong> ${c.ports.map(p => `${p.host}:${p.container}`).join(', ')}</span>
                </div>
                ` : ''}
            </div>
            <div class="container-actions">
                <button onclick="containerAction('${c.container_id}', 'start', ${selectedNodeId})" class="primary" ${c.status === 'running' ? 'disabled' : ''}>
                    <i data-lucide="play"></i> Start
                </button>
                <button onclick="containerAction('${c.container_id}', 'stop', ${selectedNodeId})" ${c.status !== 'running' ? 'disabled' : ''}>
                    <i data-lucide="square"></i> Stop
                </button>
                <button onclick="containerAction('${c.container_id}', 'restart', ${selectedNodeId})" ${c.status !== 'running' ? 'disabled' : ''}>
                    <i data-lucide="refresh-cw"></i> Restart
                </button>
                <button onclick="openContainerLogsModal('${c.container_id}', ${selectedNodeId}, '${(c.name || c.container_id.substring(0, 12)).replace(/'/g, "\\'")}')">
                    <i data-lucide="file-text"></i> Logs
                </button>
            </div>
        </div>
    `).join('');
    
    showContainersGrid();
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.containers-menu-item').forEach(item => {
        item.classList.toggle('active', item.dataset.tab === tab);
    });
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.toggle('active', content.id === `${tab}-tab`);
    });
    
    if (tab === 'network' && selectedNodeId) {
        loadNetworkInfo(selectedNodeId);
    } else if (tab === 'containers' && selectedNodeId) {
        loadContainers(selectedNodeId);
    }
}

async function loadNetworkInfo(nodeId) {
    const networkContent = document.getElementById('network-content');
    const networkEmpty = document.getElementById('network-empty');
    
    if (!nodeId) {
        if (networkContent) networkContent.style.display = 'none';
        if (networkEmpty) networkEmpty.style.display = 'flex';
        return;
    }
    
    if (networkEmpty) networkEmpty.style.display = 'none';
    if (networkContent) networkContent.style.display = 'block';
    
    if (!networkContent) return;
    
    // Загрузка данных сети из API
    let networkData = { networks: [], connections: [], ports: [] };
    try {
        const response = await fetch(`${API_BASE}/containers.php?node_id=${nodeId}&network=1`, { credentials: 'include' });
        if (response.ok) {
            const text = await response.text();
            networkData = text ? JSON.parse(text) : { networks: [], connections: [], ports: [] };
        }
    } catch (error) {
        console.error('Error loading network data:', error);
    }
    
    networkContent.innerHTML = `
        <div class="grid-2" style="margin-top: 20px;">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="network"></i>
                        <span>Сети</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Имя сети</th>
                                    <th>Драйвер</th>
                                    <th>Контейнеры</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${networkData.networks.map(n => `
                                    <tr>
                                        <td><i data-lucide="network"></i> ${n.name}</td>
                                        <td>${n.driver}</td>
                                        <td>${n.containers}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="link"></i>
                        <span>Связи контейнеров</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>От</th>
                                    <th>К</th>
                                    <th>Сеть</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${networkData.connections.map(c => `
                                    <tr>
                                        <td><i data-lucide="arrow-right"></i> ${c.from}</td>
                                        <td>${c.to}</td>
                                        <td>${c.network}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="card" style="grid-column: 1 / -1;">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="link-2"></i>
                        <span>Проброшенные порты</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Контейнер</th>
                                    <th>Хост порт</th>
                                    <th>Порт контейнера</th>
                                    <th>Протокол</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${networkData.ports.map(p => `
                                    <tr>
                                        <td><i data-lucide="box"></i> ${p.container}</td>
                                        <td>${p.host}</td>
                                        <td>${p.container_port}</td>
                                        <td>${p.protocol.toUpperCase()}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    if (networkContent) networkContent.style.display = 'block';
    if (networkEmpty) networkEmpty.style.display = 'none';
    
    // Создание иконок после небольшой задержки для рендеринга
    setTimeout(() => {
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }, 100);
}

// Навигация теперь происходит через боковое меню

function refreshContainers() {
    if (selectedNodeId) {
        loadContainers(selectedNodeId);
    }
}

async function containerAction(containerId, action, nodeId) {
    if (!containerId || !action || !nodeId) return;
    
    const actionNames = {
        'start': 'запуск',
        'stop': 'остановка',
        'restart': 'перезапуск'
    };
    const actionName = actionNames[action] || action;
    
    const confirmed = await window.showConfirm(
        `Вы уверены, что хотите выполнить ${actionName} контейнера ${containerId.substring(0, 12)}?`,
        `${actionName.charAt(0).toUpperCase() + actionName.slice(1)} контейнера`,
        action === 'stop' ? 'danger' : 'warning'
    );
    if (!confirmed) return;
    
    try {
        const response = await fetch(`${API_BASE}/containers.php?node_id=${nodeId}&container_id=${containerId}&action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: action })
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
        
        const data = await response.json();
        if (window.showToast) {
            window.showToast(data.message || `Контейнер ${actionName} успешно`, 'success');
        }
        
        // Обновляем список контейнеров
        setTimeout(() => loadContainers(nodeId), 1000);
    } catch (error) {
        console.error('Error executing container action:', error);
        if (window.showToast) {
            window.showToast(error.message || 'Ошибка выполнения действия', 'error');
        }
    }
}

window.containerAction = containerAction;

let containerLogsPaused = false;
let containerLogsInterval = null;
let currentContainerId = null;
let currentContainerNodeId = null;

function openContainerLogsModal(containerId, nodeId, containerName) {
    currentContainerId = containerId;
    currentContainerNodeId = nodeId;
    const modal = document.getElementById('container-logs-modal');
    const title = document.getElementById('container-logs-title');
    if (title) {
        title.textContent = `Логи: ${containerName}`;
    }
    if (modal) {
        modal.classList.remove('hidden');
        setTimeout(() => modal.classList.add('active'), 10);
        const logsContent = document.getElementById('container-logs-content');
        if (logsContent) logsContent.innerHTML = '';
        containerLogsPaused = false;
        loadContainerLogs(containerId, nodeId);
        if (containerLogsInterval) clearInterval(containerLogsInterval);
        containerLogsInterval = setInterval(() => {
            if (!containerLogsPaused && currentContainerId === containerId) {
                loadContainerLogs(containerId, nodeId);
            }
        }, 2000);
        
        // Закрытие при клике вне модального окна
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeContainerLogsModal();
            }
        });
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeContainerLogsModal() {
    const modal = document.getElementById('container-logs-modal');
    if (modal) {
        modal.classList.remove('active');
        setTimeout(() => modal.classList.add('hidden'), 200);
    }
    if (containerLogsInterval) {
        clearInterval(containerLogsInterval);
        containerLogsInterval = null;
    }
    currentContainerId = null;
    currentContainerNodeId = null;
}

async function loadContainerLogs(containerId, nodeId) {
    if (!containerId || !nodeId) return;
    if (containerLogsPaused) return;
    
    try {
        const response = await fetch(`${API_BASE}/containers.php?node_id=${nodeId}&container_id=${containerId}&logs=1`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { logs: [] };
        appendContainerLogs(data.logs || [], true);
    } catch (error) {
        console.error('Error loading container logs:', error);
    }
}

function appendContainerLogs(logs, replace = false) {
    const container = document.getElementById('container-logs-content');
    if (!container) return;
    
    if (replace) {
        container.innerHTML = '';
    }
    
    logs.forEach(log => {
        const entry = document.createElement('div');
        entry.className = 'log-entry';
        const logText = typeof log === 'string' ? log : (log.message || log.text || log);
        const timestamp = log.timestamp ? new Date(log.timestamp).toLocaleTimeString('ru-RU') : new Date().toLocaleTimeString('ru-RU');
        const level = log.level || 'info';
        
        // Экранируем HTML для безопасности
        const escapedText = logText.replace(/</g, '&lt;').replace(/>/g, '&gt;');
        
        entry.innerHTML = `
            <span class="log-time">${timestamp}</span>
            <span class="log-level ${level}">${level.toUpperCase()}</span>
            <span class="log-message">${escapedText}</span>
        `;
        container.appendChild(entry);
    });
    
    while (container.children.length > MAX_CONTAINER_LOGS) {
        container.removeChild(container.firstChild);
    }
    
    const autoScroll = document.getElementById('autoScrollContainerLogs');
    if (autoScroll && autoScroll.checked) {
        container.scrollTop = container.scrollHeight;
    }
}

function pauseContainerLogs() {
    containerLogsPaused = !containerLogsPaused;
    const btn = document.getElementById('pauseContainerLogsBtn');
    if (btn) {
        btn.innerHTML = containerLogsPaused 
            ? '<i data-lucide="play"></i> Продолжить'
            : '<i data-lucide="pause"></i> Пауза';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

async function clearContainerLogs() {
    const confirmed = await window.showConfirm('Вы уверены, что хотите очистить логи?', 'Очистка логов', 'warning');
    if (!confirmed) return;
    const container = document.getElementById('container-logs-content');
    if (container) container.innerHTML = '';
}

async function exportContainerLogs() {
    if (!currentContainerId || !currentContainerNodeId) {
        showToast('Нет активных логов для экспорта', 'warning');
        return;
    }
    
    try {
        const container = allContainers.find(c => c.container_id === currentContainerId);
        const containerName = container?.name || currentContainerId.substring(0, 12);
        
        const response = await fetch(`${API_BASE}/containers.php?node_id=${currentContainerNodeId}&container_id=${currentContainerId}&logs=1&limit=10000`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { logs: [] };
        const logs = data.logs || [];
        
        if (logs.length === 0) {
            showToast('Нет логов для экспорта', 'info');
            return;
        }
        
        // Формируем содержимое файла
        let content = `# Логи контейнера: ${containerName}\n`;
        content += `# Дата экспорта: ${new Date().toLocaleString('ru-RU')}\n`;
        content += `# Всего записей: ${logs.length}\n\n`;
        
        logs.forEach(log => {
            const logText = typeof log === 'string' ? log : (log.message || log.text || log);
            const timestamp = log.timestamp ? new Date(log.timestamp).toLocaleString('ru-RU') : new Date().toLocaleString('ru-RU');
            content += `[${timestamp}] ${logText}\n`;
        });
        
        // Создаем и скачиваем файл
        const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `container-logs-${containerName}-${new Date().toISOString().split('T')[0]}.txt`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showToast(`Экспортировано ${logs.length} записей`, 'success');
    } catch (error) {
        console.error('Error exporting container logs:', error);
        showToast('Ошибка экспорта логов', 'error');
    }
}

function showToast(message, type = 'info') {
    if (window.showToast) {
        window.showToast(message, type);
    } else {
        alert(message);
    }
}

window.pauseContainerLogs = pauseContainerLogs;
window.clearContainerLogs = clearContainerLogs;
window.closeContainerLogsModal = closeContainerLogsModal;
window.exportContainerLogs = exportContainerLogs;


document.addEventListener('DOMContentLoaded', () => {
    loadNodes();
    
    // Определяем текущую вкладку из URL
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab') || 'containers';
    currentTab = tab;
    
    // Показываем нужную вкладку
    const containersTab = document.getElementById('containers-tab');
    const networkTab = document.getElementById('network-tab');
    
    if (tab === 'network') {
        if (containersTab) containersTab.classList.remove('active');
        if (networkTab) networkTab.classList.add('active');
    } else {
        if (networkTab) networkTab.classList.remove('active');
        if (containersTab) containersTab.classList.add('active');
        showEmptyState();
    }
    
    document.getElementById('nodeFilter')?.addEventListener('change', (e) => {
        selectedNodeId = e.target.value;
        if (selectedNodeId) {
            if (currentTab === 'containers') {
                loadContainers(selectedNodeId);
            } else if (currentTab === 'network') {
                loadNetworkInfo(selectedNodeId);
            }
        } else {
            showEmptyState();
            const networkContent = document.getElementById('network-content');
            const networkEmpty = document.getElementById('network-empty');
            if (networkContent) networkContent.style.display = 'none';
            if (networkEmpty) networkEmpty.style.display = 'flex';
        }
    });
    
    document.getElementById('statusFilter')?.addEventListener('change', applyFilters);
    document.getElementById('containerSearch')?.addEventListener('input', applyFilters);
    
    setInterval(() => {
        if (selectedNodeId && currentTab === 'containers') {
            loadContainers(selectedNodeId, true);
        }
    }, 10000);
});

