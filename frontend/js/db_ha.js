(function () {
    if (window.__HOSTMONITOR_DB_HA_INIT) return;

    const API_BASE = window.MONITORING_API_BASE || '/api';
    const DB_HA_API = `${API_BASE}/db_ha.php`;

    let dbHaLoaded = false;
    let dbHaEditable = true;

    const val = (id) => document.getElementById(id);

    const toast = (msg, type = 'info') => {
        if (window.showToast) window.showToast(msg, type);
    };

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

    function setDbHaEditable(editable) {
        dbHaEditable = !!editable;
        const body = val('db-ha-body');
        const banner = val('db-ha-locked-banner');
        if (banner) banner.classList.toggle('hidden', dbHaEditable);
        if (body) {
            body.classList.toggle('is-locked', !dbHaEditable);
            body.querySelectorAll('input, textarea, select, button').forEach((el) => {
                if (el.id === 'db-ha-ping') {
                    el.disabled = false;
                    return;
                }
                el.disabled = !dbHaEditable;
            });
        }
        toggleReplicaFields();
        toggleSslCaBlock();
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

    function paintDbHaStatus(data) {
        const ping = data.ping || {};
        setHaPill(
            'ha-pill-active',
            'Активная',
            data.active_role === 'replica' ? 'warn' : true,
            data.active_role === 'replica' ? 'резерв' : 'основная'
        );
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
        const on = !!enabled.checked;
        fields.classList.toggle('hidden', !on);
        fields.querySelectorAll('input, textarea, button').forEach((input) => {
            if (!on) {
                input.disabled = true;
                return;
            }
            input.disabled = !dbHaEditable;
        });
        toggleSslCaBlock();
    }

    function toggleSslCaBlock() {
        const enabled = val('db-replica-enabled');
        const verify = val('db-replica-ssl-verify');
        const block = val('db-replica-ssl-ca-block');
        if (!block) return;
        const on = !!enabled?.checked && !!verify?.checked;
        block.classList.toggle('hidden', !on);
        block.querySelectorAll('input, textarea, button').forEach((el) => {
            el.disabled = !on || !dbHaEditable;
        });
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
        if (!val('db-ha-panel')) return;
        if (dbHaLoaded && !force) return;
        try {
            const data = await dbHaRequest(null);
            dbHaLoaded = true;
            fillDbHaForm(data);
        } catch (e) {
            dbHaLog(e.message, true);
            toast(e.message, 'error');
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
            toast('Выберите файл .pem или вставьте сертификат', 'warning');
            return;
        }
        try {
            const data = await dbHaRequest({ action: 'upload_ssl_ca', pem });
            fillDbHaForm(data);
            if (textarea) textarea.value = '';
            if (fileInput) fileInput.value = '';
            dbHaLog(data.message || 'CA сохранён', false);
            toast(data.message || 'CA-сертификат сохранён', 'success');
        } catch (e) {
            dbHaLog(e.message, true);
            toast(e.message, 'error');
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
            toast(data.message || 'CA-сертификат удалён', 'success');
        } catch (e) {
            dbHaLog(e.message, true);
            toast(e.message, 'error');
        }
    }

    function initDbHa() {
        if (!val('db-ha-panel')) return;
        window.__HOSTMONITOR_DB_HA_INIT = true;

        val('db-replica-enabled')?.addEventListener('change', () => {
            toggleReplicaFields();
            toggleSslCaBlock();
        });
        val('db-replica-ssl-verify')?.addEventListener('change', toggleSslCaBlock);

        val('db-ha-save')?.addEventListener('click', async () => {
            if (!dbHaEditable) return;
            try {
                const data = await dbHaRequest(collectDbHaPayload());
                fillDbHaForm(data);
                if (val('db-password')) val('db-password').value = '';
                if (val('db-replica-password')) val('db-replica-password').value = '';
                dbHaLog('Подключения сохранены.', false);
                toast('Подключения к БД сохранены', 'success');
            } catch (e) {
                dbHaLog(e.message, true);
                toast(e.message, 'error');
            }
        });

        val('db-ha-ping')?.addEventListener('click', async () => {
            dbHaLoaded = false;
            await loadDbHa(true);
            toast('Проверка завершена', 'info');
        });

        val('db-ha-failback')?.addEventListener('click', async () => {
            if (!dbHaEditable) return;
            try {
                const data = await dbHaRequest({ action: 'prefer_primary' });
                fillDbHaForm(data);
                dbHaLog('Панель снова на основной базе.', false);
                toast('Основная база активна', 'success');
            } catch (e) {
                dbHaLog(e.message, true);
                toast(e.message, 'error');
            }
        });

        const sync = async (direction, label) => {
            if (!dbHaEditable) return;
            if (!confirm(`${label}. Таблицы на приёмнике будут перезаписаны. Продолжить?`)) return;
            dbHaLog('Копирование… это может занять несколько минут.', false);
            try {
                const data = await dbHaRequest({ action: 'sync', direction });
                if (data.status) fillDbHaForm(data.status);
                const copied = data.copied || {};
                dbHaLog(`Готово: таблиц ${copied.table_count ?? 0}, строк ${copied.row_count ?? 0}.`, false);
                toast('Синхронизация завершена', 'success');
            } catch (e) {
                dbHaLog(e.message, true);
                toast(e.message, 'error');
            }
        };

        val('db-ha-to-replica')?.addEventListener('click', () => sync('to_replica', 'Скопировать основную базу на резерв'));
        val('db-ha-to-primary')?.addEventListener('click', () => sync('to_primary', 'Скопировать резервную базу на основную'));
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
        loadDbHa(true);
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    window.initDbHa = initDbHa;
    window.loadDbHa = loadDbHa;

    document.addEventListener('DOMContentLoaded', initDbHa);
})();
