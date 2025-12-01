<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Процессы', 'processes', '<button class="primary" onclick="refreshProcesses()"><i data-lucide="refresh-cw"></i> Обновить</button>');
?>
    <div class="compact-filters">
        <select id="nodeFilter" class="compact-select">
            <option value="">Выберите ноду</option>
        </select>
        <select id="statusFilter" class="compact-select">
            <option value="">Все статусы</option>
            <option value="running">Запущены</option>
            <option value="sleeping">Ожидают</option>
            <option value="stopped">Остановлены</option>
        </select>
        <div class="compact-search">
            <input type="text" id="processSearch" placeholder="Поиск процесса...">
        </div>
    </div>

    <div class="processes-tabs">
        <div class="tab-buttons">
            <button class="tab-btn active" data-tab="processes" onclick="switchProcessTab('processes')">
                <i data-lucide="cpu"></i> Процессы
            </button>
            <button class="tab-btn" data-tab="logs" onclick="switchProcessTab('logs')">
                <i data-lucide="file-text"></i> Логи
            </button>
        </div>
        
        <div id="processes-tab" class="tab-content active">
            <div id="processes-empty" class="empty-state">
                <i data-lucide="search"></i>
                <h3>Выберите ноду</h3>
                <p>Для просмотра процессов выберите ноду из списка выше</p>
            </div>
            <div id="processes-table" class="table-container" style="display: none;">
                <table>
                    <thead>
                        <tr>
                            <th>PID</th>
                            <th>Имя</th>
                            <th>CPU %</th>
                            <th>Память %</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody id="processes-tbody"></tbody>
                </table>
            </div>
        </div>
        
        <div id="processes-logs-tab" class="tab-content">
            <div id="processes-logs-empty" class="empty-state">
                <i data-lucide="file-text"></i>
                <h3>Выберите ноду</h3>
                <p>Для просмотра логов процессов выберите ноду из списка выше</p>
            </div>
            <div id="processes-logs-container" class="logs-container" style="display: none;">
                <div class="logs-header">
                    <div class="logs-controls">
                            <select id="processLogSelector" class="compact-select">
                                <option value="">Выберите процесс</option>
                            </select>
                        <button onclick="pauseProcessLogs()" id="pauseProcessLogsBtn"><i data-lucide="pause"></i> Пауза</button>
                        <button onclick="clearProcessLogs()"><i data-lucide="trash-2"></i> Очистить</button>
                        <button onclick="exportProcessLogs()"><i data-lucide="download"></i> Экспорт</button>
                        <label>
                            <input type="checkbox" id="autoScrollProcessLogs" checked> Автопрокрутка
                        </label>
                    </div>
                </div>
                <div id="processes-logs-content" class="logs-content"></div>
            </div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/processes.js']);

