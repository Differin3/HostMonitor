<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/database.php';

render_layout_start('Биллинг', 'nodes-billing', '<button class="primary" onclick="openAddPaymentModal()"><i data-lucide="plus"></i> Добавить платёж</button>');
?>
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <div class="card-title">
            <i data-lucide="credit-card"></i>
            <span>Биллинг</span>
        </div>
    </div>
    <div class="tabs">
        <button class="tab-btn active" data-tab="billing" onclick="switchBillingUnifiedTab('billing')"><i data-lucide="server"></i> Биллинг</button>
        <button class="tab-btn" data-tab="stats" onclick="switchBillingUnifiedTab('stats')"><i data-lucide="bar-chart-3"></i> Статистика</button>
        <button class="tab-btn" data-tab="expenses" onclick="switchBillingUnifiedTab('expenses')"><i data-lucide="receipt"></i> Расходы хостинга</button>
        <button class="tab-btn" data-tab="domains" onclick="switchBillingUnifiedTab('domains')"><i data-lucide="globe"></i> Домены</button>
        <button class="tab-btn" data-tab="imports" onclick="switchBillingUnifiedTab('imports')"><i data-lucide="upload"></i> Импорт</button>
    </div>
</div>

<div id="billing-tab" class="tab-content active">
    <div class="stats-grid stats-grid-dash" style="margin-top: 24px;">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);"><i data-lucide="clock"></i></div>
            <div class="stat-card-content"><h3>К оплате</h3><div class="stat-value" id="pending-nodes">0</div><p class="stat-subtitle">ноды в этом месяце</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);"><i data-lucide="wallet"></i></div>
            <div class="stat-card-content"><h3>Платежей в <?= date('m/Y') ?></h3><div class="stat-value" id="month-payments">0 ₽</div><p class="stat-subtitle">всего платежей</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #818cf8, #4f46e5);"><i data-lucide="trending-up"></i></div>
            <div class="stat-card-content"><h3>Всего потрачено</h3><div class="stat-value" id="total-spent">0 ₽</div><p class="stat-subtitle">суммарных трат</p></div>
        </div>
    </div>

    <div class="grid-3" style="margin-top: 24px;">
        <div class="card">
            <div class="card-header"><div class="card-title"><i data-lucide="plus-circle"></i><span>Быстрый платёж</span></div></div>
            <div class="card-body">
                <div class="form-field"><label class="form-label">Нода</label><select id="payment-node" class="provider-select"><option value="">Выберите ноду</option></select></div>
                <div class="form-field"><label class="form-label">Сумма, ₽</label><input type="number" id="payment-amount" step="0.01" placeholder="0.00"></div>
                <div class="form-field"><label class="form-label">Дата</label><div class="date-picker-wrapper"><i data-lucide="calendar" class="input-icon"></i><input type="text" id="payment-date-display" placeholder="Выберите дату" readonly><div class="date-picker-panel hidden" id="payment-date-panel"><div class="date-picker-calendar" data-for="payment-date"></div></div><input type="hidden" id="payment-date" value="<?= date('Y-m-d') ?>"></div></div>
                <button class="primary" onclick="addPayment()"><i data-lucide="plus"></i> Добавить платёж</button>
            </div>
        </div>
        <div class="card" style="grid-column: span 2;">
            <div class="card-header"><div class="card-title"><i data-lucide="server"></i><span>Ноды с биллингом</span></div><div class="card-actions"><button onclick="loadBillingData()" class="icon-btn"><i data-lucide="refresh-cw"></i></button></div></div>
            <div class="table-container compact-table" style="overflow-x: auto;">
                <table>
                    <thead><tr><th>Нода</th><th>Провайдер</th><th>IP</th><th>Цена/мес</th><th>Оплачено до</th><th>Остаток</th><th>Статус</th><th>Действия</th></tr></thead>
                    <tbody id="billing-nodes-tbody"><tr><td colspan="8" class="text-center">Загрузка...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 24px;">
        <div class="card-header"><div class="card-title"><i data-lucide="list"></i><span>История платежей</span></div><div class="card-actions"><button onclick="loadPaymentsHistory()" class="icon-btn"><i data-lucide="refresh-cw"></i></button></div></div>
        <div class="table-container compact-table" style="overflow-x: auto;">
            <table>
                <thead><tr><th>Дата</th><th>Нода</th><th>Сумма</th><th>Действия</th></tr></thead>
                <tbody id="payments-history-tbody"><tr><td colspan="4" class="text-center">Загрузка...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div id="stats-tab" class="tab-content">
    <div class="grid-2" style="margin-top: 24px;">
        <div class="card"><div class="card-header"><div class="card-title"><i data-lucide="bar-chart-3"></i><span>Платежи по месяцам</span></div></div><div class="card-body"><canvas id="paymentsChart" height="220"></canvas></div></div>
        <div class="card"><div class="card-header"><div class="card-title"><i data-lucide="pie-chart"></i><span>По провайдерам</span></div></div><div class="card-body"><canvas id="providersChart" height="220"></canvas></div></div>
    </div>
</div>

<div id="expenses-tab" class="tab-content">
    <div class="card" style="margin-top: 24px;">
        <div class="card-header"><div class="card-title"><i data-lucide="receipt"></i><span>Расходы хостинга</span></div><div class="card-actions"><button onclick="loadBillingExpenses()" class="icon-btn"><i data-lucide="refresh-cw"></i></button><button onclick="switchBillingUnifiedTab('imports'); document.getElementById('billing-import-type').value='expenses';" class="icon-btn primary"><i data-lucide="upload"></i></button></div></div>
        <div class="table-container compact-table" style="overflow-x: auto;">
            <table><thead><tr><th>Дата</th><th>Описание</th><th>На счёт</th><th>Сумма</th><th>Сопоставлено</th><th>Действия</th></tr></thead><tbody id="billing-expenses-tbody"><tr><td colspan="6" class="text-center">Нет данных</td></tr></tbody></table>
        </div>
    </div>
</div>

<div id="domains-tab" class="tab-content">
    <div class="card" style="margin-top: 24px;">
        <div class="card-header"><div class="card-title"><i data-lucide="globe"></i><span>Домены</span></div><div class="card-actions"><button onclick="loadBillingDomains()" class="icon-btn"><i data-lucide="refresh-cw"></i></button><button onclick="switchBillingUnifiedTab('imports'); document.getElementById('billing-import-type').value='domains';" class="icon-btn primary"><i data-lucide="upload"></i></button></div></div>
        <div class="table-container compact-table" style="overflow-x: auto;">
            <table><thead><tr><th>Домен</th><th>Зона</th><th>Проект</th><th>Действует до</th><th>Цена в год</th><th>Статус</th></tr></thead><tbody id="billing-domains-tbody"><tr><td colspan="6" class="text-center">Нет данных</td></tr></tbody></table>
        </div>
    </div>
</div>

<div id="imports-tab" class="tab-content">
    <div class="grid-2" style="margin-top: 24px;">
        <div class="card">
            <div class="card-header"><div class="card-title"><i data-lucide="upload"></i><span>Импорт CSV</span></div></div>
            <div class="card-body">
                <div class="form-field"><label class="form-label">Тип</label><select id="billing-import-type" class="provider-select"><option value="payments">Платежи</option><option value="expenses">Расходы хостинга</option><option value="domains">Домены</option></select></div>
                <div class="form-field"><label class="form-label">Файл CSV (.csv/.txt)</label><input type="file" id="billing-import-file" accept=".csv,.txt,text/csv"></div>
                <button class="primary" onclick="startBillingImport()"><i data-lucide="upload"></i> Импортировать</button>
                <div id="billing-import-result" style="margin-top: 12px; color: var(--text-secondary);"></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><i data-lucide="history"></i><span>История импортов</span></div><div class="card-actions"><button onclick="loadBillingImports()" class="icon-btn"><i data-lucide="refresh-cw"></i></button></div></div>
            <div class="table-container compact-table">
                <table><thead><tr><th>ID</th><th>Тип</th><th>Файл</th><th>Импорт</th><th>Сопоставлено</th><th>Статус</th></tr></thead><tbody id="billing-imports-tbody"><tr><td colspan="6" class="text-center">Нет данных</td></tr></tbody></table>
            </div>
        </div>
    </div>
</div>

<div class="modal hidden" id="node-billing-modal">
    <div class="modal-dialog">
        <div class="modal-header"><h2>Биллинг ноды</h2><button class="modal-close" onclick="closeNodeBillingModal()" type="button"><i data-lucide="x"></i></button></div>
        <form id="node-billing-form" onsubmit="saveNodeBilling(event)">
            <input type="hidden" id="node-billing-id">
            <div class="form-field"><label class="form-label">Цена за месяц, ₽</label><input type="number" id="node-billing-price" step="0.01" required></div>
            <div class="form-field"><label class="form-label">Оплачено до</label><div class="date-picker-wrapper"><i data-lucide="calendar" class="input-icon"></i><input type="text" id="node-billing-due-display" readonly><div class="date-picker-panel hidden" id="node-billing-due-panel"><div class="date-picker-calendar" data-for="node-billing-due"></div></div><input type="hidden" id="node-billing-due"></div></div>
            <div class="modal-actions"><button type="button" class="secondary" onclick="closeNodeBillingModal()">Отмена</button><button type="submit" class="primary">Сохранить</button></div>
        </form>
    </div>
</div>

<div class="modal hidden" id="add-payment-modal">
    <div class="modal-dialog">
        <div class="modal-header"><h2>Добавить платёж</h2><button class="modal-close" onclick="closeAddPaymentModal()" type="button"><i data-lucide="x"></i></button></div>
        <form id="add-payment-form" onsubmit="submitAddPayment(event)">
            <div class="form-field"><label class="form-label">Нода</label><select id="modal-payment-node" class="provider-select" required><option value="">Выберите ноду</option></select></div>
            <div class="form-field"><label class="form-label">Сумма, ₽</label><input type="number" id="modal-payment-amount" step="0.01" required></div>
            <div class="form-field"><label class="form-label">Дата</label><div class="date-picker-wrapper"><i data-lucide="calendar" class="input-icon"></i><input type="text" id="modal-payment-date-display" readonly><div class="date-picker-panel hidden" id="modal-payment-date-panel"><div class="date-picker-calendar" data-for="modal-payment-date"></div></div><input type="hidden" id="modal-payment-date" value="<?= date('Y-m-d') ?>" required></div></div>
            <div class="modal-actions"><button type="button" class="secondary" onclick="closeAddPaymentModal()">Отмена</button><button type="submit" class="primary">Добавить</button></div>
        </form>
    </div>
</div>

<div class="modal hidden" id="expense-match-modal">
    <div class="modal-dialog">
        <div class="modal-header"><h2>Сопоставить расход</h2><button class="modal-close" onclick="closeExpenseMatchModal()" type="button"><i data-lucide="x"></i></button></div>
        <form id="expense-match-form" onsubmit="saveExpenseMatch(event)">
            <input type="hidden" id="expense-match-id">
            <div class="form-field"><label class="form-label">Расход</label><div id="expense-match-desc" style="color: var(--text-secondary); font-size: 13px;"></div></div>
            <div class="form-field"><label class="form-label">Сопоставить с нодой</label><select id="expense-match-node" class="provider-select"><option value="">Не сопоставлять</option></select></div>
            <div class="form-field"><label class="form-label">Сопоставить с доменом</label><select id="expense-match-domain" class="provider-select"><option value="">Не сопоставлять</option></select></div>
            <div class="modal-actions"><button type="button" class="secondary" onclick="closeExpenseMatchModal()">Отмена</button><button type="submit" class="primary">Сохранить</button></div>
        </form>
    </div>
</div>

<div class="context-menu hidden" id="billing-context-menu">
    <div class="context-menu-header"><span>ВЫБРАНО: <span id="billing-selected-count">0</span></span></div>
    <div class="context-menu-actions">
        <button class="context-action-link" onclick="clearBillingSelection()">Очистить</button>
        <button class="context-action-link" onclick="selectAllBillingNodes()">Выбрать все</button>
        <button class="context-action-btn" onclick="updateSelectedPaymentDates().catch(console.error)"><i data-lucide="calendar"></i> Обновить даты</button>
        <button class="context-action-btn context-action-delete" onclick="deleteSelectedBillingNodes().catch(console.error)"><i data-lucide="trash-2"></i> Удалить</button>
    </div>
</div>

<?php render_layout_end(['/frontend/js/chart.min.js', '/frontend/js/billing.js']); ?>
