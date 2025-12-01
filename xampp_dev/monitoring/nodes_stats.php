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
                    <tr><td colspan="7" class="text-center">Загрузка...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/nodes_stats.js']);

