<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Настройки', 'settings');
?>
    <div class="settings-container">
        <p class="settings-lead">Параметры панели пишутся в базу. Резерв MySQL — на вкладке «База данных», отдельно от остальных полей.</p>
        <div class="settings-tabs">
            <button class="tab-btn active" type="button" data-tab="general">Общие</button>
            <button class="tab-btn" type="button" data-tab="web">Веб-сервер</button>
            <button class="tab-btn" type="button" data-tab="upnp">UPnP</button>
            <button class="tab-btn" type="button" data-tab="logs">Логи</button>
            <button class="tab-btn" type="button" data-tab="notifications">Уведомления</button>
            <button class="tab-btn" type="button" data-tab="security">Безопасность</button>
            <button class="tab-btn" type="button" data-tab="api">API</button>
            <button class="tab-btn" type="button" data-tab="database">База данных</button>
        </div>

        <div class="settings-content">
            <div class="tab-content active" id="general-tab">
                <div class="settings-section">
                    <h3>Общие настройки</h3>
                    <div class="form-field">
                        <label for="system_name">Название системы</label>
                        <input type="text" id="system_name" maxlength="80">
                        <small>Показывается в шапке и боковом меню</small>
                    </div>
                    <div class="form-field">
                        <label for="timezone">Часовой пояс</label>
                        <select id="timezone">
                            <option value="UTC">UTC</option>
                            <option value="Europe/Moscow">Europe/Moscow (MSK)</option>
                            <option value="Europe/Kiev">Europe/Kyiv (EET)</option>
                            <option value="Europe/Minsk">Europe/Minsk</option>
                            <option value="Asia/Yekaterinburg">Asia/Yekaterinburg</option>
                            <option value="Asia/Novosibirsk">Asia/Novosibirsk</option>
                            <option value="Asia/Vladivostok">Asia/Vladivostok</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="language">Язык интерфейса</label>
                        <select id="language">
                            <option value="ru">Русский</option>
                            <option value="en">English</option>
                        </select>
                        <small>Сейчас панель на русском. Значение сохраняется для следующих локалей.</small>
                    </div>
                    <div class="form-field">
                        <label for="collect-interval">Интервал отправки данных агентами (секунды)</label>
                        <input type="number" id="collect-interval" value="60" min="10" max="300">
                        <small>Попадает в node.conf как COLLECT_INTERVAL (от 10 до 300 секунд). Меньший интервал — больше нагрузка на БД.</small>
                        <div id="collect-interval-warning" class="settings-warn hidden"></div>
                    </div>
                </div>
            </div>

            <div class="tab-content" id="web-tab">
                <div class="settings-section">
                    <h3>Веб-сервер панели</h3>
                    <p class="form-hint-text">Эти параметры также записываются в <code>monitoring/data/web.local.php</code> — их подхватывает Python-сервер при следующем перезапуске (<code>systemctl restart monitoring-web</code>). Если панель работает за nginx — изменения применяются в конфиге nginx.</p>
                    <div class="form-field">
                        <label for="web_host">Адрес прослушивания (bind)</label>
                        <select id="web_host">
                            <option value="0.0.0.0">0.0.0.0 — все интерфейсы (локальный + публичный IP)</option>
                            <option value="127.0.0.1">127.0.0.1 — только локально (127.0.0.1 / localhost)</option>
                        </select>
                        <small>0.0.0.0 — доступно из всей сети; 127.0.0.1 — только с этого сервера (например, если стоит за nginx или туннелем)</small>
                    </div>
                    <div class="form-field">
                        <label for="web_port">Порт веб-интерфейса</label>
                        <input type="number" id="web_port" min="1" max="65535">
                        <small>Для production (nginx) обычно 80 или 443; для standalone Python-сервера — 8080, 8000 и т.п. Требуется перезапуск сервиса.</small>
                    </div>
                    <div class="form-field">
                        <label for="public_url">Публичный URL панели (необязательно)</label>
                        <input type="text" id="public_url" placeholder="https://monitoring.example.com">
                        <small>Если панель доступна по домену или внешнему IP — укажите URL. Используется для ссылок в письмах/telegram-уведомлениях.</small>
                    </div>
                    <div class="ha-actions" style="margin-top:8px">
                        <small class="settings-warn">💡 Изменения хоста/порта применятся только после перезапуска веб-сервиса панели.</small>
                    </div>
                </div>
            </div>

            <div class="tab-content" id="upnp-tab">
                <div class="settings-section">
                    <h3>UPnP / SSDP</h3>
                    <p class="form-hint-text">Эти значения записываются в конфиг агента при скачивании node.conf. Уже установленные агенты нужно обновить вручную или перевыпустить конфиг.</p>
                    <div class="form-field">
                        <label>
                            <input type="checkbox" id="upnp_enabled" checked>
                            UPnP-скан на агенте (UPNP_ENABLED)
                        </label>
                    </div>
                    <div class="form-field">
                        <label for="upnp_interval_cycles">UPNP_INTERVAL_CYCLES</label>
                        <input type="number" id="upnp_interval_cycles" value="2" min="1" max="60">
                        <small>Скан каждые N циклов сбора метрик</small>
                    </div>
                    <div class="ha-grid">
                        <div class="form-field">
                            <label for="upnp_mx">UPNP_MX</label>
                            <input type="number" id="upnp_mx" value="3" min="1" max="15">
                        </div>
                        <div class="form-field">
                            <label for="upnp_timeout">UPNP_TIMEOUT (сек)</label>
                            <input type="number" id="upnp_timeout" value="8" min="3" max="60">
                        </div>
                    </div>
                    <div class="form-field">
                        <label for="upnp_gena_port">UPNP_GENA_PORT</label>
                        <input type="number" id="upnp_gena_port" value="0" min="0" max="65535">
                        <small>HTTP-порт CALLBACK для GENA. 0 — свободный порт. Агент также слушает SSDP NOTIFY.</small>
                    </div>
                    <pre class="ha-log" id="upnp-env-preview"></pre>
                    <div class="ha-actions">
                        <button type="button" class="btn-outline" id="upnp-env-copy">Копировать переменные</button>
                    </div>
                </div>
            </div>

            <div class="tab-content" id="logs-tab">
                <div class="settings-section">
                    <h3>Настройки логов</h3>
                    <div class="form-field">
                        <label for="log-retention-days">Глубина логов (дней)</label>
                        <input type="number" id="log-retention-days" value="30" min="1" max="365">
                        <small>Система, процессы, контейнеры, SSH и вход в панель. 30 дней хватает для разбора инцидентов.</small>
                    </div>
                    <div class="form-field">
                        <label for="metrics-retention-days">Глубина метрик (дней)</label>
                        <input type="number" id="metrics-retention-days" value="14" min="2" max="90">
                        <small>CPU / RAM / диск / сеть. Графики истории берут до 7 дней — 14 дней с запасом, без раздувания таблицы.</small>
                    </div>
                    <div class="form-field">
                        <label for="alerts-retention-days">Закрытые алерты (дней)</label>
                        <input type="number" id="alerts-retention-days" value="30" min="7" max="365">
                        <small>Незакрытые алерты не удаляются.</small>
                    </div>
                    <div class="form-field">
                        <label for="logs-per-page">Записей на страницу</label>
                        <input type="number" id="logs-per-page" value="100" min="10" max="2000">
                        <small>Сколько строк логов на одной странице (10–2000)</small>
                    </div>
                    <div class="form-field">
                        <label for="log-max-rows">Максимум записей в таблице логов</label>
                        <input type="number" id="log-max-rows" value="1000000" min="10000" max="10000000">
                        <small>Потолок строк в каждой таблице логов. Срабатывает в ночной очистке, не на каждом POST агента.</small>
                    </div>
                    <div class="form-field">
                        <label for="log-max-rows-per-node">Максимум записей на одну ноду</label>
                        <input type="number" id="log-max-rows-per-node" value="100000" min="0" max="1000000">
                        <small>Защита от переполнения одной нодой. 0 — без лимита на ноду</small>
                    </div>
                    <div class="ha-actions">
                        <button type="button" class="btn-outline" id="cleanup-history-now">Очистить историю сейчас</button>
                    </div>
                    <pre class="ha-log hidden" id="cleanup-history-log"></pre>
                </div>
            </div>

            <div class="tab-content" id="notifications-tab">
                <div class="settings-section">
                    <h3>Уведомления</h3>
                    <p class="form-hint-text">Адреса и токены сохраняются в панели. Рассылка подключается к алертам, когда они срабатывают.</p>
                    <div class="form-field">
                        <label>
                            <input type="checkbox" id="notify_email_enabled" checked>
                            Email уведомления
                        </label>
                    </div>
                    <div class="form-field">
                        <label for="notify_email">Email для уведомлений</label>
                        <input type="email" id="notify_email" placeholder="admin@example.com" autocomplete="off">
                    </div>
                    <div class="ha-grid">
                        <div class="form-field">
                            <label for="smtp_host">SMTP-хост</label>
                            <input type="text" id="smtp_host" placeholder="smtp.example.com" autocomplete="off">
                        </div>
                        <div class="form-field">
                            <label for="smtp_port">SMTP-порт</label>
                            <input type="number" id="smtp_port" value="587" min="1" max="65535">
                        </div>
                        <div class="form-field">
                            <label for="smtp_user">SMTP-пользователь</label>
                            <input type="text" id="smtp_user" autocomplete="off">
                        </div>
                        <div class="form-field">
                            <label for="smtp_password">SMTP-пароль</label>
                            <input type="password" id="smtp_password" placeholder="Оставьте пустым, чтобы не менять" autocomplete="new-password">
                        </div>
                        <div class="form-field ha-span-2">
                            <label for="smtp_from">От кого (From)</label>
                            <input type="email" id="smtp_from" placeholder="hostmonitor@example.com">
                        </div>
                    </div>
                    <div class="form-field">
                        <label>
                            <input type="checkbox" id="notify_telegram_enabled">
                            Telegram уведомления
                        </label>
                    </div>
                    <div class="form-field">
                        <label for="telegram_bot_token">Токен бота</label>
                        <input type="password" id="telegram_bot_token" placeholder="Оставьте пустым, чтобы не менять" autocomplete="new-password">
                    </div>
                    <div class="form-field">
                        <label for="telegram_chat_id">Chat ID</label>
                        <input type="text" id="telegram_chat_id" placeholder="-100…" autocomplete="off">
                    </div>
                </div>
            </div>

            <div class="tab-content" id="security-tab">
                <div class="settings-section">
                    <h3>Безопасность</h3>
                    <div class="form-field">
                        <label for="min_password_length">Минимальная длина пароля</label>
                        <input type="number" id="min_password_length" value="8" min="6" max="32">
                        <small>Для новых паролей администратора (6–32)</small>
                    </div>
                    <div class="form-field">
                        <label for="session_timeout_minutes">Время жизни сессии (минут)</label>
                        <input type="number" id="session_timeout_minutes" value="60" min="5" max="1440">
                        <small>Простой дольше этого — повторный вход. От 5 минут до суток.</small>
                    </div>
                </div>
            </div>

            <div class="tab-content" id="api-tab">
                <div class="settings-section">
                    <h3>API панели</h3>
                    <p class="form-hint-text">Агенты ходят в API с заголовком <code>Authorization: Bearer &lt;node_token&gt;</code>. Токен выдаётся на странице ноды, общего ключа панели нет.</p>
                    <div class="form-field">
                        <label for="api_base_url">Базовый URL API</label>
                        <div class="input-with-action">
                            <input type="text" id="api_base_url" readonly>
                            <button class="btn-copy" type="button" id="api-base-copy" title="Копировать">
                                <i data-lucide="copy"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-field">
                        <label for="api_rate_limit">Ориентир лимита запросов в минуту</label>
                        <input type="number" id="api_rate_limit" value="100" min="10" max="10000">
                        <small>Сохраняется в настройках. Жёсткий лимит на стороне PHP пока не режется — значение для прокси и планирования.</small>
                    </div>
                </div>
            </div>

            <div class="tab-content" id="database-tab">
                <div class="settings-section">
                    <p class="form-hint-text">Те же параметры, что на странице <a href="databases.php#db-ha-panel">Базы данных</a>. Мониторинг сторонних СУБД — там же, ниже блока подключений.</p>
                    <?php
                    require_once __DIR__ . '/includes/db_ha_form.php';
                    render_db_ha_panel(['context' => 'settings', 'show_intro' => false]);
                    ?>
                </div>
            </div>
        </div>

        <div class="settings-actions" id="settings-footer">
            <button type="button" class="btn-outline" id="settings-cancel">Отмена</button>
            <button type="button" class="primary" id="settings-save">Сохранить</button>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/db_ha.js', '/frontend/js/settings.js']);