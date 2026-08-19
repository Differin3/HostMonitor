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
    
    <!-- Модальное окно для просмотра логов процесса -->
    <div id="process-logs-modal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 90%; width: 1200px; max-height: 90vh;">
            <div class="modal-header">
                <h3 id="modal-process-name">Логи процесса</h3>
                <button class="modal-close" onclick="closeProcessLogsModal()">&times;</button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 16px; max-height: calc(90vh - 120px);">
                <div style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                    <div class="form-field" style="flex: 1; min-width: 200px;">
                        <label>Период</label>
                        <select id="modal-time-range" onchange="updateCustomDateRange()">
                            <option value="1h">Последний час</option>
                            <option value="6h">Последние 6 часов</option>
                            <option value="12h">Последние 12 часов</option>
                            <option value="24h">Последние 24 часа</option>
                            <option value="7d">Последние 7 дней</option>
                            <option value="30d">Последние 30 дней</option>
                            <option value="custom">Произвольный диапазон</option>
                        </select>
                    </div>
                    <div id="custom-date-range" style="display: none; flex: 1; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <div class="form-field" style="flex: 1; min-width: 200px;">
                            <label>От</label>
                            <input type="datetime-local" id="modal-from-date" />
                        </div>
                        <div class="form-field" style="flex: 1; min-width: 200px;">
                            <label>До</label>
                            <input type="datetime-local" id="modal-to-date" />
                        </div>
                    </div>
                    <button class="primary" onclick="confirmLoadProcessLogs()" style="margin-bottom: 0;">
                        <i data-lucide="refresh-cw"></i> Загрузить
                    </button>
                </div>
                <div id="processes-logs-loading" class="logs-loading" style="display: none;">
                    <div class="spinner"></div>
                    <p>Загрузка логов с ноды...</p>
                </div>
                <div id="processes-logs-container" class="logs-container" style="display: none; flex: 1; min-height: 400px; overflow: hidden;">
                    <div class="logs-header">
                        <div class="logs-controls">
                            <button onclick="exportProcessLogs()"><i data-lucide="download"></i> Экспорт</button>
                            <button onclick="clearProcessLogs()"><i data-lucide="trash-2"></i> Очистить</button>
                            <label>
                                <input type="checkbox" id="autoScrollProcessLogs" checked> Автопрокрутка
                            </label>
                        </div>
                        <div class="logs-pagination">
                            <button id="process-logs-prev">&laquo;</button>
                            <span id="process-logs-page-info">Стр. 1</span>
                            <button id="process-logs-next">&raquo;</button>
                        </div>
                    </div>
                    <div id="processes-logs-content" class="logs-content" style="max-height: calc(90vh - 280px); overflow-y: auto;"></div>
                </div>
            </div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/processes.js']);

