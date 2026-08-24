<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start(
    'Базы данных',
    'databases',
    '<button class="btn-outline" type="button" id="dbmon-refresh"><i data-lucide="refresh-cw"></i> Опросить</button>'
    . '<button class="primary" type="button" id="dbmon-add"><i data-lucide="plus"></i> Добавить базу</button>'
);
?>
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

    <section class="card dbmon-ha-card" id="dbmon-ha-card" aria-label="Резерв и синхронизация панели">
        <div class="dbmon-ha-head">
            <div class="dbmon-ha-title">
                <i data-lucide="database-backup"></i>
                <div>
                    <h3>Резерв панели</h3>
                    <p>Статус основной/резервной MySQL и полное копирование таблиц. Это не live-репликация.</p>
                </div>
            </div>
            <div class="dbmon-ha-head-actions">
                <button type="button" class="btn-outline" id="dbmon-ha-ping" title="Проверить связь">
                    <i data-lucide="activity"></i> Проверить
                </button>
                <a class="btn-outline" href="settings.php#database" title="Настройки подключений">
                    <i data-lucide="settings"></i> Настройки
                </a>
            </div>
        </div>

        <div class="dbmon-ha-pills" id="dbmon-ha-pills">
            <span class="ha-pill" id="dbmon-pill-active">Активная: …</span>
            <span class="ha-pill" id="dbmon-pill-primary">Основная: …</span>
            <span class="ha-pill" id="dbmon-pill-replica">Резерв: …</span>
        </div>

        <div class="dbmon-ha-info" id="dbmon-ha-info">
            <div class="dbmon-ha-endpoint">
                <small>Основная</small>
                <strong id="dbmon-ha-primary-label">—</strong>
            </div>
            <div class="dbmon-ha-arrow" aria-hidden="true"><i data-lucide="arrow-left-right"></i></div>
            <div class="dbmon-ha-endpoint">
                <small>Резерв</small>
                <strong id="dbmon-ha-replica-label">—</strong>
            </div>
            <p class="dbmon-ha-note" id="dbmon-ha-note">Загрузка статуса…</p>
        </div>

        <div class="dbmon-sync-row" id="dbmon-sync-actions">
            <button type="button" class="dbmon-sync-card" id="dbmon-sync-to-replica" data-direction="to_replica" disabled>
                <i data-lucide="upload-cloud"></i>
                <span>
                    <strong>Основная → резерв</strong>
                    <small>снимок на standby</small>
                </span>
            </button>
            <button type="button" class="dbmon-sync-card" id="dbmon-sync-to-primary" data-direction="to_primary" disabled>
                <i data-lucide="download-cloud"></i>
                <span>
                    <strong>Резерв → основная</strong>
                    <small>восстановить из снимка</small>
                </span>
            </button>
            <button type="button" class="btn-outline" id="dbmon-ha-failback" title="Переключить панель на основную" disabled>
                <i data-lucide="rotate-ccw"></i> На основную
            </button>
        </div>

        <div class="db-sync-progress hidden" id="dbmon-sync-progress" aria-live="polite">
            <div class="db-sync-progress-head">
                <span id="dbmon-sync-progress-label">Подготовка…</span>
                <span id="dbmon-sync-progress-pct">0%</span>
            </div>
            <div class="hm-meter db-sync-meter" data-tone="ok"><span id="dbmon-sync-progress-bar" style="width:0%"></span></div>
            <p class="db-sync-progress-detail" id="dbmon-sync-progress-detail"></p>
            <div class="ha-actions">
                <button type="button" class="btn-outline" id="dbmon-sync-cancel"><i data-lucide="x"></i> Отменить</button>
            </div>
        </div>
        <div class="db-sync-result hidden" id="dbmon-sync-result"></div>
    </section>

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
        <div class="modal-dialog" style="max-width: 560px;">
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
                <div class="dbmon-ssl-section hidden" id="dbmon-ssl-section">
                    <div class="form-field db-ha-ssl-checks">
                        <label>
                            <input type="checkbox" id="dbmon-ssl" value="1">
                            Использовать SSL
                        </label>
                        <label>
                            <input type="checkbox" id="dbmon-ssl-verify" value="1">
                            Проверять сертификат
                        </label>
                    </div>
                    <div class="form-field hidden" id="dbmon-ssl-ca-block">
                        <label class="form-label" for="dbmon-ssl-ca-pem">CA-сертификат (PEM)</label>
                        <p class="form-hint-text">Для SkySQL / MariaDB Sky. Загрузите .pem/.crt или вставьте текст сертификата.</p>
                        <div class="ha-actions">
                            <input type="file" id="dbmon-ssl-ca-file" accept=".pem,.crt,.cer,.txt,application/x-pem-file,text/plain">
                            <button type="button" class="btn-outline" id="dbmon-ssl-ca-upload">Сохранить CA</button>
                            <button type="button" class="btn-outline" id="dbmon-ssl-ca-remove">Удалить CA</button>
                        </div>
                        <textarea id="dbmon-ssl-ca-pem" rows="4" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----" spellcheck="false"></textarea>
                        <p class="form-hint-text" id="dbmon-ssl-ca-status">CA не загружен</p>
                    </div>
                    <p class="form-hint-text hidden" id="dbmon-ssl-settings-hint">Параметры подключения резерва панели также редактируются в <a href="settings.php#database">Настройки → База данных</a>.</p>
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
render_layout_end(['/frontend/js/databases.js']);
