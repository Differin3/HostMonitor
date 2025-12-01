// JavaScript для управления нодами
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = `${API_BASE}/nodes.php`;

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
    if (!toast) {
        toast = document.getElementById('toast');
        if (!toast) return;
    }
    toast.textContent = message;
    toast.classList.remove('hidden');
    toast.dataset.type = type;
    
    // Добавляем иконку
    const iconMap = {
        success: 'check-circle',
        error: 'alert-circle',
        warning: 'alert-triangle',
        info: 'info'
    };
    
    const icon = iconMap[type] || 'info';
    toast.innerHTML = `<i data-lucide="${icon}"></i><span>${message}</span>`;
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
    
    setTimeout(() => {
        toast.classList.add('hidden');
    }, 3000);
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
        tr.innerHTML = '<td colspan="10">Нет нод</td>'; // 10 колонок с учетом столбца действий
        tableBody.appendChild(tr);
        return;
    }
    
    nodes.forEach((node) => {
        const tr = document.createElement('tr');
        tr.dataset.nodeId = node.id;
        const uptime = formatUptime(node.uptime || 0);
        const ping = node.ping !== undefined ? `${node.ping} мс` : '-';
        const statusClass = getStatusClass(node.status);
        tr.innerHTML = `
            <td><input type="checkbox" class="node-checkbox" value="${node.id}" onchange="updateSelection()"></td>
            <td>${node.id ?? '-'}</td>
            <td>${node.name ?? '-'}</td>
            <td>${node.host ?? '-'}</td>
            <td>${node.provider_name ? `<a href="${node.provider_url || '#'}" target="_blank">${node.provider_name}</a>` : '-'}</td>
            <td><span class="status pill ${statusClass}">${node.status ?? 'unknown'}</span></td>
            <td>${uptime}</td>
            <td><span class="ping-value ${getPingClass(node.ping)}">${ping}</span></td>
            <td>
                <span class="cpu-value">${node.cpu_usage !== undefined ? `${parseFloat(node.cpu_usage).toFixed(1)}%` : '-'}</span>
            </td>
            <td>
                <button class="icon-btn" type="button" onclick="editNodeById(${node.id})" title="Редактировать ноду">
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
window.togglePowerSelectedNodes = togglePowerSelectedNodes;
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
        select.innerHTML = '<option value="">Выберите провайдера</option>';
        
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
        provider_name: document.getElementById('provider-select-create')?.value || null,
    };
    
    try {
        toggleCreateLoading(true);
        const result = await fetchJson('', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        showToast('Нода создана успешно', 'success');
        closeCreateModal();
        loadNodes();
    } catch (error) {
        console.error(error);
        showToast(error.message || 'Ошибка сохранения', 'error');
    } finally {
        toggleCreateLoading(false);
    }
};

const handleEditSubmit = async (event) => {
    event.preventDefault();
    if (!editForm) return;
    
    const providerSelect = document.getElementById('provider-select-edit');
    const providerName = providerSelect ? providerSelect.value : null;
    
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
            const input = document.getElementById(inputId);
            if (input) input.value = data.secret_key;
        })
        .catch(() => {
            // Генерируем локально если API недоступен
            const key = btoa(Array.from(crypto.getRandomValues(new Uint8Array(64))).join(''));
            const input = document.getElementById(inputId);
            if (input) input.value = key;
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
TLS_VERIFY=false

# Установка зависимостей:
# pip install -r requirements.txt

# Запуск:
# python agent/main.py
`;
}

function copyConfig(type = 'create') {
    if (!createForm && type === 'create') return;
    const form = type === 'create' ? createForm : editForm;
    
    const name = form.name.value.trim();
    const host = form.host.value.trim();
    const port = form.port.value || '2222';
    const token = type === 'create' ? (generatedNodeToken || document.getElementById('secret-key-input-create')?.value || '') : '';
    
    if (!name || !host || !token) {
        showToast('Заполните основные поля перед копированием конфига', 'warning');
        return;
    }
    
    const configText = getConfigText(name, host, port, token);
    navigator.clipboard.writeText(configText).then(() => {
        showToast('Конфиг скопирован', 'success');
    }).catch(() => {
        // Fallback для старых браузеров
        const textarea = document.createElement('textarea');
        textarea.value = configText;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        showToast('Конфиг скопирован', 'success');
    });
}

async function exportNodeConfig(nodeId) {
    if (!nodeId) {
        showToast('ID ноды не указан', 'warning');
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}/nodes.php?id=${nodeId}&action=generate-config`, { credentials: 'include' });
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
        const nodeRes = await fetch(`${API_BASE}/nodes.php?id=${nodeId}`, { credentials: 'include' });
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
    const powerBtn = document.getElementById('context-power-btn');
    
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
    
    // Управление кнопкой включить/выключить в зависимости от статуса нод
    if (powerBtn) {
        if (count === 0) {
            powerBtn.disabled = true;
            powerBtn.style.opacity = '0.5';
            powerBtn.style.cursor = 'not-allowed';
            powerBtn.innerHTML = '<i data-lucide="power"></i> Включить / Выключить';
        } else {
            const selectedIds = Array.from(selectedNodes);
            const selectedData = nodesState.filter(n => selectedIds.includes(parseInt(n.id)));
            const hasOnline = selectedData.some(n => n.status === 'online');
            const allOffline = selectedData.length > 0 && selectedData.every(n => n.status !== 'online');

            powerBtn.disabled = false;
            powerBtn.style.opacity = '1';
            powerBtn.style.cursor = 'pointer';

            powerBtn.classList.remove('context-power-on', 'context-power-off');

            if (hasOnline) {
                // Есть хотя бы одна online-нода – показываем как "Выключить" (красная кнопка)
                powerBtn.innerHTML = '<i data-lucide="power"></i> Выключить';
                powerBtn.dataset.powerAction = 'shutdown';
                powerBtn.classList.add('context-power-off');
            } else {
                // Все выбранные ноды считаем выключенными – показываем как "Включить" (зелёная кнопка)
                powerBtn.innerHTML = '<i data-lucide="power"></i> Включить';
                powerBtn.dataset.powerAction = 'reboot';
                powerBtn.classList.add('context-power-on');
            }

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }
    }
    
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
    const actionNames = {
        reboot: 'перезагрузить',
        shutdown: 'выключить'
    };
    const titleNames = {
        reboot: 'Перезагрузка нод',
        shutdown: 'Выключение нод'
    };
    const actionName = actionNames[action] || action;
    const title = titleNames[action] || 'Действие с нодами';
    const confirmed = await window.showConfirm(
        `Вы уверены, что хотите ${actionName} ${selectedNodes.size} нод(у)?`,
        title,
        'danger'
    );
    if (!confirmed) return;
    try {
        for (const nodeId of selectedNodes) {
            await fetchJson(`?id=${nodeId}&action=${action}`, { method: 'POST' });
        }
        showToast(`Команда '${action}' отправлена для ${selectedNodes.size} нод(ы)`, 'success');
        clearSelection();
    } catch (error) {
        console.error('Error sending node command:', error);
        showToast(error.message || 'Ошибка отправки команды', 'error');
    }
}

async function rebootSelectedNodes() {
    await sendNodeCommand('reboot'); // оставлено для совместимости, не используется напрямую
}

async function shutdownSelectedNodes() {
    await sendNodeCommand('shutdown'); // оставлено для совместимости, не используется напрямую
}

async function togglePowerSelectedNodes() {
    if (selectedNodes.size === 0) return;
    const powerBtn = document.getElementById('context-power-btn');
    let action = 'shutdown';
    if (powerBtn && powerBtn.dataset.powerAction) {
        action = powerBtn.dataset.powerAction;
    } else {
        // Определяем действие по статусу выбранных нод
        const selectedIds = Array.from(selectedNodes);
        const selectedData = nodesState.filter(n => selectedIds.includes(parseInt(n.id)));
        const hasOnline = selectedData.some(n => n.status === 'online');
        const allOffline = selectedData.length > 0 && selectedData.every(n => n.status !== 'online');
        action = hasOnline && !allOffline ? 'shutdown' : 'reboot';
    }
    await sendNodeCommand(action);
}

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
    try {
        for (const nodeId of selectedNodes) {
            await fetchJson(`?id=${nodeId}`, { method: 'DELETE' });
        }
        showToast(`Удалено нод: ${selectedNodes.size}`, 'success');
        clearSelection();
        loadNodes();
    } catch (error) {
        console.error(error);
        showToast('Ошибка удаления', 'error');
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
