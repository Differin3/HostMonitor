<?php
declare(strict_types=1);

/**
 * Единая разметка настроек подключения панели к MySQL (основная + резерв).
 *
 * @param array{
 *   context?: string,
 *   show_sync?: bool,
 *   show_intro?: bool,
 * } $options
 */
function render_db_ha_panel(array $options = []): void
{
    $context = (string)($options['context'] ?? 'embedded');
    $showSync = !array_key_exists('show_sync', $options) || !empty($options['show_sync']);
    $showIntro = !array_key_exists('show_intro', $options) || !empty($options['show_intro']);
    $isCard = $context === 'databases';
    ?>
    <section class="db-ha-panel<?= $isCard ? ' card db-ha-panel-card' : '' ?>" id="db-ha-panel" data-db-ha-context="<?= htmlspecialchars($context) ?>">
        <?php if ($isCard): ?>
        <div class="card-header db-ha-panel-head">
            <div>
                <h3><i data-lucide="database"></i> Подключения панели</h3>
                <p class="form-hint-text">Основная и резервная MySQL/MariaDB для работы HostMonitor</p>
            </div>
        </div>
        <div class="card-body">
        <?php else: ?>
        <div class="db-ha-panel-inner">
        <?php endif; ?>

        <div class="db-ha-locked-banner hidden" id="db-ha-locked-banner" role="alert">
            <i data-lucide="lock"></i>
            <span>Редактирование недоступно: нет соединения ни с основной, ни с резервной базой. Исправьте сервер MySQL или подключитесь по SSH к <code>data/db.local.php</code>.</span>
        </div>

        <?php if ($showIntro && !$isCard): ?>
        <p class="form-hint-text">Резерв необязателен. При падении основной панель переключится на запасную. Мониторинг сторонних СУБД — ниже на странице «Базы данных».</p>
        <?php endif; ?>

        <div class="ha-status-row" id="ha-status-row">
            <div class="ha-pill" id="ha-pill-active">Активная: …</div>
            <div class="ha-pill" id="ha-pill-primary">Основная: …</div>
            <div class="ha-pill" id="ha-pill-replica">Резерв: …</div>
        </div>

        <fieldset class="db-ha-fieldset" id="db-ha-fieldset">
            <legend class="db-ha-legend">Основная база</legend>
            <div class="ha-grid">
                <div class="form-field">
                    <label class="form-label" for="db-host">Хост</label>
                    <input type="text" id="db-host" autocomplete="off">
                </div>
                <div class="form-field">
                    <label class="form-label" for="db-port">Порт</label>
                    <input type="number" id="db-port" min="1" max="65535">
                </div>
                <div class="form-field">
                    <label class="form-label" for="db-name">Имя базы</label>
                    <input type="text" id="db-name" pattern="[A-Za-z0-9_]+">
                </div>
                <div class="form-field">
                    <label class="form-label" for="db-user">Пользователь</label>
                    <input type="text" id="db-user" autocomplete="off">
                </div>
                <div class="form-field ha-span-2">
                    <label class="form-label" for="db-password">Пароль</label>
                    <input type="password" id="db-password" placeholder="Оставьте пустым, чтобы не менять" autocomplete="new-password">
                </div>
            </div>

            <div class="form-field db-ha-check-row">
                <label class="db-ha-check">
                    <input type="checkbox" id="db-replica-enabled">
                    <span>Включить резервную базу</span>
                </label>
            </div>
            <div class="form-field db-ha-check-row">
                <label class="db-ha-check">
                    <input type="checkbox" id="db-replica-failback" checked>
                    <span>Автоматически вернуться на основную, когда она снова доступна</span>
                </label>
            </div>

            <div class="ha-grid" id="db-replica-fields">
                <div class="form-field ha-span-2">
                    <span class="db-ha-legend db-ha-legend-inline">Резервная база</span>
                </div>
                <div class="form-field">
                    <label class="form-label" for="db-replica-host">Хост</label>
                    <input type="text" id="db-replica-host" placeholder="db-standby" autocomplete="off">
                </div>
                <div class="form-field">
                    <label class="form-label" for="db-replica-port">Порт</label>
                    <input type="number" id="db-replica-port" value="3306" min="1" max="65535">
                </div>
                <div class="form-field">
                    <label class="form-label" for="db-replica-name">Имя базы</label>
                    <input type="text" id="db-replica-name" placeholder="как у основной">
                </div>
                <div class="form-field">
                    <label class="form-label" for="db-replica-user">Пользователь</label>
                    <input type="text" id="db-replica-user" placeholder="как у основной">
                </div>
                <div class="form-field ha-span-2">
                    <label class="form-label" for="db-replica-password">Пароль</label>
                    <input type="password" id="db-replica-password" placeholder="Пусто — не менять / как у основной" autocomplete="new-password">
                </div>
                <div class="form-field ha-span-2 db-ha-ssl-checks">
                    <label class="db-ha-check">
                        <input type="checkbox" id="db-replica-ssl" value="1">
                        <span>Использовать SSL</span>
                    </label>
                    <label class="db-ha-check">
                        <input type="checkbox" id="db-replica-ssl-verify" value="1">
                        <span>Проверять сертификат</span>
                    </label>
                </div>
                <div class="form-field ha-span-2" id="db-replica-ssl-ca-block">
                    <label class="form-label" for="db-replica-ssl-ca-pem">CA-сертификат (PEM)</label>
                    <p class="form-hint-text">Для SkySQL / MariaDB Sky. Загрузите .pem/.crt или вставьте текст сертификата.</p>
                    <div class="ha-actions db-ha-ca-actions">
                        <input type="file" id="db-replica-ssl-ca-file" accept=".pem,.crt,.cer,.txt,application/x-pem-file,text/plain">
                        <button type="button" class="btn-outline" id="db-replica-ssl-ca-upload">Сохранить CA</button>
                        <button type="button" class="btn-outline" id="db-replica-ssl-ca-remove">Удалить CA</button>
                    </div>
                    <textarea id="db-replica-ssl-ca-pem" rows="5" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----" spellcheck="false"></textarea>
                    <p class="form-hint-text" id="db-replica-ssl-ca-status">CA не загружен</p>
                </div>
            </div>

            <div class="ha-actions">
                <button type="button" class="primary" id="db-ha-save">Сохранить подключения</button>
                <button type="button" class="btn-outline" id="db-ha-ping">Проверить</button>
                <button type="button" class="btn-outline" id="db-ha-failback">Вернуться на основную</button>
            </div>

            <?php if ($showSync): ?>
            <h4 class="ha-subhead">Синхронизация</h4>
            <p class="form-hint-text">Разовое копирование таблиц (схема + данные). На большой базе может занять несколько минут.</p>
            <div class="ha-actions">
                <button type="button" class="btn-outline" id="db-ha-to-replica">Основная → резерв</button>
                <button type="button" class="btn-outline" id="db-ha-to-primary">Резерв → основная</button>
            </div>
            <?php endif; ?>

            <pre class="ha-log hidden" id="db-ha-log"></pre>
        </fieldset>

        <?php if ($isCard): ?>
        </div>
        <?php else: ?>
        </div>
        <?php endif; ?>
    </section>
    <?php
}
