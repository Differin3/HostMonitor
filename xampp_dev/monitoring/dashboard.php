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
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/dashboard.js']);

