<?php
require_once __DIR__ . '/includes/layout.php';

// Вкладки переключаются серверным переходом (?tab=): каждый скрипт вкладки
// объявляет top-level const API_URL/formatBytes, поэтому вместе на одной
// странице они дают SyntaxError. Один переход — один скрипт вкладки.
$hmTabs = [
    'park'    => ['label' => 'Парк',   'icon' => 'layout-grid', 'js' => '/frontend/js/nodes_stats.js'],
    'node'    => ['label' => 'Нода',   'icon' => 'activity',    'js' => '/frontend/js/nodes_metrics.js'],
    'traffic' => ['label' => 'Трафик', 'icon' => 'trending-up', 'js' => '/frontend/js/nodes_traffic.js'],
];

$hmTab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'park';
if (!isset($hmTabs[$hmTab])) {
    $hmTab = 'park';
}

render_layout_start('Мониторинг', 'monitoring');
?>
    <div class="tabs">
        <?php foreach ($hmTabs as $hmSlug => $hmMeta): ?>
            <button class="tab-btn <?= $hmSlug === $hmTab ? 'active' : '' ?>" type="button"
                    data-hm-mon-tab="<?= htmlspecialchars($hmSlug) ?>">
                <i data-lucide="<?= htmlspecialchars($hmMeta['icon']) ?>"></i> <?= htmlspecialchars($hmMeta['label']) ?>
            </button>
        <?php endforeach; ?>
    </div>
    <script>
        (function () {
            document.querySelectorAll('[data-hm-mon-tab]').forEach(function (el) {
                el.addEventListener('click', function () {
                    location.href = 'monitoring.php?tab=' + encodeURIComponent(el.getAttribute('data-hm-mon-tab'));
                });
            });
        })();
    </script>

<?php if ($hmTab === 'park'): ?>
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
        <div class="stat-card" id="stat-ram">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);">
                <i data-lucide="memory-stick"></i>
            </div>
            <div class="stat-card-content">
                <h3>RAM</h3>
                <div class="stat-value" id="avg-ram-stat">0%</div>
                <p class="stat-subtitle">среднее по парку</p>
            </div>
        </div>
        <div class="stat-card" id="stat-disk">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #f59e0b, #b45309);">
                <i data-lucide="hard-drive"></i>
            </div>
            <div class="stat-card-content">
                <h3>Диск</h3>
                <div class="stat-value" id="avg-disk-stat">0%</div>
                <p class="stat-subtitle">среднее по парку</p>
            </div>
        </div>
        <div class="stat-card" id="stat-traffic">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #38bdf8, #0284c7);">
                <i data-lucide="database-backup"></i>
            </div>
            <div class="stat-card-content">
                <h3>Оборот трафика</h3>
                <div class="stat-value" id="traffic-total-stat">0 Б</div>
                <p class="stat-subtitle">накопительно по парку</p>
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
                            <th>Swap</th>
                            <th>Оборот</th>
                        </tr>
                    </thead>
                    <tbody id="stats-tbody">
                        <tr><td colspan="8" class="text-center">Нет данных</td></tr>
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
<?php elseif ($hmTab === "node"): ?>
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
                        <p class="stat-subtitle" id="node-uptime">аптайм —</p>
                        <p class="stat-subtitle" id="metrics-staleness" style="display:none; font-size:0.75rem; color:#94a3b8;"></p>
                    </div>
                </div>
                <div class="stat-card" id="metric-traffic">
                    <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);">
                        <i data-lucide="database-backup"></i>
                    </div>
                    <div class="stat-card-content">
                        <h3>Оборот трафика</h3>
                        <div class="stat-value" id="traffic-total">0 Б</div>
                        <p class="stat-subtitle" id="traffic-sub">↓ — · ↑ —</p>
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
                <article class="chart-card metrics-chart-wide">
                    <div class="chart-header">
                        <h3>Оборот трафика</h3>
                        <span class="chart-range" id="range-cum">вход · выход · накопительно</span>
                    </div>
                    <div class="chart-body">
                        <canvas id="metrics-cum-chart"></canvas>
                        <p class="metrics-chart-empty hidden" id="empty-cum">Нет истории за выбранный период</p>
                    </div>
                </article>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="stats-grid" id="traffic-kpis">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #38bdf8, #0284c7);">
                <i data-lucide="arrow-down-to-line"></i>
            </div>
            <div class="stat-card-content">
                <h3>Вход</h3>
                <div class="stat-value" id="total-download">0 Б</div>
                <p class="stat-subtitle">суммарно сейчас</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #818cf8, #4f46e5);">
                <i data-lucide="arrow-up-from-line"></i>
            </div>
            <div class="stat-card-content">
                <h3>Выход</h3>
                <div class="stat-value" id="total-upload">0 Б</div>
                <p class="stat-subtitle">суммарно сейчас</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);">
                <i data-lucide="activity"></i>
            </div>
            <div class="stat-card-content">
                <h3>Общая скорость</h3>
                <div class="stat-value" id="total-traffic">0 Б</div>
                <p class="stat-subtitle">вход + выход / с</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);">
                <i data-lucide="database-backup"></i>
            </div>
            <div class="stat-card-content">
                <h3>Оборот всего</h3>
                <div class="stat-value" id="traffic-total-cumulative">0 Б</div>
                <p class="stat-subtitle">накопительно с загрузки</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #c084fc, #7c3aed);">
                <i data-lucide="server"></i>
            </div>
            <div class="stat-card-content">
                <h3>Активные ноды</h3>
                <div class="stat-value" id="active-nodes">0</div>
                <p class="stat-subtitle">из <span id="traffic-nodes-total">0</span></p>
            </div>
        </div>
    </div>

    <div class="traffic-layout">
        <div class="card traffic-tops-card">
            <div class="card-header">
                <div class="card-title">
                    <i data-lucide="bar-chart-3"></i>
                    <span>Топ по трафику</span>
                </div>
            </div>
            <div class="traffic-tops" id="traffic-tops">
                <p class="list-empty">Нет данных</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i data-lucide="network"></i>
                    <span>По нодам</span>
                </div>
                <span class="pill pill-info" id="traffic-updated">—</span>
            </div>
            <div class="table-container">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>Нода</th>
                            <th>Статус</th>
                            <th>Вход</th>
                            <th>Выход</th>
                            <th>Оборот</th>
                            <th>Доля</th>
                        </tr>
                    </thead>
                    <tbody id="traffic-tbody">
                        <tr><td colspan="6" class="text-center">Нет данных</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php
render_layout_end([$hmTabs[$hmTab]["js"]]);
