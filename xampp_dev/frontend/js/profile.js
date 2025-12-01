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
    
    document.getElementById('notify-email').checked = notifyEmail;
    document.getElementById('notify-nodes').checked = notifyNodes;
    document.getElementById('notify-billing').checked = notifyBilling;
    document.getElementById('notify-alerts').checked = notifyAlerts;
    
    // Загрузка других настроек
    const autoRefresh = localStorage.getItem('auto-refresh') !== 'false';
    const refreshInterval = localStorage.getItem('refresh-interval') || '30';
    
    document.getElementById('auto-refresh').checked = autoRefresh;
    document.getElementById('refresh-interval').value = refreshInterval;
}

function saveProfile() {
    const newPassword = document.getElementById('new-password').value;
    const confirmPassword = document.getElementById('confirm-password').value;
    
    // Проверяем пароли только если хотя бы один указан
    if (newPassword || confirmPassword) {
        if (!newPassword || !confirmPassword) {
            alert('Заполните оба поля пароля');
            return;
        }
        if (newPassword !== confirmPassword) {
            alert('Пароли не совпадают');
            return;
        }
    }
    
    // Сохранение всех настроек
    const settings = {
        'notify-email': document.getElementById('notify-email').checked,
        'notify-nodes': document.getElementById('notify-nodes').checked,
        'notify-billing': document.getElementById('notify-billing').checked,
        'notify-alerts': document.getElementById('notify-alerts').checked,
        'auto-refresh': document.getElementById('auto-refresh').checked,
        'refresh-interval': document.getElementById('refresh-interval').value
    };
    
    Object.keys(settings).forEach(key => {
        localStorage.setItem(key, settings[key]);
    });
    
    const theme = document.getElementById('theme-select').value;
    localStorage.setItem('theme', theme);
    
    // Применение темы
    if (theme === 'dark') {
        document.body.classList.add('dark');
    } else if (theme === 'light') {
        document.body.classList.remove('dark');
    }
    
    // TODO: API call для сохранения профиля
    alert('Профиль сохранён');
}

