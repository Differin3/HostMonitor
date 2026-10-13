const AUTH_API = (window.MONITORING_API_BASE || '/api') + '/auth_logs.php';

const AUTH_TYPE_LABELS = {
    login: 'Вход',
    failed: 'Неудачный вход',
    login_2fa: '2FA (вход)',
    totp_enable: '2FA включена',
    totp_disable: '2FA отключена',
    totp_recovery_regen: 'Recovery-коды',
    session_revoke: 'Сессия завершена',
    session_revoke_others: 'Завершены др. сессии',
    session_revoke_all: 'Выйти везде',
    trusted_revoke_all: 'Отозваны устройства',
};

const authState = { limit: 100, offset: 0, total: 0 };

function authFilterParams() {
    const p = new URLSearchParams();
    const type = document.getElementById('auth-type')?.value || '';
    const result = document.getElementById('auth-result')?.value || '';
    const days = document.getElementById('auth-days')?.value || '';
    const q = (document.getElementById('auth-search')?.value || '').trim();
    if (type) p.set('type', type);
    if (result) p.set('result', result);
    if (days) p.set('days', days);
    if (q) p.set('q', q);
    return p;
}

function authQuery() {
    const p = authFilterParams();
    p.set('limit', String(authState.limit));
    p.set('offset', String(authState.offset));
    return p.toString();
}

async function authLoad() {
    const tbody = document.getElementById('auth-tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="text-muted">Загрузка…</td></tr>';
    try {
        const res = await fetch(AUTH_API + '?' + authQuery(), { credentials: 'include' });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Ошибка');

        const s = data.stats || {};
        document.getElementById('auth-count').textContent = s.total ?? 0;
        document.getElementById('auth-ok').textContent = s.ok ?? 0;
        document.getElementById('auth-fail').textContent = s.failed ?? 0;
        document.getElementById('auth-2fa').textContent = s.twofa ?? 0;
        document.getElementById('auth-ips').textContent = s.unique_ips ?? 0;

        const susp = document.getElementById('auth-suspicious');
        const suspList = document.getElementById('auth-suspicious-list');
        if ((data.suspicious || []).length) {
            susp.classList.remove('hidden');
            suspList.innerHTML = data.suspicious.map((x) => `
                <div style="display:flex; gap:10px; align-items:center; padding:6px 0;">
                    <span class="status status-offline">${authEscape(x.ip_address)}</span>
                    <span>${Number(x.fails)} неудачных попыток за сутки · последняя ${authEscape(x.last)}</span>
                </div>`).join('');
        } else {
            susp.classList.add('hidden');
        }

        const rows = data.logs || [];
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-muted">Нет событий</td></tr>';
        } else {
            tbody.innerHTML = rows.map((r) => `
                <tr>
                    <td style="white-space:nowrap;">${authEscape(r.timestamp)}</td>
                    <td>${authEscape(r.username || '—')}</td>
                    <td>${authEscape(r.ip_address || '—')}</td>
                    <td>${authEscape(AUTH_TYPE_LABELS[r.event_type] || r.event_type)}</td>
                    <td>${Number(r.success) === 1 ? '<span class="status status-online">ok</span>' : '<span class="status status-offline">fail</span>'}</td>
                    <td class="text-muted">${authEscape(r.message || '')}</td>
                </tr>`).join('');
        }

        authState.total = Number(data.total) || 0;
        authRenderPager();
        if (typeof lucide !== 'undefined') lucide.createIcons();
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-muted">Ошибка: ${authEscape(e.message)}</td></tr>`;
    }
}

function authRenderPager() {
    const pager = document.getElementById('auth-pager');
    if (!pager) return;
    const from = authState.total ? authState.offset + 1 : 0;
    const to = Math.min(authState.offset + authState.limit, authState.total);
    pager.innerHTML = `
        <button class="btn-outline" id="auth-prev" ${authState.offset <= 0 ? 'disabled' : ''}>← Назад</button>
        <span style="color:var(--text-muted); font-size:13px;">${from}–${to} из ${authState.total}</span>
        <button class="btn-outline" id="auth-next" ${to >= authState.total ? 'disabled' : ''}>Вперёд →</button>`;
    document.getElementById('auth-prev')?.addEventListener('click', () => {
        authState.offset = Math.max(0, authState.offset - authState.limit);
        authLoad();
    });
    document.getElementById('auth-next')?.addEventListener('click', () => {
        authState.offset += authState.limit;
        authLoad();
    });
}

function authDownloadText(filename, content) {
    const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

async function authExport() {
    const params = authFilterParams();
    params.set('limit', '500');
    const all = [];
    let offset = 0;
    let total = 0;
    try {
        window.showToast?.('Экспорт событий…', 'info');
        for (let guard = 0; guard < 100; guard++) {
            params.set('offset', String(offset));
            const res = await fetch(AUTH_API + '?' + params.toString(), { credentials: 'include' });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Ошибка');
            const rows = data.logs || [];
            all.push(...rows);
            total = Number(data.total) || 0;
            offset += rows.length;
            if (!rows.length || rows.length < 500 || all.length >= total) break;
        }
        if (!all.length) {
            window.showToast?.('Нет событий для экспорта', 'info');
            return;
        }
        let content = `# Безопасность — журнал входов\n# ${new Date().toLocaleString('ru-RU')}\n# Записей: ${all.length}\n\n`;
        all.forEach((r) => {
            const ok = Number(r.success) === 1 ? 'OK' : 'FAIL';
            content += `[${r.timestamp}] [${AUTH_TYPE_LABELS[r.event_type] || r.event_type || '-'}] [${ok}] ${r.username || '-'}@${r.ip_address || '-'} ${r.message || ''}\n`;
        });
        authDownloadText(`auth-logs-${new Date().toISOString().split('T')[0]}.txt`, content);
        window.showToast?.(`Экспортировано ${all.length} записей`, 'success');
    } catch (e) {
        window.showToast?.('Ошибка экспорта: ' + e.message, 'error');
    }
}

['auth-type', 'auth-result', 'auth-days'].forEach((id) => {
    document.getElementById(id)?.addEventListener('change', () => {
        authState.offset = 0;
        authLoad();
    });
});

document.getElementById('auth-search')?.addEventListener('input', () => {
    authState.offset = 0;
    clearTimeout(window.__authT);
    window.__authT = setTimeout(authLoad, 300);
});

document.getElementById('auth-refresh')?.addEventListener('click', () => {
    authState.offset = 0;
    authLoad();
});

document.getElementById('auth-export')?.addEventListener('click', authExport);

authLoad();
