(function () {
    if (window.__HOSTMONITOR_LOGS_INIT) return;
    window.__HOSTMONITOR_LOGS_INIT = true;

let logsPaused = false;
let selectedNodeId = null;
let currentLogsTab = 'system';
let systemLogsPage = 1;
let systemLogsPerPage = 100;
let systemLogsTotal = 0;
let sshLogsPage = 1;
let sshLogsPerPage = 100;
let sshLogsTotal = 0;

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

function renderSystemEmpty(message) {
    const tbody = document.getElementById('system-logs-tbody');
    if (tbody) tbody.innerHTML = `<tr><td colspan="4" class="text-center">${escapeHtml(message)}</td></tr>`;
}

function cleanSystemMessage(log) {
    let msg = String(log.message || '').trim().replace(/\s+/g, ' ');
    const source = log.source ? String(log.source) : '';
    if (source && msg.toLowerCase().startsWith(`${source.toLowerCase()}:`)) {
        msg = msg.slice(source.length + 1).trim();
    }
    return msg;
}

const SYSTEM_LEVEL_COLORS = { error: '#dc2626', warning: '#d97706', info: '#2563eb' };

function systemLevelBadge(level) {
    const lvl = String(level || 'info').toLowerCase();
    const color = SYSTEM_LEVEL_COLORS[lvl] || '#64748b';
    return `<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;background:${color}22;color:${color};white-space:nowrap;">${escapeHtml(lvl.toUpperCase())}</span>`;
}

function renderSystemStats(stats) {
    const set = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = String(value ?? 0);
    };
    set('system-stat-total', stats?.total);
    set('system-stat-errors', stats?.errors);
    set('system-stat-warnings', stats?.warnings);
    set('system-stat-sources', stats?.sources);
}

function renderSystemTopSources(rows) {
    const card = document.getElementById('system-top-sources');
    const list = document.getElementById('system-top-sources-list');
    if (!card || !list) return;
    if (!rows || !rows.length) {
        card.classList.add('hidden');
        list.innerHTML = '';
        return;
    }
    card.classList.remove('hidden');
    list.innerHTML = rows.map((row) => `
        <span class="status pill status-offline" style="margin: 3px 6px 3px 0; display: inline-flex; gap: 6px;">
            <strong>${escapeHtml(row.source || '—')}</strong>
            <span>${escapeHtml(String(row.count ?? 0))}</span>
        </span>
    `).join('');
}

function updateSystemSourceOptions(rows) {
    const select = document.getElementById('systemSource');
    if (!select) return;
    const current = select.value;
    const options = ['<option value="">Все источники</option>'];
    (rows || []).forEach((row) => {
        const src = row.source || '';
        if (!src) return;
        options.push(`<option value="${escapeHtml(src)}">${escapeHtml(src)} (${escapeHtml(String(row.count ?? 0))})</option>`);
    });
    select.innerHTML = options.join('');
    if (current && (rows || []).some((r) => (r.source || '') === current)) select.value = current;
}

async function loadLogs(nodeId, page = 1) {
    if (!nodeId) {
        renderSystemEmpty('Выберите ноду');
        renderSystemStats({});
        renderSystemTopSources([]);
        updateSystemPagination();
        return;
    }
    if (logsPaused) return;
    try {
        const params = new URLSearchParams();
        params.set('node_id', nodeId);
        params.set('page', String(page));
        params.set('per_page', String(systemLogsPerPage));
        const level = document.getElementById('levelFilter')?.value || '';
        const source = document.getElementById('systemSource')?.value || '';
        const days = document.getElementById('systemDays')?.value || '';
        const q = document.getElementById('logSearch')?.value.trim() || '';
        if (level) params.set('level', level);
        if (source) params.set('source', source);
        if (days) params.set('days', days);
        if (q) params.set('q', q);
        const response = await fetch(`${API_BASE}/logs.php?${params}`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = JSON.parse(await response.text() || '{}');
        const logs = data.logs || [];
        systemLogsPage = data.page || page;
        systemLogsPerPage = data.per_page || systemLogsPerPage;
        systemLogsTotal = data.total ?? 0;
        renderSystemLogs(logs);
        renderSystemStats(data.stats || {});
        renderSystemTopSources(data.top_sources || []);
        updateSystemSourceOptions(data.top_sources || []);
        updateSystemPagination();
    } catch (error) {
        console.error('Error loading logs:', error);
        renderSystemEmpty('Ошибка загрузки');
        updateSystemPagination();
    }
}

function renderSystemLogs(logs) {
    const tbody = document.getElementById('system-logs-tbody');
    if (!tbody) return;
    if (!logs.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center">Системные логи не найдены</td></tr>';
        return;
    }
    tbody.innerHTML = logs.map((log) => `
        <tr>
            <td>${escapeHtml(formatTs(log.timestamp))}</td>
            <td>${systemLevelBadge(log.level)}</td>
            <td>${escapeHtml(log.source || '—')}</td>
            <td>${escapeHtml(cleanSystemMessage(log) || '—')}</td>
        </tr>
    `).join('');
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
        const source = document.getElementById('systemSource')?.value || '';
        const days = document.getElementById('systemDays')?.value || '';
        const q = document.getElementById('logSearch')?.value.trim() || '';
        const allLogs = await fetchAllPages((page, perPage) => {
            const params = new URLSearchParams();
            params.set('node_id', selectedNodeId);
            params.set('page', String(page));
            params.set('per_page', String(perPage));
            if (level) params.set('level', level);
            if (source) params.set('source', source);
            if (days) params.set('days', days);
            if (q) params.set('q', q);
            return params;
        });
        if (!allLogs.length) {
            showToast('Нет логов для экспорта', 'info');
            return;
        }
        let content = `# Системные логи\n# ${new Date().toLocaleString('ru-RU')}\n# Записей: ${allLogs.length}\n\n`;
        allLogs.forEach((log) => {
            const src = log.source ? `[${log.source}] ` : '';
            content += `[${formatTs(log.timestamp)}] [${String(log.level || 'INFO').toUpperCase()}] ${src}${cleanSystemMessage(log)}\n`;
        });
        downloadText(`logs-${selectedNodeId}-${new Date().toISOString().split('T')[0]}.txt`, content);
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
        const result = document.getElementById('sshResult')?.value || '';
        const eventType = document.getElementById('sshEventType')?.value || '';
        const days = document.getElementById('sshDays')?.value || '';
        const q = document.getElementById('sshLogSearch')?.value.trim() || '';
        const allLogs = await fetchAllPages((page, perPage) => {
            const params = new URLSearchParams();
            params.set('type', 'ssh_auth');
            params.set('node_id', nodeId);
            params.set('page', String(page));
            params.set('per_page', String(perPage));
            if (result) params.set('result', result);
            if (eventType) params.set('event_type', eventType);
            if (days) params.set('days', days);
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
            const type = log.event_type || 'other';
            content += `[${formatTs(log.timestamp)}] [${ok}] [${type}] ${log.username || '-'}@${log.ip_address || log.ip || '-'} ${sshCleanMessage(log)}\n`;
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
    else exportSshLogs();
}

function switchLogsTab(tab) {
    currentLogsTab = tab;
    document.querySelectorAll('.tab-btn').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    document.getElementById('system-logs-tab')?.classList.toggle('active', tab === 'system');
    document.getElementById('ssh-logs-tab')?.classList.toggle('active', tab === 'ssh_auth');
    if (tab === 'ssh_auth') {
        sshLogsPage = 1;
        loadSshAuthLogs(1);
    }
}

const SSH_EVENT_LABELS = {
    accepted: 'Вход',
    failed: 'Ошибка входа',
    invalid_user: 'Неизв. пользователь',
    max_attempts: 'Лимит попыток',
    pam_failure: 'Отказ PAM',
    disconnect: 'Отключение',
    connection_closed: 'Соединение закрыто',
    session: 'Сессия',
    other: 'Прочее'
};

const SSH_EVENT_COLORS = {
    accepted: '#059669',
    failed: '#dc2626',
    invalid_user: '#dc2626',
    max_attempts: '#dc2626',
    pam_failure: '#dc2626',
    disconnect: '#64748b',
    connection_closed: '#64748b',
    session: '#2563eb',
    other: '#64748b'
};

function sshEventLabel(type) {
    return SSH_EVENT_LABELS[type] || (type ? type : 'Прочее');
}

function sshCleanMessage(log) {
    const msg = String(log.message || log.raw_message || '');
    const clean = msg.trim().replace(/\s+/g, ' ').replace(/^SUCCESS\s+/i, '').replace(/^FAIL\s+/i, '');
    return clean.split(' | ')[0];
}

function renderSshStats(stats) {
    const set = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = String(value ?? 0);
    };
    set('ssh-stat-total', stats?.total);
    set('ssh-stat-ok', stats?.ok);
    set('ssh-stat-fail', stats?.failed);
    set('ssh-stat-ips', stats?.unique_ips);
}

function renderSshTopIps(rows) {
    const card = document.getElementById('ssh-top-ips');
    const list = document.getElementById('ssh-top-ips-list');
    if (!card || !list) return;
    if (!rows || !rows.length) {
        card.classList.add('hidden');
        list.innerHTML = '';
        return;
    }
    card.classList.remove('hidden');
    list.innerHTML = rows.map((row) => `
        <span class="status pill status-offline" style="margin: 3px 6px 3px 0; display: inline-flex; gap: 6px;">
            <strong>${escapeHtml(row.ip_address || '—')}</strong>
            <span>${escapeHtml(String(row.fails ?? 0))} попыток</span>
        </span>
    `).join('');
}

function updateSshEventTypeOptions(rows) {
    const select = document.getElementById('sshEventType');
    if (!select) return;
    const current = select.value;
    const counts = new Map();
    (rows || []).forEach((row) => {
        const type = row.event_type || 'other';
        counts.set(type, (counts.get(type) || 0) + parseInt(row.count, 10));
    });
    const options = ['<option value="">Все события</option>'];
    counts.forEach((count, type) => {
        options.push(`<option value="${escapeHtml(type)}">${escapeHtml(sshEventLabel(type))} (${count})</option>`);
    });
    select.innerHTML = options.join('');
    if (current && counts.has(current)) select.value = current;
}

function sshBadge(log) {
    const type = log.event_type || 'other';
    const color = SSH_EVENT_COLORS[type] || '#64748b';
    return `<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;background:${color}22;color:${color};white-space:nowrap;">${escapeHtml(sshEventLabel(type))}</span>`;
}

async function loadSshAuthLogs(page = 1) {
    const nodeId = document.getElementById('sshNodeFilter')?.value || '';
    const tbody = document.getElementById('ssh-logs-tbody');
    if (!nodeId) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-center">Выберите ноду</td></tr>';
        renderSshStats({ total: 0, ok: 0, failed: 0, unique_ips: 0 });
        renderSshTopIps([]);
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
        const result = document.getElementById('sshResult')?.value || '';
        const eventType = document.getElementById('sshEventType')?.value || '';
        const days = document.getElementById('sshDays')?.value || '';
        const q = document.getElementById('sshLogSearch')?.value.trim() || '';
        if (result) params.set('result', result);
        if (eventType) params.set('event_type', eventType);
        if (days) params.set('days', days);
        if (q) params.set('q', q);
        const response = await fetch(`${API_BASE}/logs.php?${params}`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = JSON.parse(await response.text() || '{}');
        const logs = data.logs || [];
        sshLogsPage = data.page || page;
        sshLogsPerPage = data.per_page || sshLogsPerPage;
        sshLogsTotal = data.total ?? 0;
        renderSshLogs(logs);
        renderSshStats(data.stats || {});
        renderSshTopIps(data.top_ips || []);
        updateSshEventTypeOptions(data.event_types || []);
        updateSshPagination();
    } catch (error) {
        console.error(error);
        if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-center">Ошибка загрузки</td></tr>';
        updateSshPagination();
    }
}

function renderSshLogs(logs) {
    const tbody = document.getElementById('ssh-logs-tbody');
    if (!tbody) return;
    if (!logs.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">SSH-логи не найдены</td></tr>';
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
        }
        const ip = (log.ip_address || log.ip || '—') + (log.port ? `:${log.port}` : '');
        return `<tr>
            <td>${escapeHtml(formatTs(log.timestamp))}</td>
            <td>${sshBadge(log)}</td>
            <td><span class="status pill ${resultClass}">${resultText}</span></td>
            <td>${escapeHtml(log.username || '—')}</td>
            <td>${escapeHtml(ip)}</td>
            <td>${escapeHtml(sshCleanMessage(log) || '—')}</td>
        </tr>`;
    }).join('');
}

function updateSystemPagination() {
    const totalPages = systemLogsTotal > 0 ? Math.ceil(systemLogsTotal / systemLogsPerPage) : 1;
    const info = document.getElementById('system-logs-page-info');
    const prev = document.getElementById('system-logs-prev');
    const next = document.getElementById('system-logs-next');
    if (info) info.textContent = systemLogsTotal ? `Стр. ${systemLogsPage} из ${totalPages}` : 'Нет данных';
    if (prev) prev.disabled = systemLogsPage <= 1 || !systemLogsTotal;
    if (next) next.disabled = systemLogsPage >= totalPages || !systemLogsTotal;
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
window.exportCurrentTabLogs = exportCurrentTabLogs;

document.addEventListener('DOMContentLoaded', () => {
    loadLogsPerPageSetting();
    loadNodes();
    renderSystemEmpty('Выберите ноду');

    document.getElementById('nodeFilter')?.addEventListener('change', (e) => {
        selectedNodeId = e.target.value;
        systemLogsPage = 1;
        if (selectedNodeId) loadLogs(selectedNodeId, 1);
        else {
            renderSystemEmpty('Выберите ноду');
            renderSystemStats({});
            renderSystemTopSources([]);
            updateSystemPagination();
        }
    });
    document.getElementById('levelFilter')?.addEventListener('change', () => {
        if (!selectedNodeId) return;
        systemLogsPage = 1;
        loadLogs(selectedNodeId, 1);
    });
    document.getElementById('systemSource')?.addEventListener('change', () => {
        if (!selectedNodeId) return;
        systemLogsPage = 1;
        loadLogs(selectedNodeId, 1);
    });
    document.getElementById('systemDays')?.addEventListener('change', () => {
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

    document.getElementById('sshNodeFilter')?.addEventListener('change', () => {
        sshLogsPage = 1;
        loadSshAuthLogs(1);
    });
    document.getElementById('sshResult')?.addEventListener('change', () => {
        sshLogsPage = 1;
        loadSshAuthLogs(1);
    });
    document.getElementById('sshEventType')?.addEventListener('change', () => {
        sshLogsPage = 1;
        loadSshAuthLogs(1);
    });
    document.getElementById('sshDays')?.addEventListener('change', () => {
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
        } else if (currentLogsTab === 'ssh_auth' && sshLogsPage === 1) {
            const nodeId = document.getElementById('sshNodeFilter')?.value;
            if (nodeId) loadSshAuthLogs(1);
        }
    }, 8000);
});

})();
