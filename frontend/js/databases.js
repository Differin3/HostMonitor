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
    if (!confirm('Удалить CA-сертификат для этой базы?')) return;
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
            if (!confirm('Убрать эту базу из мониторинга?')) return;
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

load(true).catch((e) => {
    els.grid.innerHTML = `<div class="card"><p class="list-empty">${esc(e.message)}</p></div>`;
});
setInterval(() => load(false).catch(() => {}), 60000);
