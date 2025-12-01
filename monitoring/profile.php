<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Профиль', 'profile');
?>
    <div class="settings-container">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i data-lucide="user"></i>
                    <span>Профиль администратора</span>
                </div>
            </div>
            <div class="card-body">
                <div class="grid-2">
                    <div class="form-field">
                        <label class="form-label">Имя пользователя</label>
                        <div class="input-with-icon">
                            <i data-lucide="user" class="input-icon"></i>
                            <input type="text" value="<?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?>" readonly>
                        </div>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Роль</label>
                        <div class="input-with-icon">
                            <i data-lucide="shield" class="input-icon"></i>
                            <input type="text" value="Администратор" readonly>
                        </div>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Email</label>
                        <div class="input-with-icon">
                            <i data-lucide="mail" class="input-icon"></i>
                            <input type="email" placeholder="admin@example.com">
                        </div>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Тема оформления</label>
                        <div class="input-with-icon">
                            <i data-lucide="palette" class="input-icon"></i>
                            <select id="theme-select">
                                <option value="dark">Тёмная</option>
                                <option value="light">Светлая</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Новый пароль</label>
                        <div class="input-with-icon">
                            <i data-lucide="lock" class="input-icon"></i>
                            <input type="password" id="new-password" placeholder="Новый пароль">
                        </div>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Подтверждение пароля</label>
                        <div class="input-with-icon">
                            <i data-lucide="lock" class="input-icon"></i>
                            <input type="password" id="confirm-password" placeholder="Повторите пароль">
                        </div>
                    </div>
                </div>
                
                <div class="form-section-divider"></div>
                <h3 style="margin-bottom: 16px; font-size: 16px; color: var(--text-primary);">
                    <i data-lucide="bell" style="width: 18px; height: 18px; margin-right: 8px;"></i>
                    Уведомления
                </h3>
                
                <div class="grid-2">
                    <label class="checkbox-label">
                        <input type="checkbox" id="notify-email" checked>
                        <span>Email уведомления</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" id="notify-nodes" checked>
                        <span>Уведомления о нодах</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" id="notify-billing">
                        <span>Уведомления о биллинге</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" id="notify-alerts" checked>
                        <span>Критические алерты</span>
                    </label>
                </div>
                
                <div class="form-section-divider"></div>
                
                <div class="grid-2">
                    <label class="checkbox-label">
                        <input type="checkbox" id="auto-refresh" checked>
                        <span>Автообновление данных</span>
                    </label>
                    <div class="form-field">
                        <label class="form-label">Интервал обновления (сек)</label>
                        <div class="input-with-icon">
                            <i data-lucide="clock" class="input-icon"></i>
                            <input type="number" id="refresh-interval" value="30" min="10" max="300" style="width: 100%;">
                        </div>
                    </div>
                </div>
                
                <div class="modal-actions" style="margin-top: 24px;">
                    <button type="button" class="primary" onclick="saveProfile()">Сохранить изменения</button>
                </div>
            </div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/profile.js']);

