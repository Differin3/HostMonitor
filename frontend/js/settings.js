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
        loadDbHa();
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

function fillDbHaForm(data) {
    const primary = data.primary || {};
    const replica = data.replica || {};
    const setv = (id, value) => {
        const el = val(id);
        if (el) el.value = value ?? '';
    };
    setv('db-host', primary.host);
    setv('db-port', primary.port);
    setv('db-name', primary.name);
    setv('db-user', primary.user);
    setv('db-replica-host', replica.host);
    setv('db-replica-port', replica.port || '3306');
    setv('db-replica-name', replica.name);
    setv('db-replica-user', replica.user);
    const enabled = val('db-replica-enabled');
    const failback = val('db-replica-failback');
    if (enabled) enabled.checked = !!data.replica_enabled;
    if (failback) failback.checked = data.replica_failback !== false;
    toggleReplicaFields();
    paintDbHaStatus(data);
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
}

function toggleReplicaFields() {
    const enabled = val('db-replica-enabled');
    const fields = val('db-replica-fields');
    if (!fields || !enabled) return;
    fields.style.opacity = enabled.checked ? '1' : '0.45';
    fields.querySelectorAll('input').forEach((input) => {
        input.disabled = !enabled.checked;
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
        },
    };
}

function initDbHa() {
    const enabled = val('db-replica-enabled');
    if (!enabled) return;
    enabled.addEventListener('change', toggleReplicaFields);
    val('db-ha-save')?.addEventListener('click', async () => {
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
    const sync = async (direction, label) => {
        if (!confirm(`${label}. Таблицы на приёмнике будут перезаписаны. Продолжить?`)) return;
        dbHaLog('Копирование… это может занять несколько минут.', false);
        try {
            const data = await dbHaRequest({ action: 'sync', direction });
            if (data.status) fillDbHaForm(data.status);
            const copied = data.copied || {};
            dbHaLog(`Готово: таблиц ${copied.table_count ?? 0}, строк ${copied.row_count ?? 0}.`, false);
            showToast('Синхронизация завершена', 'success');
        } catch (e) {
            dbHaLog(e.message, true);
            showToast(e.message, 'error');
        }
    };
    val('db-ha-to-replica')?.addEventListener('click', () => sync('to_replica', 'Скопировать основную базу на резерв'));
    val('db-ha-to-primary')?.addEventListener('click', () => sync('to_primary', 'Скопировать резервную базу на основную'));
    toggleReplicaFields();
}
