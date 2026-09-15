<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Логи', 'logs', '<button class="primary" id="exportBtn" onclick="exportCurrentTabLogs()"><i data-lucide="download"></i> Экспорт</button>');
?>
    <div class="tabs">
        <button type="button" class="tab-btn active" data-tab="system" onclick="switchLogsTab('system')">
            <i data-lucide="file-text"></i> Системные логи
        </button>
        <button type="button" class="tab-btn" data-tab="auth" onclick="switchLogsTab('auth')">
            <i data-lucide="shield"></i> Авторизация панели
        </button>
        <button type="button" class="tab-btn" data-tab="ssh_auth" onclick="switchLogsTab('ssh_auth')">
            <i data-lucide="lock"></i> SSH авторизация
        </button>
    </div>

    <div id="system-logs-tab" class="tab-content active">
        <div class="compact-filters">
            <select id="nodeFilter" class="compact-select">
                <option value="">Выберите ноду</option>
            </select>
            <select id="levelFilter" class="compact-select">
                <option value="">Все уровни</option>
                <option value="error">Ошибки</option>
                <option value="warning">Предупреждения</option>
                <option value="info">Информация</option>
            </select>
            <div class="compact-search">
                <input type="text" id="logSearch" placeholder="Поиск в логах...">
            </div>
        </div>

        <div id="logs-content-wrapper">
            <div id="logs-empty" class="empty-state">
                <i data-lucide="search"></i>
                <h3>Выберите ноду</h3>
                <p>Системные логи ноды появятся после выбора сервера</p>
            </div>
            <div id="logs-container" class="logs-container" style="display: none;">
                <div class="logs-header">
                    <div class="logs-controls">
                        <button type="button" onclick="pauseLogs()" id="pauseBtn"><i data-lucide="pause"></i> Пауза</button>
                        <button type="button" onclick="clearLogs()"><i data-lucide="trash-2"></i> Очистить</button>
                        <label>
                            <input type="checkbox" id="autoScroll" checked> Автопрокрутка
                        </label>
                    </div>
                    <div class="logs-pagination">
                        <button id="system-logs-prev">&laquo;</button>
                        <span id="system-logs-page-info">Стр. 1</span>
                        <button id="system-logs-next">&raquo;</button>
                    </div>
                </div>
                <div id="logs-content" class="logs-content"></div>
            </div>
        </div>
    </div>

    <div id="auth-logs-tab" class="tab-content">
        <div class="compact-filters">
            <select id="authEventFilter" class="compact-select">
                <option value="">Все события</option>
                <option value="login">Вход</option>
                <option value="logout">Выход</option>
                <option value="failed">Неудачные попытки</option>
            </select>
            <div class="compact-search">
                <input type="text" id="authLogSearch" placeholder="Поиск по пользователю или IP...">
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Время</th>
                        <th>Пользователь</th>
                        <th>IP адрес</th>
                        <th>Событие</th>
                        <th>Результат</th>
                        <th>Сообщение</th>
                    </tr>
                </thead>
                <tbody id="auth-logs-tbody">
                    <tr>
                        <td colspan="6" class="text-center">Загрузка...</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="table-pagination">
            <button id="auth-logs-prev">&laquo;</button>
            <span id="auth-logs-page-info">Стр. 1</span>
            <button id="auth-logs-next">&raquo;</button>
        </div>
    </div>

    <div id="ssh-logs-tab" class="tab-content">
        <div class="compact-filters">
            <select id="sshNodeFilter" class="compact-select">
                <option value="">Выберите ноду</option>
            </select>
            <select id="sshLevelFilter" class="compact-select">
                <option value="">Все уровни</option>
                <option value="error">Ошибки</option>
                <option value="warning">Предупреждения</option>
                <option value="info">Информация</option>
            </select>
            <div class="compact-search">
                <input type="text" id="sshLogSearch" placeholder="Поиск по процессу или сообщению...">
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Время</th>
                        <th>Результат</th>
                        <th>Пользователь</th>
                        <th>IP</th>
                        <th>Сообщение</th>
                    </tr>
                </thead>
                <tbody id="ssh-logs-tbody">
                    <tr>
                        <td colspan="5" class="text-center">Загрузка...</td>
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

