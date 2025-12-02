<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Дашборд', 'dashboard');
?>
    <div class="dashboard">
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Ноды</h3>
                <div class="stat-value" id="nodes-count">0</div>
            </div>
            <div class="stat-card">
                <h3>Процессы</h3>
                <div class="stat-value" id="processes-count">0</div>
            </div>
            <div class="stat-card">
                <h3>Контейнеры</h3>
                <div class="stat-value" id="containers-count">0</div>
            </div>
            <div class="stat-card">
                <h3>CPU</h3>
                <div class="stat-value" id="cpu-avg">0%</div>
            </div>
            <div class="stat-card">
                <h3>Uptime</h3>
                <div class="stat-value" id="uptime-avg">-</div>
            </div>
        </div>
        
        <div class="charts charts-dashboard" style="margin-top: 2rem;">
            <div class="chart-card">
                <div class="chart-header">
                    <h3>Нагрузка CPU</h3>
                    <button type="button" class="icon-btn chart-expand-btn" title="Развернуть график">
                        <i data-lucide="maximize-2"></i>
                    </button>
                </div>
                <canvas id="cpu-chart"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <h3>Использование памяти</h3>
                    <button type="button" class="icon-btn chart-expand-btn" title="Развернуть график">
                        <i data-lucide="maximize-2"></i>
                    </button>
                </div>
                <canvas id="memory-chart"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <h3>Нагрузка диска</h3>
                    <button type="button" class="icon-btn chart-expand-btn" title="Развернуть график">
                        <i data-lucide="maximize-2"></i>
                    </button>
                </div>
                <canvas id="disk-chart"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <h3>Сеть (in+out)</h3>
                    <button type="button" class="icon-btn chart-expand-btn" title="Развернуть график">
                        <i data-lucide="maximize-2"></i>
                    </button>
                </div>
                <canvas id="net-chart"></canvas>
            </div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/dashboard.js']);

