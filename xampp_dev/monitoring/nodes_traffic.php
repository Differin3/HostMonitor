<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Расход трафика', 'nodes-traffic');
?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: var(--info);">
                <i data-lucide="download"></i>
            </div>
            <div class="stat-card-content">
                <h3>Всего скачано</h3>
                <div class="stat-value" id="total-download">0 ГБ</div>
                <p class="stat-subtitle">за все время</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: var(--accent);">
                <i data-lucide="upload"></i>
            </div>
            <div class="stat-card-content">
                <h3>Всего загружено</h3>
                <div class="stat-value" id="total-upload">0 ГБ</div>
                <p class="stat-subtitle">за все время</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: var(--success);">
                <i data-lucide="trending-up"></i>
            </div>
            <div class="stat-card-content">
                <h3>Средняя скорость</h3>
                <div class="stat-value" id="avg-speed">0 Мбит/с</div>
                <p class="stat-subtitle">входящая/исходящая</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: var(--warning);">
                <i data-lucide="activity"></i>
            </div>
            <div class="stat-card-content">
                <h3>Активные ноды</h3>
                <div class="stat-value" id="active-nodes">0</div>
                <p class="stat-subtitle">передают данные</p>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <div class="card-title">
                <i data-lucide="network"></i>
                <span>Расход трафика по нодам</span>
            </div>
            <div class="card-actions">
                <select id="traffic-period">
                    <option value="day">За день</option>
                    <option value="week" selected>За неделю</option>
                    <option value="month">За месяц</option>
                    <option value="year">За год</option>
                </select>
            </div>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Нода</th>
                        <th>Скачано</th>
                        <th>Загружено</th>
                        <th>Всего</th>
                        <th>Скорость</th>
                        <th>График</th>
                    </tr>
                </thead>
                <tbody id="traffic-tbody">
                    <tr><td colspan="6" class="text-center">Загрузка...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/nodes_traffic.js']);

