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

    <div id="processes-content">
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
<?php
render_layout_end(['/frontend/js/processes.js']);

