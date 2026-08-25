<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('SMART Мониторинг', 'smart');
?>
    <div class="compact-filters">
        <select id="nodeFilter" class="compact-select">
            <option value="">Все ноды</option>
        </select>
        <select id="healthFilter" class="compact-select">
            <option value="">Все статусы</option>
            <option value="ok">OK</option>
            <option value="warning">Предупреждение</option>
            <option value="failed">Критический</option>
        </select>
        <div class="compact-search">
            <input type="text" id="driveSearch" placeholder="Поиск диска...">
        </div>
        <button class="icon-btn" id="refresh-smart" type="button" title="Обновить">
            <i data-lucide="refresh-cw"></i>
        </button>
    </div>

    <div id="smart-content">
        <!-- Сводка по дискам -->
        <div class="stats-grid stats-grid-dash" id="smart-summary">
            <div class="stat-card">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #60a5fa, #2563eb);">
                    <i data-lucide="hard-drive"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Всего дисков</h3>
                    <div class="stat-value" id="total-drives">0</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);">
                    <i data-lucide="check-circle"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Здоровы</h3>
                    <div class="stat-value" id="healthy-drives">0</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);">
                    <i data-lucide="alert-triangle"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Предупреждения</h3>
                    <div class="stat-value" id="warning-drives">0</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, #f87171, #dc2626);">
                    <i data-lucide="x-circle"></i>
                </div>
                <div class="stat-card-content">
                    <h3>Критические</h3>
                    <div class="stat-value" id="failed-drives">0</div>
                </div>
            </div>
        </div>

        <!-- Таблица дисков -->
        <div class="table-container" id="drives-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Статус</th>
                        <th>Нода</th>
                        <th>Устройство</th>
                        <th>Модель</th>
                        <th>Серийный</th>
                        <th>Ёмкость</th>
                        <th>Темп.</th>
                        <th>Часы работы</th>
                        <th>Корзина</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody id="smart-tbody">
                    <tr>
                        <td colspan="10" class="text-center">Нет данных</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Детали выбранного диска -->
        <div id="drive-detail" style="display: none;">
            <div class="smart-detail-header">
                <h3 id="detail-title">Детали диска</h3>
                <div class="dash-ranges" id="smart-ranges" role="tablist" aria-label="Диапазон графиков">
                    <button type="button" data-range="1h">1 час</button>
                    <button type="button" data-range="24h">сутки</button>
                    <button type="button" data-range="7d" class="active">7 дней</button>
                    <button type="button" data-range="30d">30 дней</button>
                </div>
            </div>

            <!-- Карточки ключевых атрибутов -->
            <div class="stats-grid stats-grid-dash" id="key-attrs">
            </div>

            <!-- Графики -->
            <div class="metrics-charts" id="smart-charts">
                <article class="chart-card">
                    <div class="chart-header">
                        <h3>Reallocated Sectors</h3>
                        <span class="chart-range"> sect 5 (остаток → порог)</span>
                    </div>
                    <div class="chart-body">
                        <canvas id="smart-reallocated-chart"></canvas>
                        <p class="metrics-chart-empty hidden" id="empty-reallocated">Нет истории</p>
                    </div>
                </article>
                <article class="chart-card">
                    <div class="chart-header">
                        <h3>Температура</h3>
                        <span class="chart-range">°C</span>
                    </div>
                    <div class="chart-body">
                        <canvas id="smart-temp-chart"></canvas>
                        <p class="metrics-chart-empty hidden" id="empty-temp">Нет истории</p>
                    </div>
                </article>
                <article class="chart-card">
                    <div class="chart-header">
                        <h3>Power-On Hours</h3>
                        <span class="chart-range">часы</span>
                    </div>
                    <div class="chart-body">
                        <canvas id="smart-hours-chart"></canvas>
                        <p class="metrics-chart-empty hidden" id="empty-hours">Нет истории</p>
                    </div>
                </article>
                <article class="chart-card">
                    <div class="chart-header">
                        <h3>Pending Sectors</h3>
                        <span class="chart-range">sect 197</span>
                    </div>
                    <div class="chart-body">
                        <canvas id="smart-pending-chart"></canvas>
                        <p class="metrics-chart-empty hidden" id="empty-pending">Нет истории</p>
                    </div>
                </article>
                <article class="chart-card">
                    <div class="chart-header">
                        <h3>Uncorrectable Errors</h3>
                        <span class="chart-range">sect 198</span>
                    </div>
                    <div class="chart-body">
                        <canvas id="smart-uncorrectable-chart"></canvas>
                        <p class="metrics-chart-empty hidden" id="empty-uncorrectable">Нет истории</p>
                    </div>
                </article>
                <article class="chart-card">
                    <div class="chart-header">
                        <h3>Load Cycle Count</h3>
                        <span class="chart-range">sect 193</span>
                    </div>
                    <div class="chart-body">
                        <canvas id="smart-load-cycle-chart"></canvas>
                        <p class="metrics-chart-empty hidden" id="empty-load-cycle">Нет истории</p>
                    </div>
                </article>
            </div>

            <!-- Полная таблица атрибутов -->
            <div class="table-container" id="attrs-table-wrap">
                <h4 style="padding: 12px 16px; margin: 0;">Все SMART атрибуты</h4>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Атрибут</th>
                            <th>Значение</th>
                            <th>Худшее</th>
                            <th>Порог</th>
                            <th>Raw</th>
                            <th>Флаги</th>
                        </tr>
                    </thead>
                    <tbody id="attrs-tbody">
                        <tr>
                            <td colspan="7" class="text-center">Выберите диск</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/smart.js']);
