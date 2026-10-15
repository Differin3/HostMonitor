<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Логи', 'logs', '<button class="primary" id="exportBtn" onclick="exportCurrentTabLogs()"><i data-lucide="download"></i> Экспорт</button>');
?>
    <div class="tabs">
        <button type="button" class="tab-btn active" data-tab="system" onclick="switchLogsTab('system')">
            <i data-lucide="file-text"></i> Системные логи
        </button>
        <button type="button" class="tab-btn" data-tab="ssh_auth" onclick="switchLogsTab('ssh_auth')">
            <i data-lucide="lock"></i> SSH авторизация
        </button>
    </div>

    <div id="system-logs-tab" class="tab-content active">
        <div class="page-controls">
            <div class="sort-control">
                <select id="nodeFilter" class="compact-select">
                    <option value="">Выберите ноду</option>
                </select>
            </div>
            <div class="sort-control">
                <select id="systemDays">
                    <option value="1">За сутки</option>
                    <option value="7" selected>За 7 дней</option>
                    <option value="30">За 30 дней</option>
                    <option value="0">Всё время</option>
                </select>
            </div>
            <div class="sort-control">
                <select id="levelFilter">
                    <option value="">Все уровни</option>
                    <option value="error">Ошибки</option>
                    <option value="warning">Предупреждения</option>
                    <option value="info">Информация</option>
                </select>
            </div>
            <div class="sort-control">
                <select id="systemSource">
                    <option value="">Все источники</option>
                </select>
            </div>
            <div class="search-control">
                <input type="text" id="logSearch" placeholder="Поиск по сообщению или источнику...">
            </div>
        </div>

        <div class="stats-grid" id="system-stats">
            <div class="stat-card">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #60a5fa, #2563eb);"><i data-lucide="list"></i></div>
                <div class="stat-card-content"><h3>Всего записей</h3><div class="stat-value" id="system-stat-total">0</div><p class="stat-subtitle">за период</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #f87171, #dc2626);"><i data-lucide="alert-circle"></i></div>
                <div class="stat-card-content"><h3>Ошибки</h3><div class="stat-value" id="system-stat-errors">0</div><p class="stat-subtitle">level=error</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);"><i data-lucide="alert-triangle"></i></div>
                <div class="stat-card-content"><h3>Предупреждения</h3><div class="stat-value" id="system-stat-warnings">0</div><p class="stat-subtitle">level=warning</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #a78bfa, #7c3aed);"><i data-lucide="boxes"></i></div>
                <div class="stat-card-content"><h3>Источников</h3><div class="stat-value" id="system-stat-sources">0</div><p class="stat-subtitle">процессы/юниты</p></div>
            </div>
        </div>

        <div id="system-top-sources" class="card hidden" style="margin-top: 14px;">
            <div class="card-header">
                <div class="card-title"><i data-lucide="boxes"></i><span>Топ источников</span></div>
            </div>
            <div class="card-body" id="system-top-sources-list"></div>
        </div>

        <div class="table-container" style="margin-top: 14px;">
            <table>
                <thead>
                    <tr>
                        <th>Время</th>
                        <th>Уровень</th>
                        <th>Источник</th>
                        <th>Сообщение</th>
                    </tr>
                </thead>
                <tbody id="system-logs-tbody">
                    <tr>
                        <td colspan="4" class="text-center">Выберите ноду</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="table-pagination">
            <button id="system-logs-prev">&laquo;</button>
            <span id="system-logs-page-info">Стр. 1</span>
            <button id="system-logs-next">&raquo;</button>
        </div>
    </div>

    <div id="ssh-logs-tab" class="tab-content">
        <div class="page-controls">
            <div class="sort-control">
                <select id="sshNodeFilter" class="compact-select">
                    <option value="">Выберите ноду</option>
                </select>
            </div>
            <div class="sort-control">
                <select id="sshDays">
                    <option value="1">За сутки</option>
                    <option value="7" selected>За 7 дней</option>
                    <option value="30">За 30 дней</option>
                    <option value="0">Всё время</option>
                </select>
            </div>
            <div class="sort-control">
                <select id="sshResult">
                    <option value="">Все результаты</option>
                    <option value="ok">Успешные входы</option>
                    <option value="fail">Неудачные попытки</option>
                    <option value="info">Информационные</option>
                </select>
            </div>
            <div class="sort-control">
                <select id="sshEventType">
                    <option value="">Все события</option>
                </select>
            </div>
            <div class="search-control">
                <input type="text" id="sshLogSearch" placeholder="Поиск по логину, IP, сообщению...">
            </div>
        </div>

        <div class="stats-grid" id="ssh-stats">
            <div class="stat-card">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #60a5fa, #2563eb);"><i data-lucide="list"></i></div>
                <div class="stat-card-content"><h3>Всего событий</h3><div class="stat-value" id="ssh-stat-total">0</div><p class="stat-subtitle">за период</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);"><i data-lucide="log-in"></i></div>
                <div class="stat-card-content"><h3>Успешные входы</h3><div class="stat-value" id="ssh-stat-ok">0</div><p class="stat-subtitle">accepted</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #f87171, #dc2626);"><i data-lucide="x-circle"></i></div>
                <div class="stat-card-content"><h3>Неудачные</h3><div class="stat-value" id="ssh-stat-fail">0</div><p class="stat-subtitle">отказы входа</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #38bdf8, #0284c7);"><i data-lucide="globe"></i></div>
                <div class="stat-card-content"><h3>Уникальных IP</h3><div class="stat-value" id="ssh-stat-ips">0</div><p class="stat-subtitle">источники</p></div>
            </div>
        </div>

        <div id="ssh-top-ips" class="card hidden" style="margin-top: 14px; border-color: rgba(239,68,68,0.35);">
            <div class="card-header">
                <div class="card-title"><i data-lucide="alert-triangle" style="color:#f87171;"></i><span>Топ источников неудачных входов</span></div>
            </div>
            <div class="card-body" id="ssh-top-ips-list"></div>
        </div>

        <div class="table-container" style="margin-top: 14px;">
            <table>
                <thead>
                    <tr>
                        <th>Время</th>
                        <th>Событие</th>
                        <th>Результат</th>
                        <th>Пользователь</th>
                        <th>IP</th>
                        <th>Сообщение</th>
                    </tr>
                </thead>
                <tbody id="ssh-logs-tbody">
                    <tr>
                        <td colspan="6" class="text-center">Выберите ноду</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="table-pagination">
            <button id="ssh-logs-prev">&laquo;</button>
            <span id="ssh-logs-page-info">Стр. 1</span>
            <button id="ssh-logs-next">&raquo;</button>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/logs.js']);

