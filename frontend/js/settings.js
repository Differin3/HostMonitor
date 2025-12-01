// JavaScript для страницы настроек
document.addEventListener('DOMContentLoaded', () => {
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const tabId = btn.dataset.tab;
            
            // Убираем активный класс у всех
            tabButtons.forEach(b => b.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            
            // Добавляем активный класс выбранным
            btn.classList.add('active');
            document.getElementById(`${tabId}-tab`).classList.add('active');
        });
    });
    
    // Загрузка сохраненных настроек
    loadSavedSettings();
    
    // Обработчики для чекбоксов
    const checkboxes = document.querySelectorAll('#notifications-tab input[type="checkbox"], #security-tab input[type="checkbox"]');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            saveSettings();
        });
    });
    
    // Обработчик для кнопки сохранения
    const saveBtn = document.querySelector('.settings-actions .primary');
    if (saveBtn) {
        saveBtn.addEventListener('click', () => {
            saveAllSettings();
        });
    }
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});

function loadSavedSettings() {
    // Загрузка состояния чекбоксов из localStorage
    const emailNotifications = localStorage.getItem('settings-email-notifications') !== 'false';
    const telegramNotifications = localStorage.getItem('settings-telegram-notifications') === 'true';
    const twoFactorAuth = localStorage.getItem('settings-two-factor') !== 'false';
    
    const emailCheckbox = document.querySelector('#notifications-tab input[type="checkbox"]');
    const telegramCheckbox = document.querySelectorAll('#notifications-tab input[type="checkbox"]')[1];
    const twoFactorCheckbox = document.querySelector('#security-tab input[type="checkbox"]');
    
    if (emailCheckbox) emailCheckbox.checked = emailNotifications;
    if (telegramCheckbox) telegramCheckbox.checked = telegramNotifications;
    if (twoFactorCheckbox) twoFactorCheckbox.checked = twoFactorAuth;
}

function saveSettings() {
    // Сохранение состояния чекбоксов в localStorage
    const emailCheckbox = document.querySelector('#notifications-tab input[type="checkbox"]');
    const telegramCheckbox = document.querySelectorAll('#notifications-tab input[type="checkbox"]')[1];
    const twoFactorCheckbox = document.querySelector('#security-tab input[type="checkbox"]');
    
    if (emailCheckbox) {
        localStorage.setItem('settings-email-notifications', emailCheckbox.checked);
    }
    if (telegramCheckbox) {
        localStorage.setItem('settings-telegram-notifications', telegramCheckbox.checked);
    }
    if (twoFactorCheckbox) {
        localStorage.setItem('settings-two-factor', twoFactorCheckbox.checked);
    }
}

function saveAllSettings() {
    saveSettings();
    
    // Сохранение других настроек
    const systemName = document.querySelector('#general-tab input[type="text"]')?.value;
    const timezone = document.querySelector('#general-tab select')?.value;
    const language = document.querySelectorAll('#general-tab select')[1]?.value;
    const sessionTimeout = document.querySelector('#security-tab input[type="number"]')?.value;
    const apiRateLimit = document.querySelector('#api-tab input[type="number"]')?.value;
    
    if (systemName) localStorage.setItem('settings-system-name', systemName);
    if (timezone) localStorage.setItem('settings-timezone', timezone);
    if (language) localStorage.setItem('settings-language', language);
    if (sessionTimeout) localStorage.setItem('settings-session-timeout', sessionTimeout);
    if (apiRateLimit) localStorage.setItem('settings-api-rate-limit', apiRateLimit);
    
    if (window.showToast) {
        window.showToast('Настройки сохранены', 'success');
    } else {
        showToast('Настройки сохранены', 'success');
    }
}

function copyApiKey() {
    const input = document.querySelector('#api-tab input[type="text"]');
    if (input) {
        input.select();
        document.execCommand('copy');
        showToast('API ключ скопирован', 'success'); // красивое уведомление вместо alert
    }
}

function showToast(message, type = 'info') {
    if (window.showToast) {
        window.showToast(message, type);
    } else {
        // Fallback если window.showToast еще не загружен
        let toast = document.getElementById('toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'toast';
            toast.className = 'toast hidden';
            document.body.appendChild(toast);
        }
        const iconMap = { success: 'check-circle', error: 'alert-circle', warning: 'alert-triangle', info: 'info' };
        const icon = iconMap[type] || 'info';
        toast.dataset.type = type;
        toast.innerHTML = `<i data-lucide="${icon}"></i><span>${message}</span>`;
        toast.classList.remove('hidden');
        if (typeof lucide !== 'undefined') { lucide.createIcons(); }
        setTimeout(() => toast.classList.add('hidden'), 3000);
    }
}

window.copyApiKey = copyApiKey;

