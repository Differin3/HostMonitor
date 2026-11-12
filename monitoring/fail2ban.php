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
</style>

<div class="tabs">
    <button type="button" class="tab-btn active" data-tab="overview" onclick="switchFail2banTab('overview')">
        <i data-lucide="shield"></i> Обзор
    </button>
    <button type="button" class="tab-btn" data-tab="bans" onclick="switchFail2banTab('bans')">
        <i data-lucide="ban"></i> Баны
    </button>
    <button type="button" class="tab-btn" data-tab="chart" onclick="switchFail2banTab('chart')">
        <i data-lucide="activity"></i> График
    </button>
</div>

<div id="f2b-overview-tab" class="tab-content active">
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
        <table>
            <thead>
                <tr>
                    <th>Нода</th>
                    <th>Джейл</th>
                    <th>Failed (сейчас / всего)</th>
                    <th>Banned (сейчас / всего)</th>
                    <th>maxretry</th>
                    <th>findtime</th>
                    <th>bantime</th>
                    <th>Источник логов</th>
                </tr>
            </thead>
            <tbody id="f2b-jails-tbody">
                <tr><td colspan="8" class="text-center">Загрузка…</td></tr>
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

<?php render_layout_end(['/frontend/js/chart.min.js', '/frontend/js/fail2ban.js']); ?>
