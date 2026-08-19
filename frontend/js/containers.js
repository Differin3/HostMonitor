(function () {
    if (window.__HOSTMONITOR_CONTAINERS_INIT) return;
    window.__HOSTMONITOR_CONTAINERS_INIT = true;

const API_BASE = window.MONITORING_API_BASE || '/api';
let selectedNodeId = null;
let allContainers = [];
let filteredContainers = [];
const MAX_CONTAINER_LOGS = 400;
const urlParams = new URLSearchParams(window.location.search);
let currentTab = urlParams.get('tab') || 'containers';

function escapeHtml(text) {
    if (text == null) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function num(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
}

function networkNames(c) {
    const n = c.networks;
    if (!Array.isArray(n) || !n.length) return '';
    return n.map((x) => (typeof x === 'string' ? x : (x.name || ''))).filter(Boolean).join(', ');
}

function formatPorts(c) {
    const p = c.ports;
    if (!Array.isArray(p) || !p.length) return '';
    return p
        .filter((x) => x.host || x.host_port)
        .map((x) => `${x.host || x.host_port}:${x.container || x.container_port}`)
        .join(', ');
}

function statusLabel(status) {
    const map = {
        running: 'running',
        paused: 'paused',
        restarting: 'restarting',
        stopped: 'stopped',
        exited: 'stopped',
        dead: 'stopped',
        created: 'stopped',
    };
    return map[status] || (status || 'unknown');
}

function emptyRow(cols, text) {
    return `<tr><td colspan="${cols}" class="text-center" style="color:var(--text-muted);padding:18px">${escapeHtml(text)}</td></tr>`;
}

async function loadNodes() {
    const select = document.getElementById('nodeFilter');
    if (!select) return;

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

    nodes.forEach((node) => {
        const option = document.createElement('option');
        option.value = node.id;
        option.textContent = node.name;
        select.appendChild(option);
    });
}

function showEmptyState() {
    const empty = document.getElementById('containers-empty');
    const grid = document.getElementById('containers-grid');
    if (empty) empty.style.display = 'flex';
    if (grid) grid.style.display = 'none';
}

function showContainersGrid() {
    const empty = document.getElementById('containers-empty');
    const grid = document.getElementById('containers-grid');
    if (empty) empty.style.display = 'none';
    if (grid) grid.style.display = 'grid';
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
        const response = await fetch(`${API_BASE}/containers.php?node_id=${encodeURIComponent(nodeId)}`, { credentials: 'include' });
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

    filteredContainers = allContainers.filter((c) => {
        const st = statusLabel(c.status);
        if (statusFilter && st !== statusFilter) return false;
        const hay = `${c.name || ''} ${c.image || ''} ${c.ipv4 || ''}`.toLowerCase();
        if (searchTerm && !hay.includes(searchTerm)) return false;
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

    grid.innerHTML = containers.map((c) => {
        const cid = c.container_id || '';
        const name = c.name || cid.substring(0, 12) || 'container';
        const st = statusLabel(c.status);
        const nets = networkNames(c);
        const ports = formatPorts(c);
        const raw = c.raw_status && c.raw_status !== st ? c.raw_status : '';
        return `
        <div class="container-card">
            <div class="container-card-header">
                <div class="container-name">${escapeHtml(name)}</div>
                <span class="status ${escapeHtml(st)}">${escapeHtml(st)}</span>
            </div>
            <div class="container-info">
                <div class="info-row">
                    <i data-lucide="package"></i>
                    <span><strong>Образ:</strong> ${escapeHtml(c.image || '—')}</span>
                </div>
                <div class="info-row">
                    <i data-lucide="cpu"></i>
                    <span><strong>CPU:</strong> ${num(c.cpu_percent).toFixed(1)}%</span>
                </div>
                <div class="info-row">
                    <i data-lucide="hard-drive"></i>
                    <span><strong>Память:</strong> ${num(c.memory_percent).toFixed(1)}%</span>
                </div>
                ${c.ipv4 ? `<div class="info-row"><i data-lucide="globe"></i><span><strong>IP:</strong> ${escapeHtml(c.ipv4)}</span></div>` : ''}
                ${nets ? `<div class="info-row"><i data-lucide="network"></i><span><strong>Сети:</strong> ${escapeHtml(nets)}</span></div>` : ''}
                ${ports ? `<div class="info-row"><i data-lucide="link"></i><span><strong>Порты:</strong> ${escapeHtml(ports)}</span></div>` : ''}
                ${raw ? `<div class="info-row"><i data-lucide="info"></i><span>${escapeHtml(raw)}</span></div>` : ''}
            </div>
            <div class="container-actions">
                <button type="button" class="primary" data-action="start" data-cid="${escapeHtml(cid)}" ${st === 'running' || st === 'restarting' ? 'disabled' : ''}>
                    <i data-lucide="play"></i> Start
                </button>
                <button type="button" data-action="stop" data-cid="${escapeHtml(cid)}" ${st !== 'running' ? 'disabled' : ''}>
                    <i data-lucide="square"></i> Stop
                </button>
                <button type="button" data-action="restart" data-cid="${escapeHtml(cid)}" ${st !== 'running' && st !== 'paused' ? 'disabled' : ''}>
                    <i data-lucide="refresh-cw"></i> Restart
                </button>
                <button type="button" data-action="logs" data-cid="${escapeHtml(cid)}" data-name="${escapeHtml(name)}">
                    <i data-lucide="file-text"></i> Logs
                </button>
            </div>
        </div>`;
    }).join('');

    showContainersGrid();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.containers-menu-item').forEach((item) => {
        item.classList.toggle('active', item.dataset.tab === tab);
    });
    document.querySelectorAll('.tab-content').forEach((content) => {
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

    let networkData = { networks: [], attachments: [], ports: [] };
    try {
        const response = await fetch(
            `${API_BASE}/containers.php?node_id=${encodeURIComponent(nodeId)}&network=1`,
            { credentials: 'include' }
        );
        if (response.ok) {
            const text = await response.text();
            networkData = text ? JSON.parse(text) : networkData;
        }
    } catch (error) {
        console.error('Error loading network data:', error);
    }

    const networks = networkData.networks || [];
    const attachments = networkData.attachments || [];
    const ports = networkData.ports || [];

    networkContent.innerHTML = `
        <div class="grid-2" style="margin-top: 20px;">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="network"></i>
                        <span>Сети Docker</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Имя</th>
                                    <th>Драйвер</th>
                                    <th>Subnet</th>
                                    <th>Шлюз</th>
                                    <th>Контейнеры</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${networks.length ? networks.map((n) => `
                                    <tr>
                                        <td>${escapeHtml(n.name)}</td>
                                        <td>${escapeHtml(n.driver || '—')}</td>
                                        <td>${escapeHtml(n.subnet || '—')}</td>
                                        <td>${escapeHtml(n.gateway || '—')}</td>
                                        <td>${escapeHtml(n.containers ?? (n.members || []).length)}</td>
                                    </tr>
                                `).join('') : emptyRow(5, 'Нет Docker-сетей. Агент ещё не прислал снимок.')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="link"></i>
                        <span>Участники сетей</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Контейнер</th>
                                    <th>Сеть</th>
                                    <th>IPv4</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${attachments.length ? attachments.map((c) => `
                                    <tr>
                                        <td>${escapeHtml(c.container)}</td>
                                        <td>${escapeHtml(c.network)}</td>
                                        <td>${escapeHtml(c.ipv4 || '—')}</td>
                                    </tr>
                                `).join('') : emptyRow(3, 'Нет подключений контейнеров к сетям')}
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
                                    <th>Хост</th>
                                    <th>Порт контейнера</th>
                                    <th>Протокол</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${ports.length ? ports.map((p) => `
                                    <tr>
                                        <td>${escapeHtml(p.container)}</td>
                                        <td>${escapeHtml((p.host_ip && p.host_ip !== '0.0.0.0' && p.host_ip !== '::' ? p.host_ip + ':' : '') + (p.host ?? '—'))}</td>
                                        <td>${escapeHtml(p.container_port ?? '—')}</td>
                                        <td>${escapeHtml(String(p.protocol || 'tcp').toUpperCase())}</td>
                                    </tr>
                                `).join('') : emptyRow(4, 'Нет опубликованных портов')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    `;

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function refreshContainers() {
    if (!selectedNodeId) return;
    if (currentTab === 'network') {
        loadNetworkInfo(selectedNodeId);
    } else {
        loadContainers(selectedNodeId);
    }
}

async function containerAction(containerId, action, nodeId) {
    if (!containerId || !action || !nodeId) return;

    const actionNames = {
        start: 'запуск',
        stop: 'остановка',
        restart: 'перезапуск',
    };
    const actionName = actionNames[action] || action;

    const confirmed = await window.showConfirm(
        `Выполнить ${actionName} контейнера ${containerId.substring(0, 12)}?`,
        `${actionName.charAt(0).toUpperCase() + actionName.slice(1)} контейнера`,
        action === 'stop' ? 'danger' : 'warning'
    );
    if (!confirmed) return;

    try {
        const response = await fetch(
            `${API_BASE}/containers.php?node_id=${encodeURIComponent(nodeId)}&container_id=${encodeURIComponent(containerId)}&action=${encodeURIComponent(action)}`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action }),
            }
        );

        const text = await response.text();
        let data = {};
        try {
            data = text ? JSON.parse(text) : {};
        } catch {
            data = { error: text || `HTTP ${response.status}` };
        }
        if (!response.ok) {
            throw new Error(data.error || `Ошибка ${response.status}`);
        }

        if (window.showToast) {
            window.showToast(data.message || `Контейнер: ${actionName} в очереди`, 'success');
        }
        setTimeout(() => loadContainers(nodeId), 1500);
    } catch (error) {
        console.error('Error executing container action:', error);
        if (window.showToast) {
            window.showToast(error.message || 'Ошибка выполнения действия', 'error');
        }
    }
}

window.containerAction = containerAction;
window.switchTab = switchTab;
window.refreshContainers = refreshContainers;

let containerLogsPaused = false;
let containerLogsInterval = null;
let currentContainerId = null;
let currentContainerNodeId = null;
let logsModalBound = false;

function openContainerLogsModal(containerId, nodeId, containerName) {
    currentContainerId = containerId;
    currentContainerNodeId = nodeId;
    const modal = document.getElementById('container-logs-modal');
    const title = document.getElementById('container-logs-title');
    if (title) {
        title.innerHTML = `<i data-lucide="file-text" style="width: 24px; height: 24px; margin-right: 8px;"></i> Логи: ${escapeHtml(containerName)}`;
    }
    if (modal) {
        modal.classList.remove('hidden');
        setTimeout(() => modal.classList.add('active'), 10);
        const logsContent = document.getElementById('container-logs-content');
        if (logsContent) logsContent.innerHTML = '<div class="text-center" style="color:var(--text-muted);padding:16px">Запрос логов у агента…</div>';
        containerLogsPaused = false;
        requestContainerLogs(containerId, nodeId).then(() => loadContainerLogs(containerId, nodeId));
        if (containerLogsInterval) clearInterval(containerLogsInterval);
        containerLogsInterval = setInterval(() => {
            if (!containerLogsPaused && currentContainerId === containerId) {
                loadContainerLogs(containerId, nodeId);
            }
        }, 2500);

        if (!logsModalBound) {
            logsModalBound = true;
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeContainerLogsModal();
            });
        }
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function requestContainerLogs(containerId, nodeId) {
    try {
        await fetch(
            `${API_BASE}/containers.php?node_id=${encodeURIComponent(nodeId)}&container_id=${encodeURIComponent(containerId)}&action=logs`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'logs' }),
            }
        );
    } catch (error) {
        console.error('Error requesting container logs:', error);
    }
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
        const response = await fetch(
            `${API_BASE}/containers.php?node_id=${encodeURIComponent(nodeId)}&container_id=${encodeURIComponent(containerId)}&logs=1`,
            { credentials: 'include' }
        );
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

    if (replace) container.innerHTML = '';
    if (!logs.length) {
        if (replace) {
            container.innerHTML = '<div class="text-center" style="color:var(--text-muted);padding:16px">Логов пока нет — ждём агент</div>';
        }
        return;
    }

    logs.forEach((log) => {
        const entry = document.createElement('div');
        entry.className = 'log-entry';
        const logText = typeof log === 'string' ? log : (log.message || log.text || '');
        const timestamp = log.timestamp
            ? new Date(log.timestamp).toLocaleTimeString('ru-RU')
            : new Date().toLocaleTimeString('ru-RU');
        const level = log.level || 'info';
        entry.innerHTML = `
            <span class="log-time">${escapeHtml(timestamp)}</span>
            <span class="log-level ${escapeHtml(level)}">${escapeHtml(String(level).toUpperCase())}</span>
            <span class="log-message">${escapeHtml(logText)}</span>
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
    const confirmed = await window.showConfirm('Очистить отображение логов?', 'Очистка логов', 'warning');
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
        const container = allContainers.find((c) => c.container_id === currentContainerId);
        const containerName = container?.name || currentContainerId.substring(0, 12);

        const response = await fetch(
            `${API_BASE}/containers.php?node_id=${encodeURIComponent(currentContainerNodeId)}&container_id=${encodeURIComponent(currentContainerId)}&logs=1&limit=10000`,
            { credentials: 'include' }
        );
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { logs: [] };
        const logs = data.logs || [];

        if (logs.length === 0) {
            showToast('Нет логов для экспорта', 'info');
            return;
        }

        let content = `# Логи контейнера: ${containerName}\n`;
        content += `# Дата экспорта: ${new Date().toLocaleString('ru-RU')}\n`;
        content += `# Всего записей: ${logs.length}\n\n`;

        logs.forEach((log) => {
            const logText = typeof log === 'string' ? log : (log.message || log.text || log);
            const timestamp = log.timestamp
                ? new Date(log.timestamp).toLocaleString('ru-RU')
                : new Date().toLocaleString('ru-RU');
            content += `[${timestamp}] ${logText}\n`;
        });

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

const showToast = (message, type = 'info') => {
    if (typeof window.showToast === 'function') {
        window.showToast(message, type);
    }
};

window.pauseContainerLogs = pauseContainerLogs;
window.clearContainerLogs = clearContainerLogs;
window.closeContainerLogsModal = closeContainerLogsModal;
window.exportContainerLogs = exportContainerLogs;
window.openContainerLogsModal = openContainerLogsModal;

document.addEventListener('DOMContentLoaded', () => {
    loadNodes();

    const tab = new URLSearchParams(window.location.search).get('tab') || 'containers';
    currentTab = tab;

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

    document.getElementById('containers-grid')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn || !selectedNodeId) return;
        const action = btn.dataset.action;
        const cid = btn.dataset.cid;
        if (action === 'logs') {
            openContainerLogsModal(cid, selectedNodeId, btn.dataset.name || cid.substring(0, 12));
            return;
        }
        containerAction(cid, action, selectedNodeId);
    });

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
        if (!selectedNodeId) return;
        if (currentTab === 'containers') {
            loadContainers(selectedNodeId, true);
        } else if (currentTab === 'network') {
            loadNetworkInfo(selectedNodeId);
        }
    }, 15000);
});

})();
