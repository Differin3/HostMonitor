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
        alert('API ключ скопирован');
    }
}

