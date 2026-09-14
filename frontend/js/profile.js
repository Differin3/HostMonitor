// Управление профилем
document.addEventListener('DOMContentLoaded', () => {
    // Загрузка сохранённых настроек
    loadSavedSettings();
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});

function loadSavedSettings() {
    // Загрузка темы
    const savedTheme = localStorage.getItem('theme') || 'dark';
    const themeSelect = document.getElementById('theme-select');
    if (themeSelect) {
        themeSelect.value = savedTheme;
    }
    
    // Загрузка уведомлений
    const notifyEmail = localStorage.getItem('notify-email') !== 'false';
    const notifyNodes = localStorage.getItem('notify-nodes') !== 'false';
    const notifyBilling = localStorage.getItem('notify-billing') === 'true';
    const notifyAlerts = localStorage.getItem('notify-alerts') !== 'false';
    
    const setEl = (id, fn) => {
        const el = document.getElementById(id);
        if (el) fn(el);
    };

    setEl('notify-email', (el) => { el.checked = notifyEmail; });
    setEl('notify-nodes', (el) => { el.checked = notifyNodes; });
    setEl('notify-billing', (el) => { el.checked = notifyBilling; });
    setEl('notify-alerts', (el) => { el.checked = notifyAlerts; });
    
    // Загрузка других настроек
    const autoRefresh = localStorage.getItem('auto-refresh') !== 'false';
    const refreshInterval = localStorage.getItem('refresh-interval') || '30';
    
    setEl('auto-refresh', (el) => { el.checked = autoRefresh; });
    setEl('refresh-interval', (el) => { el.value = refreshInterval; });
}

window.saveProfile = saveProfile;
function saveProfile() {
    const newPassword = document.getElementById('new-password').value;
    const confirmPassword = document.getElementById('confirm-password').value;
    
    // Проверяем пароли только если хотя бы один указан
    if (newPassword || confirmPassword) {
        if (!newPassword || !confirmPassword) {
            showToast('Заполните оба поля пароля', 'warning'); // единый стиль уведомлений
            return;
        }
        if (newPassword !== confirmPassword) {
            showToast('Пароли не совпадают', 'error'); // ошибка пароля
            return;
        }
    }
    
    // Сохранение всех настроек
    const settings = {
        'notify-email': document.getElementById('notify-email')?.checked ?? false,
        'notify-nodes': document.getElementById('notify-nodes')?.checked ?? false,
        'notify-billing': document.getElementById('notify-billing')?.checked ?? false,
        'notify-alerts': document.getElementById('notify-alerts')?.checked ?? false,
        'auto-refresh': document.getElementById('auto-refresh')?.checked ?? false,
        'refresh-interval': document.getElementById('refresh-interval')?.value ?? '30'
    };
    
    Object.keys(settings).forEach(key => {
        localStorage.setItem(key, settings[key]);
    });
    
    const theme = document.getElementById('theme-select')?.value ?? 'light';
    localStorage.setItem('theme', theme);
    
    // Применение темы
    if (theme === 'dark') {
        document.body.classList.add('dark');
    } else if (theme === 'light') {
        document.body.classList.remove('dark');
    }
    
    // TODO: API call для сохранения профиля
    showToast('Профиль сохранён', 'success'); // успешное сохранение
}

const showToast = (message, type = 'info') => {
    if (typeof window.showToast === 'function') window.showToast(message, type);
};


// ——— Двухфакторная аутентификация (TOTP) ———
const TOTP_API = (window.MONITORING_API_BASE || '/api') + '/totp.php';
let totpPendingSecret = '';

async function totpLoadStatus() {
    const statusEl = document.getElementById('totp-status');
    const actionsEl = document.getElementById('totp-actions');
    const setupEl = document.getElementById('totp-setup');
    if (!statusEl || !actionsEl || !setupEl) return;
    try {
        const res = await fetch(TOTP_API + '?action=status', { credentials: 'include' });
        const data = await res.json();
        if (data.enabled) {
            statusEl.innerHTML = '<span class="status status-online">включена</span>';
            actionsEl.innerHTML = '<button type="button" class="btn-outline" id="totp-disable-btn"><i data-lucide="shield-off"></i> Отключить 2FA</button>';
            document.getElementById('totp-disable-btn').onclick = totpDisable;
            setupEl.classList.add('hidden');
        } else {
            statusEl.innerHTML = '<span class="status status-offline">выключена</span>';
            actionsEl.innerHTML = '<button type="button" class="primary" id="totp-enable-btn"><i data-lucide="shield-check"></i> Включить 2FA</button>';
            document.getElementById('totp-enable-btn').onclick = totpSetup;
            setupEl.classList.add('hidden');
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    } catch (e) {
        statusEl.textContent = 'Не удалось загрузить статус 2FA';
    }
}

async function totpSetup() {
    try {
        const res = await fetch(TOTP_API + '?action=setup', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: '{}',
        });
        const data = await res.json();
        if (!res.ok) { window.showToast?.(data.error || 'Ошибка', 'error'); return; }
        totpPendingSecret = data.secret;
        document.getElementById('totp-secret').textContent = data.secret;
        document.getElementById('totp-confirm-code').value = '';
        document.getElementById('totp-setup').classList.remove('hidden');
        document.getElementById('totp-actions').innerHTML = '';
        const canvas = document.getElementById('totp-qr');
        if (canvas && window.QRCode) {
            QRCode.toCanvas(canvas, data.otpauth, { width: 180 }, (err) => { if (err) console.error(err); });
        } else if (canvas) {
            canvas.style.display = 'none';
            document.getElementById('totp-setup').insertAdjacentHTML('afterbegin', '<p style="color:var(--text-muted);">Не удалось загрузить генератор QR-кода — введите ключ вручную.</p>');
        }
    } catch (e) {
        window.showToast?.(e.message, 'error');
    }
}

async function totpConfirm() {
    const code = document.getElementById('totp-confirm-code').value.trim();
    if (!/^\d{6}$/.test(code)) { window.showToast?.('Введите 6-значный код', 'warning'); return; }
    try {
        const res = await fetch(TOTP_API + '?action=confirm', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code }),
        });
        const data = await res.json();
        if (!res.ok) { window.showToast?.(data.error || 'Неверный код', 'error'); return; }
        window.showToast?.('Двухфакторная аутентификация включена', 'success');
        totpLoadStatus();
    } catch (e) {
        window.showToast?.(e.message, 'error');
    }
}

async function totpDisable() {
    const ok = await window.showConfirm?.('Отключить двухфакторную аутентификацию?', '2FA', 'danger');
    if (!ok) return;
    try {
        const res = await fetch(TOTP_API + '?action=disable', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: '{}',
        });
        const data = await res.json();
        if (!res.ok) { window.showToast?.(data.error || 'Ошибка', 'error'); return; }
        window.showToast?.('Двухфакторная аутентификация отключена', 'success');
        totpLoadStatus();
    } catch (e) {
        window.showToast?.(e.message, 'error');
    }
}

document.getElementById('totp-confirm-btn')?.addEventListener('click', totpConfirm);
document.getElementById('totp-cancel-btn')?.addEventListener('click', totpLoadStatus);
document.addEventListener('DOMContentLoaded', totpLoadStatus);
