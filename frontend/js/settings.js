const API_BASE = window.MONITORING_API_BASE || '/api';
const SETTINGS_API = `${API_BASE}/settings.php`;

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

    loadSettings();

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
    if (tabId === 'database' && typeof window.loadDbHa === 'function') {
        window.loadDbHa(true);
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
