/* ============================================================
 * Fail2ban — обзор, баны, график.
 * Данные: api/fail2ban.php (summary / jails / bans / series).
 * Зависит от common.js (esc) и chart.min.js.
 * ============================================================ */
'use strict';

const F2B_API = `${typeof API_BASE !== 'undefined' ? API_BASE : '/api'}/fail2ban.php`;

function f2bCanManage() {
    return window.F2B_CAN_MANAGE === true;
}

function f2bPost(data) {
    return fetch(F2B_API, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    }).then((r) => r.json().then((body) => {
        if (!r.ok) throw new Error(body.error || `HTTP ${r.status}`);
        return body;
    }));
}

function f2bToast(message, type) {
    if (typeof window.showToast === 'function') {
        window.showToast(message, type || 'info');
    }
}

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

function f2bCounts(now, total) {
    const current = Number(now) || 0;
    const hot = current > 0 ? ' is-hot' : '';
    return `<span class="f2b-count-now${hot}">${current}</span><span class="f2b-total">/ ${Number(total) || 0}</span>`;
}

function f2bPolicy(j) {
    const retry = j.maxretry === null || j.maxretry === undefined ? '—' : j.maxretry;
    return `<span title="maxretry × findtime → bantime">${esc(retry)} × ${f2bFmtDuration(j.findtime)} → ${f2bFmtDuration(j.bantime)}</span>`;
}

function f2bLogCell(j) {
    const raw = String(j.logpath || '').trim();
    if (!raw) return '<span class="f2b-total">—</span>';
    if (String(j.backend || '') === 'systemd' || raw.charAt(0) !== '/') {
        const unit = raw.match(/_SYSTEMD_UNIT=([^\s+]+)/);
        const label = unit ? unit[1] : 'journald';
        return `<span class="f2b-pill" title="${esc(raw)}">journald</span> <span class="f2b-log-main">${esc(label)}</span>`;
    }
    const paths = raw.split(/\s+/).filter(Boolean);
    const first = paths[0];
    const base = first.substring(first.lastIndexOf('/') + 1) || first;
    const extra = paths.length > 1
        ? ` <span class="f2b-pill" title="${esc(paths.slice(1).join('\n'))}">+${paths.length - 1}</span>`
        : '';
    return `<span class="f2b-log-main" title="${esc(paths.join('\n'))}">${esc(base)}</span>${extra}`;
}

function f2bRenderJailRows(jails) {
    // Джейлы одной ноды группируем в одну ячейку с rowspan — иначе имя ноды
    // повторяется в каждой строке и таблица превращается в простыню.
    const rows = [];
    let i = 0;
    while (i < jails.length) {
        const node = jails[i].node_name;
        let count = 1;
        while (i + count < jails.length && jails[i + count].node_name === node) {
            count++;
        }
        for (let k = 0; k < count; k++) {
            const j = jails[i + k];
            const nodeCell = k === 0 ? `<td class="f2b-node-cell" rowspan="${count}">${esc(node)}</td>` : '';
            const editBtn = f2bCanManage()
                ? `<button class="secondary f2b-action-btn" onclick="f2bEditJail(${Number(j.node_id)}, '${esc(j.jail)}')">Изменить</button>`
                : '';
            rows.push(`
                <tr>
                    ${nodeCell}
                    <td class="f2b-jail-name"><strong>${esc(j.jail)}</strong></td>
                    <td class="f2b-count">${f2bCounts(j.currently_failed, j.total_failed)}</td>
                    <td class="f2b-count">${f2bCounts(j.currently_banned, j.total_banned)}</td>
                    <td class="f2b-policy">${f2bPolicy(j)}</td>
                    <td class="f2b-log">${f2bLogCell(j)}</td>
                    <td class="f2b-actions">${editBtn}</td>
                </tr>`);
        }
        i += count;
    }
    return rows.join('');
}

function loadFail2banJails() {
    return f2bFetch({ action: 'jails' }).then((data) => {
        f2bJails = data.items || [];
        const tbody = document.getElementById('f2b-jails-tbody');
        if (tbody) {
            if (!f2bJails.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center">Данных пока нет — агент ещё не присылал снимок</td></tr>';
            } else {
                tbody.innerHTML = f2bRenderJailRows(f2bJails);
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
                tbody.innerHTML = '<tr><td colspan="8" class="text-center">Банов нет</td></tr>';
            } else {
                tbody.innerHTML = items.map((b) => {
                    const active = Number(b.active) === 1;
                    let actions = '';
                    if (f2bCanManage() && active) {
                        actions = b.unban_requested_at
                            ? `<span class="f2b-pending" title="запрошено ${esc(f2bFmtDate(b.unban_requested_at))}">запрошено</span>`
                            : `<button class="secondary f2b-action-btn" onclick="f2bUnban(${Number(b.id)}, '${esc(b.ip)}', '${esc(b.jail)}')">Разбанить</button>`;
                    }
                    return `
                        <tr>
                            <td>${esc(b.node_name)}</td>
                            <td>${esc(b.jail)}</td>
                            <td class="f2b-mono">${esc(b.ip)}</td>
                            <td class="${active ? 'f2b-badge-active' : 'f2b-badge-expired'}">${active ? 'активен' : 'истёк'}</td>
                            <td>${f2bFmtDate(b.banned_at)}</td>
                            <td>${f2bFmtDate(b.expires_at)}</td>
                            <td>${f2bFmtDate(b.last_seen)}</td>
                            <td class="f2b-actions">${actions}</td>
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

function f2bUnban(id, ip, jail) {
    const ask = typeof window.showConfirm === 'function'
        ? window.showConfirm(`Разбанить ${ip} в джейле ${jail}?`, 'Разбан IP', 'warning')
        : Promise.resolve(window.confirm(`Разбанить ${ip} в джейле ${jail}?`));
    ask.then((ok) => {
        if (!ok) return;
        return f2bPost({ action: 'unban', id })
            .then(() => {
                f2bToast('Команда разбана отправлена', 'success');
                loadFail2banBans().catch(() => {});
                setTimeout(reloadFail2ban, 20000);
            })
            .catch((e) => f2bToast(`Разбан не удался: ${e.message}`, 'error'));
    });
}

let f2bEditState = null;

function f2bEditJail(nodeId, jail) {
    const row = f2bJails.find((j) => Number(j.node_id) === Number(nodeId) && j.jail === jail);
    if (!row) return;
    f2bEditState = {
        node_id: Number(nodeId),
        jail,
        maxretry: row.maxretry === null ? null : Number(row.maxretry),
        findtime: row.findtime === null ? null : Number(row.findtime),
        bantime: row.bantime === null ? null : Number(row.bantime),
    };
    document.getElementById('f2b-edit-title').textContent = `Параметры · ${jail}`;
    document.getElementById('f2b-edit-maxretry').value = f2bEditState.maxretry ?? '';
    document.getElementById('f2b-edit-findtime').value = f2bEditState.findtime ? Math.round(f2bEditState.findtime / 60) : '';
    const forever = f2bEditState.bantime !== null && f2bEditState.bantime < 0;
    document.getElementById('f2b-edit-forever').checked = forever;
    document.getElementById('f2b-edit-bantime').value = forever || !f2bEditState.bantime
        ? ''
        : Math.round(f2bEditState.bantime / 60);
    document.getElementById('f2b-edit-bantime').disabled = forever;
    document.getElementById('f2b-edit-modal').hidden = false;
}

function f2bCloseEdit() {
    document.getElementById('f2b-edit-modal').hidden = true;
    f2bEditState = null;
}

function f2bSaveJail() {
    if (!f2bEditState) return;
    const int = (id) => {
        const raw = document.getElementById(id).value.trim();
        return raw === '' ? null : parseInt(raw, 10);
    };
    const retry = int('f2b-edit-maxretry');
    const findMin = int('f2b-edit-findtime');
    const forever = document.getElementById('f2b-edit-forever').checked;
    const banMin = forever ? -1 : int('f2b-edit-bantime');

    const params = {};
    if (retry !== null && retry !== f2bEditState.maxretry) params.maxretry = retry;
    if (findMin !== null && findMin * 60 !== f2bEditState.findtime) params.findtime = findMin * 60;
    if (banMin !== null && banMin !== f2bEditState.bantime) params.bantime = banMin === -1 ? -1 : banMin * 60;

    if (!Object.keys(params).length) {
        f2bCloseEdit();
        return;
    }
    f2bPost({ action: 'set_param', node_id: f2bEditState.node_id, jail: f2bEditState.jail, params })
        .then(() => {
            f2bToast('Команда изменения параметров отправлена', 'success');
            f2bCloseEdit();
            setTimeout(reloadFail2ban, 20000);
        })
        .catch((e) => f2bToast(`Изменение не удалось: ${e.message}`, 'error'));
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
