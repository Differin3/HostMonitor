<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Обновления системы', 'updates');
?>
    <div class="stats-grid">
        <div class="stat-card">
            <h3>Доступно обновлений</h3>
            <div class="stat-value" id="updates-count">-</div>
        </div>
        <div class="stat-card">
            <h3>Критических</h3>
            <div class="stat-value" id="security-updates-count">-</div>
        </div>
        <div class="stat-card">
            <h3>Последняя проверка</h3>
            <div class="stat-value" id="last-check">-</div>
        </div>
    </div>

    <div class="compact-filters" style="margin-top: 24px;">
        <select id="nodeFilter" class="compact-select">
            <option value="">Все ноды</option>
        </select>
        <select id="priorityFilter" class="compact-select">
            <option value="">Все приоритеты</option>
            <option value="security">Критические</option>
            <option value="important">Важные</option>
            <option value="normal">Обычные</option>
        </select>
        <div class="compact-search">
            <input type="text" id="updates-search" placeholder="Поиск пакета...">
        </div>
    </div>

    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <div class="card-title">
                <i data-lucide="package"></i>
                <span>Доступные обновления</span>
            </div>
            <div class="card-actions">
                <button class="primary" onclick="checkUpdates()">
                    <i data-lucide="refresh-cw"></i> Проверить обновления
                </button>
                <button class="btn-outline" onclick="installUpdates()" id="install-btn" disabled>
                    <i data-lucide="download"></i> Установить выбранные
                </button>
            </div>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" id="select-all-updates" title="Выбрать все"></th>
                        <th>Пакет</th>
                        <th>Текущая версия</th>
                        <th>Новая версия</th>
                        <th>Приоритет</th>
                        <th>Нода</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody id="updates-tbody">
                    <tr>
                        <td colspan="7" class="text-center">Нажмите "Проверить обновления" для начала</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <div class="card-title">
                <i data-lucide="history"></i>
                <span>История обновлений</span>
            </div>
            <div class="card-actions">
                <select id="history-node-filter" class="compact-select" style="margin-right: 8px;">
                    <option value="">Все ноды</option>
                </select>
                <select id="history-status-filter" class="compact-select" style="margin-right: 8px;">
                    <option value="">Все результаты</option>
                    <option value="success">Успешные</option>
                    <option value="failed">Ошибки</option>
                </select>
                <button class="btn-outline" onclick="loadHistory()">
                    <i data-lucide="refresh-cw"></i> Обновить
                </button>
            </div>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Нода</th>
                        <th>Пакет</th>
                        <th>Версия</th>
                        <th>Результат</th>
                        <th>Сообщение</th>
                    </tr>
                </thead>
                <tbody id="updates-history-tbody">
                    <tr>
                        <td colspan="6" class="text-center">Нет данных</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="toast hidden" id="toast"></div>
<?php
render_layout_end(['/frontend/js/updates.js']);

