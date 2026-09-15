<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Порты', 'ports', '<button class="primary" onclick="scanPorts()"><i data-lucide="scan"></i> Сканировать</button>');
?>
    <div class="tabs">
        <button class="tab-btn active" data-tab="ports" onclick="switchPortsTab('ports')">
            <i data-lucide="network"></i> Порты
        </button>
        <button class="tab-btn" data-tab="interfaces" onclick="switchPortsTab('interfaces')">
            <i data-lucide="server"></i> Сетевые интерфейсы
        </button>
    </div>

    <div id="ports-tab" class="tab-content active">
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
                        <td colspan="7" class="text-center">Нет данных</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="interfaces-tab" class="tab-content">
        <div class="filters-bar">
            <select id="interfacesNodeFilter" class="filter-select">
                <option value="">Все ноды</option>
            </select>
            <input type="text" id="interfaceSearch" placeholder="Поиск интерфейса..." class="filter-input">
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Интерфейс</th>
                        <th>Нода</th>
                        <th>IP адрес</th>
                        <th>Маска</th>
                        <th>Статус</th>
                        <th>Входящий трафик</th>
                        <th>Исходящий трафик</th>
                    </tr>
                </thead>
                <tbody id="interfaces-tbody">
                    <tr>
                        <td colspan="7" class="text-center">Нет данных</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/ports.js']);

