<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Метрики нод', 'nodes-metrics');
?>
    <div class="compact-filters">
        <select id="nodeFilter" class="compact-select">
            <option value="">Выберите ноду</option>
        </select>
        <select id="metrics-sort" class="compact-select">
            <option value="name">По имени</option>
            <option value="cpu">По CPU</option>
            <option value="ram">По RAM</option>
            <option value="disk">По диску</option>
        </select>
        <div class="compact-search">
            <input type="text" id="metrics-search" placeholder="Поиск по нодам...">
        </div>
    </div>

    <div id="metrics-content">
        <div id="metrics-empty" class="empty-state">
            <i data-lucide="search"></i>
            <h3>Выберите ноду</h3>
            <p>Для просмотра метрик выберите ноду из списка выше</p>
        </div>
        <div id="metrics-data" style="display: none;">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-icon" style="background: var(--accent);">
                        <i data-lucide="cpu"></i>
                    </div>
                    <div class="stat-card-content">
                        <h3>CPU</h3>
                        <div class="stat-value" id="avg-cpu">0%</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon" style="background: var(--info);">
                        <i data-lucide="hard-drive"></i>
                    </div>
                    <div class="stat-card-content">
                        <h3>RAM</h3>
                        <div class="stat-value" id="avg-ram">0%</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon" style="background: var(--warning);">
                        <i data-lucide="database"></i>
                    </div>
                    <div class="stat-card-content">
                        <h3>Диск</h3>
                        <div class="stat-value" id="avg-disk">0%</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon" style="background: var(--success);">
                        <i data-lucide="activity"></i>
                    </div>
                    <div class="stat-card-content">
                        <h3>Статус</h3>
                        <div class="stat-value" id="node-status">-</div>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-top: 24px;">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="activity"></i>
                        <span>Метрики в реальном времени</span>
                    </div>
                    <div class="card-actions">
                        <button class="icon-btn" id="refresh-metrics"><i data-lucide="refresh-cw"></i></button>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Метрика</th>
                                <th>Значение</th>
                                <th>График</th>
                            </tr>
                        </thead>
                        <tbody id="metrics-tbody">
                            <tr><td colspan="3" class="text-center">Загрузка...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/nodes_metrics.js']);

