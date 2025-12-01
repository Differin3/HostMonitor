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
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});

function copyApiKey() {
    const input = document.querySelector('#api-tab input[type="text"]');
    if (input) {
        input.select();
        document.execCommand('copy');
        showToast('API ключ скопирован', 'success'); // красивое уведомление вместо alert
    }
}

function showToast(message, type = 'info') {
    // Унифицированный toast (та же разметка, что и на других страницах)
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

