<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Расход трафика', 'nodes-traffic');
?>
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
<?php
render_layout_end(['/frontend/js/nodes_traffic.js']);
