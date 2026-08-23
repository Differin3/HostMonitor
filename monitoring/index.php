<?php
require_once __DIR__ . '/includes/layout.php';

$actions = <<<'HTML'
<button class="btn-outline" type="button" id="dash-refresh"><i data-lucide="refresh-cw"></i> Обновить</button>
<button class="btn-outline" type="button" id="dash-reset" hidden><i data-lucide="rotate-ccw"></i> Сбросить</button>
<button class="primary" type="button" id="dash-edit"><i data-lucide="sliders-horizontal"></i> Настроить</button>
HTML;

render_layout_start('Дашборд', 'dashboard', $actions);

$chrome = static function (): string {
    return <<<'HTML'
        <div class="dash-widget-bar">
            <span class="dash-grip" title="Перетащить"><i data-lucide="grip-vertical"></i></span>
            <div class="dash-span-btns" role="group" aria-label="Ширина">
                <button type="button" data-span="3" title="Узкий">S</button>
                <button type="button" data-span="6" title="Средний">M</button>
                <button type="button" data-span="12" title="На всю ширину">L</button>
            </div>
            <button type="button" class="dash-hide" data-hide title="Скрыть"><i data-lucide="eye-off"></i></button>
        </div>
HTML;
};
?>
    <section class="dashboard" id="dashboard">
        <div class="dash-toolbar">
            <div class="dash-ranges" id="dash-ranges" role="tablist" aria-label="Диапазон графиков">
                <button type="button" data-range="15m">15 мин</button>
                <button type="button" data-range="1h" class="active">1 час</button>
                <button type="button" data-range="6h">6 часов</button>
                <button type="button" data-range="24h">сутки</button>
            </div>
            <p class="dash-hint" id="dash-edit-hint">Клик по метрике открывает подробную статистику. Настроить — порядок, размер и набор виджетов.</p>
        </div>

        <div class="dash-board" id="dash-board">
            <article class="dash-widget stat-card" id="stat-nodes" data-widget="stat-nodes" data-span="3" data-detail="nodes" data-href="nodes.php" data-title="Ноды онлайн">
                <?= $chrome() ?>
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);">
                    <i data-lucide="server"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Ноды онлайн</h3>
                    <div class="stat-value" id="nodes-count">0</div>
                    <p class="stat-subtitle" id="nodes-sub">из <span id="nodes-total">0</span></p>
                </div>
            </article>
            <article class="dash-widget stat-card" id="stat-alerts" data-widget="stat-alerts" data-span="3" data-detail="alerts" data-href="logs.php" data-title="Алерты">
                <?= $chrome() ?>
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);">
                    <i data-lucide="bell"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Алерты</h3>
                    <div class="stat-value" id="alerts-count">0</div>
                    <p class="stat-subtitle" id="alerts-sub">не закрыты</p>
                </div>
            </article>
            <article class="dash-widget stat-card" id="stat-cpu" data-widget="stat-cpu" data-span="3" data-detail="cpu" data-href="nodes_metrics.php" data-title="CPU">
                <?= $chrome() ?>
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #60a5fa, #2563eb);">
                    <i data-lucide="cpu"></i>
                </div>
                <div class="stat-card-content">
                    <h3>CPU</h3>
                    <div class="stat-value" id="cpu-avg">0%</div>
                    <div class="hm-meter" id="cpu-meter"><span></span></div>
                    <p class="stat-subtitle" id="cpu-sub">среднее · 5 мин</p>
                </div>
            </article>
            <article class="dash-widget stat-card" id="stat-ram" data-widget="stat-ram" data-span="3" data-detail="ram" data-href="nodes_metrics.php" data-title="RAM">
                <?= $chrome() ?>
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);">
                    <i data-lucide="memory-stick"></i>
                </div>
                <div class="stat-card-content">
                    <h3>RAM</h3>
                    <div class="stat-value" id="ram-avg">0%</div>
                    <div class="hm-meter hm-meter-ram" id="ram-meter"><span></span></div>
                    <p class="stat-subtitle" id="ram-sub">среднее · 5 мин</p>
                </div>
            </article>
            <article class="dash-widget stat-card" id="stat-disk" data-widget="stat-disk" data-span="3" data-detail="disk" data-href="nodes_metrics.php" data-title="Диск">
                <?= $chrome() ?>
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);">
                    <i data-lucide="hard-drive"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Диск</h3>
                    <div class="stat-value" id="disk-avg">0%</div>
                    <div class="hm-meter hm-meter-disk" id="disk-meter"><span></span></div>
                    <p class="stat-subtitle" id="disk-sub">среднее · 5 мин</p>
                </div>
            </article>
            <article class="dash-widget stat-card" id="stat-proc" data-widget="stat-proc" data-span="3" data-detail="proc" data-href="processes.php" data-title="Процессы">
                <?= $chrome() ?>
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #818cf8, #4f46e5);">
                    <i data-lucide="cpu"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Процессы</h3>
                    <div class="stat-value" id="proc-count">0</div>
                    <p class="stat-subtitle" id="proc-sub">активных</p>
                </div>
            </article>
            <article class="dash-widget stat-card" id="stat-db" data-widget="stat-db" data-span="3" data-detail="db" data-href="databases.php" data-title="Базы данных">
                <?= $chrome() ?>
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #a78bfa, #6d28d9);">
                    <i data-lucide="database"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Базы данных</h3>
                    <div class="stat-value" id="db-count">0</div>
                    <p class="stat-subtitle" id="db-sub">онлайн из <span id="db-total">0</span></p>
                </div>
            </article>
            <article class="dash-widget stat-card" id="stat-ct" data-widget="stat-ct" data-span="3" data-detail="ct" data-href="containers.php" data-title="Контейнеры">
                <?= $chrome() ?>
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #38bdf8, #0284c7);">
                    <i data-lucide="box"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Контейнеры</h3>
                    <div class="stat-value" id="ct-count">0</div>
                    <p class="stat-subtitle" id="ct-sub">running</p>
                </div>
            </article>

            <article class="dash-widget chart-card" data-widget="chart-res" data-span="6" data-title="Ресурсы">
                <?= $chrome() ?>
                <div class="chart-header">
                    <h3>Ресурсы</h3>
                    <span class="chart-range" data-range-label data-base="CPU · RAM · диск">CPU · RAM · диск</span>
                </div>
                <div class="chart-body">
                    <canvas id="res-chart"></canvas>
                </div>
            </article>
            <article class="dash-widget chart-card" data-widget="chart-net" data-span="6" data-title="Сеть">
                <?= $chrome() ?>
                <div class="chart-header">
                    <h3>Сеть</h3>
                    <span class="chart-range" data-range-label data-base="вход · выход">вход · выход</span>
                </div>
                <div class="chart-body">
                    <canvas id="net-chart"></canvas>
                </div>
            </article>

            <article class="dash-widget card" id="nodes-card" data-widget="list-nodes" data-span="6" data-title="Список нод">
                <?= $chrome() ?>
                <div class="card-header">
                    <h3>Ноды</h3>
                    <a href="nodes.php">Все ноды</a>
                </div>
                <ul class="list dash-nodes" id="nodes-list">
                    <li class="list-empty">Нет данных о нодах</li>
                </ul>
            </article>
            <article class="dash-widget card" id="alerts-card" data-widget="list-alerts" data-span="6" data-title="Список алертов">
                <?= $chrome() ?>
                <div class="card-header">
                    <h3>Алерты</h3>
                    <a href="logs.php">Все алерты</a>
                </div>
                <ul class="list dash-alerts" id="alerts-list">
                    <li class="list-empty">Алертов нет</li>
                </ul>
            </article>
        </div>

        <div class="dash-catalog" id="dash-catalog" hidden>
            <h4>Скрытые виджеты</h4>
            <p>Нажмите, чтобы вернуть на доску</p>
            <div class="dash-catalog-list" id="dash-catalog-list"></div>
        </div>
    </section>

    <div class="modal hidden" id="dash-detail-modal" aria-hidden="true">
        <div class="modal-dialog" style="max-width: 820px;">
            <div class="modal-header">
                <h2 id="dash-detail-title">Подробная статистика</h2>
                <button class="icon" type="button" id="dash-detail-close" aria-label="Закрыть">&times;</button>
            </div>
            <div class="modal-body" id="dash-detail-body">
                <div class="dash-detail-kpis" id="dash-detail-kpis"></div>
                <div class="chart-body dash-detail-chart">
                    <canvas id="dash-detail-chart"></canvas>
                </div>
                <div class="table-container" style="margin-top: 16px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Нода</th>
                                <th>Статус</th>
                                <th>Значение</th>
                                <th>CPU</th>
                                <th>RAM</th>
                                <th>Диск</th>
                            </tr>
                        </thead>
                        <tbody id="dash-detail-tbody">
                            <tr><td colspan="6" class="text-center">Нет данных</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-actions">
                <a class="btn-outline" id="dash-detail-link" href="nodes_metrics.php">Открыть раздел</a>
                <button type="button" class="primary" id="dash-detail-ok">Закрыть</button>
            </div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/dashboard.js']);
