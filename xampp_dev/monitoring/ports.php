<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Порты', 'ports', '<button class="primary" onclick="scanPorts()"><i data-lucide="scan"></i> Сканировать</button>');
?>
    <div class="filters-bar">
        <select id="nodeFilter" class="filter-select">
            <option value="">Все ноды</option>
        </select>
        <select id="portTypeFilter" class="filter-select">
            <option value="">Все типы</option>
            <option value="tcp">TCP</option>
            <option value="udp">UDP</option>
        </select>
        <input type="text" id="portSearch" placeholder="Поиск порта..." class="filter-input">
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Порт</th>
                    <th>Тип</th>
                    <th>Нода</th>
                    <th>Процесс</th>
                    <th>PID</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody id="ports-tbody">
                <tr>
                    <td colspan="7" class="text-center">Загрузка...</td>
                </tr>
            </tbody>
        </table>
    </div>
<?php
render_layout_end(['/frontend/js/ports.js']);

