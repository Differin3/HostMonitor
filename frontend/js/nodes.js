// JavaScript для управления нодами
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = `${API_BASE}/nodes.php`;
const esc = (value) => String(value ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

// Глобальные переменные
let nodesState = [];
let editingNodeId = null;
let refreshTimer = null;
let generatedNodeToken = null;

// DOM элементы (будут инициализированы при загрузке)
let tableBody, tableWrapper, tableLoader, createModal, editModal, createForm, editForm, toast;

const fetchJson = async (path = '', options = {}) => {
    const url = path ? `${API_URL}${path}` : API_URL;
    const opts = {
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        ...options,
    };
    
    // Если есть body, преобразуем в строку
    if (opts.body && typeof opts.body === 'object') {
        opts.body = JSON.stringify(opts.body);
    }
    
    const res = await fetch(url, opts);
    if (!res.ok) {
        const text = await res.text();
        let error;
        try {
            error = JSON.parse(text);
        } catch {
            error = { error: `API ${res.status}` };
        }
        throw new Error(error.error || `API ${res.status}`);
    }
    if (res.status === 204) return null;
    const text = await res.text();
    if (!text) return null;
    try {
        return JSON.parse(text);
    } catch (error) {
        console.error('Невалидный ответ API:', text);
        throw new Error('Некорректный ответ сервера');
    }
};

const showToast = (message, type = 'info') => {
    if (window.showToast) window.showToast(message, type);
};

let filteredNodes = [];
let currentSort = 'name';

const filterNodes = (query) => {
    if (!query) {
        filteredNodes = [...nodesState];
    } else {
        filteredNodes = nodesState.filter(node => 
            (node.name || '').toLowerCase().includes(query) ||
            (node.host || '').toLowerCase().includes(query) ||
            (node.provider_name || '').toLowerCase().includes(query)
        );
    }
    sortNodes(currentSort);
};

const sortNodes = (sortBy) => {
    currentSort = sortBy;
    const nodes = filteredNodes.length > 0 ? filteredNodes : nodesState;
    
    nodes.sort((a, b) => {
        switch(sortBy) {
            case 'name':
                return (a.name || '').localeCompare(b.name || '');
            case 'host':
                return (a.host || '').localeCompare(b.host || '');
            case 'status':
                return (a.status || '').localeCompare(b.status || '');
            case 'billing':
                return (parseFloat(b.billing_amount) || 0) - (parseFloat(a.billing_amount) || 0);
            default:
                return 0;
        }
    });
    
    renderNodes(nodes);
};

const toggleTableLoader = (show) => {
    if (window.toggleTableLoader) {
        window.toggleTableLoader('nodes-tbody', show);
    } else {
        // Fallback для совместимости
        if (!tableWrapper) {
            tableWrapper = document.querySelector('.table-container');
        }
        if (tableWrapper) {
            tableWrapper.classList.toggle('is-loading', show);
        }
    }
};

const renderNodes = (nodesToRender = null) => {
    if (!tableBody) {
        tableBody = document.getElementById('nodes-tbody');
        if (!tableBody) return;
    }
    tableBody.innerHTML = '';
    
    const nodes = nodesToRender || filteredNodes || nodesState;
    
    if (!nodes.length) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td colspan="11">Нет нод</td>';
        tableBody.appendChild(tr);
        return;
    }
    
    nodes.forEach((node) => {
        const tr = document.createElement('tr');
        tr.dataset.nodeId = node.id;
        const uptime = formatUptime(node.uptime || 0);
        const ping = node.ping !== undefined ? `${node.ping} мс` : '-';
        const statusClass = getStatusClass(node.status);
        const agentHtml = formatAgentCell(node);
        
        const safeUrl = (node.provider_url && /^https?:\/\//i.test(node.provider_url)) ? node.provider_url : '#';
        tr.innerHTML = `
            <td><input type="checkbox" class="node-checkbox" value="${parseInt(node.id)||0}" onchange="updateSelection()"></td>
            <td>${node.id ?? '-'}</td>
            <td>${esc(node.name ?? '-')}</td>
            <td>${esc(node.host ?? '-')}</td>
            <td>${node.provider_name ? `<a href="${safeUrl}" target="_blank" rel="noopener">${esc(node.provider_name)}</a>` : '-'}</td>
            <td>
                <span class="status pill ${statusClass}">${formatNodeStatusLabel(node.status)}</span>
            </td>
            <td>${uptime}</td>
            <td><span class="ping-value ${getPingClass(node.ping)}">${ping}</span></td>
            <td>
                <span class="cpu-value">${node.cpu_usage !== undefined ? `${parseFloat(node.cpu_usage).toFixed(1)}%` : '-'}</span>
            </td>
            <td>${agentHtml}</td>
            <td>
                <button class="icon-btn" type="button" onclick="editNodeById(${parseInt(node.id)||0})" title="Редактировать ноду">
                        <i data-lucide="edit"></i>
                    </button>
            </td>
        `;
        tableBody.appendChild(tr);
    });
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
};

function formatAgentCell(node) {
    const version = (node.agent_version || '').trim();
    const commit = (node.agent_commit || '').trim();
    if (!version && !commit) {
        return '<span class="muted">—</span>';
    }
    const outdated = Number(node.agent_update_available) === 1;
    const label = version || 'agent';
    const commitShort = commit ? ` <code style="font-size:11px;">${esc(commit)}</code>` : '';
    const badge = outdated
        ? ' <span class="status pill" style="background:#f59e0b;color:#111;">update</span>'
        : '';
    return `<span title="${esc(commit)}">${esc(label)}${commitShort}${badge}</span>`;
}

const loadNodes = async (silent = false) => {
    if (refreshTimer) clearTimeout(refreshTimer);
    try {
        if (!silent) {
            toggleTableLoader(true);
        }
        const payload = await fetchJson();
        nodesState = payload?.nodes ?? payload?.data ?? [];
        filteredNodes = [...nodesState];
        sortNodes(currentSort);
    } catch (error) {
        console.error('Ошибка загрузки нод:', error);
        nodesState = [];
        filteredNodes = [];
        sortNodes(currentSort);
    } finally {
        if (!silent) {
            toggleTableLoader(false);
        }
        refreshTimer = setTimeout(() => loadNodes(true), 10000);
    }
};

// Глобальная функция для добавления ноды
function addNode() {
    if (!createModal) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => addNode(), 100);
            });
            return;
        }
        createModal = document.getElementById('node-create-modal');
        createForm = document.getElementById('node-create-form');
        if (!createModal || !createForm) {
            console.error('Create modal elements not found');
            return;
        }
    }
    editingNodeId = null;
    generatedNodeToken = null;
    createForm.reset();
    generateNewKey('create');
    openCreateModal();
}

window.addNode = addNode;
window.refreshSelectedNodes = refreshSelectedNodes;
window.deleteSelectedNodes = deleteSelectedNodes;
window.refreshNode = refreshNode;
window.refreshAllNodes = refreshAllNodes;
window.generateNewKey = generateNewKey;
window.copySecretKey = copySecretKey;
window.copyConfig = copyConfig;

function openCreateModal() {
    if (!createModal) return;
    createModal.classList.add('active');
    createModal.classList.remove('hidden');
    
    // Загружаем провайдеров при открытии модального окна
    loadProvidersForSelect('provider-select-create');
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

async function loadProvidersForSelect(selectId) {
    try {
        const res = await fetch(`${API_BASE}/providers.php`, {
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' }
        });
        
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }
        
        const text = await res.text();
        if (!text) {
            throw new Error('Empty response');
        }
        
        const data = JSON.parse(text);
        const providers = data.providers || [];
        
        const select = document.getElementById(selectId);
        if (!select) return;
        
        const currentValue = select.value;
        select.innerHTML = '<option value="">Нет / домашняя лаборатория</option>';
        
        providers.forEach(provider => {
            const option = document.createElement('option');
            option.value = provider.name;
            option.textContent = provider.name;
            if (currentValue === provider.name) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    } catch (error) {
        console.warn('Ошибка загрузки провайдеров:', error);
    }
}

function initModalTabs(modalId) {
    // Вкладки больше не используются в модальных окнах нод
}

function closeCreateModal() {
    if (!createModal || !createForm) return;
    createModal.classList.remove('active');
    setTimeout(() => createModal.classList.add('hidden'), 200);
    createForm.reset();
}

function openEditModal(node) {
    // Инициализируем элементы если они еще не инициализированы
    if (!editModal) {
        editModal = document.getElementById('node-edit-modal');
    }
    if (!editForm) {
        editForm = document.getElementById('node-edit-form');
    }
    
    if (!editModal || !editForm) {
        console.error('Edit modal elements not found');
        return;
    }
    
    editingNodeId = node.id;
    editForm.dataset.nodeId = node.id;
    
    // Заполняем форму
    editForm.name.value = node.name ?? '';
    editForm.host.value = node.host ?? '';
    editForm.port.value = node.port ?? 2222;
    editForm.country.value = node.country ?? '';
    
    // Загружаем провайдеров и устанавливаем значение
    loadProvidersForSelect('provider-select-edit').then(() => {
        const providerSelect = document.getElementById('provider-select-edit');
        if (providerSelect) {
            providerSelect.value = node.provider_name ?? '';
        }
    });
    
    editModal.classList.add('active');
    editModal.classList.remove('hidden');
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function closeEditModal() {
    if (!editModal || !editForm) return;
    editModal.classList.remove('active');
    setTimeout(() => editModal.classList.add('hidden'), 200);
    editForm.reset();
    editingNodeId = null;
}

function formatUptime(seconds) {
    if (!seconds || seconds === 0) return '-';
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    if (days > 0) return `${days}д ${hours}ч`;
    if (hours > 0) return `${hours}ч ${minutes}м`;
    return `${minutes}м`;
}

function getStatusClass(status) {
    if (status === 'online') return 'status-online';
    if (status === 'warning' || status === 'degraded') return 'status-warning';
    return 'status-offline';
}

function formatNodeStatusLabel(status) {
    if (status === 'online') return 'онлайн';
    if (status === 'warning' || status === 'degraded') return 'деградация';
    if (status === 'offline') return 'офлайн';
    return status || 'неизвестно';
}

function getPingClass(ping) {
    if (ping === undefined || ping === null) return '';
    if (ping < 50) return 'ping-good';
    if (ping < 150) return 'ping-medium';
    return 'ping-bad';
}

async function refreshNode(nodeId) {
    try {
        await fetchJson(`?id=${nodeId}&action=refresh`, { method: 'POST' });
        showToast('Нода обновлена', 'success');
        loadNodes();
    } catch (error) {
        console.error(error);
        showToast(error.message || 'Ошибка обновления', 'error');
    }
}

async function refreshAllNodes() {
    try {
        showToast('Обновление всех нод...', 'info');
        await fetchJson(`?action=refresh-all`, { method: 'POST' });
        showToast('Все ноды обновлены', 'success');
        loadNodes();
    } catch (error) {
        console.error(error);
        showToast(error.message || 'Ошибка обновления', 'error');
    }
}

const handleTableClick = async (event) => {
    // Обработка кликов в таблице (если понадобится в будущем)
};

const toggleCreateLoading = (isLoading) => {
    if (!createForm) return;
    const submitBtn = createForm.querySelector('button.primary[type="submit"]');
    const cancelBtn = document.getElementById('node-create-cancel');
    if (submitBtn) {
        submitBtn.dataset.originalContent = submitBtn.dataset.originalContent || submitBtn.innerHTML;
        submitBtn.disabled = isLoading;
        submitBtn.classList.toggle('loading', isLoading);
        if (isLoading) {
            submitBtn.innerHTML = '<span class="btn-spinner"></span> Создание...';
        } else {
            submitBtn.innerHTML = submitBtn.dataset.originalContent;
        }
    }
    if (cancelBtn) {
        cancelBtn.disabled = isLoading;
    }
    createForm.classList.toggle('form-loading', isLoading);
};

const handleCreateSubmit = async (event) => {
    event.preventDefault();
    if (!createForm) return;
    
    const payload = {
        name: createForm.name.value.trim(),
        host: createForm.host.value.trim(),
        port: Number(createForm.port.value) || 2222,
        country: createForm.country?.value || null,
        secret_key: createForm.secret_key?.value || null,
        node_token: generatedNodeToken || null,
        provider_name: document.getElementById('provider-select-create')?.value.trim() || null,
    };
    
    try {
        toggleCreateLoading(true);
        const result = await fetchJson('', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        if (result && result.id) {
            showToast('Нода создана успешно', 'success');
            closeCreateModal();
            // Обновляем список нод с небольшой задержкой, чтобы БД успела обновиться
            setTimeout(() => {
                loadNodes();
            }, 500);
        } else {
            throw new Error(result?.error || 'Неизвестная ошибка');
        }
    } catch (error) {
        console.error('Ошибка создания ноды:', error);
        const errorMessage = error.message || 'Ошибка сохранения';
        showToast(errorMessage, 'error');
    } finally {
        toggleCreateLoading(false);
    }
};

const handleEditSubmit = async (event) => {
    event.preventDefault();
    if (!editForm) return;
    
    const providerSelect = document.getElementById('provider-select-edit');
    const providerName = (providerSelect?.value || '').trim() || null;
    
    const payload = {
        name: editForm.name.value.trim(),
        host: editForm.host.value.trim(),
        port: Number(editForm.port.value) || 2222,
        country: editForm.country?.value || null,
        provider_name: providerName,
    };
    
    try {
        await fetchJson(`?id=${editingNodeId}`, {
            method: 'PUT',
            body: JSON.stringify(payload),
        });
        showToast('Нода обновлена', 'success');
        closeEditModal();
        loadNodes();
    } catch (error) {
        console.error(error);
        showToast(error.message || 'Ошибка сохранения', 'error');
    }
};

function generateNewKey(type = 'create') {
    const inputId = type === 'create' ? 'secret-key-input-create' : 'secret-key-input-edit';
    fetch(`${API_URL}?action=generate-key`)
        .then(res => res.text())
        .then(text => {
            const data = text ? JSON.parse(text) : {};
            const key = data.secret_key || '';
            const input = document.getElementById(inputId);
            if (input) input.value = key;
            if (type === 'create' && key) {
                generatedNodeToken = key;
            }
        })
        .catch(() => {
            // Генерируем локально если API недоступен
            const key = btoa(Array.from(crypto.getRandomValues(new Uint8Array(64))).join(''));
            const input = document.getElementById(inputId);
            if (input) input.value = key;
            if (type === 'create') {
                generatedNodeToken = key;
            }
        });
}

function copySecretKey(type = 'create') {
    const inputId = type === 'create' ? 'secret-key-input-create' : 'secret-key-input-edit';
    const input = document.getElementById(inputId);
    if (!input) return;
    input.select();
    document.execCommand('copy');
    showToast('Ключ скопирован', 'success');
}

function getConfigText(name, host, port, token) {
    // Автоматическое определение URL мастера из текущего URL
    const currentUrl = new URL(window.location.href);
    let masterHost = currentUrl.hostname;
    let masterPort = currentUrl.port;
    
    // Если порт не указан в URL, используем стандартные порты
    if (!masterPort) {
        masterPort = currentUrl.protocol === 'https:' ? '443' : '80';
    }
    
    // Формируем URL мастера
    let masterUrl = `${currentUrl.protocol}//${masterHost}`;
    // Добавляем порт только если это не стандартный порт
    if ((currentUrl.protocol === 'http:' && masterPort !== '80') || 
        (currentUrl.protocol === 'https:' && masterPort !== '443')) {
        masterUrl += `:${masterPort}`;
    }
    
    return `# Конфигурация агента HostMonitor
# Сгенерировано автоматически
# Дата: ${new Date().toLocaleString('ru-RU')}

MASTER_URL="${masterUrl}"
MASTER_HOST="${masterHost}"
MASTER_PORT="${masterPort}"
NODE_NAME="${name}"
NODE_HOST="${host}"
NODE_PORT="${port}"
NODE_TOKEN="${token}"
COLLECT_INTERVAL=60
HEARTBEAT_INTERVAL=15
UPNP_ENABLED=true
UPNP_INTERVAL_CYCLES=2
UPNP_MX=3
UPNP_TIMEOUT=8
UPNP_GENA_PORT=0
SNMP_ENABLED=true
SNMP_COMMUNITY="public"
SNMP_TIMEOUT=0.8
# SNMP_TARGETS="192.168.1.1,192.168.1.2"
LLDP_PASSIVE=true
# LLDP_LISTEN_INTERFACE=eth0
LLDP_ACTIVE_POLL_KNOWN=true
TLS_VERIFY=false

# Установка зависимостей:
# pip install -r agent/requirements.txt
# pip install scapy   # LLDP passive (root)

# Запуск:
# python agent/main.py
`;
}

async function copyTextToClipboard(text) {
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch (_) { /* fallback below */ }
    try {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(textarea);
        return ok;
    } catch (_) {
        return false;
    }
}

async function copyConfig(type = 'create') {
    if (!createForm && type === 'create') return;
    const form = type === 'create' ? createForm : editForm;

    // Редактирование существующей ноды — конфиг с API (как «Скачать»)
    if (type === 'edit') {
        const nodeId = form?.dataset?.nodeId || document.getElementById('node-edit-form')?.dataset?.nodeId;
        if (!nodeId) {
            showToast('Откройте ноду для копирования конфига', 'warning');
            return;
        }
        try {
            const response = await fetch(`${API_BASE}/nodes.php?id=${encodeURIComponent(nodeId)}&action=generate-config`, { credentials: 'include' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const text = await response.text();
            const data = text ? JSON.parse(text) : {};
            if (data.error) {
                showToast(data.error, 'error');
                return;
            }
            const config = data.config || '';
            if (!config) {
                showToast('Конфиг не найден', 'error');
                return;
            }
            const ok = await copyTextToClipboard(config);
            showToast(ok ? 'Конфиг скопирован' : 'Не удалось скопировать конфиг', ok ? 'success' : 'error');
        } catch (error) {
            console.error('copyConfig edit', error);
            showToast('Ошибка копирования конфига', 'error');
        }
        return;
    }
    
    const name = form.name.value.trim();
    const host = form.host.value.trim();
    const port = form.port.value || '2222';
    const token = (generatedNodeToken
        || document.getElementById('secret-key-input-create')?.value
        || form.secret_key?.value
        || '').trim();
    
    if (!name || !host) {
        showToast('Заполните имя и хост перед копированием конфига', 'warning');
        return;
    }
    if (!token) {
        showToast('Сгенерируйте секретный ключ перед копированием конфига', 'warning');
        return;
    }
    
    const configText = getConfigText(name, host, port, token);
    const ok = await copyTextToClipboard(configText);
    showToast(ok ? 'Конфиг скопирован' : 'Не удалось скопировать конфиг', ok ? 'success' : 'error');
}

async function exportNodeConfig(nodeId) {
    if (!nodeId) {
        showToast('ID ноды не указан', 'warning');
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}/nodes.php?id=${encodeURIComponent(nodeId)}&action=generate-config`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : {};
        
        if (data.error) {
            showToast(data.error, 'error');
            return;
        }
        
        const config = data.config || '';
        if (!config) {
            showToast('Конфиг не найден', 'error');
            return;
        }
        
        // Получаем имя ноды
        const nodeRes = await fetch(`${API_BASE}/nodes.php?id=${encodeURIComponent(nodeId)}`, { credentials: 'include' });
        const nodeText = await nodeRes.text();
        const nodeData = nodeText ? JSON.parse(nodeText) : {};
        const node = nodeData.node || {};
        const nodeName = node.name || `node-${nodeId}`;
        
        // Создаем и скачиваем файл
        const blob = new Blob([config], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `config-${nodeName}-${new Date().toISOString().split('T')[0]}.conf`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showToast('Конфиг экспортирован', 'success');
    } catch (error) {
        console.error('Error exporting config:', error);
        showToast('Ошибка экспорта конфига', 'error');
    }
}

window.exportNodeConfig = exportNodeConfig;

let selectedNodes = new Set();

function updateSelection() {
    const checkboxes = document.querySelectorAll('.node-checkbox:checked');
    selectedNodes.clear();
    checkboxes.forEach(cb => selectedNodes.add(parseInt(cb.value)));
    
    const count = selectedNodes.size;
    const countEl = document.getElementById('selected-count');
    const menu = document.getElementById('nodes-context-menu');
    
    if (countEl) countEl.textContent = count;
    if (menu) {
        if (count > 0) {
            menu.classList.remove('hidden');
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        } else {
            menu.classList.add('hidden');
        }
    }
    
    // Кнопка выключения удалена для безопасности
    
    // Обновляем состояние "Выбрать все"
    const selectAll = document.getElementById('select-all-nodes');
    if (selectAll) {
        const allCheckboxes = document.querySelectorAll('.node-checkbox');
        selectAll.checked = allCheckboxes.length > 0 && allCheckboxes.length === checkboxes.length;
    }
}

function clearSelection() {
    document.querySelectorAll('.node-checkbox').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('select-all-nodes');
    if (selectAll) selectAll.checked = false;
    updateSelection();
}

function selectAllNodes() {
    document.querySelectorAll('.node-checkbox').forEach(cb => cb.checked = true);
    const selectAll = document.getElementById('select-all-nodes');
    if (selectAll) selectAll.checked = true;
    updateSelection();
}

window.clearSelection = clearSelection;
window.selectAllNodes = selectAllNodes;

function enableSelectedNodes() {
    if (selectedNodes.size === 0) return;
    showToast(`Включено нод: ${selectedNodes.size}`, 'success');
}

function disableSelectedNodes() {
    if (selectedNodes.size === 0) return;
    showToast(`Отключено нод: ${selectedNodes.size}`, 'info');
}

async function refreshSelectedNodes() {
    if (selectedNodes.size === 0) return;
    try {
        showToast('Обновление выбранных нод...', 'info');
        for (const nodeId of selectedNodes) {
            await refreshNode(nodeId);
        }
        showToast(`Обновлено нод: ${selectedNodes.size}`, 'success');
        clearSelection();
    } catch (error) {
        console.error(error);
        showToast('Ошибка обновления', 'error');
    }
}

async function sendNodeCommand(action) {
    if (selectedNodes.size === 0) return;
    
    // Проверка опасных команд
    if (action === 'reboot' || action === 'shutdown') {
        const confirmed = await window.showConfirm(
            `⚠️ ВНИМАНИЕ: Команда "${action}" может выключить ноду!\n\n` +
            `Вы уверены, что хотите ${action === 'reboot' ? 'перезагрузить' : 'выключить'} ${selectedNodes.size} нод(у)?\n\n` +
            `Эта операция может привести к потере данных и недоступности сервисов.`,
            action === 'reboot' ? 'Перезагрузка нод' : 'Выключение нод',
            'danger'
        );
        if (!confirmed) return;
    }
    
const actionNames = {
        reboot: 'перезагрузить',
        shutdown: 'выключить',
        'check-agent-update': 'проверить агент',
        'update-agent': 'обновить агент',
    };
    const titleNames = {
        reboot: 'Перезагрузка нод',
        shutdown: 'Выключение нод',
        'check-agent-update': 'Проверка агента',
        'update-agent': 'Обновление агента',
    };
    const actionName = actionNames[action] || action;
    const title = titleNames[action] || 'Действие с нодами';
    
    try {
        let successCount = 0;
        let errorCount = 0;
        let blockedCount = 0;
        
        for (const nodeId of selectedNodes) {
            try {
                const response = await fetchJson(`?id=${nodeId}&action=${action}`, { method: 'POST' });
                if (response.error && response.error.includes('disabled')) {
                    blockedCount++;
                } else {
                    successCount++;
                }
            } catch (error) {
                errorCount++;
                console.error(`Error sending command to node ${nodeId}:`, error);
            }
        }
        
        if (blockedCount > 0) {
            showToast(
                `⚠️ Команды выключения/перезагрузки отключены для безопасности. ` +
                `Установите ALLOW_DANGEROUS_COMMANDS=true для включения.`,
                'warning',
                5000
            );
        } else if (errorCount > 0) {
            showToast(
                `Ошибка отправки команды для ${errorCount} нод(ы)`,
                'error'
            );
        } else if (successCount > 0) {
            showToast(`Команда '${action}' отправлена для ${successCount} нод(ы)`, 'success');
        }
        clearSelection();
    } catch (error) {
        console.error('Error sending node command:', error);
        if (error.message && error.message.includes('disabled')) {
            showToast(
                '⚠️ Команды выключения/перезагрузки отключены для безопасности',
                'warning',
                5000
            );
        } else {
            showToast(error.message || 'Ошибка отправки команды', 'error');
        }
    }
}

async function rebootSelectedNodes() {
    await sendNodeCommand('reboot'); // оставлено для совместимости, не используется напрямую
}

async function shutdownSelectedNodes() {
    await sendNodeCommand('shutdown'); // оставлено для совместимости, не используется напрямую
}

async function checkAgentUpdateSelected() {
    await sendNodeCommand('check-agent-update');
}

async function updateAgentSelected() {
    if (selectedNodes.size === 0) return;
    const confirmed = await window.showConfirm(
        `Обновить агент на ${selectedNodes.size} нод(ах)?\nАгент сделает git pull и перезапустится.`,
        'Обновление агента',
        'warning'
    );
    if (!confirmed) return;
    await sendNodeCommand('update-agent');
}

window.checkAgentUpdateSelected = checkAgentUpdateSelected;
window.updateAgentSelected = updateAgentSelected;

// Функция togglePowerSelectedNodes удалена - команды выключения отключены для безопасности

async function editSelectedNode() {
    // Функция оставлена для совместимости, редактирование теперь только по кнопке в строке
}

window.editSelectedNode = editSelectedNode;

async function editNodeById(nodeId) {
    // Редактирование конкретной ноды по ID
    if (!nodeId) return;
    const node = nodesState.find((n) => String(n.id) === String(nodeId));
    if (!node) {
        showToast('Нода не найдена', 'error');
        return;
    }
    try {
        const data = await fetchJson(`?id=${nodeId}`);
        openEditModal(data.node || node);
    } catch (error) {
        console.error('Error loading node for edit:', error);
        openEditModal(node);
    }
}

window.editNodeById = editNodeById;

async function deleteSelectedNodes() {
    if (selectedNodes.size === 0) return;
    const confirmed = await window.showConfirm(`Вы уверены, что хотите удалить ${selectedNodes.size} нод(у)? Это действие нельзя отменить.`, 'Удаление нод', 'danger');
    if (!confirmed) return;
    
    let successCount = 0;
    let errorCount = 0;
    const errors = [];
    
    try {
        for (const nodeId of selectedNodes) {
            try {
                const result = await fetchJson(`?id=${nodeId}`, { method: 'DELETE' });
                if (result && result.success) {
                    successCount++;
                } else {
                    errorCount++;
                    errors.push(`Нода ${nodeId}: ${result?.error || 'Неизвестная ошибка'}`);
                }
            } catch (error) {
                errorCount++;
                errors.push(`Нода ${nodeId}: ${error.message || 'Ошибка удаления'}`);
                console.error(`Ошибка удаления ноды ${nodeId}:`, error);
            }
        }
        
        if (successCount > 0) {
            showToast(`Удалено нод: ${successCount}${errorCount > 0 ? `, ошибок: ${errorCount}` : ''}`, successCount === selectedNodes.size ? 'success' : 'warning');
        }
        
        if (errorCount > 0) {
            console.error('Ошибки удаления:', errors);
            if (successCount === 0) {
                showToast(`Ошибка удаления: ${errors[0]}`, 'error');
            }
        }
        
        clearSelection();
        loadNodes();
    } catch (error) {
        console.error('Критическая ошибка при удалении:', error);
        showToast(`Ошибка удаления: ${error.message || 'Неизвестная ошибка'}`, 'error');
    }
}

const initNodesPage = () => {
    // Инициализация DOM элементов
    tableBody = document.getElementById('nodes-tbody');
    tableWrapper = document.querySelector('.table-container');
    tableLoader = document.getElementById('nodes-loader');
    createModal = document.getElementById('node-create-modal');
    editModal = document.getElementById('node-edit-modal');
    createForm = document.getElementById('node-create-form');
    editForm = document.getElementById('node-edit-form');
    toast = document.getElementById('toast');
    
    if (!tableBody) return;
    toggleTableLoader(true);
    loadNodes();
    tableBody.addEventListener('click', handleTableClick);
    
    // Инициализация кнопки редактирования в контекстном меню
    const editBtn = document.getElementById('context-edit-btn');
    if (editBtn) {
        editBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (!editBtn.disabled) {
                editSelectedNode();
            }
        });
    }
    
    // Инициализация чекбокса "Выбрать все"
    const selectAll = document.getElementById('select-all-nodes');
    if (selectAll) {
        selectAll.addEventListener('change', (e) => {
            const checkboxes = document.querySelectorAll('.node-checkbox');
            checkboxes.forEach(cb => cb.checked = e.target.checked);
            updateSelection();
        });
    }
    
    // Поиск
    const searchInput = document.getElementById('nodes-search');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase();
            filterNodes(query);
        });
    }
    
    // Сортировка
    const sortSelect = document.getElementById('nodes-sort');
    if (sortSelect) {
        sortSelect.addEventListener('change', (e) => {
            sortNodes(e.target.value);
        });
    }
    
    // Обработчики для модального окна создания
    if (createForm) {
        createForm.addEventListener('submit', handleCreateSubmit);
    }
    document.getElementById('node-create-close')?.addEventListener('click', closeCreateModal);
    document.getElementById('node-create-cancel')?.addEventListener('click', closeCreateModal);
    createModal?.addEventListener('click', (e) => {
        if (e.target === createModal) closeCreateModal();
    });
    
    // Обработчики для модального окна редактирования
    if (editForm) {
        editForm.addEventListener('submit', handleEditSubmit);
    }
    document.getElementById('node-edit-close')?.addEventListener('click', closeEditModal);
    document.getElementById('node-edit-cancel')?.addEventListener('click', closeEditModal);
    editModal?.addEventListener('click', (e) => {
        if (e.target === editModal) closeEditModal();
    });
    
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeCreateModal();
            closeEditModal();
        }
    });
    
    // Автоматический расчет следующей оплаты для создания
    const lastPaymentInputCreate = document.getElementById('last-payment-date-create');
    const periodInputCreate = document.getElementById('billing-period-create');
    const nextPaymentInputCreate = document.getElementById('next-payment-date-create');
    
    function calculateNextPaymentCreate() {
        if (lastPaymentInputCreate && periodInputCreate && nextPaymentInputCreate) {
            if (lastPaymentInputCreate.value && periodInputCreate.value) {
                const lastPayment = new Date(lastPaymentInputCreate.value);
                const period = parseInt(periodInputCreate.value) || 30;
                const nextPayment = new Date(lastPayment);
                nextPayment.setDate(nextPayment.getDate() + period);
                nextPaymentInputCreate.value = nextPayment.toISOString().split('T')[0];
            } else {
                nextPaymentInputCreate.value = '';
            }
        }
    }
    
    if (lastPaymentInputCreate && periodInputCreate && nextPaymentInputCreate) {
        lastPaymentInputCreate.addEventListener('change', calculateNextPaymentCreate);
        periodInputCreate.addEventListener('input', calculateNextPaymentCreate);
    }
    
    // Автоматический расчет следующей оплаты для редактирования
    const lastPaymentInputEdit = document.getElementById('last-payment-date-edit');
    const periodInputEdit = document.getElementById('billing-period-edit');
    const nextPaymentInputEdit = document.getElementById('next-payment-date-edit');
    
    function calculateNextPaymentEdit() {
        if (lastPaymentInputEdit && periodInputEdit && nextPaymentInputEdit) {
            if (lastPaymentInputEdit.value && periodInputEdit.value) {
                const lastPayment = new Date(lastPaymentInputEdit.value);
                const period = parseInt(periodInputEdit.value) || 30;
                const nextPayment = new Date(lastPayment);
                nextPayment.setDate(nextPayment.getDate() + period);
                nextPaymentInputEdit.value = nextPayment.toISOString().split('T')[0];
            } else {
                nextPaymentInputEdit.value = '';
            }
        }
    }
    
    if (lastPaymentInputEdit && periodInputEdit && nextPaymentInputEdit) {
        lastPaymentInputEdit.addEventListener('change', calculateNextPaymentEdit);
        periodInputEdit.addEventListener('input', calculateNextPaymentEdit);
    }
    
    // Инициализация иконок
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
};

document.addEventListener('DOMContentLoaded', initNodesPage);

/* ═══ ЭКСПОРТ / ИМПОРТ НОД ═══════════════════════════════════════ */

let _importNodesData = null;

function exportNodes() {
    const btn = document.querySelector('[onclick="exportNodes()"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Экспорт…'; }
    fetch(`${API_BASE}/nodes_export.php?download=1`, { credentials: 'same-origin' })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
        .then(blob => {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'nodes-export-' + new Date().toISOString().slice(0,10) + '.json';
            a.click();
            URL.revokeObjectURL(url);
            showToast('Экспорт завершён', 'success');
        })
        .catch(e => showToast('Ошибка экспорта: ' + e.message, 'error'))
        .finally(() => {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i data-lucide="download"></i> Экспорт'; if (window.lucide) lucide.createIcons(); }
        });
}

function importNodesFile(input) {
    const file = input && input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const data = JSON.parse(e.target.result);
            if (!data.nodes || !Array.isArray(data.nodes) || data.nodes.length === 0) {
                showToast('Файл не содержит нод для импорта', 'error');
                return;
            }
            _importNodesData = data;
            renderImportPreview(data.nodes);
        } catch (err) {
            showToast('Ошибка чтения файла: ' + err.message, 'error');
        }
    };
    reader.readAsText(file);
    input.value = '';
}

function renderImportPreview(nodes) {
    const modal = document.getElementById('nodes-import-modal');
    const tbody = document.getElementById('import-tbody');
    const summary = document.getElementById('import-summary');
    const result = document.getElementById('import-result');

    if (!modal || !tbody) return;

    const existingNames = new Set((nodesState || []).map(n => n.name));
    let newCount = 0, dupCount = 0;

    tbody.innerHTML = '';
    result.style.display = 'none';
    result.innerHTML = '';

    nodes.forEach((n, i) => {
        const isDup = existingNames.has(n.name);
        if (isDup) dupCount++; else newCount++;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="checkbox" class="import-row-check" data-idx="${i}" ${isDup ? '' : 'checked'}></td>
            <td>${esc(n.name || '')}${isDup ? ' <span style="color:var(--text-muted);font-size:11px">(есть)</span>' : ''}</td>
            <td>${esc(n.host || '')}</td>
            <td>${esc(String(n.port || 22))}</td>
            <td>${esc(n.country || '—')}</td>
            <td>${esc(n.provider_name || '—')}</td>
        `;
        tbody.appendChild(tr);
    });

    summary.textContent = `Найдено: ${nodes.length} нод (${newCount} новых, ${dupCount} существующих)`;
    modal.classList.remove('hidden');
    if (window.lucide) lucide.createIcons();
}

function importToggleAll(cb) {
    const checks = document.querySelectorAll('.import-row-check');
    checks.forEach(c => c.checked = cb.checked);
}

function closeImportModal() {
    const modal = document.getElementById('nodes-import-modal');
    if (modal) modal.classList.add('hidden');
    _importNodesData = null;
}

function applyImport() {
    if (!_importNodesData) return;
    const checks = document.querySelectorAll('.import-row-check:checked');
    const selected = Array.from(checks).map(cb => _importNodesData.nodes[parseInt(cb.dataset.idx)]);
    if (selected.length === 0) {
        showToast('Выберите хотя бы одну ноду', 'error');
        return;
    }
    const mode = document.querySelector('input[name="import-mode"]:checked')?.value || 'skip';
    const btn = document.getElementById('import-apply-btn');
    const result = document.getElementById('import-result');

    if (btn) { btn.disabled = true; btn.textContent = 'Импорт…'; }

    fetch(`${API_BASE}/nodes_export.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ nodes: selected, mode }),
    })
    .then(r => r.json().then(j => ({ ok: r.ok, data: j })))
    .then(({ ok, data }) => {
        result.style.display = 'block';
        if (ok) {
            result.innerHTML = `<div style="padding:8px 12px;background:rgba(34,197,94,.1);border-radius:6px;color:#22c55e;font-size:13px">${esc(data.message || 'OK')}</div>`;
            if (data.errors && data.errors.length) {
                result.innerHTML += '<ul style="margin-top:8px;color:#f87171;font-size:12px">' +
                    data.errors.map(e => '<li>' + esc(e) + '</li>').join('') + '</ul>';
            }
            loadNodes();
        } else {
            result.innerHTML = `<div style="padding:8px 12px;background:rgba(248,113,113,.1);border-radius:6px;color:#f87171;font-size:13px">${esc(data.error || 'Ошибка')}</div>`;
        }
    })
    .catch(e => {
        result.style.display = 'block';
        result.innerHTML = `<div style="padding:8px 12px;background:rgba(248,113,113,.1);border-radius:6px;color:#f87171;font-size:13px">${esc(e.message)}</div>`;
    })
    .finally(() => {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i data-lucide="upload"></i> Импортировать'; if (window.lucide) lucide.createIcons(); }
    });
}
