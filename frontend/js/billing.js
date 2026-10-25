// Биллинг система
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
const datePickers = {}; // хранилище наших однодневных календарей

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
    const fallbackLetter = providerName ? escHtmlAttr(providerName.charAt(0).toUpperCase()) : '?';
    const safeProviderName = escHtmlAttr(providerName || '');
    
    if (iconSrc) {
        return `<span class="provider-icon-wrapper"><img src="${escHtmlAttr(iconSrc)}" alt="${safeProviderName}" class="provider-favicon" loading="lazy" onerror="this.onerror=null; const wrapper=this.closest('.provider-icon-wrapper'); if(wrapper && !wrapper.querySelector('.provider-icon')){ wrapper.innerHTML='<div class=&quot;provider-icon&quot;>${fallbackLetter}</div>'; }"></span>`;
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
        
        const safeUrl = (node.provider_url && /^https?:\/\//i.test(node.provider_url)) ? node.provider_url : '#';
        return `
            <tr data-node-id="${node.id}" style="cursor: pointer;">
                <td onclick="event.stopPropagation();">
                    <input type="checkbox" class="billing-node-checkbox" value="${node.id}" onchange="updateBillingSelection()">
                </td>
                <td onclick="editNodeBilling(${node.id})">
                    <div class="table-cell-with-icon">
                        ${getProviderIcon(node.favicon_url, node.provider_name, node.provider_url)}
                        <div>
                            ${node.provider_name ? `<a href="${safeUrl}" target="_blank" rel="noopener" onclick="event.stopPropagation();">${esc(node.provider_name)}</a><i data-lucide="external-link" class="external-link-icon"></i>` : '-'}
                        </div>
                    </div>
                </td>
                <td onclick="editNodeBilling(${node.id})">
                    <div class="table-cell-with-icon">
                        ${countryFlag}
                        <span>${esc(node.name)}</span>
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
                        <span>${esc(providerName || payment.node_name || `node-${payment.node_id}`)}</span>
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
window.billingDatePickers = datePickers;

const initDatePicker = (pickerId) => { // инициализация однодневного календаря
    const displayInput = document.getElementById(`${pickerId}-display`);
    const hiddenInput = document.getElementById(pickerId);
    const panel = document.getElementById(`${pickerId}-panel`);
    const calendarRoot = panel?.querySelector('.date-picker-calendar');
    if (!displayInput || !hiddenInput || !panel || !calendarRoot) return;

    const state = { monthCursor: new Date(), selected: null };

    const formatDate = (d) => d.toLocaleDateString('ru-RU');
    const toIsoDate = (d) => d.toISOString().split('T')[0];
    const sameDay = (a, b) => a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();

    const render = () => {
        const base = new Date(state.monthCursor.getFullYear(), state.monthCursor.getMonth(), 1);
        const year = base.getFullYear();
        const month = base.getMonth();
        const firstDay = new Date(year, month, 1).getDay() || 7;
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const prevDays = firstDay - 1;
        const today = new Date();

        let html = `<div class="date-picker-header">
            <div class="date-picker-nav">
                <button type="button" id="${pickerId}-prev"><</button>
                <button type="button" id="${pickerId}-next">></button>
            </div>
            <span>${base.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' })}</span>
        </div>`;
        const weekDays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        html += '<div class="date-picker-grid">';
        weekDays.forEach(w => { html += `<div class="date-picker-weekday">${w}</div>`; });
        for (let i = 0; i < prevDays; i++) {
            html += '<div class="date-picker-day other-month"></div>';
        }
        for (let day = 1; day <= daysInMonth; day++) {
            const d = new Date(year, month, day);
            let cls = 'date-picker-day';
            if (sameDay(d, state.selected)) cls += ' selected';
            if (sameDay(d, today) && !sameDay(d, state.selected)) cls += ' today';
            html += `<button type="button" class="${cls}" data-day="${day}">${day}</button>`;
        }
        html += '</div>';
        html += `<div class="date-picker-footer">
            <span>${state.selected ? formatDate(state.selected) : '—'}</span>
            <button type="button" class="btn-outline" id="${pickerId}-today">Сегодня</button>
        </div>`;
        calendarRoot.innerHTML = html;

        document.getElementById(`${pickerId}-prev`).onclick = () => {
            state.monthCursor = new Date(year, month - 1, 1);
            render();
        };
        document.getElementById(`${pickerId}-next`).onclick = () => {
            state.monthCursor = new Date(year, month + 1, 1);
            render();
        };
        document.getElementById(`${pickerId}-today`).onclick = () => {
            const t = new Date();
            setDate(t);
            state.monthCursor = new Date(t.getFullYear(), t.getMonth(), 1);
            render();
        };
        calendarRoot.querySelectorAll('.date-picker-day[data-day]').forEach(btn => {
            btn.addEventListener('click', () => {
                const day = Number(btn.dataset.day);
                const d = new Date(year, month, day);
                setDate(d);
                render(); // перерисовываем, но панель не закрываем, можно менять месяц/дату дальше
            });
        });
    };

    const hidePanel = () => {
        panel.classList.add('hidden');
    };

    const setDate = (date) => {
        state.selected = date;
        hiddenInput.value = toIsoDate(date);
        displayInput.value = formatDate(date);
    };

    displayInput.addEventListener('click', (e) => {
        e.stopPropagation();
        panel.classList.remove('hidden');
        if (!state.selected && hiddenInput.value) {
            const d = new Date(hiddenInput.value);
            if (!isNaN(d.getTime())) state.selected = d;
        }
        if (state.selected) {
            state.monthCursor = new Date(state.selected.getFullYear(), state.selected.getMonth(), 1);
        }
        render();
    });

    // клики внутри панели не должны закрывать её через document.click
    panel.addEventListener('click', (e) => {
        e.stopPropagation();
    });

    document.addEventListener('click', (e) => {
        if (!panel.contains(e.target) && !displayInput.contains(e.target)) {
            hidePanel();
        }
    });

    datePickers[pickerId] = { setDate, getDate: () => state.selected };
};

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
        if (!isNaN(date.getTime()) && datePickers['billing-next-payment']) {
            datePickers['billing-next-payment'].setDate(date);
        }
    } else if (datePickers['billing-next-payment']) {
        datePickers['billing-next-payment'].setDate(new Date());
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
            await fetch(`${API_NODES}?id=${encodeURIComponent(nodeId)}`, {
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
            await fetch(`${API_NODES}?id=${encodeURIComponent(nodeId)}`, {
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
    }
    if (datePickers['payment-date']) {
        datePickers['payment-date'].setDate(new Date());
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

    if (!window.billingNodesData) {
        loadBillingData(); // loadBillingData сам всё populate'нет и отрисует
        return;
    }

    select.innerHTML = '<option value="">Выберите ноду</option>';
    if (window.billingNodesData.length > 0) {
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
let providersPerPage = 9; // 3 колонки × 3 ряда

async function loadProviders() {
    try {
        const res = await fetch(API_PROVIDERS, {
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' }
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
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
    }
}

function getFilteredProviders() {
    const search = (document.getElementById('providers-search')?.value || '').toLowerCase().trim();
    if (!search) return providersList;
    return providersList.filter(p =>
        (p.name || '').toLowerCase().includes(search) ||
        (p.url  || '').toLowerCase().includes(search)
    );
}

function updateProviderStats(providers) {
    const totalEl   = document.getElementById('providers-total');
    const costEl    = document.getElementById('providers-cost');
    const serversEl = document.getElementById('providers-servers');

    const allNodes = Array.isArray(window.billingNodesData) ? window.billingNodesData : [];
    const providerNames = new Set(
        providers.map(p => (p.name || '').toLowerCase()).filter(Boolean)
    );
    const linked = allNodes.filter(n =>
        n.provider_name && providerNames.has(n.provider_name.toLowerCase())
    );
    const totalCost = linked.reduce((s, n) => s + parseFloat(n.billing_amount || 0), 0);

    if (totalEl)   totalEl.textContent = providers.length;
    if (costEl)    costEl.textContent = totalCost.toFixed(2) + ' ₽';
    if (serversEl) serversEl.textContent = linked.length;
}

function renderProviderCard(provider) {
    const icon = getProviderIcon(provider.favicon_url, provider.name, provider.url);
    const safeUrl = provider.url && /^https?:\/\//i.test(provider.url) ? provider.url : null;

    const allNodes = Array.isArray(window.billingNodesData) ? window.billingNodesData : [];
    const nodes = allNodes.filter(n =>
        n.provider_name && provider.name &&
        n.provider_name.toLowerCase() === provider.name.toLowerCase()
    );
    const total = nodes.reduce((s, n) => s + parseFloat(n.billing_amount || 0), 0);
    const serversCount = nodes.length;

    const costHtml = total > 0
        ? `<span class="provider-card-metric-value">${total.toFixed(2)} ₽</span><span class="provider-card-metric-sub">/мес</span>`
        : '<span class="provider-card-metric-empty">— не указано —</span>';

    const serverWord = serversCount === 1 ? 'сервер'
                     : (serversCount >= 2 && serversCount <= 4 ? 'сервера' : 'серверов');

    const serversHtml = serversCount > 0
        ? nodes.slice(0, 5).map(n => {
            const flag = getCountryFlag(n.country);
            return `<span class="node-tag" title="${escHtmlAttr(n.name)}">${flag} ${esc(n.name)}</span>`;
        }).join('') + (serversCount > 5
            ? `<span class="node-tag node-tag-more">+${serversCount - 5}</span>` : '')
        : '<span class="provider-card-metric-empty">Нет привязанных серверов</span>';

    return `
        <div class="provider-card">
            <div class="provider-card-header">
                <div class="provider-card-favicon">${icon}</div>
                <div class="provider-card-info">
                    <div class="provider-card-name" title="${escHtmlAttr(provider.name)}">${esc(provider.name)}</div>
                    ${safeUrl
                        ? `<a class="provider-card-url" href="${escHtmlAttr(safeUrl)}" target="_blank" rel="noopener noreferrer" title="${escHtmlAttr(provider.url)}">${esc(provider.url)}</a>`
                        : '<div class="provider-card-url provider-card-url-empty">URL не указан</div>'}
                </div>
                <div class="provider-card-actions">
                    <button class="icon-btn" onclick="editProvider(${provider.id})" title="Редактировать">
                        <i data-lucide="pencil"></i>
                    </button>
                    <button class="icon-btn danger" onclick="deleteProvider(${provider.id})" title="Удалить">
                        <i data-lucide="trash-2"></i>
                    </button>
                </div>
            </div>
            <div class="provider-card-metrics">
                <div class="provider-card-metric">
                    <i data-lucide="wallet"></i>
                    ${costHtml}
                </div>
                <div class="provider-card-metric">
                    <i data-lucide="server"></i>
                    <span class="provider-card-metric-value">${serversCount}</span>
                    <span class="provider-card-metric-sub">${serverWord}</span>
                </div>
            </div>
            <div class="provider-card-servers">${serversHtml}</div>
        </div>
    `;
}

function renderProviders(providers) {
    const container = document.getElementById('providers-list');
    if (!container) return;

    updateProviderStats(providers);

    const filtered = getFilteredProviders();
    const search = (document.getElementById('providers-search')?.value || '').trim();

    if (filtered.length === 0) {
        container.innerHTML = `
            <div class="providers-empty">
                <i data-lucide="building-2"></i>
                <h3>${search ? 'Ничего не найдено' : 'Провайдеров пока нет'}</h3>
                <p>${search ? 'Попробуйте изменить поисковый запрос' : 'Добавьте первого провайдера, чтобы начать учёт'}</p>
                ${!search ? '<button class="primary" onclick="addProvider()" style="margin-top: 16px;"><i data-lucide="plus"></i> Добавить провайдера</button>' : ''}
            </div>`;
        const pag = document.getElementById('providers-pagination');
        if (pag) pag.style.display = 'none';
        if (typeof lucide !== 'undefined') lucide.createIcons();
        return;
    }

    const totalPages = Math.ceil(filtered.length / providersPerPage);
    if (providersCurrentPage > totalPages) providersCurrentPage = totalPages;
    if (providersCurrentPage < 1) providersCurrentPage = 1;

    const start = (providersCurrentPage - 1) * providersPerPage;
    const paginated = filtered.slice(start, start + providersPerPage);

    container.innerHTML = paginated.map(renderProviderCard).join('');

    const pag      = document.getElementById('providers-pagination');
    const pageInfo = document.getElementById('providers-page-info');
    const prevBtn  = document.getElementById('providers-prev');
    const nextBtn  = document.getElementById('providers-next');

    if (totalPages > 1) {
        if (pag)      pag.style.display = 'flex';
        if (pageInfo) pageInfo.textContent = `Страница ${providersCurrentPage} из ${totalPages}`;
        if (prevBtn)  prevBtn.disabled = providersCurrentPage === 1;
        if (nextBtn)  nextBtn.disabled = providersCurrentPage === totalPages;
    } else {
        if (pag) pag.style.display = 'none';
    }

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function filterProviders() {
    providersCurrentPage = 1;
    renderProviders(providersList);
}
window.filterProviders = filterProviders;

function changeProvidersPage(direction) {
    const filtered = getFilteredProviders();
    const totalPages = Math.ceil(filtered.length / providersPerPage);
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

const showToast = (message, type = 'info') => {
    if (typeof window.showToast === 'function') window.showToast(message, type);
};

function refreshBilling() {
    loadBillingData();
}

function refreshPayments() {
    loadBillingData();
}

// Закрытие основного модального окна биллинга ноды
function closeBillingModal() {
    const modal = document.getElementById('billing-modal'); // находим модалку
    if (modal) { // если есть в DOM
        modal.classList.remove('active'); // убираем класс активного состояния
        setTimeout(() => modal.classList.add('hidden'), 200); // прячем после короткой анимации
    }
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
                const res = await fetch(`${API_NODES}?id=${encodeURIComponent(nodeId)}`, {
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
                
                const res = await fetch(`${API_NODES}?id=${encodeURIComponent(nodeId)}`, {
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
            const paymentDate = formData.get('payment_date') || '';
            const dateOnly = paymentDate.split('T')[0] || paymentDate;
            
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
                if (datePickers['billing-next-payment']) {
                    datePickers['billing-next-payment'].setDate(date);
                }
            }
        });
        periodInput.addEventListener('change', () => {
            if (lastPaymentInput.value && periodInput.value) {
                const date = new Date(lastPaymentInput.value);
                date.setDate(date.getDate() + parseInt(periodInput.value));
                if (datePickers['billing-next-payment']) {
                    datePickers['billing-next-payment'].setDate(date);
                }
            }
        });
    }

    // Инициализация наших календарей
    initDatePicker('billing-next-payment');
    initDatePicker('payment-date');
    initDatePicker('update-payment-new-date');
    // значения по умолчанию
    if (datePickers['payment-date']) datePickers['payment-date'].setDate(new Date());
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
        const [paymentsRes, providersRes, nodesRes] = await Promise.all([
            fetch(API_PAYMENTS, { credentials: 'include' }),
            fetch(API_PROVIDERS, { credentials: 'include' }),
            fetch(API_NODES, { credentials: 'include' })
        ]);
        
        if (!paymentsRes.ok || !providersRes.ok) throw new Error('HTTP error');
        
        const paymentsText = await paymentsRes.text();
        const providersText = await providersRes.text();
        const paymentsData = paymentsText ? JSON.parse(paymentsText) : { payments: [] };
        const providersData = providersText ? JSON.parse(providersText) : { providers: [] };
        
        const payments = paymentsData.payments || [];
        const providers = providersData.providers || [];
        
        // Загружаем ноды для графика предстоящих платежей
        let nodes = [];
        if (nodesRes && nodesRes.ok) {
            const nodesText = await nodesRes.text();
            const nodesData = nodesText ? JSON.parse(nodesText) : {};
            nodes = nodesData?.nodes ?? nodesData?.data ?? [];
        }
        
        renderMonthlyChart(payments);
        renderProviderChart(payments, providers);
        renderDailyChart(payments);
        renderUpcomingChart(nodes, payments);
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


// Импорт и расходы
window.openExpenseImport = function() { switchBillingTab('imports'); document.getElementById('billing-import-type').value='expenses'; };
window.openDomainImport = function() { switchBillingTab('imports'); document.getElementById('billing-import-type').value='domains'; };

async function startBillingImport() {
    const type = document.getElementById('billing-import-type').value;
    const fileInput = document.getElementById('billing-import-file');
    const resDiv = document.getElementById('billing-import-result');
    if (!fileInput.files || fileInput.files.length === 0) {
        resDiv.textContent = 'Выберите файл';
        return;
    }
    const formData = new FormData();
    formData.append('type', type);
    formData.append('file', fileInput.files[0]);
    resDiv.textContent = 'Загрузка...';
    try {
        const resp = await fetch(`${API_BASE}/billing_import.php`, {
            method: 'POST',
            credentials: 'include',
            body: formData
        });
        const data = await resp.json();
        if (data.success) {
            resDiv.textContent = `Импортировано: ${data.imported}, сопоставлено: ${data.matched}`;
            loadBillingImports();
            if (type === 'expenses') loadBillingExpenses();
            if (type === 'domains') loadBillingDomains();
        } else {
            resDiv.textContent = data.error || 'Ошибка импорта';
        }
    } catch (e) {
        console.error(e);
        resDiv.textContent = 'Ошибка импорта';
    }
}
window.startBillingImport = startBillingImport;

async function loadBillingImports() {
    try {
        const resp = await fetch(`${API_BASE}/billing_import.php?type=imports`, { credentials: 'include' });
        const data = await resp.json();
        const tbody = document.getElementById('billing-imports-tbody');
        if (!tbody) return;
        const imports = data.imports || [];
        if (imports.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Нет данных</td></tr>';
            return;
        }
        tbody.innerHTML = imports.map(im => `
            <tr>
                <td>${im.id}</td>
                <td>${esc(im.type)}</td>
                <td>${esc(im.filename || '-')}</td>
                <td>${im.imported_rows || 0}</td>
                <td>${im.matched_rows || 0}</td>
                <td>${esc(im.status)}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
    }
}
window.loadBillingImports = loadBillingImports;

async function loadBillingExpenses() {
    try {
        const resp = await fetch(`${API_BASE}/billing_import.php?type=expenses`, { credentials: 'include' });
        const data = await resp.json();
        const tbody = document.getElementById('billing-expenses-tbody');
        if (!tbody) return;
        const expenses = data.expenses || [];
        if (expenses.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Нет данных</td></tr>';
            return;
        }
        tbody.innerHTML = expenses.map(e => {
            const matched = e.matched_node_id ? `VPS: ${esc(e.node_name||'')}` : (e.matched_domain_id ? `Домен: ${esc(e.domain_name||'')}` : '<span style="color: var(--text-tertiary);">не сопоставлено</span>');
            const d = e.expense_date ? new Date(e.expense_date).toLocaleDateString('ru-RU') : '-';
            return `<tr>
                <td>${d}</td>
                <td title="${esc(e.description||'')}">${esc((e.description||'').substring(0,80))}${(e.description||'').length>80?'...':''}</td>
                <td>${esc(e.account_ref||'-')}</td>
                <td>${parseFloat(e.amount||0).toFixed(2)} ₽</td>
                <td>${matched}</td>
                <td><button class="icon-btn" onclick='openExpenseMatch(${e.id}, ${JSON.stringify(e.description||'')})'><i data-lucide="link"></i></button></td>
            </tr>`;
        }).join('');
        if (typeof lucide !== 'undefined') lucide.createIcons();
    } catch (e) {
        console.error(e);
    }
}
window.loadBillingExpenses = loadBillingExpenses;




async function loadBillingDomains() {
    try {
        const resp = await fetch(`${API_BASE}/billing_import.php?type=domains`, { credentials: 'include' });
        const data = await resp.json();
        const tbody = document.getElementById('billing-domains-tbody');
        if (!tbody) return;
        const domains = data.domains || [];
        if (domains.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Нет данных</td></tr>';
            return;
        }
        tbody.innerHTML = domains.map(d => {
            const exp = d.expires_at ? new Date(d.expires_at).toLocaleDateString('ru-RU') : '-';
            const price = d.price_per_year ? parseFloat(d.price_per_year).toFixed(2) + ' ₽' : '-';
            return `<tr>
                <td><strong>${esc(d.name)}</strong></td>
                <td>${esc(d.zone||'-')}</td>
                <td>${esc(d.project||'-')}</td>
                <td>${exp}</td>
                <td>${price}</td>
                <td>${esc(d.status)}</td>
            </tr>`;
        }).join('');
        if (typeof lucide !== 'undefined') lucide.createIcons();
    } catch (e) {
        console.error(e);
    }
}
window.loadBillingDomains = loadBillingDomains;

let _expenseMatchData = {id:null};
window.openExpenseMatch = function(id, desc) {
    _expenseMatchData.id = id;
    document.getElementById('expense-match-id').value = id;
    document.getElementById('expense-match-desc').textContent = desc || '';
    // fill nodes
    const nodeSel = document.getElementById('expense-match-node');
    nodeSel.innerHTML = '<option value="">Не сопоставлять</option>';
    if (window.billingNodesData) {
        window.billingNodesData.forEach(n => {
            const opt = document.createElement('option');
            opt.value = n.id;
            opt.textContent = n.name + (n.provider_name ? ' ('+n.provider_name+')' : '');
            nodeSel.appendChild(opt);
        });
    }
    // domains
    const domSel = document.getElementById('expense-match-domain');
    domSel.innerHTML = '<option value="">Не сопоставлять</option>';
    fetch(`${API_BASE}/billing_import.php?type=domains`, {credentials:'include'}).then(r=>r.json()).then(data=>{
        (data.domains||[]).forEach(d=>{
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.name;
            domSel.appendChild(opt);
        });
    }).catch(()=>{});
    const modal = document.getElementById('expense-match-modal');
    modal.classList.remove('hidden');
    modal.classList.add('active');
    if (typeof lucide !== 'undefined') lucide.createIcons();
};
window.closeExpenseMatchModal = function() {
    const modal = document.getElementById('expense-match-modal');
    if (modal) { modal.classList.remove('active'); setTimeout(()=>modal.classList.add('hidden'),200); }
};
window.saveExpenseMatch = async function(e) {
    e.preventDefault();
    const id = document.getElementById('expense-match-id').value;
    const nodeId = document.getElementById('expense-match-node').value;
    const domainId = document.getElementById('expense-match-domain').value;
    try {
        const resp = await fetch(`${API_BASE}/billing_expense_match.php`, {
            method: 'POST',
            credentials: 'include',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({expense_id:id, node_id:nodeId||null, domain_id:domainId||null})
        });
        const data = await resp.json();
        if (data.success) {
            showToast('Сопоставлено', 'success');
            closeExpenseMatchModal();
            loadBillingExpenses();
            loadBillingData();
        } else {
            showToast(data.error||'Ошибка', 'error');
        }
    } catch (ex) {
        console.error(ex);
        showToast('Ошибка', 'error');
    }
};

const _origSwitch = window.switchBillingTab;
window.switchBillingTab = function(tab) {
    if (typeof _origSwitch === 'function') _origSwitch(tab);
    if (tab === 'expenses') loadBillingExpenses();
    if (tab === 'domains') loadBillingDomains();
    if (tab === 'imports') loadBillingImports();
};

window.switchBillingUnifiedTab = function(tab) {
    document.querySelectorAll('.tabs .tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-tab') === tab);
    });
    document.querySelectorAll('.tab-content').forEach(tc => {
        tc.classList.toggle('active', tc.id === tab + '-tab');
    });
    if (tab === 'expenses') loadBillingExpenses();
    if (tab === 'domains') loadBillingDomains();
    if (tab === 'imports') loadBillingImports();
    if (tab === 'providers') loadProviders();
    if (tab === 'stats') {
        loadBillingData();
        loadPaymentsHistory();
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
};

document.addEventListener('DOMContentLoaded', function() {
    try {
        if (typeof loadBillingData === 'function') loadBillingData();
        if (typeof loadPaymentsHistory === 'function') loadPaymentsHistory();
        if (typeof populateProviderSelects === 'function') populateProviderSelects();
    } catch (e) {
        console.error('billing init error', e);
    }
});
