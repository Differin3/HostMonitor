<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Логи', 'logs', '<button class="primary" onclick="exportLogs()"><i data-lucide="download"></i> Экспорт</button>');
?>
    <div class="tabs">
        <button class="tab-btn active" data-tab="system" onclick="switchLogsTab('system')">
            <i data-lucide="file-text"></i> Системные логи
        </button>
        <button class="tab-btn" data-tab="auth" onclick="switchLogsTab('auth')">
            <i data-lucide="shield"></i> Авторизация
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
                <p>Для просмотра логов выберите ноду из списка выше</p>
            </div>
            <div id="logs-container" class="logs-container" style="display: none;">
                <div class="logs-header">
                    <div class="logs-controls">
                        <button onclick="pauseLogs()" id="pauseBtn"><i data-lucide="pause"></i> Пауза</button>
                        <button onclick="clearLogs()"><i data-lucide="trash-2"></i> Очистить</button>
                        <label>
                            <input type="checkbox" id="autoScroll" checked> Автопрокрутка
                        </label>
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
    </div>
<?php
render_layout_end(['/frontend/js/logs.js']);

