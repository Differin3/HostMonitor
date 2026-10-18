<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Сеть нод', 'nodes-network', '<button class="icon-btn" id="refresh-network" type="button" title="Обновить"><i data-lucide="refresh-cw"></i></button>');
?>
    <div class="tabs">
        <button class="tab-btn active" data-tab="interfaces" type="button">
            <i data-lucide="server"></i> Интерфейсы
        </button>
        <button class="tab-btn" data-tab="neighbors" type="button">
            <i data-lucide="share-2"></i> Соседи
        </button>
        <button class="tab-btn" data-tab="ports" type="button">
            <i data-lucide="shield"></i> Порты и firewall
        </button>
    </div>

    <div id="net-tab-interfaces" class="tab-content active">
        <div class="compact-filters">
            <select id="net-node-filter" class="compact-select">
                <option value="">Все ноды</option>
            </select>
            <div class="dash-ranges" id="net-ranges" role="tablist" aria-label="Диапазон истории интерфейса">
                <button type="button" data-range="15m">15 мин</button>
                <button type="button" data-range="1h" class="active">1 час</button>
                <button type="button" data-range="6h">6 часов</button>
                <button type="button" data-range="24h">сутки</button>
                <button type="button" data-range="7d">7 дней</button>
            </div>
            <div class="compact-search">
                <input type="text" id="net-iface-search" placeholder="Поиск интерфейса...">
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Интерфейс</th>
                        <th>Нода</th>
                        <th>MAC</th>
                        <th>IP</th>
                        <th>Шлюз</th>
                        <th>Скорость</th>
                        <th>Статус</th>
                        <th>Вход</th>
                        <th>Выход</th>
                        <th>Ошибки</th>
                        <th>История</th>
                    </tr>
                </thead>
                <tbody id="net-iface-tbody">
                    <tr>
                        <td colspan="11" class="text-center">Нет данных</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <article class="chart-card" id="net-iface-chart-card" style="display: none;">
            <div class="chart-header">
                <h3 id="net-iface-chart-title">История интерфейса</h3>
                <span class="chart-range" id="net-iface-chart-range">вход · выход · ошибки</span>
            </div>
            <div class="chart-body">
                <canvas id="net-iface-chart"></canvas>
                <p class="metrics-chart-empty hidden" id="net-iface-empty">Нет истории за выбранный период</p>
            </div>
        </article>
    </div>

    <div id="net-tab-neighbors" class="tab-content">
        <div class="compact-filters">
            <select id="net-neighbors-node-filter" class="compact-select">
                <option value="">Все ноды</option>
            </select>
            <div class="compact-search">
                <input type="text" id="net-neighbors-search" placeholder="Поиск по IP или MAC...">
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>IP</th>
                        <th>MAC</th>
                        <th>Интерфейс</th>
                        <th>Семейство</th>
                        <th>Нода</th>
                        <th>Обновлено</th>
                    </tr>
                </thead>
                <tbody id="net-neighbors-tbody">
                    <tr>
                        <td colspan="6" class="text-center">Нет данных</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="net-tab-ports" class="tab-content">
        <div class="compact-filters">
            <select id="net-ports-node-filter" class="compact-select">
                <option value="">Все ноды</option>
            </select>
            <select id="net-ports-type-filter" class="compact-select">
                <option value="">Все типы</option>
                <option value="tcp">TCP</option>
                <option value="udp">UDP</option>
            </select>
            <div class="compact-search">
                <input type="text" id="net-ports-search" placeholder="Поиск порта...">
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
                        <th>Firewall</th>
                    </tr>
                </thead>
                <tbody id="net-ports-tbody">
                    <tr>
                        <td colspan="7" class="text-center">Нет данных</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/nodes_network.js']);
