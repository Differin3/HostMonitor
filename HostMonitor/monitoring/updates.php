<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Обновления системы', 'updates');
?>
        <div class="stats-grid stats-grid-dash">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #60a5fa, #2563eb);">
                <i data-lucide="package"></i>
            </div>
            <div class="stat-card-content">
                <h3>Доступно</h3>
                <div class="stat-value" id="updates-count">—</div>
                <p class="stat-subtitle">пакетов</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #f87171, #dc2626);">
                <i data-lucide="shield-alert"></i>
            </div>
            <div class="stat-card-content">
                <h3>Критические</h3>
                <div class="stat-value" id="security-updates-count">—</div>
                <p class="stat-subtitle">security</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #38bdf8, #0284c7);">
                <i data-lucide="clock"></i>
            </div>
            <div class="stat-card-content">
                <h3>Проверка</h3>
                <div class="stat-value" id="last-check">—</div>
                <p class="stat-subtitle">последний запуск</p>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <div class="card-title">
                <i data-lucide="bot"></i>
                <span>Агенты HostMonitor</span>
            </div>
            <div class="card-actions">
                <button class="btn-outline" type="button" id="agent-check-btn" onclick="checkAgentUpdates()">
                    <i data-lucide="search"></i> Проверить агенты
                </button>
                <button class="primary" type="button" id="agent-apply-btn" onclick="applyAgentUpdates()">
                    <i data-lucide="download"></i> Обновить устаревшие
                </button>
            </div>
        </div>
        <p class="stat-subtitle" id="agent-desired-label" style="margin: 0 16px 12px;">Целевая версия: —</p>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Нода</th>
                        <th>Статус</th>
                        <th>Версия</th>
                        <th>Commit</th>
                        <th>Remote</th>
                        <th>Обновление</th>
                    </tr>
                </thead>
                <tbody id="agent-updates-tbody">
                    <tr>
                        <td colspan="6" class="text-center">Загрузка...</td>
                    </tr>
                </tbody>
            </table>
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
                <span>Доступные обновления пакетов</span>
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

