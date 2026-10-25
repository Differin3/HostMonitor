/* ============================================================
 * Биллинг — полностью переработан
 * Совместим с billing.php (карточки провайдеров, вкладки,
 * модалки pay/link, чарты paymentsChart/providersChart)
 * Требует: api/billing_payment_link.php
 * ============================================================ */
'use strict';

/* ==================== КОНСТАНТЫ ==================== */
const API_URL           = typeof API_BASE !== 'undefined' ? API_BASE : '/api';
const API_NODES         = `${API_URL}/nodes.php`;
const API_PROVIDERS     = `${API_URL}/providers.php`;
const API_PAYMENTS      = `${API_URL}/payments.php`;
const API_PAYMENT_LINK  = `${API_URL}/billing_payment_link.php`;
const API_IMPORT        = `${API_URL}/billing_import.php`;
const API_EXPENSE_MATCH = `${API_URL}/billing_expense_match.php`;

/* ==================== ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ ==================== */
let selectedBillingNodes = new Set();
let providersList = [];
let providersCurrentPage = 1;
let providersPerPage = 9;
let monthlyChart = null;
let providerChart = null;

/* ==================== УТИЛИТЫ ==================== */
(function () {
    function escImpl(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    // Ставим в window только если там ещё ничего нет — не перетираем глобальные хелперы из layout.php
    if (typeof window.esc !== 'function') window.esc = escImpl;
    if (typeof window.escHtmlAttr !== 'function') window.escHtmlAttr = window.esc;
})();

// Локальные алиасы. ВАЖНО: именно `var`, не `function` —
// `function esc(){}` в глобальном scope снова перезаписал бы window.esc и дал бы рекурсию.
var esc = window.esc;
var escHtmlAttr = window.escHtmlAttr;

function showToastSafe(msg, type) {
    type = type || 'info';
    if (typeof window.showToast === 'function') window.showToast(msg, type);
    else console.log('[toast:' + type + ']', msg);
}

function getCsrfToken() {
    if (typeof window.CSRF_TOKEN === 'string' && window.CSRF_TOKEN) return window.CSRF_TOKEN;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    const m = document.cookie.match(/(?:^|;\s*)csrf_token=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

function toggleTableLoader(tableId, show) {
    const el = document.getElementById(tableId);
    if (!el) return;
    const c = el.closest('.table-container');
    if (c) c.classList.toggle('is-loading', !!show);
}

function buildFaviconUrl(faviconUrl, providerUrl) {
    const src = faviconUrl || providerUrl;
    if (!src) return null;
    const t = String(src).trim();
    if (!t) return null;
    if (/^https?:\/\//i.test(t)) {
        if (faviconUrl) return t;
        try { return 'https://www.google.com/s2/favicons?sz=64&domain=' + new URL(t).hostname; }
        catch (e) { return t; }
    }
    const d = t.split('/')[0].split('?')[0].split('#')[0];
    return 'https://www.google.com/s2/favicons?sz=64&domain=' + d;
}

function getProviderIcon(faviconUrl, providerName, providerUrl) {
    const src = buildFaviconUrl(faviconUrl, providerUrl);
    const letter = providerName ? escHtmlAttr(String(providerName).charAt(0).toUpperCase()) : '?';
    const safeName = escHtmlAttr(providerName || '');
    if (src) {
        return '<span class="provider-icon-wrapper"><img src="' + escHtmlAttr(src) + '" alt="' + safeName + '" class="provider-favicon" loading="lazy" onerror="this.onerror=null; var w=this.closest(\'.provider-icon-wrapper\'); if(w \&\& !w.querySelector(\'.provider-icon\')){ w.innerHTML=\'<div class=&quot;provider-icon&quot;>' + letter + '</div>\'; }"></span>';
    }
    if (providerName) return '<div class="provider-icon">' + letter + '</div>';
    return '<div class="provider-icon-placeholder">?</div>';
}

function getCountryFlag(country) {
    const flags = { RU:'🇷🇺', NL:'🇳🇱', DE:'🇩🇪', US:'🇺🇸', GB:'🇬🇧', FR:'🇫🇷', PL:'🇵🇱', FI:'🇫🇮', SG:'🇸🇬', JP:'🇯🇵' };
    return flags[country] || '';
}
/* ==================== БИЛЛИНГ НОДЫ (добавить/редактировать) ==================== */

function openNodeBillingModal(nodeId) {
    const modal = document.getElementById('node-billing-modal');
    if (!modal) return;

    const idEl = document.getElementById('node-billing-id');
    const titleEl = document.getElementById('node-billing-title');
    const nodeSel = document.getElementById('node-billing-node-select');
    const providerSel = document.getElementById('node-billing-provider');
    const amountEl = document.getElementById('node-billing-amount');
    const periodEl = document.getElementById('node-billing-period');
    const dateEl = document.getElementById('node-billing-next-payment');

    // Заполняем селект нод (все ноды, не только те что в биллинге)
    nodeSel.innerHTML = '<option value="">Выберите ноду</option>';
    (window.billingNodesData || []).forEach(n => {
        const opt = document.createElement('option');
        opt.value = n.id;
        opt.textContent = n.name + (n.provider_name ? ' (' + n.provider_name + ')' : '');
        nodeSel.appendChild(opt);
    });

    // Заполняем селект провайдеров
    providerSel.innerHTML = '<option value="">— Не выбран —</option>';
    (providersList || []).forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.name;
        opt.textContent = p.name;
        providerSel.appendChild(opt);
    });

    if (nodeId) {
        // Редактирование
        const node = (window.billingNodesData || []).find(n => n.id == nodeId);
        if (!node) return;
        idEl.value = node.id;
        titleEl.textContent = 'Редактировать биллинг · ' + node.name;
        nodeSel.value = node.id;
        nodeSel.disabled = true; // ноду менять нельзя, только параметры биллинга
        providerSel.value = node.provider_name || '';
        amountEl.value = node.billing_amount || '';
        periodEl.value = String(node.billing_period || 30);
        dateEl.value = node.next_payment_date || '';
    } else {
        // Добавление
        idEl.value = '';
        titleEl.textContent = 'Добавить в биллинг';
        nodeSel.disabled = false;
        nodeSel.value = '';
        providerSel.value = '';
        amountEl.value = '';
        periodEl.value = '30';
        dateEl.value = '';
    }

    modal.classList.remove('hidden');
    modal.classList.add('active');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeNodeBillingModal() {
    const modal = document.getElementById('node-billing-modal');
    if (!modal) return;
    modal.classList.remove('active');
    setTimeout(() => modal.classList.add('hidden'), 200);
    // Снять disabled, чтобы в следующий раз всё работало
    const nodeSel = document.getElementById('node-billing-node-select');
    if (nodeSel) nodeSel.disabled = false;
}

async function saveNodeBilling(e) {
    e.preventDefault();
    const idEl = document.getElementById('node-billing-id');
    const nodeSel = document.getElementById('node-billing-node-select');
    const providerSel = document.getElementById('node-billing-provider');
    const amountEl = document.getElementById('node-billing-amount');
    const periodEl = document.getElementById('node-billing-period');
    const dateEl = document.getElementById('node-billing-next-payment');

    const nodeId = parseInt(nodeSel.value, 10);
    const amount = parseFloat(amountEl.value);
    const period = parseInt(periodEl.value, 10) || 30;
    const providerName = providerSel.value || null;
    const nextPayment = dateEl.value || null;

    if (!nodeId || !(amount > 0)) {
        showToastSafe('Заполните ноду и цену', 'error');
        return;
    }

    // Если выбран провайдер — подтянем его URL
    let providerUrl = null;
    if (providerName) {
        const p = (providersList || []).find(x => x.name === providerName);
        if (p) providerUrl = p.url || null;
    }

    try {
        const csrf = getCsrfToken();
        const res = await fetch(`${API_NODES}?id=${encodeURIComponent(nodeId)}`, {
            method: 'PUT',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({
                billing_amount: amount,
                billing_period: period,
                provider_name: providerName,
                provider_url: providerUrl,
                next_payment_date: nextPayment,
            })
        });
        if (!res.ok) {
            const text = await res.text();
            let msg = 'HTTP ' + res.status;
            try { const d = JSON.parse(text); msg = d.error || msg; } catch (_) {}
            throw new Error(msg);
        }
        showToastSafe(idEl.value ? 'Биллинг обновлён' : 'Нода добавлена в биллинг', 'success');
        closeNodeBillingModal();
        loadBillingData();
    } catch (err) {
        console.error('saveNodeBilling', err);
        showToastSafe('Ошибка сохранения: ' + err.message, 'error');
    }
}

/* ==================== ЗАГРУЗКА ДАННЫХ ==================== */

async function loadBillingData(silent) {
    try {
        if (!silent) toggleTableLoader('billing-nodes-tbody', true);
        const [nodesRes, paymentsRes] = await Promise.all([
            fetch(API_NODES, { credentials: 'include' }),
            fetch(API_PAYMENTS, { credentials: 'include' })
        ]);
        if (!nodesRes.ok || !paymentsRes.ok) throw new Error('HTTP error');
        const nodesText = await nodesRes.text();
        const paymentsText = await paymentsRes.text();
        const nodes = (nodesText ? JSON.parse(nodesText) : { nodes: [] }).nodes || [];
        const payments = (paymentsText ? JSON.parse(paymentsText) : { payments: [] }).payments || [];
        window.billingNodesData = nodes;
        updateStats(nodes, payments);
        renderBillingNodes(nodes);
        renderPayments(payments);
        if (Array.isArray(providersList)) renderProviders(providersList);
    } catch (e) {
        console.error('loadBillingData', e);
        window.billingNodesData = [];
        updateStats([], []);
        renderBillingNodes([]);
        renderPayments([]);
    } finally {
        if (!silent) toggleTableLoader('billing-nodes-tbody', false);
    }
}

function updateStats(nodes, payments) {
    const now = new Date();
    const cm = now.getMonth(), cy = now.getFullYear();
    const pending = nodes.filter(n => {
        if (!n.next_payment_date) return false;
        const d = new Date(n.next_payment_date);
        return d.getMonth() === cm && d.getFullYear() === cy;
    });
    const monthTotal = payments.filter(p => {
        const d = new Date(p.payment_date);
        return d.getMonth() === cm && d.getFullYear() === cy;
    }).reduce((s, p) => s + parseFloat(p.amount || 0), 0);
    const totalSpent = payments.reduce((s, p) => s + parseFloat(p.amount || 0), 0);
    const a = document.getElementById('pending-nodes');
    const b = document.getElementById('month-payments');
    const c = document.getElementById('total-spent');
    if (a) a.textContent = pending.length;
    if (b) b.textContent = monthTotal.toFixed(2) + ' ₽';
    if (c) c.textContent = totalSpent.toFixed(2) + ' ₽';
}

/* ==================== НОДЫ ==================== */

function renderBillingNodes(nodes) {
    const tbody = document.getElementById('billing-nodes-tbody');
    if (!tbody) return;
    const billingNodes = nodes.filter(n => n.billing_amount && parseFloat(n.billing_amount) > 0);
    if (billingNodes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center">Нет нод с биллингом. Заполните «Цена за месяц» через редактирование ноды.</td></tr>';
        return;
    }
    selectedBillingNodes.clear();
    updateBillingSelection();
    const now = new Date();
    tbody.innerHTML = billingNodes.map(node => {
        const nextPayment = node.next_payment_date ? new Date(node.next_payment_date) : null;
        const daysLeft = nextPayment ? Math.floor((nextPayment - now) / 86400000) : null;
        const status = daysLeft === null ? 'none' : (daysLeft < 0 ? 'overdue' : (daysLeft <= 7 ? 'soon' : 'ok'));
        const statusLabel = { none: 'Нет даты', overdue: 'Просрочено', soon: 'Скоро', ok: 'Активно' }[status];
        const nextStr = nextPayment ? nextPayment.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
        const daysStr = daysLeft === null ? '—' : (daysLeft < 0 ? Math.abs(daysLeft) + ' дн. назад' : daysLeft + ' дн.');
        const flag = node.country ? getCountryFlag(node.country) : '';
        const safeUrl = (node.provider_url && /^https?:\/\//i.test(node.provider_url)) ? node.provider_url : '#';
        return `<tr data-node-id="${node.id}">
            <td><input type="checkbox" class="billing-node-checkbox" value="${node.id}" onchange="updateBillingSelection()"></td>
            <td>
                <div class="table-cell-with-icon">
                    ${getProviderIcon(node.favicon_url, node.provider_name, node.provider_url)}
                    ${node.provider_name ? `<a href="${escHtmlAttr(safeUrl)}" target="_blank" rel="noopener">${esc(node.provider_name)}</a>` : '<span style="color:var(--text-tertiary)">—</span>'}
                </div>
            </td>
            <td><div class="table-cell-with-icon">${flag} <span>${esc(node.name)}</span></div></td>
            <td>${parseFloat(node.billing_amount || 0).toFixed(2)} ₽</td>
            <td><div class="table-cell-with-icon"><i data-lucide="calendar"></i><span>${nextStr}</span></div></td>
            <td>${daysStr}</td>
            <td><span class="billing-status-badge status-${status}">${statusLabel}</span></td>
            <td><button class="icon-btn primary" onclick="payForNode(${node.id})" title="Оплатить"><i data-lucide="credit-card"></i></button></td>
        </tr>`;
    }).join('');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function updateBillingSelection() {
    const checked = document.querySelectorAll('.billing-node-checkbox:checked');
    selectedBillingNodes.clear();
    checked.forEach(cb => selectedBillingNodes.add(parseInt(cb.value, 10)));
    const countEl = document.getElementById('billing-selected-count');
    const menu = document.getElementById('billing-context-menu');
    if (countEl) countEl.textContent = selectedBillingNodes.size;
    if (menu) menu.classList.toggle('hidden', selectedBillingNodes.size === 0);
    const all = document.getElementById('select-all-billing-nodes');
    if (all) {
        const total = document.querySelectorAll('.billing-node-checkbox').length;
        all.checked = total > 0 && total === checked.length;
    }
}

function clearBillingSelection() {
    document.querySelectorAll('.billing-node-checkbox').forEach(cb => cb.checked = false);
    const all = document.getElementById('select-all-billing-nodes');
    if (all) all.checked = false;
    updateBillingSelection();
}

function selectAllBillingNodes() {
    document.querySelectorAll('.billing-node-checkbox').forEach(cb => cb.checked = true);
    const all = document.getElementById('select-all-billing-nodes');
    if (all) all.checked = true;
    updateBillingSelection();
}

async function updateSelectedPaymentDates() {
    if (selectedBillingNodes.size === 0) return;
    try {
        showToastSafe('Обновление дат...', 'info');
        const now = new Date();
        const next = new Date(now.getFullYear(), now.getMonth() + 1, now.getDate());
        const iso = next.toISOString().split('T')[0];
        const csrf = getCsrfToken();
        for (const id of selectedBillingNodes) {
            await fetch(`${API_NODES}?id=${encodeURIComponent(id)}`, {
                method: 'PUT', credentials: 'include',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ next_payment_date: iso })
            });
        }
        showToastSafe(`Обновлено нод: ${selectedBillingNodes.size}`, 'success');
        clearBillingSelection();
        loadBillingData();
    } catch (e) {
        console.error(e);
        showToastSafe('Ошибка обновления', 'error');
    }
}

async function deleteSelectedBillingNodes() {
    if (selectedBillingNodes.size === 0) return;
    let ok;
    if (typeof window.showConfirm === 'function') {
        ok = await window.showConfirm(`Удалить биллинг для ${selectedBillingNodes.size} нод?`, 'Удаление биллинга', 'danger');
    } else {
        ok = confirm(`Удалить биллинг для ${selectedBillingNodes.size} нод?`);
    }
    if (!ok) return;
    try {
        const csrf = getCsrfToken();
        for (const id of selectedBillingNodes) {
            await fetch(`${API_NODES}?id=${encodeURIComponent(id)}`, {
                method: 'PUT', credentials: 'include',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ billing_amount: null, provider_name: null, provider_url: null, next_payment_date: null })
            });
        }
        showToastSafe(`Удалено: ${selectedBillingNodes.size}`, 'success');
        clearBillingSelection();
        loadBillingData();
    } catch (e) {
        console.error(e);
        showToastSafe('Ошибка удаления', 'error');
    }
}

/* ==================== ОПЛАТА НОДЫ ==================== */

function payForNode(nodeId) {
    const node = (window.billingNodesData || []).find(n => n.id == nodeId);
    if (!node) return;
    document.getElementById('node-pay-id').value = node.id;
    document.getElementById('node-pay-name').textContent = node.name + (node.provider_name ? ' · ' + node.provider_name : '');
    document.getElementById('node-pay-amount').value = node.billing_amount || '';
    document.getElementById('node-pay-date').value = new Date().toISOString().split('T')[0];
    document.getElementById('node-pay-comment').value = '';
    const m = document.getElementById('node-pay-modal');
    if (!m) return;
    m.classList.remove('hidden');
    m.classList.add('active');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeNodePayModal() {
    const m = document.getElementById('node-pay-modal');
    if (m) { m.classList.remove('active'); setTimeout(() => m.classList.add('hidden'), 200); }
}

async function submitNodePayment(e) {
    e.preventDefault();
    const nodeId = parseInt(document.getElementById('node-pay-id').value, 10);
    const amount = parseFloat(document.getElementById('node-pay-amount').value);
    const date = document.getElementById('node-pay-date').value;
    const comment = document.getElementById('node-pay-comment').value.trim();
    if (!nodeId || !(amount > 0) || !date) {
        showToastSafe('Заполните все поля', 'error');
        return;
    }
    const node = (window.billingNodesData || []).find(n => n.id == nodeId);
    const period = parseInt(node && node.billing_period, 10) || 30;
    const next = new Date(date);
    next.setDate(next.getDate() + period);
    const nextIso = next.toISOString().split('T')[0];
    try {
        const csrf = getCsrfToken();
        const payBody = { node_id: nodeId, amount: amount, payment_date: date };
        if (comment) payBody.comment = comment;
        const payRes = await fetch(API_PAYMENTS, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify(payBody)
        });
        if (!payRes.ok) {
            const t = await payRes.text();
            throw new Error(t || `HTTP ${payRes.status}`);
        }
        await fetch(`${API_NODES}?id=${encodeURIComponent(nodeId)}`, {
            method: 'PUT', credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ last_payment_date: date, next_payment_date: nextIso })
        });
        showToastSafe(`Оплата ${amount.toFixed(2)} ₽ для ${node ? node.name : 'ноды'}`, 'success');
        closeNodePayModal();
        loadBillingData();
        loadUnlinkedPayments();
    } catch (err) {
        console.error('submitNodePayment', err);
        showToastSafe('Ошибка оплаты: ' + err.message, 'error');
    }
}

/* ==================== НЕРАСПРЕДЕЛЁННЫЕ ПЛАТЕЖИ ==================== */

window._unlinkedPaymentsCache = {};

async function loadUnlinkedPayments() {
    const tbody = document.getElementById('unlinked-tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" class="text-center">Загрузка...</td></tr>';
    try {
        const res = await fetch(API_PAYMENTS, { credentials: 'include' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const text = await res.text();
        const all = (text ? JSON.parse(text) : { payments: [] }).payments || [];
        const unlinked = all.filter(p => p.node_id == null);
        window._unlinkedPaymentsCache = {};
        unlinked.forEach(p => { window._unlinkedPaymentsCache[p.id] = p; });

        const badge = document.getElementById('unlinked-count');
        if (badge) {
            badge.textContent = unlinked.length;
            badge.style.display = unlinked.length > 0 ? 'inline-block' : 'none';
        }
        if (unlinked.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="color:var(--text-tertiary)">Все платежи привязаны к нодам</td></tr>';
            return;
        }
        unlinked.sort((a, b) => new Date(b.payment_date) - new Date(a.payment_date));
        tbody.innerHTML = unlinked.map(p => {
            const d = new Date(p.payment_date).toLocaleDateString('ru-RU');
            const amt = parseFloat(p.amount || 0).toFixed(2);
            const ext = p.external_id || p.number || '—';
            const desc = String(p.description || p.comment || '—');
            return `<tr>
                <td>${d}</td>
                <td>${amt} ₽</td>
                <td>${esc(String(ext))}</td>
                <td title="${escHtmlAttr(desc)}">${esc(desc.substring(0, 60))}${desc.length > 60 ? '...' : ''}</td>
                <td><button class="primary" onclick="openLinkPaymentModal(${p.id})"><i data-lucide="link"></i> Привязать</button></td>
            </tr>`;
        }).join('');
        if (typeof lucide !== 'undefined') lucide.createIcons();
    } catch (e) {
        console.error('loadUnlinkedPayments', e);
        tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="color:var(--danger)">Ошибка загрузки</td></tr>';
    }
}

function openLinkPaymentModal(paymentId) {
    const p = window._unlinkedPaymentsCache[paymentId];
    if (!p) return;
    document.getElementById('link-payment-id').value = paymentId;
    const desc = String(p.description || p.comment || '—');
    document.getElementById('link-payment-desc').textContent =
        new Date(p.payment_date).toLocaleDateString('ru-RU') + ' · ' +
        parseFloat(p.amount || 0).toFixed(2) + ' ₽ · ' + desc;

    const sel = document.getElementById('link-payment-node');
    sel.innerHTML = '<option value="">Выберите ноду</option>';
    (window.billingNodesData || []).forEach(n => {
        const opt = document.createElement('option');
        opt.value = n.id;
        opt.textContent = n.name + (n.provider_name ? ' (' + n.provider_name + ')' : '');
        sel.appendChild(opt);
    });

    const m = document.getElementById('link-payment-modal');
    if (!m) return;
    m.classList.remove('hidden');
    m.classList.add('active');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeLinkPaymentModal() {
    const m = document.getElementById('link-payment-modal');
    if (m) { m.classList.remove('active'); setTimeout(() => m.classList.add('hidden'), 200); }
}

async function submitLinkPayment(e) {
    e.preventDefault();
    const paymentId = parseInt(document.getElementById('link-payment-id').value, 10);
    const nodeId = parseInt(document.getElementById('link-payment-node').value, 10);
    if (!paymentId || !nodeId) { showToastSafe('Выберите ноду', 'error'); return; }
    try {
        const res = await fetch(API_PAYMENT_LINK, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
            body: JSON.stringify({ payment_id: paymentId, node_id: nodeId })
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
        showToastSafe('Платёж привязан', 'success');
        closeLinkPaymentModal();
        loadBillingData();
        loadUnlinkedPayments();
    } catch (err) {
        console.error('submitLinkPayment', err);
        showToastSafe('Ошибка: ' + err.message, 'error');
    }
}

/* ==================== ИСТОРИЯ ПЛАТЕЖЕЙ ==================== */

function renderPayments(payments) {
    const tbody = document.getElementById('payments-history-tbody') || document.getElementById('payments-tbody');
    if (!tbody) return;
    if (!payments || payments.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center">Нет платежей</td></tr>';
        return;
    }
    payments.sort((a, b) => new Date(b.payment_date) - new Date(a.payment_date));
    tbody.innerHTML = payments.slice(0, 200).map(p => {
        const d = new Date(p.payment_date).toLocaleDateString('ru-RU');
        const node = (window.billingNodesData || []).find(n => n.id == p.node_id);
        const label = node
            ? (node.provider_name ? node.provider_name + ' · ' + node.name : node.name)
            : (p.node_name || '<span style="color:var(--text-tertiary)">не привязан</span>');
        return `<tr>
            <td>${label}</td>
            <td>${d}</td>
            <td>${parseFloat(p.amount || 0).toFixed(2)} ₽</td>
        </tr>`;
    }).join('');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function loadPaymentsHistory() {
    try {
        const res = await fetch(API_PAYMENTS, { credentials: 'include' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const text = await res.text();
        const data = text ? JSON.parse(text) : { payments: [] };
        renderPayments(data.payments || []);
    } catch (e) { console.error('loadPaymentsHistory', e); }
}

/* ==================== ПРОВАЙДЕРЫ ==================== */

async function loadProviders() {
    try {
        const res = await fetch(API_PROVIDERS, { credentials: 'include' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const text = await res.text();
        const data = text ? JSON.parse(text) : { providers: [] };
        providersList = data.providers || [];
        renderProviders(providersList);
    } catch (e) {
        console.error('loadProviders', e);
        providersList = [];
        renderProviders(providersList);
    }
}

function getFilteredProviders() {
    const q = (document.getElementById('providers-search')?.value || '').toLowerCase().trim();
    if (!q) return providersList;
    return providersList.filter(p =>
        (p.name || '').toLowerCase().includes(q) || (p.url || '').toLowerCase().includes(q));
}

function updateProviderStats(providers) {
    const totalEl = document.getElementById('providers-total');
    const costEl = document.getElementById('providers-cost');
    const serversEl = document.getElementById('providers-servers');
    const allNodes = Array.isArray(window.billingNodesData) ? window.billingNodesData : [];
    const names = new Set(providers.map(p => (p.name || '').toLowerCase()).filter(Boolean));
    const linked = allNodes.filter(n => n.provider_name && names.has(n.provider_name.toLowerCase()));
    const total = linked.reduce((s, n) => s + parseFloat(n.billing_amount || 0), 0);
    if (totalEl) totalEl.textContent = providers.length;
    if (costEl) costEl.textContent = total.toFixed(2) + ' ₽';
    if (serversEl) serversEl.textContent = linked.length;
}

function renderProviderCard(p) {
    const icon = getProviderIcon(p.favicon_url, p.name, p.url);
    const safeUrl = p.url && /^https?:\/\//i.test(p.url) ? p.url : null;
    const allNodes = Array.isArray(window.billingNodesData) ? window.billingNodesData : [];
    const nodes = allNodes.filter(n => n.provider_name && p.name && n.provider_name.toLowerCase() === p.name.toLowerCase());
    const total = nodes.reduce((s, n) => s + parseFloat(n.billing_amount || 0), 0);
    const count = nodes.length;
    const word = count === 1 ? 'сервер' : (count >= 2 && count <= 4 ? 'сервера' : 'серверов');
    const costHtml = total > 0
        ? `<span class="provider-card-metric-value">${total.toFixed(2)} ₽</span><span class="provider-card-metric-sub">/мес</span>`
        : '<span class="provider-card-metric-empty">— не указано —</span>';
    const serversHtml = count > 0
        ? nodes.slice(0, 5).map(n => `<span class="node-tag" title="${escHtmlAttr(n.name)}">${getCountryFlag(n.country)} ${esc(n.name)}</span>`).join('') +
          (count > 5 ? `<span class="node-tag node-tag-more">+${count - 5}</span>` : '')
        : '<span class="provider-card-metric-empty">Нет привязанных серверов</span>';
    return `<div class="provider-card">
        <div class="provider-card-header">
            <div class="provider-card-favicon">${icon}</div>
            <div class="provider-card-info">
                <div class="provider-card-name" title="${escHtmlAttr(p.name)}">${esc(p.name)}</div>
                ${safeUrl ? `<a class="provider-card-url" href="${escHtmlAttr(safeUrl)}" target="_blank" rel="noopener">${esc(p.url)}</a>` : '<div class="provider-card-url provider-card-url-empty">URL не указан</div>'}
            </div>
            <div class="provider-card-actions">
                <button class="icon-btn" onclick="editProvider(${p.id})" title="Редактировать"><i data-lucide="pencil"></i></button>
                <button class="icon-btn danger" onclick="deleteProvider(${p.id})" title="Удалить"><i data-lucide="trash-2"></i></button>
            </div>
        </div>
        <div class="provider-card-metrics">
            <div class="provider-card-metric"><i data-lucide="wallet"></i>${costHtml}</div>
            <div class="provider-card-metric"><i data-lucide="server"></i><span class="provider-card-metric-value">${count}</span><span class="provider-card-metric-sub">${word}</span></div>
        </div>
        <div class="provider-card-servers">${serversHtml}</div>
    </div>`;
}

function renderProviders(providers) {
    const container = document.getElementById('providers-list');
    if (!container) return;
    updateProviderStats(providers);
    const filtered = getFilteredProviders();
    const q = (document.getElementById('providers-search')?.value || '').trim();

    if (filtered.length === 0) {
        container.innerHTML = `<div class="providers-empty">
            <i data-lucide="building-2"></i>
            <h3>${q ? 'Ничего не найдено' : 'Провайдеров пока нет'}</h3>
            <p>${q ? 'Попробуйте изменить поисковый запрос' : 'Добавьте первого провайдера'}</p>
            ${!q ? '<button class="primary" onclick="addProvider()" style="margin-top:16px;"><i data-lucide="plus"></i> Добавить</button>' : ''}
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
    container.innerHTML = filtered.slice(start, start + providersPerPage).map(renderProviderCard).join('');

    const pag = document.getElementById('providers-pagination');
    const info = document.getElementById('providers-page-info');
    const prev = document.getElementById('providers-prev');
    const next = document.getElementById('providers-next');
    if (totalPages > 1) {
        if (pag) pag.style.display = 'flex';
        if (info) info.textContent = `Страница ${providersCurrentPage} из ${totalPages}`;
        if (prev) prev.disabled = providersCurrentPage === 1;
        if (next) next.disabled = providersCurrentPage === totalPages;
    } else if (pag) pag.style.display = 'none';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function filterProviders() { providersCurrentPage = 1; renderProviders(providersList); }

function changeProvidersPage(direction) {
    const filtered = getFilteredProviders();
    const totalPages = Math.ceil(filtered.length / providersPerPage);
    const np = providersCurrentPage + direction;
    if (np >= 1 && np <= totalPages) { providersCurrentPage = np; renderProviders(providersList); }
}

function addProvider() {
    const m = document.getElementById('provider-modal');
    if (!m) return;
    document.getElementById('provider-form').reset();
    document.getElementById('provider-edit-id').value = '';
    m.querySelector('button[type="submit"]').textContent = 'Создать';
    m.classList.remove('hidden'); m.classList.add('active');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeProviderModal() {
    const m = document.getElementById('provider-modal');
    if (m) { m.classList.remove('active'); setTimeout(() => m.classList.add('hidden'), 200); }
}

function editProvider(id) {
    const p = providersList.find(x => x.id == id);
    if (!p) return;
    const m = document.getElementById('provider-modal');
    if (!m) return;
    document.getElementById('provider-edit-id').value = p.id;
    document.getElementById('provider-name').value = p.name || '';
    document.getElementById('provider-favicon').value = p.favicon_url || '';
    document.getElementById('provider-url').value = p.url || '';
    m.querySelector('button[type="submit"]').textContent = 'Сохранить';
    m.classList.remove('hidden'); m.classList.add('active');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function deleteProvider(id) {
    let ok;
    if (typeof window.showConfirm === 'function') {
        ok = await window.showConfirm('Удалить провайдера? Действие нельзя отменить.', 'Удаление провайдера', 'danger');
    } else {
        ok = confirm('Удалить провайдера?');
    }
    if (!ok) return;
    try {
        const res = await fetch(`${API_PROVIDERS}?id=${id}`, {
            method: 'DELETE', credentials: 'include',
            headers: { 'X-CSRF-Token': getCsrfToken() }
        });
        const text = await res.text();
        const data = text ? JSON.parse(text) : {};
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
        showToastSafe('Провайдер удалён', 'success');
        loadProviders();
    } catch (e) {
        console.error(e);
        showToastSafe(e.message, 'error');
    }
}

/* ==================== СТАТИСТИКА ==================== */

async function loadBillingStats() {
    if (typeof Chart === 'undefined') {
        console.warn('[billing] Chart.js не загружен — графики пропущены');
        return;
    }
    try {
        const [paymentsRes, providersRes] = await Promise.all([
            fetch(API_PAYMENTS, { credentials: 'include' }),
            fetch(API_PROVIDERS, { credentials: 'include' })
        ]);
        if (!paymentsRes.ok || !providersRes.ok) throw new Error('HTTP error');
        const pt = await paymentsRes.text();
        const rt = await providersRes.text();
        const payments = (pt ? JSON.parse(pt) : {}).payments || [];
        const providers = (rt ? JSON.parse(rt) : {}).providers || [];
        renderMonthlyChart(payments);
        renderProviderChart(payments, providers);
    } catch (e) { console.error('loadBillingStats', e); }
}

function renderMonthlyChart(payments) {
    const ctx = document.getElementById('paymentsChart');
    if (!ctx) return;
    const monthly = {};
    payments.forEach(p => {
        const d = new Date(p.payment_date);
        const k = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
        monthly[k] = (monthly[k] || 0) + parseFloat(p.amount || 0);
    });
    const months = Object.keys(monthly).sort();
    if (monthlyChart) monthlyChart.destroy();
    monthlyChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: months.map(m => { const [y, mo] = m.split('-'); return new Date(y, mo - 1).toLocaleDateString('ru-RU', { month: 'short', year: 'numeric' }); }),
            datasets: [{
                label: 'Расходы, ₽',
                data: months.map(m => monthly[m]),
                borderColor: 'rgb(59,130,246)',
                backgroundColor: 'rgba(59,130,246,0.1)',
                tension: 0.4, fill: true
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { callback: v => v + ' ₽' } } }
        }
    });
}

function renderProviderChart(payments, providers) {
    const ctx = document.getElementById('providersChart');
    if (!ctx) return;
    const data = {};
    payments.forEach(p => {
        const name = p.provider_name || 'Неизвестно';
        data[name] = (data[name] || 0) + parseFloat(p.amount || 0);
    });
    const labels = Object.keys(data);
    if (providerChart) providerChart.destroy();
    providerChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: Object.values(data),
                backgroundColor: ['rgba(59,130,246,.8)','rgba(16,185,129,.8)','rgba(245,158,11,.8)','rgba(239,68,68,.8)','rgba(139,92,246,.8)','rgba(236,72,153,.8)'],
                borderWidth: 2, borderColor: 'var(--bg-primary)'
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
}

/* ==================== РАСХОДЫ / ДОМЕНЫ / ИМПОРТ ==================== */

async function loadBillingExpenses() {
    try {
        const res = await fetch(`${API_IMPORT}?type=expenses`, { credentials: 'include' });
        const data = await res.json();
        const tbody = document.getElementById('billing-expenses-tbody');
        if (!tbody) return;
        const list = data.expenses || [];
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Нет данных</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(e => {
            const matched = e.matched_node_id
                ? `VPS: ${esc(e.node_name || '')}`
                : e.matched_domain_id
                    ? `Домен: ${esc(e.domain_name || '')}`
                    : '<span style="color:var(--text-tertiary)">не сопоставлено</span>';
            const d = e.expense_date ? new Date(e.expense_date).toLocaleDateString('ru-RU') : '—';
            const desc = String(e.description || '');
            return `<tr>
                <td>${d}</td>
                <td title="${escHtmlAttr(desc)}">${esc(desc.substring(0, 80))}${desc.length > 80 ? '...' : ''}</td>
                <td>${esc(e.account_ref || '—')}</td>
                <td>${parseFloat(e.amount || 0).toFixed(2)} ₽</td>
                <td>${matched}</td>
                <td><button class="icon-btn" onclick="openExpenseMatch(${e.id})" title="Сопоставить"><i data-lucide="link"></i></button></td>
            </tr>`;
        }).join('');
        if (typeof lucide !== 'undefined') lucide.createIcons();
    } catch (e) { console.error('loadBillingExpenses', e); }
}

async function loadBillingDomains() {
    try {
        const res = await fetch(`${API_IMPORT}?type=domains`, { credentials: 'include' });
        const data = await res.json();
        const tbody = document.getElementById('billing-domains-tbody');
        if (!tbody) return;
        const list = data.domains || [];
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Нет данных</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(d => {
            const exp = d.expires_at ? new Date(d.expires_at).toLocaleDateString('ru-RU') : '—';
            const price = d.price ? parseFloat(d.price).toFixed(2) + ' ₽' : '—';
            return `<tr>
                <td><strong>${esc(d.domain_name || '—')}</strong></td>
                <td>${esc(d.tld || '—')}</td>
                <td>${esc(d.contact || '—')}</td>
                <td>${exp}</td>
                <td>${price}</td>
                <td>${esc(d.status || '—')}</td>
            </tr>`;
        }).join('');
        if (typeof lucide !== 'undefined') lucide.createIcons();
    } catch (e) {
        console.error('loadBillingDomains', e);
        const tbody = document.getElementById('billing-domains-tbody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-center" style="color:var(--danger)">Ошибка загрузки</td></tr>';
    }
}

async function loadBillingImports() {
    try {
        const res = await fetch(`${API_IMPORT}?type=imports`, { credentials: 'include' });
        const data = await res.json();
        const tbody = document.getElementById('billing-imports-tbody');
        if (!tbody) return;
        const list = data.imports || [];
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Нет данных</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(im => `<tr>
            <td>${im.id}</td>
            <td>${esc(im.type)}</td>
            <td>${esc(im.filename || '—')}</td>
            <td>${im.imported_rows || 0}</td>
            <td>${im.matched_rows || 0}</td>
            <td>${esc(im.status)}</td>
        </tr>`).join('');
    } catch (e) { console.error('loadBillingImports', e); }
}

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
        const resp = await fetch(API_IMPORT, {
            method: 'POST', credentials: 'include',
            headers: { 'X-CSRF-Token': getCsrfToken() },
            body: formData
        });
        const data = await resp.json();
        if (data.success) {
            resDiv.textContent = `Импортировано: ${data.imported}, сопоставлено: ${data.matched}`;
            loadBillingImports();
            if (type === 'expenses') loadBillingExpenses();
            if (type === 'domains') loadBillingDomains();
            if (type === 'payments') { loadBillingData(); loadUnlinkedPayments(); }
        } else {
            resDiv.textContent = 'Ошибка: ' + (data.error || 'неизвестно') + (data.debug ? ' — ' + data.debug : '');
        }
    } catch (e) {
        console.error(e);
        resDiv.textContent = 'Ошибка импорта';
    }
}

/* ==================== СОПОСТАВЛЕНИЕ РАСХОДА ==================== */

function openExpenseMatch(id) {
    document.getElementById('expense-match-id').value = id;
    const nodeSel = document.getElementById('expense-match-node');
    nodeSel.innerHTML = '<option value="">Не сопоставлять</option>';
    (window.billingNodesData || []).forEach(n => {
        const opt = document.createElement('option');
        opt.value = n.id;
        opt.textContent = n.name + (n.provider_name ? ' (' + n.provider_name + ')' : '');
        nodeSel.appendChild(opt);
    });
    const domSel = document.getElementById('expense-match-domain');
    domSel.innerHTML = '<option value="">Не сопоставлять</option>';
    fetch(`${API_IMPORT}?type=domains`, { credentials: 'include' })
        .then(r => r.json())
        .then(data => (data.domains || []).forEach(d => {
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.domain_name;
            domSel.appendChild(opt);
        }))
        .catch(() => {});

    // Попытка вытащить описание из кэша (если открыто из таблицы расходов)
    const descEl = document.getElementById('expense-match-desc');
    if (descEl) descEl.textContent = '';

    const m = document.getElementById('expense-match-modal');
    if (!m) return;
    m.classList.remove('hidden');
    m.classList.add('active');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeExpenseMatchModal() {
    const m = document.getElementById('expense-match-modal');
    if (m) { m.classList.remove('active'); setTimeout(() => m.classList.add('hidden'), 200); }
}

async function saveExpenseMatch(e) {
    e.preventDefault();
    const id = document.getElementById('expense-match-id').value;
    const nodeId = document.getElementById('expense-match-node').value;
    const domainId = document.getElementById('expense-match-domain').value;
    try {
        const res = await fetch(API_EXPENSE_MATCH, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
            body: JSON.stringify({ expense_id: id, node_id: nodeId || null, domain_id: domainId || null })
        });
        const data = await res.json();
        if (data.success) {
            showToastSafe('Сопоставлено', 'success');
            closeExpenseMatchModal();
            loadBillingExpenses();
        } else {
            showToastSafe(data.error || 'Ошибка', 'error');
        }
    } catch (e) {
        console.error('saveExpenseMatch', e);
        showToastSafe('Ошибка', 'error');
    }
}

/* ==================== ПЕРЕКЛЮЧЕНИЕ ВКЛАДОК ==================== */

function switchBillingUnifiedTab(tab) {
    document.querySelectorAll('.tabs .tab-btn').forEach(btn =>
        btn.classList.toggle('active', btn.getAttribute('data-tab') === tab));
    document.querySelectorAll('.tab-content').forEach(tc =>
        tc.classList.toggle('active', tc.id === tab + '-tab'));

    if (tab === 'billing')   { loadBillingData(); loadUnlinkedPayments(); }
    if (tab === 'expenses')  loadBillingExpenses();
    if (tab === 'domains')   loadBillingDomains();
    if (tab === 'imports')   loadBillingImports();
    if (tab === 'providers') loadProviders();
    if (tab === 'stats')     { loadBillingData(); loadBillingStats(); }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

/* ==================== ЭКСПОРТ В WINDOW (для inline onclick) ==================== */
window.loadBillingData        = loadBillingData;
window.loadPaymentsHistory    = loadPaymentsHistory;
window.loadUnlinkedPayments   = loadUnlinkedPayments;
window.loadProviders          = loadProviders;
window.loadBillingStats       = loadBillingStats;
window.loadBillingExpenses    = loadBillingExpenses;
window.loadBillingDomains     = loadBillingDomains;
window.loadBillingImports     = loadBillingImports;
window.startBillingImport     = startBillingImport;

window.payForNode             = payForNode;
window.closeNodePayModal      = closeNodePayModal;
window.submitNodePayment      = submitNodePayment;
window.openLinkPaymentModal   = openLinkPaymentModal;
window.closeLinkPaymentModal  = closeLinkPaymentModal;
window.submitLinkPayment      = submitLinkPayment;

window.addProvider            = addProvider;
window.editProvider           = editProvider;
window.deleteProvider         = deleteProvider;
window.closeProviderModal     = closeProviderModal;
window.filterProviders        = filterProviders;
window.changeProvidersPage    = changeProvidersPage;

window.clearBillingSelection       = clearBillingSelection;
window.selectAllBillingNodes       = selectAllBillingNodes;
window.updateBillingSelection      = updateBillingSelection;
window.updateSelectedPaymentDates  = updateSelectedPaymentDates;
window.deleteSelectedBillingNodes  = deleteSelectedBillingNodes;

window.openExpenseMatch        = openExpenseMatch;
window.closeExpenseMatchModal  = closeExpenseMatchModal;
window.saveExpenseMatch        = saveExpenseMatch;

window.switchBillingUnifiedTab = switchBillingUnifiedTab;

/* ==================== ИНИЦИАЛИЗАЦИЯ ==================== */
document.addEventListener('DOMContentLoaded', function () {
    loadProviders();
    loadBillingData();
    loadUnlinkedPayments();

    // Select-all
    const selAll = document.getElementById('select-all-billing-nodes');
    if (selAll) {
        selAll.addEventListener('change', e => {
            document.querySelectorAll('.billing-node-checkbox').forEach(cb => cb.checked = e.target.checked);
            updateBillingSelection();
        });
    }

    // Клик на фон модалок — закрыть
    const modalClosers = {
        'node-pay-modal': closeNodePayModal,
        'link-payment-modal': closeLinkPaymentModal,
        'provider-modal': closeProviderModal,
        'expense-match-modal': closeExpenseMatchModal
    };
    Object.keys(modalClosers).forEach(id => {
        const m = document.getElementById(id);
        if (!m) return;
        m.addEventListener('click', e => { if (e.target === m) modalClosers[id](); });
    });

    // Форма провайдера
    const providerForm = document.getElementById('provider-form');
    if (providerForm) {
        providerForm.addEventListener('submit', async e => {
            e.preventDefault();
            const id = document.getElementById('provider-edit-id').value;
            const isEdit = id && id !== '';
            const name = document.getElementById('provider-name').value.trim();
            const url = document.getElementById('provider-url').value.trim();
            const favicon = document.getElementById('provider-favicon').value.trim();
            if (!name) { showToastSafe('Введите название', 'error'); return; }
            try {
                const res = await fetch(`${API_PROVIDERS}${isEdit ? '?id=' + id : ''}`, {
                    method: isEdit ? 'PUT' : 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                    body: JSON.stringify({ name, url: url || null, favicon_url: favicon || null })
                });
                const text = await res.text();
                const data = text ? JSON.parse(text) : {};
                if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
                showToastSafe(isEdit ? 'Провайдер обновлён' : 'Провайдер добавлен', 'success');
                closeProviderModal();
                loadProviders();
                loadBillingData();
            } catch (err) {
                console.error('providerForm', err);
                showToastSafe(err.message, 'error');
            }
        });
    }

    // Автогенерация favicon из URL
    const pUrl = document.getElementById('provider-url');
    const pFav = document.getElementById('provider-favicon');
    if (pUrl && pFav) {
        let autoGenerated = false;
        const generate = () => {
            const url = pUrl.value.trim();
            if (url && (!pFav.value.trim() || autoGenerated)) {
                const gen = buildFaviconUrl(null, url);
                if (gen) {
                    pFav.value = gen;
                    autoGenerated = true;
                    pFav.style.color = 'var(--text-muted)';
                    pFav.title = 'Автоматически сгенерировано из URL';
                }
            } else if (!url) {
                autoGenerated = false;
                pFav.style.color = '';
                pFav.title = '';
            }
        };
        pUrl.addEventListener('blur', generate);
        let t;
        pUrl.addEventListener('input', () => { clearTimeout(t); t = setTimeout(generate, 500); });
        pFav.addEventListener('input', () => { autoGenerated = false; pFav.style.color = ''; pFav.title = ''; });
    }

    // Авто-обновление раз в минуту (без анимации)
    setInterval(() => loadBillingData(true), 60000);
});