<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Порты нод', 'nodes-ports', '<button class="primary" onclick="scanPorts()"><i data-lucide="scan"></i> Сканировать</button>');
?>
    <div class="compact-filters">
        <select id="nodeFilter" class="compact-select" onchange="return false;">
            <option value="">Все ноды</option>
        </select>
        <select id="portTypeFilter" class="compact-select" onchange="return false;">
            <option value="">Все типы</option>
            <option value="tcp">TCP</option>
            <option value="udp">UDP</option>
        </select>
        <div class="compact-search">
            <input type="text" id="portSearch" placeholder="Поиск порта...">
        </div>
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
                    <td colspan="7" class="text-center">Нет данных</td>
                </tr>
            </tbody>
        </table>
    </div>
<?php
render_layout_end(['/frontend/js/nodes_ports.js']);

