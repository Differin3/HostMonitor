/* ============================================================
 * Fail2ban — обзор, баны, график.
 * Данные: api/fail2ban.php (summary / jails / bans / series).
 * Зависит от common.js (esc) и chart.min.js.
 * ============================================================ */
'use strict';

const F2B_API = `${typeof API_BASE !== 'undefined' ? API_BASE : '/api'}/fail2ban.php`;

let f2bBansPage = 1;
let f2bBansPages = 1;
let f2bBansTimer = null;
let f2bChart = null;
let f2bJails = [];
let f2bCurrentTab = 'overview';
let f2bRefreshTimer = null;

function f2bFetch(params) {
    const qs = new URLSearchParams(params).toString();
    return fetch(`${F2B_API}?${qs}`, { credentials: 'include' }).then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
    });
}

function f2bFmtDate(value) {
    if (!value) return '—';
    const s = String(value).replace('T', ' ');
    const m = s.match(/^\d{4}-(\d{2})-(\d{2})[ ](\d{2}):(\d{2})/);
    if (!m) return s;
    return `${m[2]}.${m[1]} ${m[3]}:${m[4]}`;
}

function f2bFmtDuration(sec) {
    if (sec === null || sec === undefined || sec === '') return '—';
    const n = Number(sec);
    if (!Number.isFinite(n)) return '—';
    if (n < 0) return 'навсегда';
    if (n === 0) return '0';
    const units = [['д', 86400], ['ч', 3600], ['м', 60], ['с', 1]];
    const parts = [];
    let rest = n;
    for (const [label, size] of units) {
        const v = Math.floor(rest / size);
        if (v > 0) {
            parts.push(`${v}${label}`);
            rest -= v * size;
        }
        if (parts.length === 2) break;
    }
    return parts.join(' ') || '0';
}

function f2bNodeState(node) {
    // Отсутствие снимка != «fail2ban не установлен»: так выглядят ноды
    // со старым агентом, который ещё не умеет собирать fail2ban.
    if (!node.collected_at) return 'unknown';
    if (!node.installed) return 'off';
    if (node.active) return 'active';
    return 'error';
}

function f2bRenderNodes(nodes) {
    const wrap = document.getElementById('f2b-nodes');
    if (!wrap) return;
    if (!nodes.length) {
        wrap.innerHTML = '<div class="f2b-node-card"><div class="f2b-node-name">Нод нет</div></div>';
        return;
    }
    wrap.innerHTML = nodes.map((n) => {
        const state = f2bNodeState(n);
        const version = n.version ? `v${esc(n.version)}` : '—';
        const meta = !n.collected_at
            ? 'нет снимка — обновите агент'
            : (n.installed
                ? `${n.jail_count} джейл(ов) · ${n.active_bans} бан(ов)`
                : 'fail2ban не установлен');
        const error = n.error ? `<div class="f2b-node-error" title="${esc(n.error)}">${esc(n.error)}</div>` : '';
        const subtitle = n.collected_at ? `снимок: ${f2bFmtDate(n.collected_at)}` : 'снимка ещё не было';
        return `
            <div class="f2b-node-card" data-status="${state}">
                <div class="f2b-node-head">
                    <span class="f2b-dot"></span>
                    <span class="f2b-node-name" title="${esc(n.host || '')}">${esc(n.name)}</span>
                    <span class="f2b-badge">${version}</span>
                </div>
                <div class="f2b-node-meta"><span>${esc(meta)}</span></div>
                <div class="f2b-node-sub">${esc(subtitle)}</div>
                ${error}
            </div>`;
    }).join('');
}

function f2bFillSelect(id, items, currentValue) {
    const select = document.getElementById(id);
    if (!select) return;
    const keep = currentValue !== undefined ? currentValue : select.value;
    const head = select.querySelector('option[value=""]');
    select.innerHTML = '';
    if (head) select.appendChild(head);
    items.forEach((item) => {
        const opt = document.createElement('option');
        opt.value = item.value;
        opt.textContent = item.label;
        select.appendChild(opt);
    });
    if (keep && [...select.options].some((o) => o.value === keep)) {
        select.value = keep;
    }
}

function loadFail2banSummary() {
    return f2bFetch({ action: 'summary' }).then((data) => {
        const t = data.totals || {};
        const set = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };
        set('f2b-stat-nodes', `${t.active || 0} / ${t.nodes || 0}`);
        set('f2b-stat-bans', t.active_bans || 0);
        set('f2b-stat-24h', t.bans_24h || 0);

        const nodes = data.nodes || [];
        set('f2b-stat-jails', nodes.reduce((sum, n) => sum + (Number(n.jail_count) || 0), 0));
        f2bRenderNodes(nodes);
        f2bFillSelect('f2b-node-filter', nodes.map((n) => ({ value: String(n.id), label: n.name })));
    });
}

function loadFail2banJails() {
    return f2bFetch({ action: 'jails' }).then((data) => {
        f2bJails = data.items || [];
        const tbody = document.getElementById('f2b-jails-tbody');
        if (tbody) {
            if (!f2bJails.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center">Данных пока нет — агент ещё не присылал снимок</td></tr>';
            } else {
                tbody.innerHTML = f2bJails.map((j) => `
                    <tr>
                        <td>${esc(j.node_name)}</td>
                        <td><strong>${esc(j.jail)}</strong></td>
                        <td>${Number(j.currently_failed) || 0} / ${Number(j.total_failed) || 0}</td>
                        <td>${Number(j.currently_banned) || 0} / ${Number(j.total_banned) || 0}</td>
                        <td>${j.maxretry === null ? '—' : esc(j.maxretry)}</td>
                        <td>${f2bFmtDuration(j.findtime)}</td>
                        <td>${f2bFmtDuration(j.bantime)}</td>
                        <td class="f2b-mono" title="${esc(j.logpath || '')}">${esc(j.logpath || '—')}</td>
                    </tr>`).join('');
            }
        }
        const jails = [...new Set(f2bJails.map((j) => j.jail))].sort();
        f2bFillSelect('f2b-jail-filter', jails.map((j) => ({ value: j, label: j })));
    });
}

function f2bOnBansSearch() {
    clearTimeout(f2bBansTimer);
    f2bBansTimer = setTimeout(() => {
        f2bBansPage = 1;
        loadFail2banBans();
    }, 300);
}

function loadFail2banBans() {
    const params = {
        action: 'bans',
        page: f2bBansPage,
        per_page: 50,
        status: document.getElementById('f2b-status-filter')?.value || 'active',
        node_id: document.getElementById('f2b-node-filter')?.value || '',
        jail: document.getElementById('f2b-jail-filter')?.value || '',
        q: (document.getElementById('f2b-bans-search')?.value || '').trim(),
    };
    return f2bFetch(params).then((data) => {
        f2bBansPage = data.page || 1;
        f2bBansPages = data.pages || 1;
        const tbody = document.getElementById('f2b-bans-tbody');
        const items = data.items || [];
        if (tbody) {
            if (!items.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center">Банов нет</td></tr>';
            } else {
                tbody.innerHTML = items.map((b) => {
                    const active = Number(b.active) === 1;
                    return `
                        <tr>
                            <td>${esc(b.node_name)}</td>
                            <td>${esc(b.jail)}</td>
                            <td class="f2b-mono">${esc(b.ip)}</td>
                            <td class="${active ? 'f2b-badge-active' : 'f2b-badge-expired'}">${active ? 'активен' : 'истёк'}</td>
                            <td>${f2bFmtDate(b.banned_at)}</td>
                            <td>${f2bFmtDate(b.expires_at)}</td>
                            <td>${f2bFmtDate(b.last_seen)}</td>
                        </tr>`;
                }).join('');
            }
        }
        const info = document.getElementById('f2b-bans-page-info');
        if (info) info.textContent = `Стр. ${f2bBansPage} из ${f2bBansPages} · ${data.total || 0}`;
        const prev = document.getElementById('f2b-bans-prev');
        const next = document.getElementById('f2b-bans-next');
        if (prev) prev.disabled = f2bBansPage <= 1;
        if (next) next.disabled = f2bBansPage >= f2bBansPages;
    });
}

function f2bChartLabel(bucket, days) {
    const s = String(bucket || '');
    const m = s.match(/^\d{4}-(\d{2})-(\d{2})[ ](\d{2})?:?(\d{2})?/);
    if (!m) return s;
    return days <= 1 ? `${m[3]}:00` : `${m[2]}.${m[1]}`;
}

function loadFail2banSeries() {
    const days = Number(document.getElementById('f2b-chart-days')?.value || 1);
    return f2bFetch({ action: 'series', days }).then((data) => {
        const points = data.points || [];
        const canvas = document.getElementById('f2b-chart');
        if (!canvas) return;
        const series = {
            labels: points.map((p) => f2bChartLabel(p.bucket, days)),
            bans: points.map((p) => Number(p.bans) || 0),
            unbans: points.map((p) => Number(p.unbans) || 0),
        };
        if (f2bChart) {
            f2bChart.data.labels = series.labels;
            f2bChart.data.datasets[0].data = series.bans;
            f2bChart.data.datasets[1].data = series.unbans;
            f2bChart.update();
            return;
        }
        f2bChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: series.labels,
                datasets: [
                    {
                        label: 'Баны',
                        data: series.bans,
                        backgroundColor: 'rgba(248, 113, 113, .75)',
                        borderColor: 'rgba(220, 38, 38, 1)',
                        borderWidth: 1,
                        borderRadius: 3,
                    },
                    {
                        label: 'Разбаны',
                        data: series.unbans,
                        backgroundColor: 'rgba(52, 211, 153, .7)',
                        borderColor: 'rgba(5, 150, 105, 1)',
                        borderWidth: 1,
                        borderRadius: 3,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { labels: { color: '#9ca3af' } } },
                scales: {
                    x: { ticks: { color: '#9ca3af', maxRotation: 0, autoSkip: true }, grid: { color: 'rgba(148,163,184,.1)' } },
                    y: { beginAtZero: true, ticks: { color: '#9ca3af', precision: 0 }, grid: { color: 'rgba(148,163,184,.1)' } },
                },
            },
        });
    });
}

function switchFail2banTab(tab) {
    f2bCurrentTab = tab;
    document.querySelectorAll('.tab-btn').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    document.getElementById('f2b-overview-tab')?.classList.toggle('active', tab === 'overview');
    document.getElementById('f2b-bans-tab')?.classList.toggle('active', tab === 'bans');
    document.getElementById('f2b-chart-tab')?.classList.toggle('active', tab === 'chart');
    if (tab === 'bans') loadFail2banBans().catch((e) => console.error(e));
    if (tab === 'chart') loadFail2banSeries().catch((e) => console.error(e));
}

function reloadFail2ban() {
    loadFail2banSummary().catch((e) => console.error(e));
    loadFail2banJails().catch((e) => console.error(e));
    if (f2bCurrentTab === 'bans') loadFail2banBans().catch((e) => console.error(e));
    if (f2bCurrentTab === 'chart') loadFail2banSeries().catch((e) => console.error(e));
}

document.addEventListener('DOMContentLoaded', () => {
    loadFail2banSummary().catch((e) => console.error(e));
    loadFail2banJails().catch((e) => console.error(e));
    f2bRefreshTimer = setInterval(() => {
        if (document.hidden) return;
        if (f2bCurrentTab === 'overview') {
            loadFail2banSummary().catch(() => {});
            loadFail2banJails().catch(() => {});
        }
    }, 30000);
});

window.addEventListener('beforeunload', () => {
    if (f2bRefreshTimer) clearInterval(f2bRefreshTimer);
});
