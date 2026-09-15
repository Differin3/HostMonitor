(function () {
    if (window.__HOSTMONITOR_LOGS_INIT) return;
    window.__HOSTMONITOR_LOGS_INIT = true;

const API_BASE = window.MONITORING_API_BASE || '/api';
let logsPaused = false;
let autoScroll = true;
let selectedNodeId = null;
let currentLogsTab = 'system';
let systemLogsPage = 1;
let systemLogsPerPage = 100;
let systemLogsTotal = 0;
let authLogsPage = 1;
let authLogsPerPage = 100;
let authLogsTotal = 0;
let sshLogsPage = 1;
let sshLogsPerPage = 100;
let sshLogsTotal = 0;

function escapeHtml(text) {
    if (text == null) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

const showToast = (message, type = 'info') => {
    if (typeof window.showToast === 'function') window.showToast(message, type);
};

function formatTs(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return escapeHtml(String(value));
    return d.toLocaleString('ru-RU');
}

async function loadNodes() {
    const selects = [document.getElementById('nodeFilter'), document.getElementById('sshNodeFilter')];
    selects.forEach((select) => {
        if (!select) return;
        select.disabled = true;
        select.innerHTML = '<option value="">Загрузка нод...</option>';
    });
    try {
        const nodesRes = await fetch(`${API_BASE}/nodes.php`, { credentials: 'include' });
        if (!nodesRes.ok) throw new Error(`HTTP ${nodesRes.status}`);
        const nodesData = JSON.parse(await nodesRes.text() || '{}');
        const nodes = nodesData.nodes || [];
        selects.forEach((select) => {
            if (!select) return;
            select.innerHTML = '<option value="">Выберите ноду</option>';
            if (!nodes.length) {
                select.innerHTML = '<option value="">Ноды не найдены</option>';
                return;
            }
            nodes.forEach((node) => {
                const option = document.createElement('option');
                option.value = node.id;
                option.textContent = node.name;
                select.appendChild(option);
            });
        });
    } catch (error) {
        console.error('Error loading nodes:', error);
        selects.forEach((select) => {
            if (select) select.innerHTML = '<option value="">Ошибка загрузки нод</option>';
        });
    } finally {
        selects.forEach((select) => {
            if (select) select.disabled = false;
        });
    }
}

function showEmptyState() {
    const empty = document.getElementById('logs-empty');
    const box = document.getElementById('logs-container');
    if (empty) empty.style.display = 'flex';
    if (box) box.style.display = 'none';
}

function showLogsContainer() {
    const empty = document.getElementById('logs-empty');
    const box = document.getElementById('logs-container');
    if (empty) empty.style.display = 'none';
    if (box) box.style.display = 'block';
}

async function loadLogs(nodeId, page = 1) {
    if (!nodeId) {
        showEmptyState();
        return;
    }
    if (logsPaused) return;
    try {
        const params = new URLSearchParams();
        params.set('node_id', nodeId);
        params.set('page', String(page));
        params.set('per_page', String(systemLogsPerPage));
        const level = document.getElementById('levelFilter')?.value || '';
        const q = document.getElementById('logSearch')?.value.trim() || '';
        if (level) params.set('level', level);
        if (q) params.set('q', q);
        const response = await fetch(`${API_BASE}/logs.php?${params}`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = JSON.parse(await response.text() || '{}');
        const logs = data.logs || [];
        systemLogsPage = data.page || page;
        systemLogsPerPage = data.per_page || systemLogsPerPage;
        systemLogsTotal = data.total ?? 0;
        renderSystemLogs(logs);
        updateSystemPagination();
    } catch (error) {
        console.error('Error loading logs:', error);
    }
}

function renderSystemLogs(logs) {
    const container = document.getElementById('logs-content');
    if (!container) return;
    showLogsContainer();
    if (!logs.length) {
        container.innerHTML = '<div class="text-center" style="color:var(--text-muted);padding:24px">Нет системных логов за выбранный фильтр</div>';
        return;
    }
    const chronological = [...logs].reverse();
    container.innerHTML = chronological.map((log) => `
        <div class="log-entry">
            <span class="log-time">${escapeHtml(formatTs(log.timestamp))}</span>
            <span class="log-level ${escapeHtml(log.level || 'info')}">${escapeHtml(String(log.level || 'info').toUpperCase())}</span>
            <span class="log-message">${escapeHtml(log.message || '')}</span>
        </div>
    `).join('');
    if (autoScroll) container.scrollTop = container.scrollHeight;
}

function pauseLogs() {
    logsPaused = !logsPaused;
    const btn = document.getElementById('pauseBtn');
    if (!btn) return;
    btn.innerHTML = logsPaused
        ? '<i data-lucide="play"></i> Продолжить'
        : '<i data-lucide="pause"></i> Пауза';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function clearLogs() {
    const confirmed = await window.showConfirm('Очистить отображение логов на экране?', 'Очистка', 'warning');
    if (!confirmed) return;
    const container = document.getElementById('logs-content');
    if (container) container.innerHTML = '';
}

async function fetchAllPages(buildParams) {
    const all = [];
    let page = 1;
    const perPage = 1000;
    while (page <= 20) {
        const params = buildParams(page, perPage);
        const response = await fetch(`${API_BASE}/logs.php?${params}`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = JSON.parse(await response.text() || '{}');
        const logs = data.logs || [];
        all.push(...logs);
        const total = data.total || 0;
        if (!logs.length || all.length >= total || logs.length < perPage) break;
        page += 1;
    }
    return all;
}

function downloadText(filename, content) {
    const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

async function exportLogs() {
    if (!selectedNodeId) {
        showToast('Выберите ноду для экспорта логов', 'warning');
        return;
    }
    try {
        showToast('Экспорт логов...', 'info');
        const level = document.getElementById('levelFilter')?.value || '';
        const q = document.getElementById('logSearch')?.value.trim() || '';
        const allLogs = await fetchAllPages((page, perPage) => {
            const params = new URLSearchParams();
            params.set('node_id', selectedNodeId);
            params.set('page', String(page));
            params.set('per_page', String(perPage));
            if (level) params.set('level', level);
            if (q) params.set('q', q);
            return params;
        });
        if (!allLogs.length) {
            showToast('Нет логов для экспорта', 'info');
            return;
        }
        let content = `# Системные логи\n# ${new Date().toLocaleString('ru-RU')}\n# Записей: ${allLogs.length}\n\n`;
        allLogs.forEach((log) => {
            content += `[${formatTs(log.timestamp)}] [${String(log.level || 'INFO').toUpperCase()}] ${log.message || ''}\n`;
        });
        downloadText(`logs-${selectedNodeId}-${new Date().toISOString().split('T')[0]}.txt`, content);
        showToast(`Экспортировано ${allLogs.length} записей`, 'success');
    } catch (error) {
        console.error(error);
        showToast('Ошибка экспорта логов', 'error');
    }
}

async function exportAuthLogs() {
    try {
        showToast('Экспорт логов...', 'info');
        const eventType = document.getElementById('authEventFilter')?.value || '';
        const q = document.getElementById('authLogSearch')?.value.trim() || '';
        const allLogs = await fetchAllPages((page, perPage) => {
            const params = new URLSearchParams();
            params.set('type', 'auth');
            params.set('page', String(page));
            params.set('per_page', String(perPage));
            if (eventType) params.set('event_type', eventType);
            if (q) params.set('q', q);
            return params;
        });
        if (!allLogs.length) {
            showToast('Нет логов для экспорта', 'info');
            return;
        }
        let content = `# Авторизация панели\n# ${new Date().toLocaleString('ru-RU')}\n# Записей: ${allLogs.length}\n\n`;
        allLogs.forEach((log) => {
            content += `[${formatTs(log.timestamp)}] [${log.event_type || '-'}] ${log.username || '-'}@${log.ip_address || '-'} ${log.message || ''}\n`;
        });
        downloadText(`auth-logs-${new Date().toISOString().split('T')[0]}.txt`, content);
        showToast(`Экспортировано ${allLogs.length} записей`, 'success');
    } catch (error) {
        console.error(error);
        showToast('Ошибка экспорта логов', 'error');
    }
}

async function exportSshLogs() {
    const nodeId = document.getElementById('sshNodeFilter')?.value || '';
    if (!nodeId) {
        showToast('Выберите ноду для экспорта', 'warning');
        return;
    }
    try {
        showToast('Экспорт логов...', 'info');
        const level = document.getElementById('sshLevelFilter')?.value || '';
        const q = document.getElementById('sshLogSearch')?.value.trim() || '';
        const allLogs = await fetchAllPages((page, perPage) => {
            const params = new URLSearchParams();
            params.set('type', 'ssh_auth');
            params.set('node_id', nodeId);
            params.set('page', String(page));
            params.set('per_page', String(perPage));
            if (level) params.set('level', level);
            if (q) params.set('q', q);
            return params;
        });
        if (!allLogs.length) {
            showToast('Нет логов для экспорта', 'info');
            return;
        }
        let content = `# SSH авторизация\n# ${new Date().toLocaleString('ru-RU')}\n# Записей: ${allLogs.length}\n\n`;
        allLogs.forEach((log) => {
            const ok = log.success === true ? 'OK' : (log.success === false ? 'FAIL' : 'INFO');
            content += `[${formatTs(log.timestamp)}] [${ok}] ${log.username || '-'}@${log.ip_address || log.ip || '-'} ${log.message || ''}\n`;
        });
        downloadText(`ssh-logs-${nodeId}-${new Date().toISOString().split('T')[0]}.txt`, content);
        showToast(`Экспортировано ${allLogs.length} записей`, 'success');
    } catch (error) {
        console.error(error);
        showToast('Ошибка экспорта логов', 'error');
    }
}

function exportCurrentTabLogs() {
    if (currentLogsTab === 'system') exportLogs();
    else if (currentLogsTab === 'auth') exportAuthLogs();
    else exportSshLogs();
}

function switchLogsTab(tab) {
    currentLogsTab = tab;
    document.querySelectorAll('.tab-btn').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    document.getElementById('system-logs-tab')?.classList.toggle('active', tab === 'system');
    document.getElementById('auth-logs-tab')?.classList.toggle('active', tab === 'auth');
    document.getElementById('ssh-logs-tab')?.classList.toggle('active', tab === 'ssh_auth');
    if (tab === 'auth') {
        authLogsPage = 1;
        loadAuthLogs();
    } else if (tab === 'ssh_auth') {
        sshLogsPage = 1;
        loadSshAuthLogs(1);
    }
}

async function loadAuthLogs() {
    const tbody = document.getElementById('auth-logs-tbody');
    try {
        const params = new URLSearchParams();
        params.set('type', 'auth');
        params.set('page', String(authLogsPage));
        params.set('per_page', String(authLogsPerPage));
        const eventType = document.getElementById('authEventFilter')?.value || '';
        const q = document.getElementById('authLogSearch')?.value.trim() || '';
        if (eventType) params.set('event_type', eventType);
        if (q) params.set('q', q);
        const response = await fetch(`${API_BASE}/logs.php?${params}`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = JSON.parse(await response.text() || '{}');
        const logs = data.logs || [];
        authLogsPage = data.page || authLogsPage;
        authLogsPerPage = data.per_page || authLogsPerPage;
        authLogsTotal = data.total ?? 0;
        renderAuthLogs(logs);
        updateAuthPagination();
    } catch (error) {
        console.error(error);
        if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-center">Ошибка загрузки</td></tr>';
    }
}

function renderAuthLogs(logs) {
    const tbody = document.getElementById('auth-logs-tbody');
    if (!tbody) return;
    if (!logs.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">Логи авторизации панели пусты</td></tr>';
        return;
    }
    const labels = { login: 'Вход', logout: 'Выход', failed: 'Неудачная попытка' };
    tbody.innerHTML = logs.map((log) => {
        const eventType = log.event_type || 'unknown';
        const success = Number(log.success) === 1 || log.success === true;
        return `<tr>
            <td>${escapeHtml(formatTs(log.timestamp))}</td>
            <td>${escapeHtml(log.username || '—')}</td>
            <td>${escapeHtml(log.ip_address || '—')}</td>
            <td><span class="status pill">${escapeHtml(labels[eventType] || eventType)}</span></td>
            <td><span class="status ${success ? 'status-online' : 'status-offline'}">${success ? 'Успешно' : 'Ошибка'}</span></td>
            <td>${escapeHtml(log.message || '—')}</td>
        </tr>`;
    }).join('');
}

async function loadSshAuthLogs(page = 1) {
    const nodeId = document.getElementById('sshNodeFilter')?.value || '';
    const tbody = document.getElementById('ssh-logs-tbody');
    if (!nodeId) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center">Выберите ноду</td></tr>';
        updateSshPagination();
        return;
    }
    try {
        sshLogsPage = page;
        const params = new URLSearchParams();
        params.set('type', 'ssh_auth');
        params.set('node_id', nodeId);
        params.set('page', String(page));
        params.set('per_page', String(sshLogsPerPage));
        const level = document.getElementById('sshLevelFilter')?.value || '';
        const q = document.getElementById('sshLogSearch')?.value.trim() || '';
        if (level) params.set('level', level);
        if (q) params.set('q', q);
        const response = await fetch(`${API_BASE}/logs.php?${params}`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = JSON.parse(await response.text() || '{}');
        const logs = data.logs || [];
        sshLogsPage = data.page || page;
        sshLogsPerPage = data.per_page || sshLogsPerPage;
        sshLogsTotal = data.total ?? 0;
        renderSshLogs(logs);
        updateSshPagination();
    } catch (error) {
        console.error(error);
        if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center">Ошибка загрузки</td></tr>';
        updateSshPagination();
    }
}

function renderSshLogs(logs) {
    const tbody = document.getElementById('ssh-logs-tbody');
    if (!tbody) return;
    if (!logs.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center">SSH-логи не найдены</td></tr>';
        return;
    }
    tbody.innerHTML = logs.map((log) => {
        let resultText = 'Инфо';
        let resultClass = 'info';
        if (log.success === true) {
            resultText = 'Успех';
            resultClass = 'status-online';
        } else if (log.success === false) {
            resultText = 'Ошибка';
            resultClass = 'status-offline';
        } else if ((log.level || '') === 'error') {
            resultText = 'Ошибка';
            resultClass = 'status-offline';
        }
        const ip = (log.ip_address || log.ip || '—') + (log.port ? `:${log.port}` : '');
        return `<tr>
            <td>${escapeHtml(formatTs(log.timestamp))}</td>
            <td><span class="status pill ${resultClass}">${resultText}</span></td>
            <td>${escapeHtml(log.username || '—')}</td>
            <td>${escapeHtml(ip)}</td>
            <td>${escapeHtml(log.message || '—')}</td>
        </tr>`;
    }).join('');
}

function updateSystemPagination() {
    const totalPages = systemLogsTotal > 0 ? Math.ceil(systemLogsTotal / systemLogsPerPage) : 1;
    const info = document.getElementById('system-logs-page-info');
    const prev = document.getElementById('system-logs-prev');
    const next = document.getElementById('system-logs-next');
    if (info) info.textContent = `Стр. ${systemLogsPage} из ${totalPages}`;
    if (prev) prev.disabled = systemLogsPage <= 1;
    if (next) next.disabled = systemLogsPage >= totalPages;
}

function updateAuthPagination() {
    const totalPages = authLogsTotal > 0 ? Math.ceil(authLogsTotal / authLogsPerPage) : 1;
    const info = document.getElementById('auth-logs-page-info');
    const prev = document.getElementById('auth-logs-prev');
    const next = document.getElementById('auth-logs-next');
    if (info) info.textContent = `Стр. ${authLogsPage} из ${totalPages}`;
    if (prev) prev.disabled = authLogsPage <= 1;
    if (next) next.disabled = authLogsPage >= totalPages;
}

function updateSshPagination() {
    const totalPages = sshLogsTotal > 0 ? Math.ceil(sshLogsTotal / sshLogsPerPage) : 1;
    const info = document.getElementById('ssh-logs-page-info');
    const prev = document.getElementById('ssh-logs-prev');
    const next = document.getElementById('ssh-logs-next');
    if (info) info.textContent = sshLogsTotal ? `Стр. ${sshLogsPage} из ${totalPages}` : 'Нет данных';
    if (prev) prev.disabled = sshLogsPage <= 1 || !sshLogsTotal;
    if (next) next.disabled = sshLogsPage >= totalPages || !sshLogsTotal;
}

async function loadLogsPerPageSetting() {
    try {
        const response = await fetch(`${API_BASE}/settings.php`, { credentials: 'include' });
        if (!response.ok) return;
        const data = await response.json();
        const perPage = parseInt((data.settings || {}).logs_per_page, 10);
        if (perPage >= 10 && perPage <= 2000) {
            systemLogsPerPage = perPage;
            authLogsPerPage = perPage;
            sshLogsPerPage = perPage;
        }
    } catch (error) {
        console.error('Error loading logs per page setting:', error);
    }
}

let searchTimer = null;
function debounceReload(fn) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(fn, 300);
}

window.switchLogsTab = switchLogsTab;
window.pauseLogs = pauseLogs;
window.clearLogs = clearLogs;
window.exportCurrentTabLogs = exportCurrentTabLogs;

document.addEventListener('DOMContentLoaded', () => {
    loadLogsPerPageSetting();
    loadNodes();
    showEmptyState();

    document.getElementById('nodeFilter')?.addEventListener('change', (e) => {
        selectedNodeId = e.target.value;
        systemLogsPage = 1;
        if (selectedNodeId) loadLogs(selectedNodeId, 1);
        else showEmptyState();
    });
    document.getElementById('levelFilter')?.addEventListener('change', () => {
        if (!selectedNodeId) return;
        systemLogsPage = 1;
        loadLogs(selectedNodeId, 1);
    });
    document.getElementById('logSearch')?.addEventListener('input', () => {
        if (!selectedNodeId) return;
        debounceReload(() => {
            systemLogsPage = 1;
            loadLogs(selectedNodeId, 1);
        });
    });
    document.getElementById('autoScroll')?.addEventListener('change', (e) => {
        autoScroll = e.target.checked;
    });

    document.getElementById('authEventFilter')?.addEventListener('change', () => {
        authLogsPage = 1;
        loadAuthLogs();
    });
    document.getElementById('authLogSearch')?.addEventListener('input', () => {
        debounceReload(() => {
            authLogsPage = 1;
            loadAuthLogs();
        });
    });

    document.getElementById('sshNodeFilter')?.addEventListener('change', () => {
        sshLogsPage = 1;
        loadSshAuthLogs(1);
    });
    document.getElementById('sshLevelFilter')?.addEventListener('change', () => {
        sshLogsPage = 1;
        loadSshAuthLogs(1);
    });
    document.getElementById('sshLogSearch')?.addEventListener('input', () => {
        debounceReload(() => loadSshAuthLogs(1));
    });

    document.getElementById('system-logs-prev')?.addEventListener('click', () => {
        if (!selectedNodeId || systemLogsPage <= 1) return;
        loadLogs(selectedNodeId, systemLogsPage - 1);
    });
    document.getElementById('system-logs-next')?.addEventListener('click', () => {
        const totalPages = Math.ceil(systemLogsTotal / systemLogsPerPage) || 1;
        if (!selectedNodeId || systemLogsPage >= totalPages) return;
        loadLogs(selectedNodeId, systemLogsPage + 1);
    });
    document.getElementById('auth-logs-prev')?.addEventListener('click', () => {
        if (authLogsPage <= 1) return;
        authLogsPage -= 1;
        loadAuthLogs();
    });
    document.getElementById('auth-logs-next')?.addEventListener('click', () => {
        const totalPages = Math.ceil(authLogsTotal / authLogsPerPage) || 1;
        if (authLogsPage >= totalPages) return;
        authLogsPage += 1;
        loadAuthLogs();
    });
    document.getElementById('ssh-logs-prev')?.addEventListener('click', () => {
        if (sshLogsPage <= 1) return;
        loadSshAuthLogs(sshLogsPage - 1);
    });
    document.getElementById('ssh-logs-next')?.addEventListener('click', () => {
        const totalPages = Math.ceil(sshLogsTotal / sshLogsPerPage) || 1;
        if (sshLogsPage >= totalPages) return;
        loadSshAuthLogs(sshLogsPage + 1);
    });

    setInterval(() => {
        if (currentLogsTab === 'system' && selectedNodeId && !logsPaused && systemLogsPage === 1) {
            loadLogs(selectedNodeId, 1);
        } else if (currentLogsTab === 'auth' && authLogsPage === 1) {
            loadAuthLogs();
        } else if (currentLogsTab === 'ssh_auth' && sshLogsPage === 1) {
            const nodeId = document.getElementById('sshNodeFilter')?.value;
            if (nodeId) loadSshAuthLogs(1);
        }
    }, 8000);
});

})();
