const API_BASE = window.MONITORING_API_BASE || '/api';

let allDatabases = [];
let chart = null;
let chartId = '';

const els = {
    grid: document.getElementById('dbmon-grid'),
    search: document.getElementById('dbmon-search'),
    engine: document.getElementById('dbmon-engine'),
    status: document.getElementById('dbmon-status'),
    count: document.getElementById('dbmon-count'),
    online: document.getElementById('dbmon-online'),
    conn: document.getElementById('dbmon-conn'),
    size: document.getElementById('dbmon-size'),
    modal: document.getElementById('dbmon-modal'),
    form: document.getElementById('dbmon-form'),
    chartTarget: document.getElementById('dbmon-chart-target'),
};

const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

const formatBytes = (n) => {
    const v = Number(n) || 0;
    if (!v) return '—';
    if (v >= 1099511627776) return `${(v / 1099511627776).toFixed(1)} ТиБ`;
    if (v >= 1073741824) return `${(v / 1073741824).toFixed(v >= 10737418240 ? 0 : 1)} ГиБ`;
    if (v >= 1048576) return `${(v / 1048576).toFixed(v >= 10485760 ? 0 : 1)} МиБ`;
    return `${Math.round(v / 1024)} КиБ`;
};

const formatUptime = (sec) => {
    const n = Number(sec) || 0;
    if (!n) return '—';
    const d = Math.floor(n / 86400);
    const h = Math.floor((n % 86400) / 3600);
    if (d > 0) return `${d}д ${h}ч`;
    const m = Math.floor((n % 3600) / 60);
    if (h > 0) return `${h}ч ${m}м`;
    return `${m} мин`;
};

const engineLabel = (engine) => {
    if (engine === 'postgres') return 'PostgreSQL';
    if (engine === 'mariadb') return 'MariaDB';
    return 'MySQL';
};

const isMysqlEngine = (engine) => engine === 'mysql' || engine === 'mariadb';

function paintDbmonSslStatus(row) {
    const statusEl = document.getElementById('dbmon-ssl-ca-status');
    const removeBtn = document.getElementById('dbmon-ssl-ca-remove');
    if (!statusEl) return;
    if (row?.has_ssl_ca) {
        const parts = ['CA установлен'];
        if (row.ssl_ca) parts.push(`(${row.ssl_ca})`);
        if (row.ssl_ca_subject) parts.push(`— ${row.ssl_ca_subject}`);
        if (row.ssl_ca_cert_count > 1) parts.push(`[${row.ssl_ca_cert_count} серт.]`);
        statusEl.textContent = parts.join(' ');
        statusEl.classList.add('ok-hint');
    } else {
        statusEl.textContent = 'CA не загружен — при «Проверять сертификат» используется системный bundle или загрузите свой PEM';
        statusEl.classList.remove('ok-hint');
    }
    if (removeBtn) removeBtn.disabled = !row?.has_ssl_ca;
}

function toggleDbmonSslSection(row) {
    const section = document.getElementById('dbmon-ssl-section');
    const caBlock = document.getElementById('dbmon-ssl-ca-block');
    const hint = document.getElementById('dbmon-ssl-settings-hint');
    const engine = row?.engine || document.getElementById('dbmon-engine-field')?.value || 'mysql';
    const kind = row?.kind || 'custom';
    const show = isMysqlEngine(engine) && kind !== 'panel';
    if (section) section.classList.toggle('hidden', !show);
    if (hint) hint.classList.toggle('hidden', kind !== 'replica');
    if (!show) return;
    const verify = document.getElementById('dbmon-ssl-verify')?.checked;
    if (caBlock) caBlock.classList.toggle('hidden', !verify);
}

async function fetchJson(url, options = {}) {
    const res = await fetch(url, { credentials: 'include', ...options });
    const text = await res.text();
    const data = text ? JSON.parse(text) : {};
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
}

function filtered() {
    const q = (els.search?.value || '').toLowerCase();
    const engine = els.engine?.value || '';
    const status = els.status?.value || '';
    return allDatabases.filter((d) => {
        if (engine && d.engine !== engine) return false;
        if (status && d.status !== status) return false;
        if (!q) return true;
        const m = d.metrics || {};
        return `${d.name} ${d.host} ${d.db_name} ${d.notes} ${m.version || ''}`.toLowerCase().includes(q);
    });
}

function openModal(row) {
    els.form.reset();
    document.getElementById('dbmon-id').value = row?.id || '';
    document.getElementById('dbmon-modal-title').textContent = row ? 'Изменить базу' : 'Добавить базу';
    const builtin = !!row?.is_builtin;
    const kind = row?.kind || 'custom';
    ['dbmon-engine-field', 'dbmon-host', 'dbmon-port', 'dbmon-dbname', 'dbmon-user', 'dbmon-password'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.disabled = builtin;
    });
    const sslPem = document.getElementById('dbmon-ssl-ca-pem');
    if (sslPem) sslPem.value = '';
    const sslFile = document.getElementById('dbmon-ssl-ca-file');
    if (sslFile) sslFile.value = '';
    if (row) {
        document.getElementById('dbmon-name').value = row.name || '';
        document.getElementById('dbmon-engine-field').value = row.engine || 'mysql';
        document.getElementById('dbmon-host').value = row.host || '';
        document.getElementById('dbmon-port').value = String(row.port || 3306);
        document.getElementById('dbmon-dbname').value = row.db_name || '';
        document.getElementById('dbmon-user').value = row.username || '';
        document.getElementById('dbmon-notes').value = row.notes || '';
        const sslEl = document.getElementById('dbmon-ssl');
        const sslVerifyEl = document.getElementById('dbmon-ssl-verify');
        if (sslEl) sslEl.checked = !!row.ssl;
        if (sslVerifyEl) sslVerifyEl.checked = !!row.ssl_verify;
        paintDbmonSslStatus(row);
    } else {
        document.getElementById('dbmon-port').value = '3306';
        const sslEl = document.getElementById('dbmon-ssl');
        const sslVerifyEl = document.getElementById('dbmon-ssl-verify');
        if (sslEl) sslEl.checked = false;
        if (sslVerifyEl) sslVerifyEl.checked = false;
        paintDbmonSslStatus(null);
    }
    toggleDbmonSslSection(row || { engine: 'mysql', kind: 'custom' });
    els.modal.classList.remove('hidden');
    els.modal.classList.add('active');
    if (window.lucide) lucide.createIcons();
}

function closeModal() {
    els.modal.classList.add('hidden');
    els.modal.classList.remove('active');
}

function cardHtml(d) {
    const m = d.metrics || {};
    const online = d.status === 'online';
    const max = Number(m.max_connections) || 0;
    const used = Number(m.threads_connected) || 0;
    const pct = max > 0 ? Math.min(100, Math.round((used / max) * 100)) : 0;
    const tone = !online ? 'bad' : (pct >= 85 ? 'bad' : (pct >= 70 ? 'warn' : 'ok'));
    const kind = d.kind === 'panel' ? 'панель' : (d.kind === 'replica' ? 'резерв' : engineLabel(d.engine));
    const err = d.last_error ? `<p class="dbmon-error">${esc(d.last_error)}</p>` : '';
    const lag = m.replica_lag_sec == null ? '' : `<span>лаг реплики ${esc(m.replica_lag_sec)} с</span>`;
    return `
        <article class="card dbmon-card" data-tone="${tone}">
            <div class="dbmon-top">
                <div>
                    <h3>${esc(d.name)}</h3>
                    <p class="dbmon-host">${esc(d.host)}:${esc(d.port)}${d.db_name ? ' · ' + esc(d.db_name) : ''}</p>
                </div>
                <span class="pill status ${online ? 'online' : 'offline'}">${online ? 'online' : 'offline'}</span>
            </div>
            <div class="dbmon-meta">
                <span class="pill">${esc(kind)}</span>
                <span>${esc(m.version || 'нет версии')}</span>
            </div>
            <div class="dbmon-kpis">
                <div><small>пинг</small><strong>${m.ping_ms != null ? esc(m.ping_ms) + ' мс' : '—'}</strong></div>
                <div><small>сессии</small><strong>${used}${max ? ' / ' + max : ''}</strong></div>
                <div><small>QPS</small><strong>${m.qps != null ? esc(Number(m.qps).toFixed(1)) : '—'}</strong></div>
                <div><small>размер</small><strong>${formatBytes(m.data_bytes)}</strong></div>
                <div><small>uptime</small><strong>${formatUptime(m.uptime_sec)}</strong></div>
                <div><small>slow</small><strong>${m.slow_queries != null ? esc(m.slow_queries) : '—'}</strong></div>
            </div>
            <div class="hm-meter" data-tone="${tone}"><span style="width:${pct}%"></span></div>
            ${lag ? `<p class="dbmon-lag">${lag}</p>` : ''}
            ${err}
            <div class="dbmon-actions">
                <button type="button" class="btn-outline" data-probe="${d.id}"><i data-lucide="activity"></i> Проверить</button>
                <button type="button" class="btn-outline" data-edit="${d.id}"><i data-lucide="pencil"></i></button>
                ${d.is_builtin ? '' : `<button type="button" class="icon-btn" data-del="${d.id}" title="Удалить"><i data-lucide="trash-2"></i></button>`}
            </div>
        </article>
    `;
}

function render() {
    const list = filtered();
    const online = allDatabases.filter((d) => d.status === 'online');
    els.count.textContent = String(allDatabases.length);
    els.online.textContent = String(online.length);
    els.conn.textContent = String(allDatabases.reduce((s, d) => s + Number(d.metrics?.threads_connected || 0), 0));
    els.size.textContent = formatBytes(allDatabases.reduce((s, d) => s + Number(d.metrics?.data_bytes || 0), 0));
    if (!list.length) {
        els.grid.innerHTML = '<div class="card"><p class="list-empty">Нет баз. Добавьте удалённую MySQL или дождитесь опроса базы панели.</p></div>';
    } else {
        els.grid.innerHTML = list.map(cardHtml).join('');
    }
    const prev = els.chartTarget.value;
    els.chartTarget.innerHTML = '<option value="">Выберите базу</option>' + allDatabases
        .filter((d) => d.status === 'online' || d.id === Number(prev))
        .map((d) => `<option value="${d.id}">${esc(d.name)}</option>`).join('');
    if (prev) els.chartTarget.value = prev;
    if (window.lucide) lucide.createIcons();
}

async function load(probe) {
    const data = await fetchJson(`${API_BASE}/databases.php${probe ? '?probe=1' : ''}`);
    allDatabases = data.databases || [];
    render();
    const pick = els.chartTarget.value || (allDatabases.find((d) => d.kind === 'panel')?.id ?? allDatabases[0]?.id);
    if (pick) {
        els.chartTarget.value = String(pick);
        await loadChart(pick);
    }
}

async function loadChart(id) {
    if (!id || !window.Chart) return;
    chartId = String(id);
    const from = Math.floor(Date.now() / 1000) - 3600;
    const data = await fetchJson(`${API_BASE}/databases.php?id=${id}&history=1&from=${from}&limit=120`).catch(() => null);
    const points = data?.points || [];
    const labels = points.map((p) => {
        const dt = new Date(String(p.timestamp).replace(' ', 'T'));
        return dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    });
    const ctx = document.getElementById('dbmon-chart');
    if (!ctx) return;
    const payload = {
        labels,
        datasets: [
            {
                label: 'Сессии',
                data: points.map((p) => Number(p.threads_connected) || 0),
                borderColor: '#60a5fa',
                backgroundColor: 'rgba(96,165,250,0.12)',
                fill: true,
                yAxisID: 'y',
            },
            {
                label: 'QPS',
                data: points.map((p) => Number(p.qps) || 0),
                borderColor: '#34d399',
                backgroundColor: 'rgba(52,211,153,0.08)',
                fill: false,
                yAxisID: 'y1',
            },
        ],
    };
    if (chart) {
        chart.data = payload;
        chart.update('none');
        return;
    }
    chart = new Chart(ctx, {
        type: 'line',
        data: payload,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: true, position: 'bottom' } },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'сессии' } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'QPS' } },
            },
        },
    });
}

els.search?.addEventListener('input', render);
els.engine?.addEventListener('change', render);
els.status?.addEventListener('change', render);
els.chartTarget?.addEventListener('change', () => loadChart(els.chartTarget.value));

document.getElementById('dbmon-add')?.addEventListener('click', () => openModal(null));
document.getElementById('dbmon-refresh')?.addEventListener('click', async () => {
    try {
        await load(true);
        window.showToast?.('Опрос завершён', 'success');
    } catch (e) {
        window.showToast?.(e.message, 'error');
    }
});
document.getElementById('dbmon-cancel')?.addEventListener('click', closeModal);
document.getElementById('dbmon-modal-close')?.addEventListener('click', closeModal);

document.getElementById('dbmon-engine-field')?.addEventListener('change', (e) => {
    const port = document.getElementById('dbmon-port');
    if (!port || document.getElementById('dbmon-id').value) return;
    port.value = e.target.value === 'postgres' ? '5432' : '3306';
    toggleDbmonSslSection({ engine: e.target.value, kind: 'custom' });
});

document.getElementById('dbmon-ssl-verify')?.addEventListener('change', () => {
    toggleDbmonSslSection(allDatabases.find((d) => String(d.id) === document.getElementById('dbmon-id').value) || { engine: document.getElementById('dbmon-engine-field')?.value, kind: 'custom' });
});

async function uploadDbmonSslCa() {
    const id = Number(document.getElementById('dbmon-id').value);
    if (!id) {
        window.showToast?.('Сначала сохраните базу, затем загрузите CA', 'error');
        return;
    }
    const textarea = document.getElementById('dbmon-ssl-ca-pem');
    const pem = (textarea?.value || '').trim();
    if (!pem) {
        window.showToast?.('Выберите файл или вставьте PEM', 'error');
        return;
    }
    try {
        const data = await fetchJson(`${API_BASE}/databases.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'upload_ssl_ca', id, pem }),
        });
        if (textarea) textarea.value = '';
        const fileInput = document.getElementById('dbmon-ssl-ca-file');
        if (fileInput) fileInput.value = '';
        const sslEl = document.getElementById('dbmon-ssl');
        if (sslEl) sslEl.checked = true;
        if (data.database) {
            const idx = allDatabases.findIndex((d) => String(d.id) === String(id));
            if (idx >= 0) allDatabases[idx] = data.database;
            paintDbmonSslStatus(data.database);
        }
        window.showToast?.(data.message || 'CA сохранён', 'success');
    } catch (err) {
        window.showToast?.(err.message, 'error');
    }
}

async function removeDbmonSslCa() {
    const id = Number(document.getElementById('dbmon-id').value);
    if (!id) return;
    const confirmed = await window.showConfirm('Удалить CA-сертификат для этой базы?', 'CA-сертификат', 'warning');
    if (!confirmed) return;
    try {
        const data = await fetchJson(`${API_BASE}/databases.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'remove_ssl_ca', id }),
        });
        const textarea = document.getElementById('dbmon-ssl-ca-pem');
        if (textarea) textarea.value = '';
        if (data.database) {
            const idx = allDatabases.findIndex((d) => String(d.id) === String(id));
            if (idx >= 0) allDatabases[idx] = data.database;
            paintDbmonSslStatus(data.database);
        }
        window.showToast?.(data.message || 'CA удалён', 'success');
    } catch (err) {
        window.showToast?.(err.message, 'error');
    }
}

document.getElementById('dbmon-ssl-ca-upload')?.addEventListener('click', uploadDbmonSslCa);
document.getElementById('dbmon-ssl-ca-remove')?.addEventListener('click', removeDbmonSslCa);
document.getElementById('dbmon-ssl-ca-file')?.addEventListener('change', async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const textarea = document.getElementById('dbmon-ssl-ca-pem');
    if (textarea) textarea.value = await file.text();
});

els.grid?.addEventListener('click', async (e) => {
    const probe = e.target.closest('[data-probe]');
    const edit = e.target.closest('[data-edit]');
    const del = e.target.closest('[data-del]');
    try {
        if (probe) {
            await fetchJson(`${API_BASE}/databases.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'probe', id: Number(probe.dataset.probe) }),
            });
            await load(false);
            window.showToast?.('Проверено', 'success');
        }
        if (edit) {
            const row = allDatabases.find((d) => String(d.id) === String(edit.dataset.edit));
            if (row) openModal(row);
        }
        if (del) {
            const confirmed = await window.showConfirm('Убрать эту базу из мониторинга?', 'Удаление базы', 'danger');
            if (!confirmed) return;
            await fetchJson(`${API_BASE}/databases.php?id=${del.dataset.del}`, { method: 'DELETE' });
            await load(false);
            window.showToast?.('Удалено', 'success');
        }
    } catch (err) {
        window.showToast?.(err.message, 'error');
    }
});

els.form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = document.getElementById('dbmon-id').value;
    const payload = {
        name: document.getElementById('dbmon-name').value.trim(),
        engine: document.getElementById('dbmon-engine-field').value,
        host: document.getElementById('dbmon-host').value.trim(),
        port: Number(document.getElementById('dbmon-port').value),
        db_name: document.getElementById('dbmon-dbname').value.trim(),
        username: document.getElementById('dbmon-user').value.trim(),
        password: document.getElementById('dbmon-password').value,
        notes: document.getElementById('dbmon-notes').value.trim(),
    };
    const sslSection = document.getElementById('dbmon-ssl-section');
    if (sslSection && !sslSection.classList.contains('hidden')) {
        payload.ssl = !!document.getElementById('dbmon-ssl')?.checked;
        payload.ssl_verify = !!document.getElementById('dbmon-ssl-verify')?.checked;
    }
    try {
        let savedId = id ? Number(id) : 0;
        if (id) {
            await fetchJson(`${API_BASE}/databases.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update', id: Number(id), ...payload }),
            });
        } else {
            const created = await fetchJson(`${API_BASE}/databases.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            savedId = Number(created.database?.id || 0);
        }
        const pem = (document.getElementById('dbmon-ssl-ca-pem')?.value || '').trim();
        if (savedId && pem) {
            await fetchJson(`${API_BASE}/databases.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'upload_ssl_ca', id: savedId, pem }),
            });
        }
        closeModal();
        await load(false);
        window.showToast?.('Сохранено', 'success');
    } catch (err) {
        window.showToast?.(err.message, 'error');
    }
});

// --- Резерв панели / синхронизация (компактный блок) ---
const DB_HA_API = `${API_BASE}/db_ha.php`;
const haEls = {
    card: document.getElementById('dbmon-ha-card'),
    primaryLabel: document.getElementById('dbmon-ha-primary-label'),
    replicaLabel: document.getElementById('dbmon-ha-replica-label'),
    note: document.getElementById('dbmon-ha-note'),
    actions: document.getElementById('dbmon-sync-actions'),
    progress: document.getElementById('dbmon-sync-progress'),
    result: document.getElementById('dbmon-sync-result'),
    cancel: document.getElementById('dbmon-sync-cancel'),
    ping: document.getElementById('dbmon-ha-ping'),
    failback: document.getElementById('dbmon-ha-failback'),
    toReplica: document.getElementById('dbmon-sync-to-replica'),
    toPrimary: document.getElementById('dbmon-sync-to-primary'),
};

let dbHaEditable = true;
let dbSyncRunning = false;
let dbSyncAbort = false;

function endpointLabel(ep) {
    if (!ep || !ep.host) return 'не задан';
    const port = ep.port ? `:${ep.port}` : '';
    const name = ep.name ? ` / ${ep.name}` : '';
    return `${ep.host}${port}${name}`;
}

function setHostLabel(el, text) {
    if (!el) return;
    el.textContent = text;
    el.title = text && text !== '—' && text !== 'выключен' && text !== 'не задан' ? text : '';
}

function setHaPill(id, label, ok, extra) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = extra ? `${label}: ${extra}` : label;
    el.classList.remove('ok', 'fail', 'warn', 'online', 'offline');
    if (ok === true) el.classList.add('ok');
    if (ok === false) el.classList.add('fail');
    if (ok === 'warn') el.classList.add('warn');
}

function setDbmonSyncUi(running) {
    dbSyncRunning = !!running;
    if (haEls.actions) haEls.actions.classList.toggle('is-busy', dbSyncRunning);
    if (haEls.progress) haEls.progress.classList.toggle('hidden', !dbSyncRunning);
    [haEls.toReplica, haEls.toPrimary, haEls.failback].forEach((btn) => {
        if (!btn) return;
        if (btn === haEls.failback) {
            btn.disabled = !dbHaEditable || dbSyncRunning;
            return;
        }
        btn.disabled = !dbHaEditable || dbSyncRunning || !btn.dataset.canSync;
    });
    if (haEls.cancel) haEls.cancel.disabled = !dbSyncRunning;
    if (!dbSyncRunning && window.lucide) lucide.createIcons();
}

function updateDbmonSyncProgress(label, pct, detail) {
    const labelEl = document.getElementById('dbmon-sync-progress-label');
    const pctEl = document.getElementById('dbmon-sync-progress-pct');
    const bar = document.getElementById('dbmon-sync-progress-bar');
    const detailEl = document.getElementById('dbmon-sync-progress-detail');
    if (labelEl && label != null) labelEl.textContent = label;
    if (pct != null && !Number.isNaN(Number(pct))) {
        if (pctEl) pctEl.textContent = `${Math.round(pct)}%`;
        if (bar) bar.style.width = `${Math.min(100, Math.max(0, pct))}%`;
    }
    if (detailEl && detail != null) detailEl.textContent = detail;
    if (window.HostJobs) {
        window.HostJobs.update('db-sync', {
            title: label || 'Синхронизация БД',
            detail: detail || '',
            pct: pct,
        });
    }
}

function showDbmonSyncResult(message, isError) {
    if (!haEls.result) return;
    haEls.result.classList.remove('hidden', 'is-ok', 'is-error');
    haEls.result.classList.add(isError ? 'is-error' : 'is-ok');
    haEls.result.textContent = message;
}

function hideDbmonSyncResult() {
    haEls.result?.classList.add('hidden');
}

function paintDbmonHa(data) {
    const ping = data.ping || {};
    const primary = data.primary || {};
    const replica = data.replica || {};
    dbHaEditable = data.editable !== false;

    setHaPill(
        'dbmon-pill-active',
        'Активная',
        data.active_role === 'replica' ? 'warn' : true,
        data.active_role === 'replica' ? 'резерв' : 'основная'
    );
    const pOk = ping.primary ? ping.primary.ok : null;
    const rOk = ping.replica ? ping.replica.ok : null;
    setHaPill(
        'dbmon-pill-primary',
        'Основная',
        pOk,
        pOk ? `${ping.primary.ms} мс` : (ping.primary?.error || 'нет ответа')
    );
    if (!data.replica_enabled) {
        setHaPill('dbmon-pill-replica', 'Резерв', false, 'выключен');
    } else {
        setHaPill(
            'dbmon-pill-replica',
            'Резерв',
            rOk,
            rOk ? `${ping.replica.ms} мс` : (ping.replica?.error || 'нет ответа')
        );
    }

    if (haEls.primaryLabel) setHostLabel(haEls.primaryLabel, endpointLabel(primary));
    if (haEls.replicaLabel) {
        setHostLabel(haEls.replicaLabel, data.replica_enabled ? endpointLabel(replica) : 'выключен');
    }

    const notes = [];
    if (!data.replica_enabled) {
        notes.push('Резерв выключен — включите в Настройках.');
    } else if (!dbHaEditable) {
        notes.push('Нет связи с основной и резервом — синхронизация недоступна.');
    } else if (data.active_role === 'replica') {
        notes.push('Панель на резерве. После восстановления основной нажмите «На основную».');
    } else {
        notes.push('Полный снимок таблиц (не live-репликация). На приёмнике таблицы пересоздаются.');
    }
    if (haEls.note) haEls.note.textContent = notes.join(' ');

    const canSync = !!data.replica_enabled && dbHaEditable;
    if (haEls.toReplica) haEls.toReplica.dataset.canSync = canSync ? '1' : '';
    if (haEls.toPrimary) haEls.toPrimary.dataset.canSync = canSync ? '1' : '';
    setDbmonSyncUi(dbSyncRunning);
    if (window.lucide) lucide.createIcons();
}

async function dbHaRequest(payload, { retries = 0 } = {}) {
    const isSyncChunk = payload && ['sync_table', 'sync_table_schema', 'sync_prepare'].includes(payload.action);
    const maxAttempts = retries > 0 ? retries + 1 : (isSyncChunk ? 4 : 1);
    let lastError = null;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
        try {
            const opts = payload
                ? {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify(payload),
                }
                : { credentials: 'include' };
            const response = await fetch(DB_HA_API, opts);
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const status = response.status;
                const msg = data.error || `HTTP ${status}`;
                if ((status === 502 || status === 504 || status === 408) && attempt < maxAttempts) {
                    lastError = new Error(msg);
                    updateDbmonSyncProgress(
                        'Повтор…',
                        undefined,
                        `Таймаут прокси (${status}), попытка ${attempt + 1}/${maxAttempts}`
                    );
                    await new Promise((r) => setTimeout(r, 800 * attempt));
                    continue;
                }
                throw new Error(msg);
            }
            return data;
        } catch (e) {
            lastError = e;
            const transient = /HTTP 502|HTTP 504|HTTP 408|Failed to fetch|NetworkError|network/i.test(String(e.message || e));
            if (transient && attempt < maxAttempts) {
                await new Promise((r) => setTimeout(r, 800 * attempt));
                continue;
            }
            throw e;
        }
    }
    throw lastError || new Error('Запрос не выполнен');
}

async function loadDbmonHa() {
    if (!haEls.card) return;
    try {
        const data = await dbHaRequest(null);
        paintDbmonHa(data);
    } catch (e) {
        if (haEls.note) haEls.note.textContent = e.message;
        window.showToast?.(e.message, 'error');
    }
}

async function runDbmonSync(direction) {
    if (!dbHaEditable || dbSyncRunning) return;

    const titles = {
        to_replica: 'Скопировать основную базу на резерв',
        to_primary: 'Скопировать резервную базу на основную',
    };
    const confirmed = await window.showConfirm?.(
        `${titles[direction] || 'Копирование'}.\n\nВсе таблицы на приёмнике будут удалены и созданы заново из источника. Копирование идёт короткими порциями.\n\nПродолжить?`,
        'Копирование базы',
        'warning'
    );
    if (!confirmed) return;

    dbSyncAbort = false;
    hideDbmonSyncResult();
    setDbmonSyncUi(true);
    window.HostJobs?.start('db-sync', {
        title: direction === 'to_primary' ? 'Синхронизация: резерв → основная' : 'Синхронизация: основная → резерв',
        detail: 'Подготовка…',
        pct: 0,
        cancelable: true,
        onCancel: () => { dbSyncAbort = true; },
    });
    updateDbmonSyncProgress('Подготовка…', 0, 'Проверка соединений и списка таблиц');

    let directionUsed = direction;
    let totalRows = 0;

    try {
        const prep = await dbHaRequest({ action: 'sync_prepare', direction });
        directionUsed = prep.direction || direction;
        const tables = prep.tables || [];
        const total = tables.length;
        const chunkLimit = Number(prep.chunk_limit) || 200;
        if (!total) throw new Error('В источнике нет таблиц для копирования');

        const srcLabel = prep.source_label || 'источник';
        const dstLabel = prep.target_label || 'приёмник';
        const srcName = prep.source_name ? ` (${prep.source_name})` : '';
        const dstName = prep.target_name ? ` (${prep.target_name})` : '';
        updateDbmonSyncProgress(
            'Копирование…',
            0,
            `${srcLabel}${srcName} → ${dstLabel}${dstName} · таблиц: ${total}`
        );

        for (let i = 0; i < total; i++) {
            if (dbSyncAbort) throw new Error('Отменено пользователем');

            const table = tables[i];
            let offset = 0;
            let cursor = null;
            let tableRows = 0;
            let guard = 0;

            updateDbmonSyncProgress(
                `Таблица ${i + 1} из ${total}`,
                (i / total) * 100,
                `Создание схемы «${table}»…`
            );
            await dbHaRequest({
                action: 'sync_table_schema',
                direction: directionUsed,
                table,
            });

            while (true) {
                if (dbSyncAbort) throw new Error('Отменено пользователем');
                if (++guard > 50000) {
                    throw new Error(`Слишком много порций для таблицы «${table}» — прервано`);
                }

                const basePct = (i / total) * 100;
                const within = Math.min(99, (tableRows / Math.max(tableRows + chunkLimit, 1)) * (100 / total));
                updateDbmonSyncProgress(
                    `Таблица ${i + 1} из ${total}`,
                    basePct + within,
                    `«${table}» · уже ${tableRows.toLocaleString('ru-RU')} строк…`
                );

                const res = await dbHaRequest({
                    action: 'sync_table',
                    direction: directionUsed,
                    table,
                    index: i,
                    total,
                    offset,
                    cursor,
                    limit: Math.min(chunkLimit, 200),
                });

                const rows = Number(res.rows) || 0;
                tableRows += rows;
                totalRows += rows;
                offset = Number(res.next_offset) || (offset + rows);
                cursor = res.next_cursor ?? null;

                updateDbmonSyncProgress(
                    `Таблица ${i + 1} из ${total}`,
                    ((i + (res.table_done ? 1 : 0.55)) / total) * 100,
                    `«${table}» — ${tableRows.toLocaleString('ru-RU')} строк`
                );

                if (res.status) paintDbmonHa(res.status);
                if (res.table_done || rows === 0) break;
            }
        }

        updateDbmonSyncProgress('Готово', 100, `Скопировано таблиц: ${total}, строк: ${totalRows.toLocaleString('ru-RU')}`);
        showDbmonSyncResult(
            `Готово: ${total} таблиц, ${totalRows.toLocaleString('ru-RU')} строк (${srcLabel} → ${dstLabel}).`,
            false
        );
        window.HostJobs?.done('db-sync', `Готово: ${total} табл., ${totalRows.toLocaleString('ru-RU')} строк`);
        window.showToast?.('Копирование завершено', 'success');
        await loadDbmonHa();
    } catch (e) {
        if (dbSyncAbort || e.message === 'Отменено пользователем') {
            try {
                await dbHaRequest({ action: 'sync_abort', direction: directionUsed });
            } catch (_) { /* ignore */ }
            updateDbmonSyncProgress('Отменено', 0, '');
            showDbmonSyncResult('Копирование прервано.', true);
            window.HostJobs?.fail('db-sync', 'Отменено');
            window.showToast?.('Копирование отменено', 'info');
        } else {
            updateDbmonSyncProgress('Ошибка', 0, e.message);
            showDbmonSyncResult(e.message, true);
            window.HostJobs?.fail('db-sync', e.message);
            window.showToast?.(e.message, 'error');
        }
    } finally {
        setDbmonSyncUi(false);
        dbSyncAbort = false;
    }
}

if (haEls.card) {
    haEls.ping?.addEventListener('click', async () => {
        await loadDbmonHa();
        window.showToast?.('Проверка завершена', 'info');
    });
    haEls.failback?.addEventListener('click', async () => {
        if (!dbHaEditable || dbSyncRunning) return;
        try {
            const data = await dbHaRequest({ action: 'prefer_primary' });
            paintDbmonHa(data);
            window.showToast?.('Основная база активна', 'success');
        } catch (e) {
            window.showToast?.(e.message, 'error');
        }
    });
    haEls.toReplica?.addEventListener('click', () => runDbmonSync('to_replica'));
    haEls.toPrimary?.addEventListener('click', () => runDbmonSync('to_primary'));
    haEls.cancel?.addEventListener('click', () => {
        if (!dbSyncRunning) return;
        dbSyncAbort = true;
        const pct = parseFloat(String(document.getElementById('dbmon-sync-progress-pct')?.textContent || '0').replace('%', '')) || 0;
        updateDbmonSyncProgress('Отмена…', pct, 'Дождитесь завершения текущей порции');
    });
    setDbmonSyncUi(false);
    loadDbmonHa().catch(() => {});
}

// Без probe на открытии — иначе N×12с к недоступным БД → Gateway Timeout
load(false).catch((e) => {
    els.grid.innerHTML = `<div class="card"><p class="list-empty">${esc(e.message)}</p></div>`;
});
setInterval(() => load(false).catch(() => {}), 60000);
