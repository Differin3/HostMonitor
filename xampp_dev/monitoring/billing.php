<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Биллинг', 'nodes-billing', '<button class="primary" onclick="addPayment()"><i data-lucide="plus"></i> Добавить платеж</button>');
?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: var(--info);">
                <i data-lucide="calendar"></i>
            </div>
            <div class="stat-card-content">
                <h3>Текущая дата</h3>
                <div class="stat-value" id="current-date"><?= date('d') ?></div>
                <p class="stat-subtitle"><?= date('F Y', strtotime('now')) ?></p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: var(--warning);">
                <i data-lucide="clock"></i>
            </div>
            <div class="stat-card-content">
                <h3>Ожидается в <?= date('F') ?></h3>
                <div class="stat-value" id="pending-nodes">0</div>
                <p class="stat-subtitle">ноды, ожидающие оплаты</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: var(--success);">
                <i data-lucide="wallet"></i>
            </div>
            <div class="stat-card-content">
                <h3>Платежей в <?= date('F') ?></h3>
                <div class="stat-value" id="month-payments">0 ₽</div>
                <p class="stat-subtitle">всего платежей</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: var(--purple);">
                <i data-lucide="trending-up"></i>
            </div>
            <div class="stat-card-content">
                <h3>Всего потрачено</h3>
                <div class="stat-value" id="total-spent">0 ₽</div>
                <p class="stat-subtitle">суммарных трат</p>
            </div>
        </div>
    </div>

    <div class="grid-3" style="margin-top: 24px;">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i data-lucide="server"></i>
                    <span>Оплачиваемые ноды</span>
                </div>
                <div class="card-actions">
                    <button onclick="refreshBilling()" class="icon-btn"><i data-lucide="refresh-cw"></i></button>
                    <button onclick="addNodeBilling()" class="icon-btn primary"><i data-lucide="plus"></i></button>
                </div>
            </div>
            <div class="table-container compact-table">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="select-all-billing-nodes" title="Выбрать все"></th>
                            <th><i data-lucide="building"></i> Имя хостера</th>
                            <th><i data-lucide="server"></i> Нода</th>
                            <th><i data-lucide="calendar"></i> Следующий платеж</th>
                            <th><i data-lucide="check-circle"></i></th>
                        </tr>
                    </thead>
                    <tbody id="billing-nodes-tbody">
                        <tr><td colspan="5" class="text-center">Загрузка...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
             <div class="card-header">
                 <div class="card-title">
                     <i data-lucide="history"></i>
                     <span>История платежей</span>
                 </div>
                 <div class="card-actions">
                     <button onclick="refreshPayments()" class="icon-btn"><i data-lucide="refresh-cw"></i></button>
                     <button onclick="addPayment()" class="icon-btn primary"><i data-lucide="plus"></i></button>
                 </div>
             </div>
             <div class="table-container compact-table">
                 <table>
                     <thead>
                         <tr>
                             <th><i data-lucide="building"></i> Имя хостера</th>
                             <th><i data-lucide="calendar"></i> Дата оплаты</th>
                             <th><i data-lucide="dollar-sign"></i> Сумма</th>
                         </tr>
                     </thead>
                     <tbody id="payments-tbody">
                         <tr><td colspan="3" class="text-center">Загрузка...</td></tr>
                     </tbody>
                 </table>
             </div>
         </div>
        <div class="card" style="grid-column: 1 / -1;">
            <div class="card-header">
                <div class="card-title">
                    <i data-lucide="building"></i>
                    <span>Провайдеры</span>
                </div>
                <div class="card-actions">
                    <button onclick="refreshProviders()" class="icon-btn"><i data-lucide="refresh-cw"></i></button>
                    <button onclick="addProvider()" class="icon-btn primary"><i data-lucide="plus"></i></button>
                </div>
            </div>
            <div class="table-container compact-table" style="overflow-x: auto;">
                <table id="providers-table">
                    <thead>
                        <tr>
                            <th><i data-lucide="building"></i> Имя хостера</th>
                            <th><i data-lucide="link"></i> Ссылка для входа</th>
                            <th><i data-lucide="dollar-sign"></i> Всего, ₽</th>
                            <th><i data-lucide="server"></i> Сервера</th>
                            <th><i data-lucide="settings"></i> Действия</th>
                        </tr>
                    </thead>
                    <tbody id="providers-tbody">
                        <tr><td colspan="5" class="text-center">Загрузка...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="pagination" id="providers-pagination" style="display: none;">
                <button class="pagination-btn" id="providers-prev" onclick="changeProvidersPage(-1)"><i data-lucide="chevron-left"></i></button>
                <span class="pagination-info" id="providers-page-info">Страница 1 из 1</span>
                <button class="pagination-btn" id="providers-next" onclick="changeProvidersPage(1)"><i data-lucide="chevron-right"></i></button>
            </div>
        </div>
    </div>

    <!-- Модальное окно для редактирования биллинга ноды -->
    <div class="modal hidden" id="billing-modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2>Биллинг нода</h2>
                <button class="icon" onclick="closeBillingModal()">&times;</button>
            </div>
            <form id="billing-form">
                <input type="hidden" name="node_id" id="billing-node-id">
                <div class="form-field">
                    <label class="form-label">Биллинг нода</label>
                    <div class="input-with-icon">
                        <i data-lucide="server" class="input-icon"></i>
                        <select name="node_id_select" id="billing-node-select" class="provider-select" required>
                            <option value="">Ноды для биллинга не найдены</option>
                        </select>
                    </div>
                </div>
                <div class="form-field">
                    <label class="form-label">Провайдер</label>
                    <div class="form-hint-text">Выберите провайдера.</div>
                    <div class="input-with-icon">
                        <i data-lucide="building" class="input-icon"></i>
                        <select name="provider_name" id="billing-provider" class="provider-select">
                            <option value="">Выбрать провайдера...</option>
                        </select>
                    </div>
                </div>
                <div class="form-field">
                    <label class="form-label">Следующий платеж *</label>
                    <div class="form-hint-text">Дата и время следующей оплаты этого узла. Система уведомит вас о предстоящем платеже.</div>
                    <div class="input-with-icon">
                        <i data-lucide="calendar" class="input-icon"></i>
                        <input type="datetime-local" name="next_payment_date" id="billing-next-payment" required>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-outline" onclick="closeBillingModal()">Отмена</button>
                    <button type="submit" class="primary">Создать</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Модальное окно для добавления платежа -->
    <div class="modal hidden" id="payment-modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2>Платежная запись</h2>
                <button class="icon" onclick="closePaymentModal()">&times;</button>
            </div>
            <form id="payment-form">
                <div class="form-field">
                    <label class="form-label">Провайдер</label>
                    <div class="form-hint-text">Выберите провайдера.</div>
                    <div class="input-with-icon">
                        <i data-lucide="building" class="input-icon"></i>
                        <select name="provider_id" id="payment-provider-id" class="provider-select" required>
                            <option value="">Выбрать провайдера...</option>
                        </select>
                    </div>
                </div>
                <div class="form-field">
                    <label class="form-label">Дата оплаты *</label>
                    <div class="form-hint-text">Дата и время оплаты счёта.</div>
                    <div class="input-with-icon">
                        <i data-lucide="calendar" class="input-icon"></i>
                        <input type="datetime-local" name="payment_date" id="payment-date" required value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                </div>
                <div class="form-field">
                    <label class="form-label">Сумма *</label>
                    <div class="form-hint-text">Сумма платежа, RUB</div>
                    <div class="input-with-icon">
                        <i data-lucide="dollar-sign" class="input-icon"></i>
                        <input type="number" name="amount" step="0.01" min="0" required placeholder="0.00">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-outline" onclick="closePaymentModal()">Отмена</button>
                    <button type="submit" class="primary">Создать</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Модальное окно для добавления провайдера -->
    <div class="modal hidden" id="provider-modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2>Провайдер</h2>
                <button class="icon" onclick="closeProviderModal()">&times;</button>
            </div>
            <form id="provider-form">
                <input type="hidden" name="provider_id" id="provider-edit-id" value="">
                <div class="form-field">
                    <label class="form-label">Имя *</label>
                    <div class="form-hint-text">Имя провайдера. Используется для вывода в пользовательский интерфейс.</div>
                    <div class="input-with-icon">
                        <i data-lucide="tag" class="input-icon"></i>
                        <input type="text" name="name" id="provider-name" required placeholder="Введите имя провайдера">
                    </div>
                </div>
                <div class="form-field">
                    <label class="form-label">Ссылка на Favicon *</label>
                    <div class="form-hint-text">Это ссылка на иконку провайдера. Она используется для отображения иконки провайдера в пользовательском интерфейсе. Достаточно просто ввести доменное имя.</div>
                    <div class="input-with-icon">
                        <i data-lucide="image" class="input-icon"></i>
                        <input type="url" name="favicon_url" id="provider-favicon" required placeholder="https://hetzner.com">
                    </div>
                </div>
                <div class="form-field">
                    <label class="form-label">Ссылка для входа *</label>
                    <div class="form-hint-text">URL-адрес для входа — это URL-адрес страницы входа провайдера. Он поможет вам быстро перейти на страницу входа провайдера.</div>
                    <div class="input-with-icon">
                        <i data-lucide="link" class="input-icon"></i>
                        <input type="url" name="url" id="provider-url" required placeholder="https://cloud.hetzner.com">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-outline" onclick="closeProviderModal()">Отмена</button>
                    <button type="submit" class="primary">Создать</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Модальное окно для обновления даты платежа -->
    <div class="modal hidden" id="update-payment-date-modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2>Обновить дату оплаты</h2>
                <button class="icon" onclick="closeUpdatePaymentDateModal()">&times;</button>
            </div>
            <form id="update-payment-date-form">
                <input type="hidden" name="node_id" id="update-payment-node-id">
                <div class="form-field">
                    <label class="form-label">Текущая дата</label>
                    <div class="input-with-icon">
                        <i data-lucide="calendar" class="input-icon"></i>
                        <input type="date" id="update-payment-current-date" readonly>
                    </div>
                </div>
                <div class="form-field">
                    <label class="form-label">Новая дата</label>
                    <div class="input-with-icon">
                        <i data-lucide="calendar" class="input-icon"></i>
                        <input type="date" name="new_date" id="update-payment-new-date" required>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-outline" onclick="closeUpdatePaymentDateModal()">Закрыть</button>
                    <button type="submit" class="primary">Обновить дату</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Контекстное меню для выбранных нод -->
    <div class="context-menu hidden" id="billing-context-menu">
        <div class="context-menu-header">
            <span>ВЫБРАНО: <span id="billing-selected-count">0</span></span>
        </div>
        <div class="context-menu-actions">
            <button class="context-action-link" onclick="clearBillingSelection()">Очистить выбор</button>
            <button class="context-action-link" onclick="selectAllBillingNodes()">Выбрать все</button>
            <button class="context-action-btn context-action-refresh" onclick="updateSelectedPaymentDates()">
                <i data-lucide="calendar"></i> Обновить даты
            </button>
            <button class="context-action-btn context-action-delete" onclick="deleteSelectedBillingNodes()">
                <i data-lucide="trash-2"></i> Удалить
            </button>
        </div>
    </div>
    
<?php
render_layout_end(['/frontend/js/billing.js']);


