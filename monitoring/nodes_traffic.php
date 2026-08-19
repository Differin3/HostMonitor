<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Расход трафика', 'nodes-traffic');
?>
    <div class="stats-grid stats-grid-dash">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #38bdf8, #0284c7);">
                <i data-lucide="download"></i>
            </div>
            <div class="stat-card-content">
                <h3>Вход</h3>
                <div class="stat-value" id="total-download">0 Б</div>
                <p class="stat-subtitle">сумма по нодам</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #818cf8, #4f46e5);">
                <i data-lucide="upload"></i>
            </div>
            <div class="stat-card-content">
                <h3>Выход</h3>
                <div class="stat-value" id="total-upload">0 Б</div>
                <p class="stat-subtitle">сумма по нодам</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);">
                <i data-lucide="arrow-left-right"></i>
            </div>
            <div class="stat-card-content">
                <h3>Всего</h3>
                <div class="stat-value" id="total-traffic">0 Б</div>
                <p class="stat-subtitle">вход + выход</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);">
                <i data-lucide="activity"></i>
            </div>
            <div class="stat-card-content">
                <h3>Онлайн</h3>
                <div class="stat-value" id="active-nodes">0</div>
                <p class="stat-subtitle">нод передают данные</p>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i data-lucide="network"></i>
                <span>По нодам</span>
            </div>
        </div>
        <div class="table-container">
            <table class="stats-table">
                <thead>
                    <tr>
                        <th>Нода</th>
                        <th>Вход</th>
                        <th>Выход</th>
                        <th>Доля</th>
                    </tr>
                </thead>
                <tbody id="traffic-tbody">
                    <tr><td colspan="4" class="text-center">Нет данных</td></tr>
                </tbody>
            </table>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/nodes_traffic.js']);
