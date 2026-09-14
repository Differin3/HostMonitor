<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Профиль', 'profile');
?>
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
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
                <h3 style="margin-bottom: 14px; font-size: 16px; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="shield-check" style="width: 18px; height: 18px;"></i>
                    Двухфакторная аутентификация
                </h3>
                <div id="totp-section">
                    <div id="totp-status" style="color: var(--text-muted); margin-bottom: 10px;">Загрузка…</div>
                    <div id="totp-actions" style="display: flex; gap: 8px; flex-wrap: wrap;"></div>
                    <div id="totp-setup" class="hidden" style="margin-top: 14px;">
                        <p style="color: var(--text-muted); margin-bottom: 12px;">Отсканируйте QR-код в приложении аутентификации (Google Authenticator, Aegis и т.п.) или введите ключ вручную.</p>
                        <div style="display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap;">
                            <canvas id="totp-qr" width="180" height="180" style="background:#fff; border-radius:10px; padding:6px;"></canvas>
                            <div style="min-width: 200px;">
                                <div class="form-label">Ключ вручную</div>
                                <code id="totp-secret" style="font-family: ui-monospace, monospace; font-size: 13px; word-break: break-all; display: inline-block; margin-top: 6px;"></code>
                            </div>
                        </div>
                        <div class="form-field" style="margin-top: 14px; max-width: 260px;">
                            <label class="form-label">Код подтверждения</label>
                            <div class="input-with-icon">
                                <i data-lucide="shield-check" class="input-icon"></i>
                                <input type="text" id="totp-confirm-code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="6 цифр">
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="primary" id="totp-confirm-btn">Включить 2FA</button>
                            <button type="button" class="btn-outline" id="totp-cancel-btn">Отмена</button>
                        </div>
                    </div>
                    <div id="totp-recovery" class="hidden" style="margin-top: 16px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                            <div style="color: var(--text-muted);">Коды восстановления: <b id="totp-recovery-count" style="color: var(--text-primary);">0</b></div>
                            <button type="button" class="btn-outline" id="totp-regen-btn"><i data-lucide="refresh-cw"></i> Перегенерировать коды</button>
                        </div>
                        <div id="totp-codes-view" class="hidden" style="margin-top: 12px; padding: 12px; border: 1px solid rgba(245,158,11,0.35); background: rgba(245,158,11,0.08); border-radius: 10px;">
                            <p style="color: #fbbf24; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                                <i data-lucide="alert-triangle" style="width: 14px; height: 14px;"></i>
                                Сохраните коды — они показываются один раз. Каждый код одноразовый.
                            </p>
                            <div id="totp-codes-list" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px 16px; font-family: ui-monospace, monospace; font-size: 14px;"></div>
                            <button type="button" class="btn-outline" id="totp-codes-done" style="margin-top: 10px;">Я сохранил коды</button>
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

