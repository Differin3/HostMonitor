<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start('Fail2ban', 'fail2ban', '<button class="secondary" onclick="reloadFail2ban()"><i data-lucide="refresh-cw"></i> Обновить</button>');
?>

<style>
.f2b-nodes { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; margin-top: 16px; }
.f2b-node-card { background: var(--bg-secondary, #1a1a1a); border: 1px solid var(--border-color, #2a2a2a); border-radius: 12px; padding: 14px 16px; }
.f2b-node-card[data-status="active"] { border-color: rgba(52, 211, 153, .45); }
.f2b-node-card[data-status="error"] { border-color: rgba(248, 113, 113, .45); }
.f2b-node-card[data-status="off"] { opacity: .7; }
.f2b-node-card[data-status="unknown"] { opacity: .55; border-style: dashed; }
.f2b-node-head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.f2b-dot { width: 9px; height: 9px; border-radius: 50%; background: #6b7280; flex-shrink: 0; }
.f2b-node-card[data-status="active"] .f2b-dot { background: #34d399; box-shadow: 0 0 8px rgba(52, 211, 153, .7); }
.f2b-node-card[data-status="error"] .f2b-dot { background: #f87171; }
.f2b-node-card[data-status="off"] .f2b-dot { background: #fbbf24; }
.f2b-node-name { font-weight: 600; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.f2b-badge { margin-left: auto; font-size: 11px; color: var(--text-secondary, #999); border: 1px solid var(--border-color, #2a2a2a); border-radius: 6px; padding: 1px 6px; white-space: nowrap; }
.f2b-node-meta { font-size: 13px; color: var(--text-secondary, #999); display: flex; gap: 12px; }
.f2b-node-sub { margin-top: 6px; font-size: 11px; color: var(--text-muted, #666); }
.f2b-node-error { margin-top: 6px; font-size: 11px; color: #f87171; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.f2b-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
.f2b-badge-active { color: #34d399; }
.f2b-badge-expired { color: var(--text-secondary, #999); }
.f2b-chart-wrap { position: relative; height: 360px; margin-top: 16px; }

.f2b-jails-table { font-size: 13px; }
.f2b-jails-table th { white-space: nowrap; }
.f2b-jails-table td { padding-top: 10px; padding-bottom: 10px; vertical-align: middle; }
.f2b-node-cell { font-weight: 600; white-space: nowrap; border-right: 1px solid var(--border-color, #2a2a2a); }
.f2b-jail-name { white-space: nowrap; }
.f2b-count { white-space: nowrap; font-variant-numeric: tabular-nums; }
.f2b-count-now { font-weight: 600; }
.f2b-count-now.is-hot { color: #f87171; }
.f2b-total { color: var(--text-secondary, #999); font-size: 12px; margin-left: 4px; }
.f2b-policy { white-space: nowrap; color: var(--text-secondary, #999); }
.f2b-log { max-width: 280px; }
.f2b-log-main { display: inline-block; max-width: 210px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
.f2b-pill { display: inline-block; font-size: 11px; line-height: 16px; padding: 0 6px; border-radius: 6px; background: rgba(148, 163, 184, .15); color: var(--text-secondary, #999); vertical-align: bottom; }
.f2b-actions { white-space: nowrap; text-align: right; }
.f2b-action-btn { font-size: 12px; padding: 4px 10px; }
.f2b-pending { font-size: 12px; color: #fbbf24; white-space: nowrap; }

.f2b-modal { position: fixed; inset: 0; z-index: 1000; background: rgba(0, 0, 0, .55); display: flex; align-items: center; justify-content: center; }
.f2b-modal[hidden] { display: none; }
.f2b-modal-box { background: var(--bg-card, #141414); border: 1px solid var(--border-color, #2a2a2a); border-radius: 12px; padding: 18px 20px; width: min(420px, calc(100vw - 32px)); }
.f2b-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; font-weight: 600; }
.f2b-edit-grid { display: grid; gap: 10px; }
.f2b-edit-grid label { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13px; color: var(--text-secondary, #999); }
.f2b-edit-grid input[type="number"] { width: 120px; }
.f2b-edit-check { justify-content: flex-start !important; }
.f2b-modal-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.f2b-edit-grid input[type="text"] { width: 220px; }
.f2b-drift-banner { display: flex; align-items: center; gap: 10px; margin-top: 16px; padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(251, 191, 36, .45); background: rgba(251, 191, 36, .08); color: #fbbf24; font-size: 13px; }
.f2b-drift-banner[hidden] { display: none; }
.f2b-drift-banner button { margin-left: auto; }
.f2b-managed-card { margin-top: 16px; }
.f2b-managed-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-size: 12px; color: var(--text-secondary, #999); }
.f2b-managed-meta + .f2b-managed-meta { margin-top: 6px; }
.f2b-managed-status { font-size: 11px; border-radius: 6px; padding: 1px 7px; border: 1px solid transparent; }
.f2b-managed-status.is-ok { color: #34d399; border-color: rgba(52, 211, 153, .45); }
.f2b-managed-status.is-drift { color: #fbbf24; border-color: rgba(251, 191, 36, .45); }
.f2b-managed-status.is-missing { color: var(--text-secondary, #999); border-color: var(--border-color, #2a2a2a); }
.f2b-managed-actions { display: flex; gap: 8px; }
.f2b-managed-table { margin-top: 12px; }
.f2b-rule-state { font-size: 12px; white-space: nowrap; }
.f2b-rule-state.is-managed { color: #34d399; }
.f2b-rule-state.is-inherit { color: var(--text-secondary, #999); }
.f2b-rule-state.is-off { color: #fbbf24; }
.f2b-diff { max-height: 58vh; overflow: auto; background: #0d0d0d; border: 1px solid var(--border-color, #2a2a2a); border-radius: 8px; padding: 10px 12px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; line-height: 1.5; white-space: pre; }
.f2b-diff-line.is-add { color: #34d399; }
.f2b-diff-line.is-del { color: #f87171; }
.f2b-diff-line.is-same { color: #6b7280; }
.f2b-diff-empty { color: var(--text-secondary, #999); font-size: 13px; }
.f2b-managed-error { margin-top: 8px; font-size: 12px; color: #f87171; }
.f2b-history-changes { font-size: 12px; color: var(--text-secondary, #999); }
.f2b-history-action { font-size: 12px; white-space: nowrap; }
.f2b-history-action.is-save { color: #34d399; }
.f2b-history-action.is-delete { color: #f87171; }
</style>

<div class="tabs">
    <button type="button" class="tab-btn active" data-tab="overview" onclick="switchFail2banTab('overview')">
        <i data-lucide="shield"></i> Обзор
    </button>
    <button type="button" class="tab-btn" data-tab="bans" onclick="switchFail2banTab('bans')">
        <i data-lucide="ban"></i> Баны
    </button>
    <button type="button" class="tab-btn" data-tab="rules" onclick="switchFail2banTab('rules')">
        <i data-lucide="file-cog"></i> Правила
    </button>
    <button type="button" class="tab-btn" data-tab="chart" onclick="switchFail2banTab('chart')">
        <i data-lucide="activity"></i> График
    </button>
</div>

<div id="f2b-overview-tab" class="tab-content active">
    <div id="f2b-drift-banner" class="f2b-drift-banner" hidden>
        <i data-lucide="alert-triangle"></i>
        <span id="f2b-drift-text">Файл правил на ноде отличается от настроек панели</span>
        <button class="secondary f2b-action-btn" onclick="switchFail2banTab('rules')">Открыть правила</button>
    </div>
    <div class="stats-grid" style="margin-top: 16px;">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);"><i data-lucide="shield-check"></i></div>
            <div class="stat-card-content"><h3>Нод с fail2ban</h3><div class="stat-value" id="f2b-stat-nodes">0 / 0</div><p class="stat-subtitle">служба активна</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #f87171, #dc2626);"><i data-lucide="ban"></i></div>
            <div class="stat-card-content"><h3>Активных банов</h3><div class="stat-value" id="f2b-stat-bans">0</div><p class="stat-subtitle">сейчас</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);"><i data-lucide="clock"></i></div>
            <div class="stat-card-content"><h3>Банов за 24 часа</h3><div class="stat-value" id="f2b-stat-24h">0</div><p class="stat-subtitle">новые события</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #818cf8, #4f46e5);"><i data-lucide="layers"></i></div>
            <div class="stat-card-content"><h3>Джейлов</h3><div class="stat-value" id="f2b-stat-jails">0</div><p class="stat-subtitle">на всех нодах</p></div>
        </div>
    </div>

    <div id="f2b-nodes" class="f2b-nodes"></div>

    <div class="table-container" style="margin-top: 16px;">
        <table class="f2b-jails-table">
            <thead>
                <tr>
                    <th>Нода</th>
                    <th>Джейл</th>
                    <th title="Сейчас / всего за всё время">Провалы</th>
                    <th title="Сейчас / всего за всё время">Баны</th>
                    <th title="maxretry × findtime → bantime">Политика</th>
                    <th>Логи</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="f2b-jails-tbody">
                <tr><td colspan="6" class="text-center">Загрузка…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div id="f2b-bans-tab" class="tab-content">
    <div class="page-controls">
        <div class="sort-control">
            <select id="f2b-node-filter" class="compact-select" onchange="f2bBansPage = 1; loadFail2banBans();">
                <option value="">Все ноды</option>
            </select>
        </div>
        <div class="sort-control">
            <select id="f2b-jail-filter" onchange="f2bBansPage = 1; loadFail2banBans();">
                <option value="">Все джейлы</option>
            </select>
        </div>
        <div class="sort-control">
            <select id="f2b-status-filter" onchange="f2bBansPage = 1; loadFail2banBans();">
                <option value="active" selected>Активные</option>
                <option value="expired">Истёкшие</option>
                <option value="all">Все</option>
            </select>
        </div>
        <div class="search-control">
            <input type="text" id="f2b-bans-search" placeholder="Поиск по IP…" oninput="f2bOnBansSearch()">
        </div>
    </div>

    <div class="table-container" style="margin-top: 16px;">
        <table>
            <thead>
                <tr>
                    <th>Нода</th>
                    <th>Джейл</th>
                    <th>IP</th>
                    <th>Статус</th>
                    <th>Забанен</th>
                    <th>Истекает</th>
                    <th>Последний раз виден</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="f2b-bans-tbody">
                <tr><td colspan="7" class="text-center">Загрузка…</td></tr>
            </tbody>
        </table>
    </div>
    <div class="table-pagination">
        <button id="f2b-bans-prev" onclick="f2bBansPage = Math.max(1, f2bBansPage - 1); loadFail2banBans();">&laquo;</button>
        <span id="f2b-bans-page-info">Стр. 1</span>
        <button id="f2b-bans-next" onclick="f2bBansPage = f2bBansPage + 1; loadFail2banBans();">&raquo;</button>
    </div>
</div>

<div id="f2b-chart-tab" class="tab-content">
    <div class="page-controls">
        <div class="sort-control">
            <select id="f2b-chart-days" onchange="loadFail2banSeries()">
                <option value="1" selected>За сутки (по часам)</option>
                <option value="7">За 7 дней</option>
                <option value="30">За 30 дней</option>
            </select>
        </div>
    </div>
    <div class="card" style="margin-top: 16px;">
        <div class="card-header">
            <div class="card-title"><i data-lucide="activity"></i><span>Баны и разбаны</span></div>
        </div>
        <div class="card-body">
            <div class="f2b-chart-wrap"><canvas id="f2b-chart"></canvas></div>
        </div>
    </div>
</div>

<div id="f2b-rules-tab" class="tab-content">
    <div class="card" style="margin-top: 16px;">
        <div class="card-header">
            <div class="card-title"><i data-lucide="file-cog"></i><span>Управляемый файл</span></div>
            <div class="f2b-managed-actions">
                <div class="sort-control">
                    <select id="f2b-rules-node" class="compact-select" onchange="f2bRenderRules()">
                        <option value="">Все ноды</option>
                    </select>
                </div>
                <button type="button" class="secondary f2b-action-btn" id="f2b-add-rule-btn" onclick="f2bAddRule()">+ Джейл</button>
            </div>
        </div>
        <div class="card-body">
            <div class="f2b-managed-meta">
                <span>Панель перезаписывает <span class="f2b-mono">90-hostmonitor.local</span> целиком: незаданные здесь параметры наследуются из штатных конфигов джейла.</span>
            </div>
            <div id="f2b-rules-hint" class="f2b-managed-meta" hidden></div>
        </div>
    </div>
    <div id="f2b-rules-nodes"></div>
</div>

<div id="f2b-edit-modal" class="f2b-modal" hidden>
    <div class="f2b-modal-box">
        <div class="f2b-modal-head">
            <span id="f2b-edit-title">Параметры джейла</span>
            <button class="secondary" onclick="f2bCloseEdit()">&times;</button>
        </div>
        <div class="f2b-edit-grid">
            <label id="f2b-edit-jail-wrap" hidden>джейл <input id="f2b-edit-jail" type="text" placeholder="например apache-auth" autocomplete="off"></label>
            <label>maxretry <input id="f2b-edit-maxretry" type="number" min="1" max="100"></label>
            <label>findtime, мин <input id="f2b-edit-findtime" type="number" min="1" max="1440"></label>
            <label>bantime, мин <input id="f2b-edit-bantime" type="number" min="1" max="525600"></label>
            <label class="f2b-edit-check"><input id="f2b-edit-forever" type="checkbox" onchange="document.getElementById('f2b-edit-bantime').disabled = this.checked"> бан навсегда</label>
            <label class="f2b-edit-check"><input id="f2b-edit-enabled" type="checkbox" checked> джейл включён</label>
            <label>ignoreip <input id="f2b-edit-ignoreip" type="text" placeholder="127.0.0.1 10.0.0.0/8"></label>
            <div class="f2b-managed-meta">Файл <span class="f2b-mono">90-hostmonitor.local</span> будет перезаписан целиком — остальные джейлы берут настройки из своих конфигов.</div>
        </div>
        <div class="f2b-modal-foot">
            <button class="secondary" onclick="f2bCloseEdit()">Отмена</button>
            <button class="primary" onclick="f2bSaveJail()">Сохранить</button>
        </div>
    </div>
</div>

<div id="f2b-totp-modal" class="f2b-modal" hidden>
    <div class="f2b-modal-box" style="width: min(380px, calc(100vw - 32px));">
        <div class="f2b-modal-head">
            <span id="f2b-totp-title">Подтверждение 2FA</span>
            <button class="secondary" onclick="f2bTotpCancel()">&times;</button>
        </div>
        <div class="f2b-edit-grid">
            <label>Код из приложения <input id="f2b-totp-code" type="text" inputmode="numeric" maxlength="11" placeholder="6 цифр" autocomplete="one-time-code"></label>
            <div class="f2b-managed-meta">Перезапись файла стирает ручные правки, поэтому нужно подтвердить действие кодом 2FA.</div>
        </div>
        <div class="f2b-modal-foot">
            <button class="secondary" onclick="f2bTotpCancel()">Отмена</button>
            <button class="primary" id="f2b-totp-submit">Подтвердить</button>
        </div>
    </div>
</div>

<div id="f2b-history-modal" class="f2b-modal" hidden>
    <div class="f2b-modal-box" style="width: min(860px, calc(100vw - 32px));">
        <div class="f2b-modal-head">
            <span id="f2b-history-title">История правил</span>
            <button class="secondary" onclick="f2bCloseHistory()">&times;</button>
        </div>
        <div class="table-container" style="max-height: 60vh; overflow: auto;">
            <table class="f2b-jails-table">
                <thead><tr><th>Когда</th><th>Кто</th><th>Джейл</th><th>Действие</th><th>Изменения</th></tr></thead>
                <tbody id="f2b-history-tbody">
                    <tr><td colspan="5" class="text-center">Загрузка…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="f2b-modal-foot">
            <button class="secondary" onclick="f2bCloseHistory()">Закрыть</button>
        </div>
    </div>
</div>

<div id="f2b-diff-modal" class="f2b-modal" hidden>
    <div class="f2b-modal-box" style="width: min(780px, calc(100vw - 32px));">
        <div class="f2b-modal-head">
            <span id="f2b-diff-title">Предпросмотр правил</span>
            <button class="secondary" onclick="f2bCloseDiff()">&times;</button>
        </div>
        <div id="f2b-diff-body" class="f2b-diff"></div>
        <div class="f2b-modal-foot">
            <button class="secondary" onclick="f2bCloseDiff()">Закрыть</button>
            <button class="primary" id="f2b-diff-apply" onclick="f2bApplyFromDiff()">Применить</button>
        </div>
    </div>
</div>

<script>window.F2B_CAN_MANAGE = <?= (($_SESSION['role'] ?? 'admin') === 'admin') ? 'true' : 'false' ?>;</script>
<?php render_layout_end(['/frontend/js/chart.min.js', '/frontend/js/fail2ban.js']); ?>
