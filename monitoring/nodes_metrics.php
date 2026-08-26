<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Метрики нод', 'nodes-metrics');
?>
    <div class="compact-filters">
        <select id="nodeFilter" class="compact-select">
            <option value="">Выберите ноду</option>
        </select>
        <div class="dash-ranges" id="metrics-ranges" role="tablist" aria-label="Диапазон графиков">
            <button type="button" data-range="15m">15 мин</button>
            <button type="button" data-range="1h" class="active">1 час</button>
            <button type="button" data-range="6h">6 часов</button>
            <button type="button" data-range="24h">сутки</button>
            <button type="button" data-range="7d">7 дней</button>
        </div>
        <button class="icon-btn" id="refresh-metrics" type="button" title="Обновить">
            <i data-lucide="refresh-cw"></i>
        </button>
    </div>

    <div id="metrics-content">
        <div id="metrics-empty" class="empty-state">
            <i data-lucide="search"></i>
            <h3>Выберите ноду</h3>
            <p>Снимок и графики CPU, RAM, диска и сети появятся после выбора сервера</p>
        </div>
        <div id="metrics-data" style="display: none;">
            <div class="stats-grid stats-grid-dash">
                <div class="stat-card" id="metric-cpu">
                    <div class="stat-card-icon" style="background: linear-gradient(180deg, #60a5fa, #2563eb);">
                        <i data-lucide="cpu"></i>
                    </div>
                    <div class="stat-card-content">
                        <h3>CPU</h3>
                        <div class="stat-value" id="avg-cpu">0%</div>
                        <div class="hm-meter hm-meter-cpu" id="meter-cpu"><span></span></div>
                        <p class="stat-subtitle" id="cpu-sub">load —</p>
                    </div>
                </div>
                <div class="stat-card" id="metric-ram">
                    <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);">
                        <i data-lucide="memory-stick"></i>
                    </div>
                    <div class="stat-card-content">
                        <h3>RAM</h3>
                        <div class="stat-value" id="avg-ram">0%</div>
                        <div class="hm-meter hm-meter-ram" id="meter-ram"><span></span></div>
                        <p class="stat-subtitle" id="ram-sub">—</p>
                    </div>
                </div>
                <div class="stat-card" id="metric-disk">
                    <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);">
                        <i data-lucide="hard-drive"></i>
                    </div>
                    <div class="stat-card-content">
                        <h3>Диск</h3>
                        <div class="stat-value" id="avg-disk">0%</div>
                        <div class="hm-meter hm-meter-disk" id="meter-disk"><span></span></div>
                        <p class="stat-subtitle" id="disk-sub">—</p>
                    </div>
                </div>
                <div class="stat-card" id="metric-status">
                    <div class="stat-card-icon" style="background: linear-gradient(180deg, #38bdf8, #0284c7);">
                        <i data-lucide="activity"></i>
                    </div>
                    <div class="stat-card-content">
                        <h3>Статус</h3>
                        <div class="stat-value" id="node-status">—</div>
                        <p class="stat-subtitle" id="node-net">сеть —</p>
                        <p class="stat-subtitle" id="metrics-staleness" style="display:none; font-size:0.75rem; color:#94a3b8;"></p>
                    </div>
                </div>
                <div class="stat-card" id="metric-gpu" style="display:none;">
                    <div class="stat-card-icon" style="background: linear-gradient(180deg, #a78bfa, #7c3aed);">
                        <i data-lucide="circuit-board"></i>
                    </div>
                    <div class="stat-card-content">
                        <h3>GPU</h3>
                        <div class="stat-value" id="avg-gpu">0%</div>
                        <div class="hm-meter hm-meter-cpu" id="meter-gpu"><span></span></div>
                        <p class="stat-subtitle" id="gpu-sub">—</p>
                    </div>
                </div>
            </div>

            <div class="metrics-charts">
                <article class="chart-card">
                    <div class="chart-header">
                        <h3>Ресурсы</h3>
                        <span class="chart-range" id="range-res">CPU · RAM · диск</span>
                    </div>
                    <div class="chart-body">
                        <canvas id="metrics-res-chart"></canvas>
                        <p class="metrics-chart-empty hidden" id="empty-res">Нет истории за выбранный период</p>
                    </div>
                </article>
                <article class="chart-card">
                    <div class="chart-header">
                        <h3>Сеть</h3>
                        <span class="chart-range" id="range-net">вход · выход</span>
                    </div>
                    <div class="chart-body">
                        <canvas id="metrics-net-chart"></canvas>
                        <p class="metrics-chart-empty hidden" id="empty-net">Нет истории за выбранный период</p>
                    </div>
                </article>
                <article class="chart-card metrics-chart-wide">
                    <div class="chart-header">
                        <h3>Нагрузка</h3>
                        <span class="chart-range" id="range-load">load · swap</span>
                    </div>
                    <div class="chart-body">
                        <canvas id="metrics-load-chart"></canvas>
                        <p class="metrics-chart-empty hidden" id="empty-load">Нет истории за выбранный период</p>
                    </div>
                </article>
            </div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/nodes_metrics.js']);
