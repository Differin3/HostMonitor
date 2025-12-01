<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Настройки', 'settings');
?>
    <div class="settings-container">
        <div class="settings-tabs">
            <button class="tab-btn active" data-tab="general">Общие</button>
            <button class="tab-btn" data-tab="notifications">Уведомления</button>
            <button class="tab-btn" data-tab="security">Безопасность</button>
            <button class="tab-btn" data-tab="api">API</button>
        </div>
        
        <div class="settings-content">
            <div class="tab-content active" id="general-tab">
                <div class="settings-section">
                    <h3>Общие настройки</h3>
                    <div class="form-field">
                        <label>Название системы</label>
                        <input type="text" value="Система мониторинга" />
                    </div>
                    <div class="form-field">
                        <label>Часовой пояс</label>
                        <select>
                            <option value="UTC">UTC</option>
                            <option value="Europe/Moscow" selected>Europe/Moscow (MSK)</option>
                            <option value="Europe/Kiev">Europe/Kiev</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>Язык интерфейса</label>
                        <select>
                            <option value="ru" selected>Русский</option>
                            <option value="en">English</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="tab-content" id="notifications-tab">
                <div class="settings-section">
                    <h3>Уведомления</h3>
                    <div class="form-field">
                        <label>
                            <input type="checkbox" checked />
                            Email уведомления
                        </label>
                    </div>
                    <div class="form-field">
                        <label>
                            <input type="checkbox" />
                            Telegram уведомления
                        </label>
                    </div>
                    <div class="form-field">
                        <label>Email для уведомлений</label>
                        <input type="email" placeholder="admin@example.com" />
                    </div>
                </div>
            </div>
            
            <div class="tab-content" id="security-tab">
                <div class="settings-section">
                    <h3>Безопасность</h3>
                    <div class="form-field">
                        <label>Минимальная длина пароля</label>
                        <input type="number" value="8" min="6" max="32" />
                    </div>
                    <div class="form-field">
                        <label>
                            <input type="checkbox" checked />
                            Требовать двухфакторную аутентификацию
                        </label>
                    </div>
                    <div class="form-field">
                        <label>Время жизни сессии (минут)</label>
                        <input type="number" value="60" min="5" max="1440" />
                    </div>
                </div>
            </div>
            
            <div class="tab-content" id="api-tab">
                <div class="settings-section">
                    <h3>API настройки</h3>
                    <div class="form-field">
                        <label>API ключ</label>
                        <div class="input-with-action">
                            <input type="text" value="sk_live_xxxxxxxxxxxxx" readonly />
                            <button class="btn-copy" onclick="copyApiKey()">
                                <i data-lucide="copy"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-field">
                        <label>Лимит запросов в минуту</label>
                        <input type="number" value="100" min="10" max="1000" />
                    </div>
                </div>
            </div>
        </div>
        
        <div class="settings-actions">
            <button class="btn-outline">Отмена</button>
            <button class="primary">Сохранить</button>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/settings.js']);

