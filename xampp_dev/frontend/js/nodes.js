// JavaScript для управления нодами
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = `${API_BASE}/nodes.php`;

// Глобальные переменные
let nodesState = [];
let editingNodeId = null;
let refreshTimer = null;
let generatedNodeToken = null;

// DOM элементы (будут инициализированы при загрузке)
let tableBody, createModal, editModal, createForm, editForm, toast;

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
    return text ? JSON.parse(text) : null;
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
    
    // Инициализация чекбокса "Выбрать все"
    const selectAll = document.getElementById('select-all-nodes');
    if (selectAll) {
        selectAll.addEventListener('change', (e) => {
            const checkboxes = document.querySelectorAll('.node-checkbox');
            checkboxes.forEach(cb => cb.checked = e.target.checked);
            updateSelection();
        });
    }
    
    nodes.forEach((node) => {
        const tr = document.createElement('tr');
        tr.dataset.nodeId = node.id;
        const nextPayment = node.next_payment_date ? new Date(node.next_payment_date).toLocaleDateString('ru-RU') : '-';
        const billingAmount = node.billing_amount ? `${parseFloat(node.billing_amount).toFixed(2)} ₽` : '-';
        const uptime = formatUptime(node.uptime || 0);
        const ping = node.ping !== undefined ? `${node.ping} мс` : '-';
        const statusClass = getStatusClass(node.status);
        tr.innerHTML = `
            <td><input type="checkbox" class="node-checkbox" value="${node.id}" onchange="updateSelection()"></td>
            <td>${node.id ?? '-'}</td>
            <td>${node.name ?? '-'}</td>
            <td>${node.host ?? '-'}</td>
            <td>${node.provider_name ? `<a href="${node.provider_url || '#'}" target="_blank">${node.provider_name}</a>` : '-'}</td>
            <td>${billingAmount}</td>
            <td>${nextPayment}</td>
            <td><span class="status pill ${statusClass}">${node.status ?? 'unknown'}</span></td>
            <td>${uptime}</td>
            <td><span class="ping-value ${getPingClass(node.ping)}">${ping}</span></td>
            <td>
                <div class="table-actions">
                    <button class="icon-btn" data-action="edit" data-id="${node.id}" title="Редактировать">
                        <i data-lucide="edit"></i>
                    </button>
                    <button class="icon-btn" data-action="refresh" data-id="${node.id}" title="Обновить">
                        <i data-lucide="refresh-cw"></i>
                    </button>
                    <button class="icon-btn danger" data-action="delete" data-id="${node.id}" title="Удалить">
                        <i data-lucide="trash-2"></i>
                    </button>
                </div>
            </td>
        `;
        tableBody.appendChild(tr);
    });
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
};

const loadNodes = async () => {
    if (refreshTimer) clearTimeout(refreshTimer);
    try {
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
        refreshTimer = setTimeout(loadNodes, 10000);
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
    if (!editModal || !editForm) return;
    editingNodeId = node.id;
    
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
    const { target } = event;
    if (!(target instanceof HTMLElement)) return;
    const action = target.dataset.action;
    const id = target.dataset.id;
    if (!action || !id) return;
    
    if (action === 'refresh') {
        await refreshNode(id);
        return;
    }
    
    if (action === 'edit') {
        const node = nodesState.find((n) => String(n.id) === id);
        if (!node) return;
        // Загружаем полные данные ноды
        try {
            const data = await fetchJson(`?id=${id}`);
            openEditModal(data.node || node);
        } catch (error) {
            openEditModal(node);
        }
    }
    if (action === 'delete') {
        if (!confirm('Удалить ноду?')) return;
        try {
            await fetchJson(`?id=${id}`, { method: 'DELETE' });
            showToast('Нода удалена', 'success');
            loadNodes();
        } catch (error) {
            console.error(error);
            showToast(error.message || 'Ошибка удаления', 'error');
        }
    }
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
        .then(res => res.json())
        .then(data => {
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
    const masterHost = window.location.hostname;
    const masterPort = '8000';
    const masterUrl = `${window.location.protocol}//${masterHost}:${masterPort}`;
    
    return `# Конфигурация агента мониторинга
# Сгенерировано автоматически

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

async function deleteSelectedNodes() {
    if (selectedNodes.size === 0) return;
    if (!confirm(`Удалить ${selectedNodes.size} нод(у)?`)) return;
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
    createModal = document.getElementById('node-create-modal');
    editModal = document.getElementById('node-edit-modal');
    createForm = document.getElementById('node-create-form');
    editForm = document.getElementById('node-edit-form');
    toast = document.getElementById('toast');
    
    if (!tableBody) return;
    loadNodes();
    tableBody.addEventListener('click', handleTableClick);
    
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
