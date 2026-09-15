<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start(
    'Безопасность',
    'security',
    '<button class="primary" type="button" id="auth-refresh"><i data-lucide="refresh-cw"></i> Обновить</button>'
);
?>
    <div class="page-controls">
        <div class="search-control">
            <input type="text" id="auth-search" placeholder="Поиск по логину, IP, сообщению...">
        </div>
        <div class="sort-control">
            <select id="auth-type">
                <option value="">Все события</option>
                <option value="login">Вход</option>
                <option value="failed">Неудачный вход</option>
                <option value="login_2fa">2FA (вход)</option>
                <option value="totp_enable">2FA включена</option>
                <option value="totp_disable">2FA отключена</option>
                <option value="totp_recovery_regen">Recovery-коды</option>
                <option value="session_revoke">Сессия завершена</option>
                <option value="session_revoke_others">Завершены другие сессии</option>
                <option value="session_revoke_all">Выйти везде</option>
                <option value="trusted_revoke_all">Отозваны устройства</option>
            </select>
        </div>
        <div class="sort-control">
            <select id="auth-result">
                <option value="">Все результаты</option>
                <option value="ok">Успешные</option>
                <option value="fail">Неудачные</option>
            </select>
        </div>
        <div class="sort-control">
            <select id="auth-days">
                <option value="1">За сутки</option>
                <option value="7" selected>За 7 дней</option>
                <option value="30">За 30 дней</option>
                <option value="0">Всё время</option>
            </select>
        </div>
    </div>

    <div class="stats-grid" id="auth-stats">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #60a5fa, #2563eb);"><i data-lucide="list"></i></div>
            <div class="stat-card-content"><h3>Всего событий</h3><div class="stat-value" id="auth-count">0</div><p class="stat-subtitle">за период</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);"><i data-lucide="check-circle"></i></div>
            <div class="stat-card-content"><h3>Успешных</h3><div class="stat-value" id="auth-ok">0</div><p class="stat-subtitle">входы и действия</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #f87171, #dc2626);"><i data-lucide="x-circle"></i></div>
            <div class="stat-card-content"><h3>Неудачных</h3><div class="stat-value" id="auth-fail">0</div><p class="stat-subtitle">отказы входа</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #a78bfa, #7c3aed);"><i data-lucide="shield-check"></i></div>
            <div class="stat-card-content"><h3>2FA событий</h3><div class="stat-value" id="auth-2fa">0</div><p class="stat-subtitle">вход/вкл/выкл</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #38bdf8, #0284c7);"><i data-lucide="globe"></i></div>
            <div class="stat-card-content"><h3>Уникальных IP</h3><div class="stat-value" id="auth-ips">0</div><p class="stat-subtitle">источники</p></div>
        </div>
    </div>

    <div id="auth-suspicious" class="card hidden" style="margin-top: 14px; border-color: rgba(239,68,68,0.35);">
        <div class="card-header">
            <div class="card-title"><i data-lucide="alert-triangle" style="color:#f87171;"></i><span>Подозрительная активность</span></div>
        </div>
        <div class="card-body" id="auth-suspicious-list"></div>
    </div>

    <div class="table-container" style="margin-top: 14px;">
        <table>
            <thead>
                <tr><th>Время</th><th>Пользователь</th><th>IP</th><th>Событие</th><th>Результат</th><th>Сообщение</th></tr>
            </thead>
            <tbody id="auth-tbody"><tr><td colspan="6" class="text-muted">Загрузка…</td></tr></tbody>
        </table>
    </div>
    <div id="auth-pager" style="display: flex; gap: 8px; justify-content: center; align-items: center; margin-top: 14px;"></div>
<?php
render_layout_end(['/frontend/js/auth_logs.js']);
