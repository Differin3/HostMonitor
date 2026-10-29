<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/database.php';

render_layout_start('Биллинг', 'nodes-billing', '');
?>

<style>
.providers-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; }
.providers-empty { grid-column: 1 / -1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; text-align: center; color: var(--text-secondary, #999); }
.providers-empty > svg { width: 56px; height: 56px; opacity: .3; margin-bottom: 16px; }
.providers-empty h3 { margin: 0 0 8px; font-size: 16px; color: inherit; }
.providers-empty p { margin: 0; font-size: 13px; color: var(--text-muted, #666); }

.provider-card { display: flex; flex-direction: column; background: var(--bg-secondary, #1a1a1a); border: 1px solid var(--border-color, #2a2a2a); border-radius: 12px; padding: 16px; transition: border-color .2s, transform .15s, box-shadow .2s; }
.provider-card:hover { border-color: #4f46e5; transform: translateY(-2px); box-shadow: 0 8px 24px rgba(79,70,229,.15); }
.provider-card-header { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
.provider-card-favicon { width: 44px; height: 44px; flex-shrink: 0; border-radius: 10px; background: var(--bg-tertiary, #252525); display: flex; align-items: center; justify-content: center; overflow: hidden; }
.provider-card-favicon .provider-icon-wrapper,
.provider-card-favicon .provider-icon,
.provider-card-favicon .provider-icon-placeholder { width: 100%; height: 100%; border-radius: 0; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #4f46e5, #7c3aed); color: #fff; font-weight: 600; font-size: 18px; }
.provider-card-favicon .provider-icon-wrapper { background: var(--bg-tertiary, #252525); }
.provider-card-favicon .provider-favicon,
.provider-card-favicon img { width: 28px; height: 28px; object-fit: contain; }
.provider-card-info { flex: 1; min-width: 0; }
.provider-card-name { font-weight: 600; font-size: 15px; margin-bottom: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.provider-card-url { display: block; font-size: 12px; text-decoration: none; color: var(--text-secondary, #999); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; transition: color .15s; }
.provider-card-url:hover { color: #4f46e5; }
.provider-card-url-empty { font-style: italic; color: var(--text-muted, #666); }
.provider-card-actions { display: flex; gap: 2px; flex-shrink: 0; }
.provider-card-metrics { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 12px 0; margin-bottom: 12px; border-top: 1px solid var(--border-color, #2a2a2a); border-bottom: 1px solid var(--border-color, #2a2a2a); }
.provider-card-metric { display: flex; align-items: center; gap: 6px; font-size: 13px; min-width: 0; }
.provider-card-metric > svg { width: 16px; height: 16px; flex-shrink: 0; opacity: .7; }
.provider-card-metric-value { font-weight: 600; font-size: 14px; }
.provider-card-metric-sub { color: var(--text-secondary, #999); font-size: 12px; }
.provider-card-metric-empty { color: var(--text-muted, #666); font-size: 12px; font-style: italic; }
.provider-card-servers { display: flex; flex-wrap: wrap; gap: 6px; min-height: 22px; }
.node-tag-more { opacity: .6; font-style: italic; }
.providers-search-wrapper { position: relative; display: inline-flex; align-items: center; }
.providers-search-wrapper > svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; opacity: .5; pointer-events: none; }
.providers-search-wrapper input { padding: 6px 12px 6px 32px; border-radius: 6px; font-size: 13px; border: 1px solid var(--border-color, #2a2a2a); background: var(--bg-secondary, #1a1a1a); color: inherit; width: 220px; }
.providers-search-wrapper input:focus { outline: none; border-color: #4f46e5; }

.billing-status-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; }
.billing-status-badge.status-overdue { background: rgba(239,68,68,.15); color: #ef4444; }
.billing-status-badge.status-soon    { background: rgba(251,191,36,.15); color: #d97706; }
.billing-status-badge.status-ok      { background: rgba(52,211,153,.15); color: #059669; }
.billing-status-badge.status-none    { background: rgba(148,163,184,.15); color: #64748b; }

.unlinked-payments-badge { display: inline-block; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: #ef4444; color: #fff; font-size: 11px; font-weight: 700; line-height: 20px; text-align: center; margin-left: 8px; }

@media (max-width: 640px) {
    .providers-grid { grid-template-columns: 1fr; }
    .providers-search-wrapper input { width: 140px; }
}
</style>

<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <div class="card-title"><i data-lucide="credit-card"></i><span>Биллинг</span></div>
    </div>
    <div class="tabs">
        <button class="tab-btn active" data-tab="billing" onclick="switchBillingUnifiedTab('billing')"><i data-lucide="server"></i> Биллинг</button>
        <button class="tab-btn" data-tab="stats" onclick="switchBillingUnifiedTab('stats')"><i data-lucide="bar-chart-3"></i> Статистика</button>
        <button class="tab-btn" data-tab="expenses" onclick="switchBillingUnifiedTab('expenses')"><i data-lucide="receipt"></i> Расходы хостинга</button>
        <button class="tab-btn" data-tab="domains" onclick="switchBillingUnifiedTab('domains')"><i data-lucide="globe"></i> Домены</button>
        <button class="tab-btn" data-tab="providers" onclick="switchBillingUnifiedTab('providers')"><i data-lucide="building-2"></i> Провайдеры</button>
        <button class="tab-btn" data-tab="imports" onclick="switchBillingUnifiedTab('imports')"><i data-lucide="upload"></i> Импорт</button>
    </div>
</div>

<!-- ===================== БИЛЛИНГ ===================== -->
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

    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <div class="card-title"><i data-lucide="server"></i><span>Ноды с биллингом</span></div>
            <div class="card-actions">
                <button onclick="loadBillingData()" class="icon-btn" title="Обновить"><i data-lucide="refresh-cw"></i></button>
                <button onclick="openNodeBillingModal()" class="primary"><i data-lucide="plus"></i> Добавить ноду</button>
            </div>
        </div>
        <div class="table-container compact-table" style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width:32px;"><input type="checkbox" id="select-all-billing-nodes"></th>
                        <th>Провайдер</th>
                        <th>Нода</th>
                        <th>Цена/мес</th>
                        <th>Оплачено до</th>
                        <th>Остаток</th>
                        <th>Статус</th>
                        <th style="width:110px;">Действия</th>
                    </tr>
                </thead>
                <tbody id="billing-nodes-tbody"><tr><td colspan="8" class="text-center">Загрузка...</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <div class="card-title">
                <i data-lucide="inbox"></i>
                <span>Нераспределённые платежи</span>
                <span class="unlinked-payments-badge" id="unlinked-count" style="display:none;">0</span>
            </div>
            <div class="card-actions"><button onclick="loadUnlinkedPayments()" class="icon-btn" title="Обновить"><i data-lucide="refresh-cw"></i></button></div>
        </div>
        <div class="table-container compact-table" style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Сумма</th>
                        <th>Внешний ID</th>
                        <th>Описание</th>
                        <th style="width:120px;">Действия</th>
                    </tr>
                </thead>
                <tbody id="unlinked-tbody"><tr><td colspan="5" class="text-center">Загрузка...</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <div class="card-title"><i data-lucide="list"></i><span>История платежей</span></div>
            <div class="card-actions"><button onclick="loadPaymentsHistory()" class="icon-btn" title="Обновить"><i data-lucide="refresh-cw"></i></button></div>
        </div>
        <div class="table-container compact-table" style="overflow-x: auto;">
            <table>
                <thead><tr><th>Провайдер / Нода</th><th>Дата</th><th>Сумма</th></tr></thead>
                <tbody id="payments-history-tbody"><tr><td colspan="3" class="text-center">Загрузка...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<!-- ===================== СТАТИСТИКА ===================== -->
<div id="stats-tab" class="tab-content">
    <div class="grid-2" style="margin-top: 24px;">
        <div class="card"><div class="card-header"><div class="card-title"><i data-lucide="bar-chart-3"></i><span>Платежи по месяцам</span></div></div><div class="card-body"><canvas id="paymentsChart" height="220"></canvas></div></div>
        <div class="card"><div class="card-header"><div class="card-title"><i data-lucide="pie-chart"></i><span>По провайдерам</span></div></div><div class="card-body"><canvas id="providersChart" height="220"></canvas></div></div>
    </div>
</div>

<!-- ===================== РАСХОДЫ ===================== -->
<div id="expenses-tab" class="tab-content">
    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <div class="card-title"><i data-lucide="receipt"></i><span>Расходы хостинга</span></div>
            <div class="card-actions">
                <button onclick="loadBillingExpenses()" class="icon-btn" title="Обновить"><i data-lucide="refresh-cw"></i></button>
                <button onclick="switchBillingUnifiedTab('imports'); document.getElementById('billing-import-type').value='expenses';" class="icon-btn primary" title="Импорт"><i data-lucide="upload"></i></button>
            </div>
        </div>
        <div class="table-container compact-table" style="overflow-x: auto;">
            <table><thead><tr><th>Дата</th><th>Описание</th><th>Провайдер</th><th>На счёт</th><th>Сумма</th><th>Сопоставлено</th><th>Действия</th></tr></thead><tbody id="billing-expenses-tbody"><tr><td colspan="7" class="text-center">Нет данных</td></tr></tbody></table>
        </div>
    </div>
</div>

<!-- ===================== ДОМЕНЫ ===================== -->
<div id="domains-tab" class="tab-content">
    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <div class="card-title"><i data-lucide="globe"></i><span>Домены</span></div>
            <div class="card-actions">
                <button onclick="loadBillingDomains()" class="icon-btn" title="Обновить"><i data-lucide="refresh-cw"></i></button>
                <button onclick="switchBillingUnifiedTab('imports'); document.getElementById('billing-import-type').value='domains';" class="icon-btn primary" title="Импорт"><i data-lucide="upload"></i></button>
            </div>
        </div>
        <div class="table-container compact-table" style="overflow-x: auto;">
            <table><thead><tr><th>Домен</th><th>TLD</th><th>Провайдер</th><th>Контакт</th><th>Действует до</th><th>Цена</th><th>Статус</th></tr></thead><tbody id="billing-domains-tbody"><tr><td colspan="7" class="text-center">Нет данных</td></tr></tbody></table>
        </div>
    </div>
</div>

<!-- ===================== ПРОВАЙДЕРЫ ===================== -->
<div id="providers-tab" class="tab-content">
    <div class="stats-grid stats-grid-dash" style="margin-top: 24px;">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #818cf8, #4f46e5);"><i data-lucide="building-2"></i></div>
            <div class="stat-card-content"><h3>Провайдеров</h3><div class="stat-value" id="providers-total">0</div><p class="stat-subtitle">всего в системе</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);"><i data-lucide="wallet"></i></div>
            <div class="stat-card-content"><h3>Расходы</h3><div class="stat-value" id="providers-cost">0 ₽</div><p class="stat-subtitle">в месяц суммарно</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);"><i data-lucide="server"></i></div>
            <div class="stat-card-content"><h3>Серверов</h3><div class="stat-value" id="providers-servers">0</div><p class="stat-subtitle">привязано к провайдерам</p></div>
        </div>
    </div>

    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <div class="card-title"><i data-lucide="building-2"></i><span>Провайдеры</span></div>
            <div class="card-actions">
                <div class="providers-search-wrapper">
                    <i data-lucide="search"></i>
                    <input type="text" id="providers-search" placeholder="Поиск..." oninput="filterProviders()">
                </div>
                <button onclick="loadProviders()" class="icon-btn" title="Обновить"><i data-lucide="refresh-cw"></i></button>
                <button onclick="addProvider()" class="primary"><i data-lucide="plus"></i> Добавить</button>
            </div>
        </div>
        <div class="card-body">
            <div id="providers-list" class="providers-grid"><div class="providers-empty"><p>Загрузка...</p></div></div>
            <div class="pagination" id="providers-pagination" style="display: none; margin-top: 24px; align-items: center; justify-content: center; gap: 12px;">
                <button type="button" class="icon-btn" id="providers-prev" onclick="changeProvidersPage(-1)"><i data-lucide="chevron-left"></i></button>
                <span id="providers-page-info">Страница 1</span>
                <button type="button" class="icon-btn" id="providers-next" onclick="changeProvidersPage(1)"><i data-lucide="chevron-right"></i></button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== ИМПОРТ ===================== -->
<div id="imports-tab" class="tab-content">
    <div class="grid-2" style="margin-top: 24px;">
        <div class="card">
            <div class="card-header"><div class="card-title"><i data-lucide="upload"></i><span>Импорт CSV</span></div></div>
            <div class="card-body">
                <div class="form-field">
                    <label class="form-label">Тип</label>
                    <select id="billing-import-type" class="provider-select">
                        <option value="vds">VDS / Ноды (var_vds.csv)</option>
                        <option value="payments">Платежи (var_payment.csv)</option>
                        <option value="expenses">Расходы хостинга (var_expense.csv)</option>
                        <option value="domains">Домены (var_domain.csv)</option>
                    </select>
                </div>
                <div class="form-field"><label class="form-label">Файл CSV (.csv/.txt)</label><input type="file" id="billing-import-file" accept=".csv,.txt,text/csv"></div>
                <button class="primary" onclick="startBillingImport()"><i data-lucide="upload"></i> Импортировать</button>
                <button class="secondary" onclick="recomputeBillingLinks()" title="Заполнить провайдеров расходов, связать расходы с платежами и домены с провайдерами"><i data-lucide="link-2"></i> Пересчитать связи</button>
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

<!-- ===================== МОДАЛКА: БИЛЛИНГ НОДЫ ===================== -->
<div class="modal hidden" id="node-billing-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2 id="node-billing-title">Добавить в биллинг</h2>
            <button class="modal-close" onclick="closeNodeBillingModal()" type="button"><i data-lucide="x"></i></button>
        </div>
        <form id="node-billing-form" onsubmit="saveNodeBilling(event)">
            <input type="hidden" id="node-billing-id">
            <div class="form-field">
                <label class="form-label">Нода *</label>
                <select id="node-billing-node-select" class="provider-select" required>
                    <option value="">Выберите ноду</option>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label">Провайдер</label>
                <select id="node-billing-provider" class="provider-select">
                    <option value="">— Не выбран —</option>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label">Цена, ₽ *</label>
                <input type="number" id="node-billing-amount" step="0.01" min="0.01" required placeholder="0.00">
            </div>
            <div class="form-field">
                <label class="form-label">Период оплаты</label>
                <select id="node-billing-period" class="provider-select">
                    <option value="30">Месяц (30 дней)</option>
                    <option value="365">Год (365 дней)</option>
                    <option value="1">День</option>
                    <option value="7">Неделя</option>
                    <option value="90">Квартал (90 дней)</option>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label">Оплачено до</label>
                <input type="date" id="node-billing-next-payment">
            </div>
            <div class="modal-actions">
                <button type="button" class="secondary" onclick="closeNodeBillingModal()">Отмена</button>
                <button type="submit" class="primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- ===================== МОДАЛКА: ОПЛАТИТЬ НОДУ ===================== -->
<div class="modal hidden" id="node-pay-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2>Оплатить ноду</h2>
            <button class="modal-close" onclick="closeNodePayModal()" type="button"><i data-lucide="x"></i></button>
        </div>
        <form id="node-pay-form" onsubmit="submitNodePayment(event)">
            <input type="hidden" id="node-pay-id">
            <div class="form-field">
                <label class="form-label">Нода</label>
                <div id="node-pay-name" style="color: var(--text-secondary); font-size: 13px; margin-bottom: 6px;">—</div>
            </div>
            <div class="form-field">
                <label class="form-label">Сумма, ₽</label>
                <input type="number" id="node-pay-amount" step="0.01" min="0.01" required>
            </div>
            <div class="form-field">
                <label class="form-label">Дата платежа</label>
                <input type="date" id="node-pay-date" required>
            </div>
            <div class="form-field">
                <label class="form-label">Комментарий (необязательно)</label>
                <input type="text" id="node-pay-comment" maxlength="255" placeholder="Например, оплата за октябрь">
            </div>
            <div class="modal-actions">
                <button type="button" class="secondary" onclick="closeNodePayModal()">Отмена</button>
                <button type="submit" class="primary">Оплатить</button>
            </div>
        </form>
    </div>
</div>

<!-- ===================== МОДАЛКА: ПРИВЯЗАТЬ ПЛАТЁЖ ===================== -->
<div class="modal hidden" id="link-payment-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2>Привязать платёж к ноде</h2>
            <button class="modal-close" onclick="closeLinkPaymentModal()" type="button"><i data-lucide="x"></i></button>
        </div>
        <form id="link-payment-form" onsubmit="submitLinkPayment(event)">
            <input type="hidden" id="link-payment-id">
            <div class="form-field">
                <label class="form-label">Платёж</label>
                <div id="link-payment-desc" style="color: var(--text-secondary); font-size: 13px;"></div>
            </div>
            <div class="form-field">
                <label class="form-label">Нода</label>
                <select id="link-payment-node" class="provider-select" required>
                    <option value="">Выберите ноду</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="secondary" onclick="closeLinkPaymentModal()">Отмена</button>
                <button type="submit" class="primary">Привязать</button>
            </div>
        </form>
    </div>
</div>

<!-- ===================== МОДАЛКА: ПРОВАЙДЕР ===================== -->
<div class="modal hidden" id="provider-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2>Провайдер</h2>
            <button class="modal-close" onclick="closeProviderModal()" type="button"><i data-lucide="x"></i></button>
        </div>
        <form id="provider-form">
            <input type="hidden" id="provider-edit-id">
            <div class="form-field"><label class="form-label">Название *</label><input type="text" id="provider-name" required maxlength="255" placeholder="Например, Hetzner"></div>
            <div class="form-field"><label class="form-label">Ссылка для входа</label><input type="url" id="provider-url" maxlength="255" placeholder="https://example.com"></div>
            <div class="form-field"><label class="form-label">Favicon URL <span style="color: var(--text-secondary); font-size: 11px;">(можно оставить пустым — сгенерируется автоматически)</span></label><input type="url" id="provider-favicon" maxlength="255" placeholder="https://..."></div>
            <div class="modal-actions">
                <button type="button" class="secondary" onclick="closeProviderModal()">Отмена</button>
                <button type="submit" class="primary">Создать</button>
            </div>
        </form>
    </div>
</div>

<!-- ===================== МОДАЛКА: СОПОСТАВИТЬ РАСХОД ===================== -->
<div class="modal hidden" id="expense-match-modal">
    <div class="modal-dialog">
        <div class="modal-header"><h2>Сопоставить расход</h2><button class="modal-close" onclick="closeExpenseMatchModal()" type="button"><i data-lucide="x"></i></button></div>
        <form id="expense-match-form" onsubmit="saveExpenseMatch(event)">
            <input type="hidden" id="expense-match-id">
            <div class="form-field"><label class="form-label">Расход</label><div id="expense-match-desc" style="color: var(--text-secondary); font-size: 13px;"></div></div>
            <div class="form-field"><label class="form-label">Сопоставить с нодой</label><select id="expense-match-node" class="provider-select"><option value="">Не сопоставлять</option></select></div>
            <div class="form-field"><label class="form-label">Сопоставить с доменом</label><select id="expense-match-domain" class="provider-select"><option value="">Не сопоставлять</option></select></div>
            <div class="form-field"><label class="form-label">Платёж (списание за счёт этого платежа)</label><select id="expense-match-payment" class="provider-select"><option value="">Не привязывать</option></select></div>
            <div class="modal-actions"><button type="button" class="secondary" onclick="closeExpenseMatchModal()">Отмена</button><button type="submit" class="primary">Сохранить</button></div>
        </form>
    </div>
</div>

<!-- ===================== КОНТЕКСТНОЕ МЕНЮ ===================== -->
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