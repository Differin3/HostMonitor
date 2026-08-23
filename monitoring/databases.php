<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db_ha_form.php';

render_layout_start(
    'Базы данных',
    'databases',
    '<button class="btn-outline" type="button" id="dbmon-refresh"><i data-lucide="refresh-cw"></i> Опросить</button>'
    . '<button class="primary" type="button" id="dbmon-add"><i data-lucide="plus"></i> Добавить базу</button>'
);
?>
    <?php render_db_ha_panel(['context' => 'databases']); ?>

    <h3 class="dbmon-section-title">Мониторинг баз данных</h3>
    <div class="page-controls">
        <div class="search-control">
            <input type="text" id="dbmon-search" placeholder="Поиск по имени, хосту, версии...">
        </div>
        <div class="sort-control">
            <select id="dbmon-engine">
                <option value="">Все СУБД</option>
                <option value="mysql">MySQL</option>
                <option value="mariadb">MariaDB</option>
                <option value="postgres">PostgreSQL</option>
            </select>
        </div>
        <div class="sort-control">
            <select id="dbmon-status">
                <option value="">Все статусы</option>
                <option value="online">Онлайн</option>
                <option value="offline">Оффлайн</option>
            </select>
        </div>
    </div>

    <div class="stats-grid" id="dbmon-stats">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #60a5fa, #2563eb);">
                <i data-lucide="database"></i>
            </div>
            <div class="stat-card-content">
                <h3>Базы</h3>
                <div class="stat-value" id="dbmon-count">0</div>
                <p class="stat-subtitle">в мониторинге</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);">
                <i data-lucide="check-circle"></i>
            </div>
            <div class="stat-card-content">
                <h3>Онлайн</h3>
                <div class="stat-value" id="dbmon-online">0</div>
                <p class="stat-subtitle">отвечают на запрос</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #38bdf8, #0284c7);">
                <i data-lucide="users"></i>
            </div>
            <div class="stat-card-content">
                <h3>Сессии</h3>
                <div class="stat-value" id="dbmon-conn">0</div>
                <p class="stat-subtitle">подключений сейчас</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);">
                <i data-lucide="hard-drive"></i>
            </div>
            <div class="stat-card-content">
                <h3>Данные</h3>
                <div class="stat-value" id="dbmon-size">—</div>
                <p class="stat-subtitle">суммарный размер</p>
            </div>
        </div>
    </div>

    <div id="dbmon-grid" class="dbmon-grid"></div>

    <article class="card dbmon-chart-card">
        <div class="card-header">
            <h3>Сессии и QPS</h3>
            <select id="dbmon-chart-target">
                <option value="">Выберите базу</option>
            </select>
        </div>
        <div class="chart-body" style="height:220px">
            <canvas id="dbmon-chart"></canvas>
        </div>
    </article>

    <div class="modal hidden" id="dbmon-modal">
        <div class="modal-dialog" style="max-width: 520px;">
            <div class="modal-header">
                <h2 id="dbmon-modal-title">Добавить базу</h2>
                <button class="modal-close" type="button" id="dbmon-modal-close"><i data-lucide="x"></i></button>
            </div>
            <form id="dbmon-form">
                <input type="hidden" name="id" id="dbmon-id">
                <div class="form-field">
                    <label class="form-label">Имя</label>
                    <input type="text" name="name" id="dbmon-name" required placeholder="billing-db">
                </div>
                <div class="form-field">
                    <label class="form-label">СУБД</label>
                    <select name="engine" id="dbmon-engine-field">
                        <option value="mysql">MySQL</option>
                        <option value="mariadb">MariaDB</option>
                        <option value="postgres">PostgreSQL</option>
                    </select>
                </div>
                <div class="ha-grid">
                    <div class="form-field">
                        <label class="form-label">Хост</label>
                        <input type="text" name="host" id="dbmon-host" required placeholder="db.internal">
                    </div>
                    <div class="form-field">
                        <label class="form-label">Порт</label>
                        <input type="number" name="port" id="dbmon-port" value="3306" min="1" max="65535" required>
                    </div>
                </div>
                <div class="form-field">
                    <label class="form-label">Имя базы</label>
                    <input type="text" name="db_name" id="dbmon-dbname" placeholder="можно оставить пустым">
                    <small class="form-hint-text">Для проверки размера конкретной схемы. Пусто — все пользовательские базы на инстансе.</small>
                </div>
                <div class="ha-grid">
                    <div class="form-field">
                        <label class="form-label">Пользователь</label>
                        <input type="text" name="username" id="dbmon-user" required autocomplete="off">
                    </div>
                    <div class="form-field">
                        <label class="form-label">Пароль</label>
                        <input type="password" name="password" id="dbmon-password" placeholder="для правки — оставьте пустым" autocomplete="new-password">
                    </div>
                </div>
                <div class="form-field">
                    <label class="form-label">Заметка</label>
                    <input type="text" name="notes" id="dbmon-notes" placeholder="прод, реплика, биллинг…">
                </div>
                <p class="setup-hint">Панель подключается сама с этой машины. Пользователю достаточно SELECT / SHOW STATUS. Таблицы HostMonitor на чужой базе не создаются.</p>
                <div class="modal-actions">
                    <button type="button" class="btn-outline" id="dbmon-cancel">Отмена</button>
                    <button type="submit" class="primary"><i data-lucide="check"></i> Сохранить и проверить</button>
                </div>
            </form>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/db_ha.js', '/frontend/js/databases.js']);
