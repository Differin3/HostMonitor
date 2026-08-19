<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Статистика нод', 'nodes-stats');
?>
    <div class="stats-grid stats-grid-dash">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #60a5fa, #2563eb);">
                <i data-lucide="server"></i>
            </div>
            <div class="stat-card-content">
                <h3>Всего</h3>
                <div class="stat-value" id="total-nodes">0</div>
                <p class="stat-subtitle">нод в панели</p>
            </div>
        </div>
        <div class="stat-card" id="stat-online">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);">
                <i data-lucide="activity"></i>
            </div>
            <div class="stat-card-content">
                <h3>Онлайн</h3>
                <div class="stat-value" id="online-nodes">0</div>
                <p class="stat-subtitle">отвечают агентом</p>
            </div>
        </div>
        <div class="stat-card" id="stat-offline">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #f87171, #dc2626);">
                <i data-lucide="alert-circle"></i>
            </div>
            <div class="stat-card-content">
                <h3>Оффлайн</h3>
                <div class="stat-value" id="offline-nodes">0</div>
                <p class="stat-subtitle">нет связи</p>
            </div>
        </div>
        <div class="stat-card" id="stat-load">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);">
                <i data-lucide="cpu"></i>
            </div>
            <div class="stat-card-content">
                <h3>CPU</h3>
                <div class="stat-value" id="avg-load">0%</div>
                <p class="stat-subtitle">среднее по парку</p>
            </div>
        </div>
    </div>

    <script>
        function switchNodesStatsTab(tab) {
            if (window.switchNodesStatsTabImpl) window.switchNodesStatsTabImpl(tab);
        }
    </script>
    <div class="tabs">
        <button class="tab-btn active" data-tab="current" onclick="switchNodesStatsTab('current')">
            <i data-lucide="activity"></i> Сейчас
        </button>
        <button class="tab-btn" data-tab="history" onclick="switchNodesStatsTab('history')">
            <i data-lucide="clock"></i> История
        </button>
    </div>

    <div id="current-tab" class="tab-content active">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i data-lucide="bar-chart-2"></i>
                    <span>Нагрузка по нодам</span>
                </div>
            </div>
            <div class="table-container">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>Нода</th>
                            <th>Статус</th>
                            <th>CPU</th>
                            <th>RAM</th>
                            <th>Диск</th>
                            <th>Сеть</th>
                        </tr>
                    </thead>
                    <tbody id="stats-tbody">
                        <tr><td colspan="6" class="text-center">Нет данных</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="history-tab" class="tab-content">
        <div class="card">
            <div class="card-header history-head">
                <div class="card-title">
                    <i data-lucide="clock"></i>
                    <span>История</span>
                </div>
                <div class="card-actions">
                    <div class="history-filters">
                        <div class="input-with-icon">
                            <i data-lucide="server" class="input-icon"></i>
                            <select id="history-node" class="provider-select">
                                <option value="" disabled selected>Нода</option>
                            </select>
                        </div>
                        <select id="history-range">
                            <option value="1h">1 час</option>
                            <option value="6 hours">6 часов</option>
                            <option value="12 hours">12 часов</option>
                            <option value="1 day">1 день</option>
                            <option value="7 days">7 дней</option>
                        </select>
                        <div class="input-with-icon history-range-wrapper">
                            <i data-lucide="calendar-range" class="input-icon"></i>
                            <input type="text" id="history-range-display" placeholder="Свой период" readonly>
                            <div class="history-range-panel hidden" id="history-range-panel">
                                <input type="hidden" id="history-from">
                                <input type="hidden" id="history-to">
                                <div class="history-range-row">
                                    <div id="history-calendar" class="history-calendar"></div>
                                </div>
                                <div class="history-range-actions">
                                    <button type="button" class="btn-outline" id="history-range-clear">Сбросить</button>
                                    <button type="button" class="primary" id="history-range-apply">Применить</button>
                                </div>
                            </div>
                        </div>
                        <span id="history-node-warning" class="history-node-warning">Выберите ноду</span>
                    </div>
                </div>
            </div>
            <div class="charts charts-split">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Ресурсы</h3>
                    </div>
                    <div class="chart-body">
                        <canvas id="history-cpu-chart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Сеть</h3>
                    </div>
                    <div class="chart-body">
                        <canvas id="history-net-chart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/nodes_stats.js']);
