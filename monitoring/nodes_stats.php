<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Статистика нод', 'nodes-stats');
?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: var(--accent);">
                <i data-lucide="server"></i>
            </div>
            <div class="stat-card-content">
                <h3>Всего нод</h3>
                <div class="stat-value" id="total-nodes">0</div>
                <p class="stat-subtitle">активных нод</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: var(--success);">
                <i data-lucide="activity"></i>
            </div>
            <div class="stat-card-content">
                <h3>Онлайн</h3>
                <div class="stat-value" id="online-nodes">0</div>
                <p class="stat-subtitle">нод в сети</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: var(--danger);">
                <i data-lucide="alert-circle"></i>
            </div>
            <div class="stat-card-content">
                <h3>Оффлайн</h3>
                <div class="stat-value" id="offline-nodes">0</div>
                <p class="stat-subtitle">нод не в сети</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: var(--warning);">
                <i data-lucide="cpu"></i>
            </div>
            <div class="stat-card-content">
                <h3>Средняя нагрузка</h3>
                <div class="stat-value" id="avg-load">0%</div>
                <p class="stat-subtitle">CPU по всем нодам</p>
            </div>
        </div>
    </div>

    <script>
        // Обертка для вкладок, чтобы onclick не падал даже если основной скрипт еще не загрузился
        function switchNodesStatsTab(tab) {
            if (window.switchNodesStatsTabImpl) {
                window.switchNodesStatsTabImpl(tab);
            }
        }
    </script>
    <div class="tabs" style="margin-top: 24px;">
        <button class="tab-btn active" data-tab="current" onclick="switchNodesStatsTab('current')">
            <i data-lucide="activity"></i> Текущая статистика
        </button>
        <button class="tab-btn" data-tab="history" onclick="switchNodesStatsTab('history')">
            <i data-lucide="clock"></i> История
        </button>
    </div>

    <div id="current-tab" class="tab-content active">
        <div class="grid-2" style="margin-top: 24px;">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="activity"></i>
                        <span>Нагрузка CPU по нодам</span>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="nodes-cpu-chart" style="max-height: 260px;"></canvas>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="hard-drive"></i>
                        <span>Использование RAM и диска</span>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="nodes-usage-chart" style="max-height: 260px;"></canvas>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top: 24px;">
            <div class="card-header">
                <div class="card-title">
                    <i data-lucide="bar-chart-2"></i>
                    <span>Детальная статистика</span>
                </div>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Нода</th>
                            <th>Статус</th>
                            <th>CPU</th>
                            <th>RAM</th>
                            <th>Диск</th>
                            <th>Сеть</th>
                            <th>Время работы</th>
                        </tr>
                    </thead>
                    <tbody id="stats-tbody">
                        <tr><td colspan="7" class="text-center">Нет данных</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="history-tab" class="tab-content">
        <div class="card" style="margin-top: 24px;">
            <div class="card-header">
                <div class="card-title">
                    <i data-lucide="clock"></i>
                    <span>История по времени</span>
                </div>
                <div class="card-actions">
                    <div class="history-filters" style="display: flex; gap: 12px; align-items: center; flex-wrap: nowrap;">
                        <div class="input-with-icon" style="min-width: 200px;">
                            <i data-lucide="server" class="input-icon"></i>
                            <select id="history-node" class="provider-select">
                                <option value="" disabled selected>Выберите ноду...</option>
                            </select>
                        </div>
                        <div class="input-with-icon history-range-wrapper" style="min-width: 260px; position: relative;">
                            <i data-lucide="calendar-range" class="input-icon"></i>
                            <input type="text" id="history-range-display" placeholder="Выберите период" readonly>
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
                        <select id="history-range" style="min-width: 140px;">
                            <option value="1h">1 час</option>
                            <option value="6 hours">6 часов</option>
                            <option value="12 hours">12 часов</option>
                            <option value="1 day">1 день</option>
                            <option value="7 days">7 дней</option>
                        </select>
                        <span id="history-node-warning" class="history-node-warning">Сначала выберите ноду, чтобы загрузить историю.</span>
                    </div>
                </div>
            </div>
            <div class="charts">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>CPU (история)</h3>
                        <button type="button" class="icon-btn chart-expand-btn" title="Развернуть график">
                            <i data-lucide="maximize-2"></i>
                        </button>
                    </div>
                    <canvas id="history-cpu-chart"></canvas>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>RAM (история)</h3>
                        <button type="button" class="icon-btn chart-expand-btn" title="Развернуть график">
                            <i data-lucide="maximize-2"></i>
                        </button>
                    </div>
                    <canvas id="history-ram-chart"></canvas>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Диск (история)</h3>
                        <button type="button" class="icon-btn chart-expand-btn" title="Развернуть график">
                            <i data-lucide="maximize-2"></i>
                        </button>
                    </div>
                    <canvas id="history-disk-chart"></canvas>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Сеть (история)</h3>
                        <button type="button" class="icon-btn chart-expand-btn" title="Развернуть график">
                            <i data-lucide="maximize-2"></i>
                        </button>
                    </div>
                    <canvas id="history-net-chart"></canvas>
                </div>
            </div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/nodes_stats.js']);

