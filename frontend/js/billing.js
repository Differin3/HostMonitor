// Биллинг система
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;
const API_NODES = `${API_BASE}/nodes.php`;
const API_PROVIDERS = `${API_BASE}/providers.php`;
const API_PAYMENTS = `${API_BASE}/payments.php`;
let selectedBillingNodes = new Set();
let providersList = [];
let providersCurrentPage = 1;
let providersPerPage = 10;
let monthlyChart = null;
let providerChart = null;
let paymentsTimelineChart = null;

const toggleTableLoader = (tableId, show) => {
    const tableContainer = document.querySelector(`#${tableId}`)?.closest('.table-container');
    if (tableContainer) {
        tableContainer.classList.toggle('is-loading', show);
    }
};

async function loadBillingData(silent = false) {
    try {
        if (!silent) {
            toggleTableLoader('billing-nodes-tbody', true);
            toggleTableLoader('payments-tbody', true);
        }
        
        const [nodesRes, paymentsRes] = await Promise.all([
            fetch(API_NODES, { credentials: 'include' }),
            fetch(API_PAYMENTS, { credentials: 'include' })
        ]);
        
        if (!nodesRes.ok || !paymentsRes.ok) throw new Error('HTTP error');
        
        const nodesText = await nodesRes.text();
        const paymentsText = await paymentsRes.text();
        
        const nodesData = nodesText ? JSON.parse(nodesText) : { nodes: [] };
        const paymentsData = paymentsText ? JSON.parse(paymentsText) : { payments: [] };
        
        const nodes = nodesData.nodes || [];
        const payments = paymentsData.payments || [];
        
        window.billingNodesData = nodes;
        
        updateStats(nodes, payments);
        renderBillingNodes(nodes);
        renderPayments(payments);
        populateBillingNodes();
    } catch (error) {
        console.error('Error loading billing:', error);
        window.billingNodesData = [];
        updateStats([], []);
        renderBillingNodes([]);
        renderPayments([]);
        populateBillingNodes();
    } finally {
        if (!silent) {
            toggleTableLoader('billing-nodes-tbody', false);
            toggleTableLoader('payments-tbody', false);
        }
    }
}

function updateStats(nodes, payments) {
    const now = new Date();
    const currentMonth = now.getMonth();
    const currentYear = now.getFullYear();
    
    // Ноды ожидающие оплаты в текущем месяце
    const pendingNodes = nodes.filter(n => {
        if (!n.next_payment_date) return false;
        const nextPayment = new Date(n.next_payment_date);
        return nextPayment.getMonth() === currentMonth && nextPayment.getFullYear() === currentYear;
    });
    
    // Платежи в текущем месяце
    const monthPayments = payments.filter(p => {
        const paymentDate = new Date(p.payment_date);
        return paymentDate.getMonth() === currentMonth && paymentDate.getFullYear() === currentYear;
    });
    
    // Общая сумма платежей в месяце
    const monthTotal = monthPayments.reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
    
    // Общая сумма всех платежей
    const totalSpent = payments.reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
    
    document.getElementById('pending-nodes').textContent = pendingNodes.length;
    document.getElementById('month-payments').textContent = monthTotal.toFixed(2) + ' ₽';
    document.getElementById('total-spent').textContent = totalSpent.toFixed(2) + ' ₽';
}

function buildFaviconUrl(faviconUrl, providerUrl) {
    const source = faviconUrl || providerUrl;
    if (!source) return null;
    const trimmed = source.trim();
    if (!trimmed) return null;
    
    // Если это уже полный URL с протоколом
    if (/^https?:\/\//i.test(trimmed)) {
        // Если это favicon_url и это полный URL, используем его напрямую
    if (faviconUrl) {
            return trimmed;
        }
        // Если это provider_url, извлекаем домен для favicon
        try {
            const url = new URL(trimmed);
            return `https://www.google.com/s2/favicons?sz=64&domain=${url.hostname}`;
        } catch {
            return trimmed;
        }
    }
    
    // Если это домен без протокола, используем Google favicon API
    // Убираем возможные пути и параметры
    const domain = trimmed.split('/')[0].split('?')[0].split('#')[0];
    return `https://www.google.com/s2/favicons?sz=64&domain=${domain}`;
}

function getProviderIcon(faviconUrl, providerName, providerUrl) {
    const iconSrc = buildFaviconUrl(faviconUrl, providerUrl);
    const fallbackLetter = providerName ? providerName.charAt(0).toUpperCase() : '?';
    const safeProviderName = (providerName || '').replace(/"/g, '&quot;');
    
    if (iconSrc) {
        return `<span class="provider-icon-wrapper"><img src="${iconSrc}" alt="${safeProviderName}" class="provider-favicon" loading="lazy" onerror="this.onerror=null; const wrapper=this.closest('.provider-icon-wrapper'); if(wrapper && !wrapper.querySelector('.provider-icon')){ wrapper.innerHTML='<div class=&quot;provider-icon&quot;>${fallbackLetter}</div>'; }"></span>`;
    }
    if (providerName) {
        return `<div class="provider-icon">${fallbackLetter}</div>`;
    }
    return '<div class="provider-icon-placeholder">?</div>';
}

function renderBillingNodes(nodes) {
    const tbody = document.getElementById('billing-nodes-tbody');
    const billingNodes = nodes.filter(n => n.billing_amount && parseFloat(n.billing_amount) > 0);
    
    if (billingNodes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center">Нет нод с биллингом</td></tr>';
        return;
    }
    
    // Сбрасываем выбор при перерисовке
    selectedBillingNodes.clear();
    updateBillingSelection();
    
    // Сохраняем все ноды для редактирования
    window.billingNodesData = nodes;
    
    tbody.innerHTML = billingNodes.map(node => {
        const nextPayment = node.next_payment_date ? new Date(node.next_payment_date) : null;
        const isOverdue = nextPayment && nextPayment < new Date();
        const nextPaymentStr = nextPayment ? nextPayment.toLocaleDateString('ru-RU', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        }) : '-';
        const countryFlag = node.country ? getCountryFlag(node.country) : '';
        
        return `
            <tr data-node-id="${node.id}" style="cursor: pointer;">
                <td onclick="event.stopPropagation();">
                    <input type="checkbox" class="billing-node-checkbox" value="${node.id}" onchange="updateBillingSelection()">
                </td>
                <td onclick="editNodeBilling(${node.id})">
                    <div class="table-cell-with-icon">
                        ${getProviderIcon(node.favicon_url, node.provider_name, node.provider_url)}
                        <div>
                            ${node.provider_name ? `<a href="${node.provider_url || '#'}" target="_blank" onclick="event.stopPropagation();">${node.provider_name}</a><i data-lucide="external-link" class="external-link-icon"></i>` : '-'}
                        </div>
                    </div>
                </td>
                <td onclick="editNodeBilling(${node.id})">
                    <div class="table-cell-with-icon">
                        ${countryFlag}
                        <span>${node.name}</span>
                    </div>
                </td>
                <td onclick="editNodeBilling(${node.id})">
                    <div class="table-cell-with-icon">
                        <i data-lucide="calendar"></i>
                        <span class="billing-date-pill ${isOverdue ? 'billing-date-pill-overdue' : ''}">${nextPaymentStr}</span>
                    </div>
                </td>
                <td onclick="quickUpdatePaymentDate(${node.id})">
                    <div class="table-cell-with-icon">
                        <i data-lucide="check-circle" style="color: var(--success); cursor: pointer;" title="Быстро обновить до следующего месяца"></i>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function getCountryFlag(country) {
    const flags = {
        'RU': '🇷🇺', 'NL': '🇳🇱', 'DE': '🇩🇪', 'US': '🇺🇸', 'GB': '🇬🇧',
        'FR': '🇫🇷', 'PL': '🇵🇱', 'FI': '🇫🇮', 'SG': '🇸🇬', 'JP': '🇯🇵'
    };
    return flags[country] || '';
}

function renderPayments(payments) {
    const tbody = document.getElementById('payments-tbody');
    
    if (payments.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center">Нет платежей</td></tr>';
        return;
    }
    
    // Сортируем по дате (новые сверху)
    payments.sort((a, b) => new Date(b.payment_date) - new Date(a.payment_date));
    
    tbody.innerHTML = payments.map(payment => {
        const paymentDate = new Date(payment.payment_date);
        const paymentDateStr = paymentDate.toLocaleDateString('ru-RU', {
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        }) + ' года';
        const node = window.billingNodesData?.find(n => n.id == payment.node_id);
        const providerName = node?.provider_name || payment.provider_name || '';
        
        return `
            <tr>
                <td>
                    <div class="table-cell-with-icon">
                        ${getProviderIcon(node?.favicon_url || '', providerName, node?.provider_url)}
                        <span>${providerName || payment.node_name || `node-${payment.node_id}`}</span>
                    </div>
                </td>
                <td>
                    <div class="table-cell-with-icon">
                        <i data-lucide="calendar"></i>
                        <span>${paymentDateStr}</span>
                    </div>
                </td>
                <td>
                    <div class="table-cell-with-icon">
                        <i data-lucide="dollar-sign"></i>
                        <span>${parseFloat(payment.amount || 0).toFixed(2)} ₽</span>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

window.editNodeBilling = editNodeBilling;
window.quickUpdatePaymentDate = quickUpdatePaymentDate;
window.addNodeBilling = addNodeBilling;
window.addPayment = addPayment;
window.addProvider = addProvider;
window.editProvider = editProvider;
window.deleteProvider = deleteProvider;
window.refreshBilling = refreshBilling;
window.refreshPayments = refreshPayments;
window.closeBillingModal = closeBillingModal;
window.closePaymentModal = closePaymentModal;
window.closeProviderModal = closeProviderModal;
window.closeUpdatePaymentDateModal = closeUpdatePaymentDateModal;

function editNodeBilling(nodeId) {
    const node = window.billingNodesData?.find(n => n.id == nodeId);
    if (!node) return;
    
    const modal = document.getElementById('billing-modal');
    const form = document.getElementById('billing-form');
    if (!modal || !form) return;
    
    form.node_id.value = node.id;
    
    // Заполняем выбор ноды
    const nodeSelect = document.getElementById('billing-node-select');
    if (nodeSelect) {
        nodeSelect.innerHTML = '';
        window.billingNodesData?.forEach(n => {
            const option = document.createElement('option');
            option.value = n.id;
            option.textContent = n.name;
            option.selected = n.id == nodeId;
            nodeSelect.appendChild(option);
        });
    }
    
    const providerSelect = document.getElementById('billing-provider');
    if (providerSelect) {
        providerSelect.value = node.provider_name || '';
    }
    
    const billingAmountInput = document.getElementById('billing-amount');
    if (billingAmountInput) {
        billingAmountInput.value = node.billing_amount || '';
    }
    
    if (node.next_payment_date) {
        const date = new Date(node.next_payment_date);
        const datetimeLocal = date.toISOString().slice(0, 16);
        document.getElementById('billing-next-payment').value = datetimeLocal;
    } else {
        document.getElementById('billing-next-payment').value = '';
    }
    
    form.querySelector('button[type="submit"]').textContent = 'Сохранить';
    
    modal.classList.remove('hidden');
    modal.classList.add('active');
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

async function addNodeBilling() {
    const modal = document.getElementById('billing-modal');
    if (!modal) {
        console.error('Billing modal not found');
        return;
    }
    
    // Убеждаемся что данные загружены
    if (!window.billingNodesData) {
        await loadBillingData();
    }
    
    const form = document.getElementById('billing-form');
    if (form) {
        form.reset();
        document.getElementById('billing-node-id').value = '';
        populateBillingNodes();
        populateProviderSelects();
        form.querySelector('button[type="submit"]').textContent = 'Создать';
    }
    modal.classList.remove('hidden');
    modal.classList.add('active');
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function updateBillingSelection() {
    const checkboxes = document.querySelectorAll('.billing-node-checkbox:checked');
    selectedBillingNodes.clear();
    checkboxes.forEach(cb => selectedBillingNodes.add(parseInt(cb.value)));
    
    const count = selectedBillingNodes.size;
    const countEl = document.getElementById('billing-selected-count');
    const menu = document.getElementById('billing-context-menu');
    
    if (countEl) countEl.textContent = count;
    if (menu) {
        if (count > 0) {
            menu.classList.remove('hidden');
            setTimeout(() => {
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }, 10);
        } else {
            menu.classList.add('hidden');
        }
    }
    
    const selectAll = document.getElementById('select-all-billing-nodes');
    if (selectAll) {
        const allCheckboxes = document.querySelectorAll('.billing-node-checkbox');
        selectAll.checked = allCheckboxes.length > 0 && allCheckboxes.length === checkboxes.length;
    }
}

function clearBillingSelection() {
    document.querySelectorAll('.billing-node-checkbox').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('select-all-billing-nodes');
    if (selectAll) selectAll.checked = false;
    updateBillingSelection();
}

function selectAllBillingNodes() {
    document.querySelectorAll('.billing-node-checkbox').forEach(cb => cb.checked = true);
    const selectAll = document.getElementById('select-all-billing-nodes');
    if (selectAll) selectAll.checked = true;
    updateBillingSelection();
}

window.clearBillingSelection = clearBillingSelection;
window.selectAllBillingNodes = selectAllBillingNodes;

function quickUpdatePaymentDate(nodeId) {
    const node = window.billingNodesData?.find(n => n.id == nodeId);
    if (!node) return;
    
    const modal = document.getElementById('update-payment-date-modal');
    const form = document.getElementById('update-payment-date-form');
    if (!modal || !form) return;
    
    document.getElementById('update-payment-node-id').value = nodeId;
    const currentDate = node.next_payment_date ? new Date(node.next_payment_date).toISOString().split('T')[0] : '';
    document.getElementById('update-payment-current-date').value = currentDate;
    
    // Автоматически устанавливаем дату на следующий месяц
    const nextMonth = new Date(currentDate || new Date());
    nextMonth.setMonth(nextMonth.getMonth() + 1);
    document.getElementById('update-payment-new-date').value = nextMonth.toISOString().split('T')[0];
    
    modal.classList.remove('hidden');
    modal.classList.add('active');
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function closeUpdatePaymentDateModal() {
    const modal = document.getElementById('update-payment-date-modal');
    if (modal) {
        modal.classList.remove('active');
        setTimeout(() => modal.classList.add('hidden'), 200);
    }
}

async function updateSelectedPaymentDates() {
    if (selectedBillingNodes.size === 0) return;
    
    try {
        showToast('Обновление дат...', 'info');
        const now = new Date();
        const nextMonth = new Date(now.getFullYear(), now.getMonth() + 1, now.getDate());
        const newDate = nextMonth.toISOString().split('T')[0];
        
        for (const nodeId of selectedBillingNodes) {
            await fetch(`${API_NODES}?id=${nodeId}`, {
                method: 'PUT',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ next_payment_date: newDate })
            });
        }
        showToast(`Обновлено нод: ${selectedBillingNodes.size}`, 'success');
        clearBillingSelection();
        loadBillingData();
    } catch (error) {
        console.error(error);
        showToast('Ошибка обновления', 'error');
    }
}

async function deleteSelectedBillingNodes() {
    if (selectedBillingNodes.size === 0) return;
    const confirmed = await window.showConfirm(`Вы уверены, что хотите удалить биллинг для ${selectedBillingNodes.size} нод(у)?`, 'Удаление биллинга', 'danger');
    if (!confirmed) return;
    try {
        for (const nodeId of selectedBillingNodes) {
            await fetch(`${API_NODES}?id=${nodeId}`, {
                method: 'PUT',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ billing_amount: null, provider_name: null, provider_url: null })
            });
        }
        showToast(`Удалено: ${selectedBillingNodes.size}`, 'success');
        clearBillingSelection();
        loadBillingData();
    } catch (error) {
        console.error(error);
        showToast('Ошибка удаления', 'error');
    }
}

// Экспортируем функции в window для доступа из onclick
window.updateSelectedPaymentDates = updateSelectedPaymentDates;
window.deleteSelectedBillingNodes = deleteSelectedBillingNodes;

function addPayment() {
    const modal = document.getElementById('payment-modal');
    if (!modal) {
        console.error('Payment modal not found');
        return;
    }
    const form = document.getElementById('payment-form');
    if (form) {
        form.reset();
        form.payment_date.value = new Date().toISOString().slice(0, 16);
    }
    populatePaymentProviders();
    modal.classList.remove('hidden');
    modal.classList.add('active');
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function populatePaymentProviders() {
    const select = document.getElementById('payment-provider-id');
    if (!select || !providersList) return;
    
    select.innerHTML = '<option value="">Выбрать провайдера...</option>';
    providersList.forEach(provider => {
        const option = document.createElement('option');
        option.value = provider.id;
        option.textContent = provider.name;
        select.appendChild(option);
    });
}

function closePaymentModal() {
    const modal = document.getElementById('payment-modal');
    if (modal) {
        modal.classList.remove('active');
        setTimeout(() => modal.classList.add('hidden'), 200);
    }
}

function populatePaymentNodes() {
    const select = document.getElementById('payment-node-id');
    if (!select || !window.billingNodesData) return;
    
    select.innerHTML = '<option value="">Выберите ноду</option>';
    window.billingNodesData.forEach(node => {
        const option = document.createElement('option');
        option.value = node.id;
        option.textContent = node.name;
        select.appendChild(option);
    });
}

function populateBillingNodes() {
    const select = document.getElementById('billing-node-select');
    if (!select) return;
    
    // Если данные еще не загружены, загружаем их
    if (!window.billingNodesData) {
        loadBillingData().then(() => {
            populateBillingNodes();
        });
        return;
    }
    
    select.innerHTML = '<option value="">Выберите ноду</option>';
    if (window.billingNodesData && window.billingNodesData.length > 0) {
        window.billingNodesData.forEach(node => {
            const option = document.createElement('option');
            option.value = node.id;
            option.textContent = node.name;
            select.appendChild(option);
        });
    } else {
        select.innerHTML = '<option value="">Ноды для биллинга не найдены</option>';
    }
}

function addProvider() {
    const modal = document.getElementById('provider-modal');
    if (!modal) {
        console.error('Provider modal not found');
        return;
    }
    const form = document.getElementById('provider-form');
    if (form) {
        form.reset();
        document.getElementById('provider-edit-id').value = '';
        form.querySelector('button[type="submit"]').textContent = 'Создать';
    }
    modal.classList.remove('hidden');
    modal.classList.add('active');
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function closeProviderModal() {
    const modal = document.getElementById('provider-modal');
    if (modal) {
        modal.classList.remove('active');
        setTimeout(() => modal.classList.add('hidden'), 200);
    }
}

function refreshProviders() {
    loadProviders();
}

async function loadProviders() {
    try {
        if (window.toggleTableLoader) {
            window.toggleTableLoader('providers-tbody', true);
        }
        const res = await fetch(API_PROVIDERS, {
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' }
        });
        
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            const text = await res.text();
        const data = text ? JSON.parse(text) : { providers: [] };
        
            providersList = data.providers || [];
            renderProviders(providersList);
            populateProviderSelects();
            populatePaymentProviders();
    } catch (error) {
            console.error('Ошибка загрузки провайдеров:', error);
            providersList = [];
            renderProviders(providersList);
            populateProviderSelects();
            populatePaymentProviders();
    } finally {
        if (window.toggleTableLoader) {
            window.toggleTableLoader('providers-tbody', false);
        }
    }
}

function renderProviders(providers) {
    const tbody = document.getElementById('providers-tbody');
    if (!tbody) return;
    
    if (providers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center">Нет провайдеров</td></tr>';
        document.getElementById('providers-pagination').style.display = 'none';
        return;
    }
    
    // Подсчитываем общую сумму и количество серверов для каждого провайдера
    const providersWithStats = providers.map(provider => {
        const nodes = window.billingNodesData?.filter(n => n.provider_id === provider.id) || [];
        const total = nodes.reduce((sum, n) => sum + parseFloat(n.billing_amount || 0), 0);
        return { ...provider, total, servers: nodes };
    });
    
    // Пагинация
    const totalPages = Math.ceil(providersWithStats.length / providersPerPage);
    const startIndex = (providersCurrentPage - 1) * providersPerPage;
    const endIndex = startIndex + providersPerPage;
    const paginatedProviders = providersWithStats.slice(startIndex, endIndex);
    
    tbody.innerHTML = paginatedProviders.map(provider => {
        const servers = provider.servers && provider.servers.length > 0 
            ? provider.servers.map(n => {
                const flag = getCountryFlag(n.country);
                return `<span class="node-tag">${flag} ${n.name}</span>`;
            }).join('')
            : '<span class="text-muted">Нет серверов</span>';
        
        return `
            <tr>
                <td>
                    <div class="table-cell-with-icon">
                        ${getProviderIcon(provider.favicon_url, provider.name, provider.url)}
                        <span>${provider.name}</span>
                    </div>
                </td>
                <td>
                    <div class="table-cell-with-icon">
                        <i data-lucide="link"></i>
                        ${provider.url ? `<a href="${provider.url}" target="_blank" onclick="event.stopPropagation();">${provider.url}</a><i data-lucide="external-link" class="external-link-icon"></i>` : '<span>-</span>'}
                    </div>
                </td>
                <td>
                    <div class="table-cell-with-icon">
                        <i data-lucide="dollar-sign"></i>
                        <span class="provider-total">${provider.total.toFixed(2)} ₽</span>
                    </div>
                </td>
                <td>
                    <div class="table-cell-with-icon">
                        <i data-lucide="server"></i>
                        <div class="provider-servers">${servers}</div>
                    </div>
                </td>
                <td>
                    <div class="table-actions">
                        <button class="icon-btn" onclick="editProvider(${provider.id})" title="Редактировать">
                            <i data-lucide="edit"></i>
                        </button>
                        <button class="icon-btn danger" onclick="deleteProvider(${provider.id})" title="Удалить">
                            <i data-lucide="trash-2"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    
    // Обновление пагинации
    const pagination = document.getElementById('providers-pagination');
    const pageInfo = document.getElementById('providers-page-info');
    const prevBtn = document.getElementById('providers-prev');
    const nextBtn = document.getElementById('providers-next');
    
    if (totalPages > 1) {
        pagination.style.display = 'flex';
        pageInfo.textContent = `Страница ${providersCurrentPage} из ${totalPages}`;
        prevBtn.disabled = providersCurrentPage === 1;
        nextBtn.disabled = providersCurrentPage === totalPages;
    } else {
        pagination.style.display = 'none';
    }
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function changeProvidersPage(direction) {
    const totalPages = Math.ceil(providersList.length / providersPerPage);
    const newPage = providersCurrentPage + direction;
    if (newPage >= 1 && newPage <= totalPages) {
        providersCurrentPage = newPage;
        renderProviders(providersList);
    }
}

window.changeProvidersPage = changeProvidersPage;

function editProvider(id) {
    const provider = providersList.find(p => p.id == id);
    if (!provider) return;
    
    const modal = document.getElementById('provider-modal');
    const form = document.getElementById('provider-form');
    if (!modal || !form) return;
    
    document.getElementById('provider-edit-id').value = provider.id;
    document.getElementById('provider-name').value = provider.name || '';
    document.getElementById('provider-favicon').value = provider.favicon_url || '';
    document.getElementById('provider-url').value = provider.url || '';
    form.querySelector('button[type="submit"]').textContent = 'Сохранить';
    
    modal.classList.remove('hidden');
    modal.classList.add('active');
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

async function deleteProvider(id) {
    const confirmed = await window.showConfirm('Вы уверены, что хотите удалить этого провайдера? Это действие нельзя отменить.', 'Удаление провайдера', 'danger');
    if (!confirmed) return;
    fetch(`${API_PROVIDERS}?id=${id}`, { 
        method: 'DELETE',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' }
    })
        .then(async res => {
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            const text = await res.text();
            return text ? JSON.parse(text) : {};
        })
        .then(data => {
            if (data.success) {
                showToast('Провайдер удален', 'success');
                loadProviders();
            } else {
                throw new Error(data.error || 'Ошибка удаления');
            }
        })
        .catch(error => {
            console.error('Ошибка удаления провайдера:', error);
            showToast(error.message || 'Ошибка удаления', 'error');
        });
}

function showToast(message, type = 'info') {
    if (window.showToast) {
        window.showToast(message, type);
    } else {
        // Fallback если window.showToast еще не загружен
        const toast = document.getElementById('toast') || document.createElement('div');
        toast.id = 'toast';
        toast.textContent = message;
        toast.className = `toast toast-${type}`;
        if (!document.getElementById('toast')) {
            document.body.appendChild(toast);
        }
        toast.classList.remove('hidden');
        setTimeout(() => toast.classList.add('hidden'), 3000);
    }
}

function refreshBilling() {
    loadBillingData();
}

function refreshPayments() {
    loadBillingData();
}

document.addEventListener('DOMContentLoaded', () => {
    loadProviders();
    loadBillingData();
    populatePaymentProviders();
    setInterval(() => loadBillingData(true), 60000); // Обновление каждую минуту без анимации
    
    // Обработчики клика вне модальных окон
    const billingModal = document.getElementById('billing-modal');
    const paymentModal = document.getElementById('payment-modal');
    const providerModal = document.getElementById('provider-modal');
    const updateDateModal = document.getElementById('update-payment-date-modal');
    
    if (billingModal) {
        billingModal.addEventListener('click', (e) => {
            if (e.target === billingModal) closeBillingModal();
        });
    }
    
    if (paymentModal) {
        paymentModal.addEventListener('click', (e) => {
            if (e.target === paymentModal) closePaymentModal();
        });
    }
    
    if (providerModal) {
        providerModal.addEventListener('click', (e) => {
            if (e.target === providerModal) closeProviderModal();
        });
    }
    
    if (updateDateModal) {
        updateDateModal.addEventListener('click', (e) => {
            if (e.target === updateDateModal) closeUpdatePaymentDateModal();
        });
    }
    
    // Инициализация чекбокса "Выбрать все"
    const selectAll = document.getElementById('select-all-billing-nodes');
    if (selectAll) {
        selectAll.addEventListener('change', (e) => {
            const checkboxes = document.querySelectorAll('.billing-node-checkbox');
            checkboxes.forEach(cb => cb.checked = e.target.checked);
            updateBillingSelection();
        });
    }
    
    // Обработчик формы обновления даты
    const updateDateForm = document.getElementById('update-payment-date-form');
    if (updateDateForm) {
        updateDateForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const nodeId = formData.get('node_id');
            const newDate = formData.get('new_date');
            
            try {
                const res = await fetch(`${API_NODES}?id=${nodeId}`, {
                    method: 'PUT',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ next_payment_date: newDate })
                });
                
                if (res.ok) {
                    showToast('Дата обновлена', 'success');
                    closeUpdatePaymentDateModal();
                    loadBillingData();
                } else {
                    throw new Error('Ошибка обновления');
                }
            } catch (error) {
                console.error('Ошибка обновления даты:', error);
                showToast(error.message || 'Ошибка обновления', 'error');
            }
        });
    }
    
    // Обработчик формы биллинга
    const billingForm = document.getElementById('billing-form');
    if (billingForm) {
        billingForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(billingForm);
            const nodeId = formData.get('node_id') || formData.get('node_id_select');
            if (!nodeId) {
                showToast('Выберите ноду', 'error');
                return;
            }
            const nextPayment = formData.get('next_payment_date');
            const dateOnly = nextPayment ? nextPayment.split('T')[0] : null;
            
            try {
                const providerName = formData.get('provider_name');
                const billingAmount = parseFloat(formData.get('billing_amount')) || null;
                const provider = providersList.find(p => p.name === providerName);
                
                const res = await fetch(`${API_NODES}?id=${nodeId}`, {
                    method: 'PUT',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        provider_name: providerName,
                        provider_url: provider?.url || null,
                        billing_amount: billingAmount,
                        next_payment_date: dateOnly,
                    })
                });
                
                if (res.ok) {
                    const text = await res.text();
                    const data = text ? JSON.parse(text) : {};
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    showToast('Биллинг сохранен', 'success');
                    closeBillingModal();
                    loadBillingData();
                } else {
                    const text = await res.text();
                    let errorMsg = 'Ошибка сохранения';
                    try {
                        const data = text ? JSON.parse(text) : {};
                        errorMsg = data.error || errorMsg;
                    } catch {}
                    throw new Error(errorMsg);
                }
            } catch (error) {
                console.error('Ошибка сохранения биллинга:', error);
                showToast(error.message || 'Ошибка сохранения', 'error');
            }
        });
    }
    
    // Обработчик формы платежа
    const paymentForm = document.getElementById('payment-form');
    if (paymentForm) {
        paymentForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const paymentDate = formData.get('payment_date');
            const dateOnly = paymentDate.split('T')[0];
            
            try {
                // Находим ноду по провайдеру
                const providerId = parseInt(formData.get('provider_id'));
                const provider = providersList.find(p => p.id == providerId);
                if (!provider) {
                    throw new Error('Провайдер не найден');
                }
                
                const nodes = window.billingNodesData?.filter(n => n.provider_id === provider.id) || [];
                if (nodes.length === 0) {
                    throw new Error('Ноды для этого провайдера не найдены');
                }
                
                // Создаем платеж для всех нод провайдера
                const amount = parseFloat(formData.get('amount'));
                const promises = nodes.map(node => {
                    const nodeAmount = amount / nodes.length;
                    return fetch(API_PAYMENTS, {
                        method: 'POST',
                        credentials: 'include',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            node_id: node.id,
                            amount: nodeAmount,
                            payment_date: dateOnly
                        })
                    });
                });
                
                const results = await Promise.all(promises);
                
                if (results.every(r => r.ok)) {
                    showToast(`Платежи добавлены для ${nodes.length} нод`, 'success');
                    closePaymentModal();
                    loadBillingData();
                } else {
                    const failed = results.filter(r => !r.ok).length;
                    throw new Error(`Ошибка добавления платежа для ${failed} нод`);
                }
            } catch (error) {
                console.error('Ошибка добавления платежа:', error);
                showToast(error.message || 'Ошибка добавления платежа', 'error');
            }
        });
    }
    
    // Автоматическая генерация favicon_url из URL
    const providerUrlInput = document.getElementById('provider-url');
    const providerFaviconInput = document.getElementById('provider-favicon');
    if (providerUrlInput && providerFaviconInput) {
        let autoGenerated = false;
        
        const generateFavicon = () => {
            const url = providerUrlInput.value.trim();
            if (url) {
                // Генерируем favicon_url из URL только если поле favicon пустое или было автоматически сгенерировано
                if (!providerFaviconInput.value.trim() || autoGenerated) {
                    const faviconUrl = buildFaviconUrl(null, url);
                    if (faviconUrl) {
                        providerFaviconInput.value = faviconUrl;
                        autoGenerated = true;
                        providerFaviconInput.style.color = 'var(--text-muted)';
                        providerFaviconInput.title = 'Автоматически сгенерировано из ссылки для входа';
                    }
                }
            } else {
                autoGenerated = false;
                providerFaviconInput.style.color = '';
                providerFaviconInput.title = '';
            }
        };
        
        // Генерируем при потере фокуса
        providerUrlInput.addEventListener('blur', generateFavicon);
        
        // Генерируем при вводе (с небольшой задержкой)
        let timeout;
        providerUrlInput.addEventListener('input', () => {
            clearTimeout(timeout);
            timeout = setTimeout(generateFavicon, 500);
        });
        
        // Сбрасываем флаг автоматической генерации при ручном редактировании favicon
        providerFaviconInput.addEventListener('input', () => {
            autoGenerated = false;
            providerFaviconInput.style.color = '';
            providerFaviconInput.title = '';
        });
    }
    
    // Обработчик формы провайдера
    const providerForm = document.getElementById('provider-form');
    if (providerForm) {
        providerForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const providerId = document.getElementById('provider-edit-id').value;
            const isEdit = providerId && providerId !== '';
            
            // Если favicon_url пустой, но есть url, сервер сам сгенерирует его
            const faviconUrl = providerFaviconInput?.value.trim() || null;
            const providerUrl = providerUrlInput?.value.trim() || null;
            
            try {
                const url = `${API_PROVIDERS}${isEdit ? `?id=${providerId}` : ''}`;
                const res = await fetch(url, {
                    method: isEdit ? 'PUT' : 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name: document.getElementById('provider-name').value,
                        favicon_url: faviconUrl,
                        url: providerUrl
                    })
                });
                
                if (res.ok) {
                    const text = await res.text();
                    const data = text ? JSON.parse(text) : {};
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    showToast(isEdit ? 'Провайдер обновлен' : 'Провайдер добавлен', 'success');
                    closeProviderModal();
                    loadProviders();
                    loadBillingData();
                } else {
                    const text = await res.text();
                    const data = text ? JSON.parse(text) : {};
                    throw new Error(data.error || 'Ошибка сохранения');
                }
            } catch (error) {
                console.error('Ошибка сохранения провайдера:', error);
                showToast(error.message || 'Ошибка сохранения', 'error');
            }
        });
    }
    
    // Обработчик изменения даты последней оплаты
    const lastPaymentInput = document.getElementById('billing-last-payment');
    const periodInput = document.getElementById('billing-period');
    if (lastPaymentInput && periodInput) {
        lastPaymentInput.addEventListener('change', () => {
            if (lastPaymentInput.value && periodInput.value) {
                const date = new Date(lastPaymentInput.value);
                date.setDate(date.getDate() + parseInt(periodInput.value));
                document.getElementById('billing-next-payment').value = date.toISOString().split('T')[0];
            }
        });
        periodInput.addEventListener('change', () => {
            if (lastPaymentInput.value && periodInput.value) {
                const date = new Date(lastPaymentInput.value);
                date.setDate(date.getDate() + parseInt(periodInput.value));
                document.getElementById('billing-next-payment').value = date.toISOString().split('T')[0];
            }
        });
    }
});


function populateProviderSelects() {
    const selects = document.querySelectorAll('[data-provider-select="name"]');
    selects.forEach(select => {
        const currentValue = select.value;
        select.innerHTML = '<option value="">Выбрать провайдера...</option>';
        providersList.forEach(provider => {
            const option = document.createElement('option');
            option.value = provider.name;
            option.textContent = provider.name;
            if (currentValue === provider.name) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    });
}

window.populateProviderSelects = populateProviderSelects;

function switchBillingTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.toggle('active', content.id === `${tab}-tab`);
    });
    
    if (tab === 'stats') {
        loadBillingStats();
    }
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

async function loadBillingStats() {
    try {
        const [paymentsRes, providersRes] = await Promise.all([
            fetch(API_PAYMENTS, { credentials: 'include' }),
            fetch(API_PROVIDERS, { credentials: 'include' })
        ]);
        
        if (!paymentsRes.ok || !providersRes.ok) throw new Error('HTTP error');
        
        const paymentsText = await paymentsRes.text();
        const providersText = await providersRes.text();
        const paymentsData = paymentsText ? JSON.parse(paymentsText) : { payments: [] };
        const providersData = providersText ? JSON.parse(providersText) : { providers: [] };
        
        const payments = paymentsData.payments || [];
        const providers = providersData.providers || [];
        
        renderMonthlyChart(payments);
        renderProviderChart(payments, providers);
        renderDailyChart(payments);
        renderUpcomingChart(nodes || [], payments);
        renderPaymentsTimelineChart(payments);
    } catch (error) {
        console.error('Ошибка загрузки статистики:', error);
    }
}

function renderMonthlyChart(payments) {
    const ctx = document.getElementById('monthly-chart');
    if (!ctx) return;
    
    // Группируем по месяцам
    const monthlyData = {};
    payments.forEach(p => {
        const date = new Date(p.payment_date);
        const monthKey = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
        if (!monthlyData[monthKey]) {
            monthlyData[monthKey] = 0;
        }
        monthlyData[monthKey] += parseFloat(p.amount || 0);
    });
    
    const months = Object.keys(monthlyData).sort();
    const amounts = months.map(m => monthlyData[m]);
    
    if (monthlyChart) {
        monthlyChart.destroy();
    }
    
    monthlyChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: months.map(m => {
                const [year, month] = m.split('-');
                return new Date(year, month - 1).toLocaleDateString('ru-RU', { month: 'short', year: 'numeric' });
            }),
            datasets: [{
                label: 'Расходы, ₽',
                data: amounts,
                borderColor: 'rgb(59, 130, 246)',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value + ' ₽';
                        }
                    }
                }
            }
        }
    });
}

function renderProviderChart(payments, providers) {
    const ctx = document.getElementById('provider-chart');
    if (!ctx) return;
    
    // Группируем по провайдерам
    const providerData = {};
    payments.forEach(p => {
        const providerName = p.provider_name || 'Неизвестно';
        if (!providerData[providerName]) {
            providerData[providerName] = 0;
        }
        providerData[providerName] += parseFloat(p.amount || 0);
    });
    
    const labels = Object.keys(providerData);
    const data = Object.values(providerData);
    const colors = [
        'rgba(59, 130, 246, 0.8)',
        'rgba(16, 185, 129, 0.8)',
        'rgba(245, 158, 11, 0.8)',
        'rgba(239, 68, 68, 0.8)',
        'rgba(139, 92, 246, 0.8)',
        'rgba(236, 72, 153, 0.8)'
    ];
    
    if (providerChart) {
        providerChart.destroy();
    }
    
    providerChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors.slice(0, labels.length),
                borderWidth: 2,
                borderColor: 'var(--bg-primary)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

function renderDailyChart(payments) {
    const ctx = document.getElementById('daily-chart');
    if (!ctx) return;
    
    const now = new Date();
    const start = new Date(now);
    start.setDate(start.getDate() - 29); // последние 30 дней
    
    const days = [];
    const dayTotals = {};
    for (let i = 0; i < 30; i++) {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        const key = d.toISOString().split('T')[0];
        days.push(key);
        dayTotals[key] = 0;
    }
    
    payments.forEach(p => {
        const d = new Date(p.payment_date);
        const key = d.toISOString().split('T')[0];
        if (dayTotals[key] !== undefined) {
            dayTotals[key] += parseFloat(p.amount || 0);
        }
    });
    
    const labels = days.map(d => {
        const [y, m, day] = d.split('-');
        return `${day}.${m}`;
    });
    const data = days.map(d => dayTotals[d]);
    
    if (window.dailyChart) window.dailyChart.destroy();
    window.dailyChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Платежи за день, ₽',
                data,
                backgroundColor: 'rgba(16, 185, 129, 0.7)',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: v => v + ' ₽'
                    }
                }
            }
        }
    });
}

function renderUpcomingChart(nodes, payments) {
    const ctx = document.getElementById('upcoming-chart');
    if (!ctx) return;
    
    // строим по полю next_payment_date из нод
    const now = new Date();
    const end = new Date(now);
    end.setDate(end.getDate() + 30); // 30 дней вперёд
    
    const dayCounts = {};
    nodes.forEach(n => {
        if (!n.next_payment_date) return;
        const d = new Date(n.next_payment_date);
        if (d >= now && d <= end) {
            const key = d.toISOString().split('T')[0];
            if (!dayCounts[key]) dayCounts[key] = 0;
            dayCounts[key] += 1;
        }
    });
    
    const sortedKeys = Object.keys(dayCounts).sort();
    const labels = sortedKeys.map(d => {
        const [y, m, day] = d.split('-');
        return `${day}.${m}`;
    });
    const data = sortedKeys.map(d => dayCounts[d]);
    
    if (window.upcomingChart) window.upcomingChart.destroy();
    window.upcomingChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Кол-во нод с оплатой',
                data,
                borderColor: 'rgba(239, 68, 68, 0.9)',
                backgroundColor: 'rgba(239, 68, 68, 0.15)',
                tension: 0.3,
                fill: true,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
}

function renderPaymentsTimelineChart(payments) {
    const ctx = document.getElementById('payments-timeline-chart');
    if (!ctx) return;
    
    // Сортируем по дате
    const sortedPayments = [...payments].sort((a, b) => 
        new Date(a.payment_date) - new Date(b.payment_date)
    );
    
    const dates = sortedPayments.map(p => {
        const d = new Date(p.payment_date);
        return d.toLocaleDateString('ru-RU');
    });
    const amounts = sortedPayments.map(p => parseFloat(p.amount || 0));
    
    if (paymentsTimelineChart) {
        paymentsTimelineChart.destroy();
    }
    
    paymentsTimelineChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: dates,
            datasets: [{
                label: 'Сумма платежа, ₽',
                data: amounts,
                backgroundColor: 'rgba(59, 130, 246, 0.6)',
                borderColor: 'rgb(59, 130, 246)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value + ' ₽';
                        }
                    }
                }
            }
        }
    });
}

window.switchBillingTab = switchBillingTab;

function openProviderModal() {
    const name = prompt('Введите имя провайдера:');
    if (name) {
        const url = prompt('Введите URL провайдера (опционально):');
        providersList.push({ id: providersList.length + 1, name, url: url || '' });
        populateProviderSelects();
    }
}

