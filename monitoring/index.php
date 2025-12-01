<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Дашборд', 'dashboard');
?>
    <section class="dashboard">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card-icon" style="background: var(--success);">
                    <i data-lucide="server"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Ноды онлайн</h3>
                    <div class="stat-value" id="nodes-count">0</div>
                    <p class="stat-subtitle">из <span id="nodes-total">0</span></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background: var(--info);">
                    <i data-lucide="activity"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Процессы</h3>
                    <div class="stat-value" id="processes-count">0</div>
                    <p class="stat-subtitle">активных</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background: var(--warning);">
                    <i data-lucide="box"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Контейнеры</h3>
                    <div class="stat-value" id="containers-count">0</div>
                    <p class="stat-subtitle">работает</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background: var(--accent);">
                    <i data-lucide="cpu"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Средний CPU</h3>
                    <div class="stat-value" id="cpu-avg">0%</div>
                    <p class="stat-subtitle">последние 5 мин</p>
                </div>
            </div>
        </div>

        <div class="charts">
            <div class="chart-card">
                <div class="chart-header">
                    <h3>Нагрузка CPU</h3>
                    <span class="chart-range" id="cpu-range">1ч</span>
                </div>
                <canvas id="cpu-chart"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <h3>Использование памяти</h3>
                    <span class="chart-range" id="memory-range">1ч</span>
                </div>
                <canvas id="memory-chart"></canvas>
            </div>
        </div>

        <div class="grid-2">
            <div class="card" id="nodes-card">
                <div class="card-header">
                    <h3>Последние ноды</h3>
                    <a href="nodes.php">Все ноды</a>
                </div>
                <ul class="list" id="nodes-list">
                    <li class="list-empty">Нет данных о нодах</li>
                </ul>
            </div>
            <div class="card" id="alerts-card">
                <div class="card-header">
                    <h3>Активные алерты</h3>
                    <a href="logs.php">Все алерты</a>
                </div>
                <ul class="list" id="alerts-list">
                    <li class="list-empty">Алертов нет</li>
                </ul>
            </div>
        </div>
    </section>
<?php
render_layout_end([
    'https://cdn.jsdelivr.net/npm/chart.js',
    '/frontend/js/dashboard.js'
]);

