const API_BASE = window.MONITORING_API_BASE || '/api';
const SETTINGS_API = `${API_BASE}/settings.php`;
const DB_HA_API = `${API_BASE}/db_ha.php`;

const TEXT_FIELDS = {
    system_name: 'system_name',
    web_host: 'web_host',
    web_port: 'web_port',
    public_url: 'public_url',
    timezone: 'timezone',
    language: 'language',
    'collect-interval': 'collect_interval',
    upnp_interval_cycles: 'upnp_interval_cycles',
    upnp_mx: 'upnp_mx',
    upnp_timeout: 'upnp_timeout',
    upnp_gena_port: 'upnp_gena_port',
    'log-retention-days': 'log_retention_days',
    'metrics-retention-days': 'metrics_retention_days',
    'alerts-retention-days': 'alerts_retention_days',
    'logs-per-page': 'logs_per_page',
    'log-max-rows': 'log_max_rows',
    'log-max-rows-per-node': 'log_max_rows_per_node',
    notify_email: 'notify_email',
    smtp_host: 'smtp_host',
    smtp_port: 'smtp_port',
    smtp_user: 'smtp_user',
    smtp_from: 'smtp_from',
    telegram_chat_id: 'telegram_chat_id',
    min_password_length: 'min_password_length',
    session_timeout_minutes: 'session_timeout_minutes',
    api_rate_limit: 'api_rate_limit',
};

const CHECK_FIELDS = {
    upnp_enabled: 'upnp_enabled',
    notify_email_enabled: 'notify_email_enabled',
    notify_telegram_enabled: 'notify_telegram_enabled',
};

let dbHaLoaded = false;
let dbHaEditable = true;
let dbSyncRunning = false;
let dbSyncAbort = false;
let lastSettings = {};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.settings-tabs .tab-btn').forEach((btn) => {
        btn.addEventListener('click', () => activateSettingsTab(btn.dataset.tab));
    });

    const hashTab = (location.hash || '').replace('#', '');
    if (hashTab && document.getElementById(`${hashTab}-tab`)) {
        activateSettingsTab(hashTab);
    }

    document.getElementById('collect-interval')?.addEventListener('input', updateCollectIntervalWarning);
    document.getElementById('settings-save')?.addEventListener('click', saveAllSettings);
    document.getElementById('settings-cancel')?.addEventListener('click', () => {
        fillSettingsForm(lastSettings);
        showToast('Изменения сброшены', 'info');
    });
    document.getElementById('api-base-copy')?.addEventListener('click', () => {
        copyText(document.getElementById('api_base_url')?.value || '', 'URL API скопирован');
    });
    document.getElementById('upnp-env-copy')?.addEventListener('click', () => {
        copyText(buildUpnpEnv(), 'Переменные UPnP скопированы');
    });
    ['upnp_enabled', 'upnp_interval_cycles', 'upnp_mx', 'upnp_timeout', 'upnp_gena_port'].forEach((id) => {
        document.getElementById(id)?.addEventListener('input', paintUpnpEnv);
        document.getElementById(id)?.addEventListener('change', paintUpnpEnv);
    });

    const apiBase = document.getElementById('api_base_url');
    if (apiBase) {
        apiBase.value = `${location.origin}${API_BASE}`;
    }

    initDbHa();

    document.getElementById('cleanup-history-now')?.addEventListener('click', async () => {
        const log = document.getElementById('cleanup-history-log');
        if (log) {
            log.classList.remove('hidden');
            log.textContent = 'Удаление старых записей…';
            log.classList.remove('is-error');
        }
        try {
            const response = await fetch(SETTINGS_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'cleanup_history' }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
            const deleted = data.result?.deleted || {};
            const lines = Object.entries(deleted)
                .filter(([, n]) => Number(n) > 0)
                .map(([table, n]) => `${table}: ${n}`);
            const text = lines.length
                ? `Удалено ${data.result.total} строк.\n${lines.join('\n')}`
                : 'Старых записей нет — база уже в пределах сроков.';
            if (log) log.textContent = text;
            showToast('Очистка завершена', 'success');
        } catch (e) {
            if (log) {
                log.textContent = e.message;
                log.classList.add('is-error');
            }
            showToast(e.message, 'error');
        }
    });
    loadSettingsFromServer();
    paintUpnpEnv();

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});

function activateSettingsTab(tabId) {
    document.querySelectorAll('.settings-tabs .tab-btn').forEach((b) => {
        b.classList.toggle('active', b.dataset.tab === tabId);
    });
    document.querySelectorAll('.settings-content .tab-content').forEach((c) => {
        c.classList.toggle('active', c.id === `${tabId}-tab`);
    });
    const footer = document.getElementById('settings-footer');
    if (footer) {
        footer.classList.toggle('hidden', tabId === 'database');
    }
    if (tabId === 'database') {
        loadDbHa(true);
    }
    const url = tabId === 'general' ? 'settings.php' : `settings.php#${tabId}`;
    history.replaceState(null, '', url);
}

function val(id) {
    return document.getElementById(id);
}

function fillSettingsForm(settings) {
    lastSettings = settings || {};
    Object.entries(TEXT_FIELDS).forEach(([id, key]) => {
        const el = val(id);
        if (!el || settings[key] === undefined || settings[key] === null) return;
        el.value = settings[key];
    });
    Object.entries(CHECK_FIELDS).forEach(([id, key]) => {
        const el = val(id);
        if (!el) return;
        const raw = String(settings[key] ?? '');
        el.checked = raw === '1' || raw === 'true';
    });
    const smtpPass = val('smtp_password');
    const tgToken = val('telegram_bot_token');
    if (smtpPass) {
        smtpPass.value = '';
        smtpPass.placeholder = settings.has_smtp_password ? 'Сохранён, оставьте пустым' : 'Оставьте пустым, чтобы не менять';
    }
    if (tgToken) {
        tgToken.value = '';
        tgToken.placeholder = settings.has_telegram_bot_token ? 'Сохранён, оставьте пустым' : 'Оставьте пустым, чтобы не менять';
    }
    updateCollectIntervalWarning();
    paintUpnpEnv();
}

async function loadSettingsFromServer() {
    try {
        const response = await fetch(SETTINGS_API, { credentials: 'include' });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
        fillSettingsForm(data.settings || {});
    } catch (error) {
        console.error('Error loading settings from server:', error);
        showToast('Не удалось загрузить настройки', 'error');
    }
}

function collectPanelSettings() {
    const settings = {};
    Object.entries(TEXT_FIELDS).forEach(([id, key]) => {
        const el = val(id);
        if (el) settings[key] = el.value;
    });
    Object.entries(CHECK_FIELDS).forEach(([id, key]) => {
        const el = val(id);
        if (!el) return;
        settings[key] = el.checked ? (key === 'upnp_enabled' ? 'true' : '1') : (key === 'upnp_enabled' ? 'false' : '0');
    });
    const smtpPass = val('smtp_password')?.value || '';
    const tgToken = val('telegram_bot_token')?.value || '';
    if (smtpPass) settings.smtp_password = smtpPass;
    if (tgToken) settings.telegram_bot_token = tgToken;
    return settings;
}

async function saveAllSettings() {
    try {
        const response = await fetch(SETTINGS_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ settings: collectPanelSettings() }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
        fillSettingsForm(data.settings || collectPanelSettings());
        showToast('Настройки сохранены', 'success');
    } catch (error) {
        showToast(error.message || 'Ошибка сохранения', 'error');
    }
}

function buildUpnpEnv() {
    const on = val('upnp_enabled')?.checked ? 'true' : 'false';
    const cycles = val('upnp_interval_cycles')?.value || '2';
    const mx = val('upnp_mx')?.value || '3';
    const timeout = val('upnp_timeout')?.value || '8';
    const port = val('upnp_gena_port')?.value || '0';
    return [
        `UPNP_ENABLED=${on}`,
        `UPNP_INTERVAL_CYCLES=${cycles}`,
        `UPNP_MX=${mx}`,
        `UPNP_TIMEOUT=${timeout}`,
        `UPNP_GENA_PORT=${port}`,
    ].join('\n');
}

function paintUpnpEnv() {
    const el = val('upnp-env-preview');
    if (el) el.textContent = buildUpnpEnv();
}

async function copyText(text, okMessage) {
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
        } else {
            const tmp = document.createElement('textarea');
            tmp.value = text;
            document.body.appendChild(tmp);
            tmp.select();
            document.execCommand('copy');
            tmp.remove();
        }
        showToast(okMessage, 'success');
    } catch (e) {
        showToast('Не удалось скопировать', 'error');
    }
}

const showToast = (message, type = 'info') => {
    if (typeof window.showToast === 'function') window.showToast(message, type);
};

function updateCollectIntervalWarning() {
    const input = val('collect-interval');
    const warning = val('collect-interval-warning');
    if (!input || !warning) return;
    const interval = parseInt(input.value, 10) || 60;
    const recordsPerNodePerSecond = Math.round((537 * (60 / interval)) / 60);
    let message = '';
    let level = 'info';
    if (interval <= 15) {
        level = 'warning';
        message = `Высокая нагрузка: ~${recordsPerNodePerSecond} записей/сек на ноду. Рекомендуется до 25–30 нод.`;
    } else if (interval <= 30) {
        level = 'warning';
        message = `Средняя нагрузка: ~${recordsPerNodePerSecond} записей/сек на ноду. Рекомендуется до 50–75 нод.`;
    }
    warning.classList.toggle('hidden', !message);
    warning.classList.toggle('is-warning', level === 'warning');
    warning.textContent = message;
}

function dbHaLog(message, isError) {
    const log = val('db-ha-log');
    if (!log) return;
    log.classList.remove('hidden');
    log.textContent = message;
    log.classList.toggle('is-error', !!isError);
}

function setHaPill(id, label, ok, extra) {
    const el = val(id);
    if (!el) return;
    el.textContent = extra ? `${label}: ${extra}` : label;
    el.classList.remove('ok', 'fail', 'warn');
    if (ok === true) el.classList.add('ok');
    if (ok === false) el.classList.add('fail');
    if (ok === 'warn') el.classList.add('warn');
}

function setDbSyncUi(running) {
    dbSyncRunning = !!running;
    const actions = val('db-sync-actions');
    const progress = val('db-sync-progress');
    if (actions) actions.classList.toggle('hidden', dbSyncRunning);
    if (progress) progress.classList.toggle('hidden', !dbSyncRunning);
    document.querySelectorAll('.db-sync-card').forEach((btn) => {
        btn.disabled = !dbHaEditable || dbSyncRunning;
    });
    const cancelBtn = val('db-sync-cancel');
    if (cancelBtn) cancelBtn.disabled = !dbSyncRunning;
    if (!dbSyncRunning && window.lucide) lucide.createIcons();
}

function updateDbSyncProgress(label, pct, detail) {
    const labelEl = val('db-sync-progress-label');
    const pctEl = val('db-sync-progress-pct');
    const bar = val('db-sync-progress-bar');
    const detailEl = val('db-sync-progress-detail');
    if (labelEl) labelEl.textContent = label;
    if (pctEl) pctEl.textContent = `${Math.round(pct)}%`;
    if (bar) bar.style.width = `${Math.min(100, Math.max(0, pct))}%`;
    if (detailEl) detailEl.textContent = detail || '';
}

function showDbSyncResult(message, isError) {
    const box = val('db-sync-result');
    if (!box) return;
    box.classList.remove('hidden', 'is-ok', 'is-error');
    box.classList.add(isError ? 'is-error' : 'is-ok');
    box.textContent = message;
}

function hideDbSyncResult() {
    const box = val('db-sync-result');
    if (box) box.classList.add('hidden');
}

async function runDbSync(direction) {
    if (!dbHaEditable || dbSyncRunning) return;

    const titles = {
        to_replica: 'Сделать резервную базу актуальной',
        to_primary: 'Вернуть данные на основную базу',
    };
    const confirmed = window.showConfirm
        ? await window.showConfirm(
            `${titles[direction] || 'Копирование'}.\n\nВсе таблицы на приёмнике будут удалены и созданы заново из источника. Это может занять несколько минут.\n\nПродолжить?`,
            'Копирование базы',
            'warning'
        )
        : confirm('Таблицы на приёмнике будут перезаписаны. Продолжить?');
    if (!confirmed) return;

    dbSyncAbort = false;
    hideDbSyncResult();
    setDbSyncUi(true);
    updateDbSyncProgress('Подготовка…', 0, 'Проверка соединений и списка таблиц');

    let directionUsed = direction;
    let totalRows = 0;
    const tableStats = [];

    try {
        const prep = await dbHaRequest({ action: 'sync_prepare', direction });
        directionUsed = prep.direction || direction;
        const tables = prep.tables || [];
        const total = tables.length;
        if (!total) {
            throw new Error('В источнике нет таблиц для копирования');
        }

        const srcLabel = prep.source_label || 'источник';
        const dstLabel = prep.target_label || 'приёмник';
        const srcName = prep.source_name ? ` (${prep.source_name})` : '';
        const dstName = prep.target_name ? ` (${prep.target_name})` : '';
        updateDbSyncProgress(
            'Копирование…',
            0,
            `${srcLabel}${srcName} → ${dstLabel}${dstName} · таблиц: ${total}`
        );

        for (let i = 0; i < total; i++) {
            if (dbSyncAbort) {
                throw new Error('Отменено пользователем');
            }
            const table = tables[i];
            const pctBefore = (i / total) * 100;
            updateDbSyncProgress(
                `Таблица ${i + 1} из ${total}`,
                pctBefore,
                `Копируется «${table}»…`
            );

            const res = await dbHaRequest({
                action: 'sync_table',
                direction: directionUsed,
                table,
                index: i,
                total,
            });

            const rows = Number(res.rows) || 0;
            totalRows += rows;
            tableStats.push({ table, rows });

            const pctAfter = ((i + 1) / total) * 100;
            updateDbSyncProgress(
                i + 1 >= total ? 'Завершение…' : `Таблица ${i + 1} из ${total}`,
                pctAfter,
                `«${table}» — ${rows.toLocaleString('ru-RU')} строк`
            );

            if (res.done && res.status) {
                fillDbHaForm(res.status);
            }
        }

        updateDbSyncProgress('Готово', 100, `Скопировано таблиц: ${tableStats.length}, строк: ${totalRows.toLocaleString('ru-RU')}`);
        const summary = `Готово: ${tableStats.length} таблиц, ${totalRows.toLocaleString('ru-RU')} строк (${srcLabel} → ${dstLabel}).`;
        showDbSyncResult(summary, false);
        dbHaLog(summary, false);
        showToast('Копирование завершено', 'success');
    } catch (e) {
        if (dbSyncAbort || e.message === 'Отменено пользователем') {
            try {
                await dbHaRequest({ action: 'sync_abort', direction: directionUsed });
            } catch (abortErr) {
                // ignore
            }
            updateDbSyncProgress('Отменено', 0, '');
            showDbSyncResult('Копирование прервано.', true);
            dbHaLog('Копирование прервано.', true);
            showToast('Копирование отменено', 'info');
        } else {
            updateDbSyncProgress('Ошибка', 0, e.message);
            showDbSyncResult(e.message, true);
            dbHaLog(e.message, true);
            showToast(e.message, 'error');
        }
    } finally {
        setDbSyncUi(false);
        dbSyncAbort = false;
    }
}

function setDbHaEditable(editable) {
    dbHaEditable = !!editable;
    const banner = val('db-ha-locked-banner');
    if (banner) banner.classList.toggle('hidden', dbHaEditable);
    const section = val('database-tab')?.querySelector('.settings-section');
    if (!section) return;
    section.querySelectorAll('input, textarea, select, button').forEach((el) => {
        if (el.id === 'db-ha-ping' || el.id === 'db-sync-cancel') {
            if (el.id === 'db-sync-cancel') {
                el.disabled = !dbSyncRunning;
            } else {
                el.disabled = false;
                el.readOnly = false;
            }
            return;
        }
        if (el.classList.contains('db-sync-card')) {
            el.disabled = !dbHaEditable || dbSyncRunning;
            return;
        }
        const textLike = el.tagName === 'TEXTAREA'
            || (el.tagName === 'INPUT' && !['checkbox', 'file', 'button', 'submit', 'reset'].includes(el.type));
        if (textLike) {
            el.readOnly = !dbHaEditable;
            el.disabled = false;
        } else {
            el.disabled = !dbHaEditable;
        }
    });
    if (dbHaEditable) {
        toggleReplicaFields();
        toggleSslCaBlock();
    }
    setDbSyncUi(dbSyncRunning);
}

function paintSslCaStatus(replica) {
    const statusEl = val('db-replica-ssl-ca-status');
    const removeBtn = val('db-replica-ssl-ca-remove');
    if (!statusEl) return;
    if (replica?.has_ssl_ca) {
        const parts = ['CA установлен'];
        if (replica.ssl_ca) parts.push(`(${replica.ssl_ca})`);
        if (replica.ssl_ca_subject) parts.push(`— ${replica.ssl_ca_subject}`);
        if (replica.ssl_ca_cert_count > 1) parts.push(`[${replica.ssl_ca_cert_count} серт.]`);
        statusEl.textContent = parts.join(' ');
        statusEl.classList.add('ok-hint');
    } else {
        statusEl.textContent = 'CA не загружен — при «Проверять сертификат» используется системный bundle или загрузите свой PEM';
        statusEl.classList.remove('ok-hint');
    }
    if (removeBtn) removeBtn.disabled = !dbHaEditable || !replica?.has_ssl_ca;
}

function fillDbHaForm(data) {
    const primary = data.primary || {};
    const replica = data.replica || {};
    const setv = (id, value) => {
        const el = val(id);
        if (!el) return;
        const ro = el.readOnly;
        el.readOnly = false;
        el.value = value != null && value !== '' ? String(value) : '';
        if (ro) el.readOnly = true;
    };
    setv('db-host', primary.host);
    setv('db-port', primary.port || '3306');
    setv('db-name', primary.name);
    setv('db-user', primary.user);
    setv('db-replica-host', replica.host);
    setv('db-replica-port', replica.port || '3306');
    setv('db-replica-name', replica.name);
    setv('db-replica-user', replica.user);

    const passPrimary = val('db-password');
    if (passPrimary) {
        passPrimary.value = '';
        passPrimary.placeholder = primary.has_password
            ? 'Сохранён, оставьте пустым'
            : 'Оставьте пустым, чтобы не менять';
    }
    const passReplica = val('db-replica-password');
    if (passReplica) {
        passReplica.value = '';
        passReplica.placeholder = replica.has_password
            ? 'Сохранён, оставьте пустым'
            : 'Пусто — не менять / как у основной';
    }

    const sslEl = val('db-replica-ssl');
    const sslVerifyEl = val('db-replica-ssl-verify');
    if (sslEl) sslEl.checked = !!replica.ssl;
    if (sslVerifyEl) sslVerifyEl.checked = !!replica.ssl_verify;
    paintSslCaStatus(replica);

    const enabled = val('db-replica-enabled');
    const failback = val('db-replica-failback');
    if (enabled) enabled.checked = !!data.replica_enabled;
    if (failback) failback.checked = data.replica_failback !== false;
    paintDbHaStatus(data);
    toggleReplicaFields();
    toggleSslCaBlock();
}

function paintDbHaStatus(data) {
    const ping = data.ping || {};
    setHaPill('ha-pill-active', 'Активная', data.active_role === 'replica' ? 'warn' : true, data.active_role === 'replica' ? 'резерв' : 'основная');
    const pOk = ping.primary ? ping.primary.ok : null;
    const rOk = ping.replica ? ping.replica.ok : null;
    setHaPill('ha-pill-primary', 'Основная', pOk, pOk ? `${ping.primary.ms} мс` : (ping.primary?.error || 'нет ответа'));
    if (!data.replica_enabled) {
        setHaPill('ha-pill-replica', 'Резерв', false, 'выключен');
    } else {
        setHaPill('ha-pill-replica', 'Резерв', rOk, rOk ? `${ping.replica.ms} мс` : (ping.replica?.error || 'нет ответа'));
    }
    setDbHaEditable(data.editable !== false);
}

function toggleReplicaFields() {
    const enabled = val('db-replica-enabled');
    const fields = val('db-replica-fields');
    if (!fields || !enabled) return;
    const on = enabled.checked && dbHaEditable;
    fields.style.opacity = on ? '1' : '0.45';
    fields.querySelectorAll('input, textarea, button').forEach((input) => {
        input.disabled = !on;
    });
    toggleSslCaBlock();
}

function toggleSslCaBlock() {
    const enabled = val('db-replica-enabled');
    const verify = val('db-replica-ssl-verify');
    const block = val('db-replica-ssl-ca-block');
    if (!block) return;
    const on = !!enabled?.checked && !!verify?.checked && dbHaEditable;
    block.style.display = on ? '' : 'none';
    block.querySelectorAll('input, textarea, button').forEach((el) => {
        el.disabled = !on;
    });
}

async function dbHaRequest(payload) {
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
    if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
    return data;
}

async function loadDbHa(force) {
    if (!val('db-host')) return;
    if (dbHaLoaded && !force) return;
    try {
        const data = await dbHaRequest(null);
        dbHaLoaded = true;
        fillDbHaForm(data);
    } catch (e) {
        dbHaLog(e.message, true);
        showToast(e.message, 'error');
    }
}

function collectDbHaPayload() {
    return {
        action: 'save',
        host: val('db-host')?.value.trim() || '',
        port: val('db-port')?.value.trim() || '3306',
        name: val('db-name')?.value.trim() || '',
        user: val('db-user')?.value.trim() || '',
        password: val('db-password')?.value || '',
        replica_enabled: !!val('db-replica-enabled')?.checked,
        replica_failback: !!val('db-replica-failback')?.checked,
        replica: {
            host: val('db-replica-host')?.value.trim() || '',
            port: val('db-replica-port')?.value.trim() || '3306',
            name: val('db-replica-name')?.value.trim() || '',
            user: val('db-replica-user')?.value.trim() || '',
            password: val('db-replica-password')?.value || '',
            ssl: !!val('db-replica-ssl')?.checked,
            ssl_verify: !!val('db-replica-ssl-verify')?.checked,
        },
    };
}

async function uploadSslCa() {
    if (!dbHaEditable) return;
    const fileInput = val('db-replica-ssl-ca-file');
    const textarea = val('db-replica-ssl-ca-pem');
    let pem = textarea?.value.trim() || '';
    const file = fileInput?.files?.[0];
    if (file) pem = await file.text();
    if (!pem) {
        showToast('Выберите файл .pem или вставьте сертификат', 'warning');
        return;
    }
    try {
        const data = await dbHaRequest({ action: 'upload_ssl_ca', pem });
        fillDbHaForm(data);
        if (textarea) textarea.value = '';
        if (fileInput) fileInput.value = '';
        dbHaLog(data.message || 'CA сохранён', false);
        showToast(data.message || 'CA-сертификат сохранён', 'success');
    } catch (e) {
        dbHaLog(e.message, true);
        showToast(e.message, 'error');
    }
}

async function removeSslCa() {
    if (!dbHaEditable) return;
    const confirmed = window.showConfirm
        ? await window.showConfirm('Удалить загруженный CA-сертификат?', 'CA-сертификат', 'warning')
        : confirm('Удалить CA-сертификат?');
    if (!confirmed) return;
    try {
        const data = await dbHaRequest({ action: 'remove_ssl_ca' });
        fillDbHaForm(data);
        if (val('db-replica-ssl-ca-pem')) val('db-replica-ssl-ca-pem').value = '';
        dbHaLog(data.message || 'CA удалён', false);
        showToast(data.message || 'CA-сертификат удалён', 'success');
    } catch (e) {
        dbHaLog(e.message, true);
        showToast(e.message, 'error');
    }
}

function initDbHa() {
    if (!val('db-host')) return;

    val('db-replica-enabled')?.addEventListener('change', toggleReplicaFields);
    val('db-replica-ssl-verify')?.addEventListener('change', toggleSslCaBlock);

    val('db-ha-save')?.addEventListener('click', async () => {
        if (!dbHaEditable) return;
        try {
            const data = await dbHaRequest(collectDbHaPayload());
            fillDbHaForm(data);
            if (val('db-password')) val('db-password').value = '';
            if (val('db-replica-password')) val('db-replica-password').value = '';
            dbHaLog('Подключения сохранены.', false);
            showToast('Подключения к БД сохранены', 'success');
        } catch (e) {
            dbHaLog(e.message, true);
            showToast(e.message, 'error');
        }
    });

    val('db-ha-ping')?.addEventListener('click', async () => {
        dbHaLoaded = false;
        await loadDbHa(true);
        showToast('Проверка завершена', 'info');
    });

    val('db-ha-failback')?.addEventListener('click', async () => {
        if (!dbHaEditable) return;
        try {
            const data = await dbHaRequest({ action: 'prefer_primary' });
            fillDbHaForm(data);
            dbHaLog('Панель снова на основной базе.', false);
            showToast('Основная база активна', 'success');
        } catch (e) {
            dbHaLog(e.message, true);
            showToast(e.message, 'error');
        }
    });

    val('db-ha-to-replica')?.addEventListener('click', () => runDbSync('to_replica'));
    val('db-ha-to-primary')?.addEventListener('click', () => runDbSync('to_primary'));
    val('db-sync-cancel')?.addEventListener('click', () => {
        if (!dbSyncRunning) return;
        dbSyncAbort = true;
        const pct = parseFloat(String(val('db-sync-progress-pct')?.textContent || '0').replace('%', '')) || 0;
        updateDbSyncProgress('Отмена…', pct, 'Дождитесь завершения текущей таблицы');
    });
    val('db-replica-ssl-ca-upload')?.addEventListener('click', uploadSslCa);
    val('db-replica-ssl-ca-remove')?.addEventListener('click', removeSslCa);
    val('db-replica-ssl-ca-file')?.addEventListener('change', async (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        const textarea = val('db-replica-ssl-ca-pem');
        if (textarea) textarea.value = await file.text();
    });

    toggleReplicaFields();
    toggleSslCaBlock();
    setDbSyncUi(false);
    loadDbHa(true);
}
