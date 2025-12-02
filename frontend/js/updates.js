// JavaScript для системы обновлений
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;

let updatesData = [];
let allNodes = [];
let toast = null;
let isInstalling = false; // Флаг для блокировки повторных нажатий
let isChecking = false; // Флаг для блокировки проверки обновлений

// Функция показа уведомлений в стиле nodes.js
const showToast = (message, type = 'info') => {
    if (!toast) {
        toast = document.getElementById('toast');
        if (!toast) return;
    }
    toast.textContent = message;
    toast.classList.remove('hidden');
    toast.dataset.type = type;
    
    // Добавляем иконку
    const iconMap = {
        success: 'check-circle',
        error: 'alert-circle',
        warning: 'alert-triangle',
        info: 'info'
    };
    
    const icon = iconMap[type] || 'info';
    toast.innerHTML = `<i data-lucide="${icon}"></i><span>${message}</span>`;
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
    
    setTimeout(() => {
        toast.classList.add('hidden');
    }, 3000);
};

async function fetchJson(path, options = {}) {
    try {
        const res = await fetch(`${API_URL}${path}`, { credentials: 'include', ...options });
        if (!res.ok) throw new Error(`API error ${res.status}`);
        const text = await res.text();
        return text ? JSON.parse(text) : {};
    } catch (error) {
        console.error('API недоступен:', error);
        throw error;
    }
}

async function loadNodes() {
    try {
        const result = await fetchJson('/nodes.php');
        allNodes = result.nodes || [];
        populateNodeFilter();
        return Promise.resolve();
    } catch (error) {
        console.error('Error loading nodes:', error);
        return Promise.reject(error);
    }
}

function populateNodeFilter() {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    
    const currentValue = select.value;
    select.innerHTML = '<option value="">Все ноды</option>';
    
    allNodes.forEach(node => {
        const option = document.createElement('option');
        option.value = node.id;
        option.textContent = `${node.name} (${node.status === 'online' ? 'онлайн' : 'офлайн'})`;
        if (node.id.toString() === currentValue) {
            option.selected = true;
        }
        select.appendChild(option);
    });
}

async function checkUpdates(silent = false) {
    // Блокируем повторные нажатия при ручной проверке
    if (!silent && isChecking) {
        showToast('Проверка обновлений уже выполняется...', 'warning');
        return;
    }
    
    try {
        const nodeId = document.getElementById('nodeFilter')?.value || '';
        
        // Блокируем кнопку проверки при ручной проверке
        if (!silent) {
            isChecking = true;
            const checkBtn = document.querySelector('button[onclick="checkUpdates()"]');
            if (checkBtn) {
                checkBtn.disabled = true;
                const originalContent = checkBtn.innerHTML;
                checkBtn.innerHTML = '<i data-lucide="loader-2" class="spinning"></i> Проверка...';
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
                checkBtn.dataset.originalContent = originalContent;
            }
            showToast('Проверка обновлений...', 'info');
        }
        
        const url = nodeId ? `/updates.php?action=check&node_id=${nodeId}` : '/updates.php?action=check';
        const result = await fetchJson(url, { method: 'POST' });
        
        if (result.success) {
            updatesData = result.updates || [];
            
            // Проверяем статус нод и предупреждаем об офлайн нодах
            const offlineNodesInUpdates = [];
            for (const update of updatesData) {
                const node = allNodes.find(n => n.id.toString() === update.node_id.toString());
                if (node && node.status !== 'online') {
                    if (!offlineNodesInUpdates.find(n => n.id === node.id)) {
                        offlineNodesInUpdates.push(node);
                    }
                }
            }
            
            if (offlineNodesInUpdates.length > 0 && !silent) {
                const nodeNames = offlineNodesInUpdates.map(n => n.name).join(', ');
                showToast(`Внимание: ${offlineNodesInUpdates.length} нод(а) офлайн (${nodeNames}). Обновления для них недоступны.`, 'warning');
            }
            
            applyFilters();
            updateStats(result);
            // Показываем уведомление только при ручной проверке (не silent)
            if (!silent) {
                if (updatesData.length > 0) {
                    const nodeName = nodeId ? allNodes.find(n => n.id.toString() === nodeId)?.name : '';
                    showToast(`Найдено обновлений: ${result.total_updates || 0}${nodeName ? ` для ${nodeName}` : ''}`, 'success');
                } else {
                    showToast('Обновления не найдены', 'info');
                }
            }
        } else {
            if (!silent) {
                showToast(result.error || 'Ошибка проверки обновлений', 'error');
            }
        }
    } catch (error) {
        console.error('Error checking updates:', error);
        if (!silent) {
            showToast('Ошибка проверки обновлений', 'error');
        }
    } finally {
        // Разблокируем кнопку проверки
        if (!silent) {
            isChecking = false;
            const checkBtn = document.querySelector('button[onclick="checkUpdates()"]');
            if (checkBtn && checkBtn.dataset.originalContent) {
                checkBtn.disabled = false;
                checkBtn.innerHTML = checkBtn.dataset.originalContent;
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }
        }
    }
}

function applyFilters() {
    const nodeFilter = document.getElementById('nodeFilter')?.value || '';
    const priorityFilter = document.getElementById('priorityFilter')?.value || '';
    const searchText = document.getElementById('updates-search')?.value.toLowerCase() || '';
    
    let filtered = updatesData;
    
    if (nodeFilter) {
        filtered = filtered.filter(u => u.node_id.toString() === nodeFilter);
    }
    
    if (priorityFilter) {
        filtered = filtered.filter(u => u.priority === priorityFilter);
    }
    
    if (searchText) {
        filtered = filtered.filter(u => 
            (u.package || '').toLowerCase().includes(searchText) ||
            (u.node_name || '').toLowerCase().includes(searchText)
        );
    }
    
    renderUpdates(filtered);
}

function updateStats(data) {
    const total = data.total_updates || 0;
    const security = data.security_updates || 0;
    const lastCheck = data.last_check || '-';
    
    document.getElementById('updates-count').textContent = total;
    document.getElementById('security-updates-count').textContent = security;
    document.getElementById('last-check').textContent = lastCheck;
}

function renderUpdates(updates) {
    const tbody = document.getElementById('updates-tbody');
    if (!tbody) return;
    
    if (updates.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center">Обновления не найдены</td></tr>';
        return;
    }
    
    tbody.innerHTML = updates.map((update, index) => {
        const priority = update.priority || 'normal';
        const priorityClass = priority === 'security' ? 'status-warning' : 'status-online';
        const priorityText = {
            'security': 'Критическое',
            'important': 'Важное',
            'normal': 'Обычное'
        }[priority] || priority;
        
        // Статус установки
        const installStatus = update.install_status || 'available';
        let statusHtml = '';
        let checkboxDisabled = false;
        
        // Проверяем статус ноды
        const node = allNodes.find(n => n.id.toString() === update.node_id.toString());
        const isNodeOffline = node && node.status !== 'online';
        
        const isUpdating = installStatus === 'pending' || installStatus === 'installing';
        const rowClass = isUpdating ? 'update-in-progress' : '';
        
        if (isNodeOffline && installStatus === 'available') {
            statusHtml = '<span class="status status-offline" title="Нода офлайн">Нода офлайн</span>';
            checkboxDisabled = true;
        } else if (installStatus === 'pending') {
            statusHtml = '<div class="progress-bar" style="width: 140px; height: 24px; margin: 0 auto; background: rgba(0, 0, 0, 0.2); border-radius: 4px; position: relative; overflow: hidden;"><div style="width: 30%; height: 100%; background: var(--warning); border-radius: 4px;"></div><span style="position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); font-size: 11px; font-weight: 600; color: var(--text-primary); z-index: 1; white-space: nowrap;">Ожидание...</span></div>';
            checkboxDisabled = true;
        } else if (installStatus === 'installing') {
            statusHtml = '<div class="progress-bar" style="width: 140px; height: 24px; margin: 0 auto; background: rgba(0, 0, 0, 0.2); border-radius: 4px; position: relative; overflow: hidden;"><div style="width: 60%; height: 100%; background: var(--info); border-radius: 4px; animation: pulse 1.5s ease-in-out infinite;"></div><span style="position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); font-size: 11px; font-weight: 600; color: var(--text-primary); z-index: 1; white-space: nowrap;">Установка...</span></div>';
            checkboxDisabled = true;
        } else if (installStatus === 'completed') {
            statusHtml = '<span class="status status-online">Установлено</span>';
            checkboxDisabled = true;
        } else {
            statusHtml = '<span class="status status-online">Доступно</span>';
        }
        
        return `
            <tr data-package="${update.package}" data-node-id="${update.node_id}" class="${rowClass}">
                <td><input type="checkbox" class="update-checkbox" value="${index}" onchange="updateSelection()" ${checkboxDisabled ? 'disabled' : ''}></td>
                <td><strong>${update.package || '-'}</strong></td>
                <td>${update.current_version || '-'}</td>
                <td>${update.new_version || '-'}</td>
                <td><span class="status pill ${priorityClass}">${priorityText}</span></td>
                <td>${update.node_name || '-'}</td>
                <td>${statusHtml}</td>
            </tr>
        `;
    }).join('');
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function updateSelection() {
    const checkboxes = document.querySelectorAll('.update-checkbox:checked');
    const installBtn = document.getElementById('install-btn');
    if (installBtn) {
        installBtn.disabled = checkboxes.length === 0;
    }
}

async function installUpdates() {
    // Блокируем повторные нажатия
    if (isInstalling) {
        showToast('Установка уже выполняется, пожалуйста подождите...', 'warning');
        return;
    }
    
    const checkboxes = document.querySelectorAll('.update-checkbox:checked');
    if (checkboxes.length === 0) return;
    
    const selected = Array.from(checkboxes).map(cb => {
        const index = parseInt(cb.value);
        return updatesData[index];
    });
    
    // Блокируем кнопки СРАЗУ при нажатии (до проверок и подтверждения)
    const installBtn = document.getElementById('install-btn');
    const checkBtn = document.querySelector('button[onclick="checkUpdates()"]');
    const originalInstallContent = installBtn?.innerHTML;
    const originalCheckContent = checkBtn?.innerHTML;
    
    // Сохраняем оригинальное состояние всех кнопок установки
    const buttonStates = new Map();
    document.querySelectorAll('button[onclick^="installSingleUpdate"]').forEach(btn => {
        const onclick = btn.getAttribute('onclick');
        const match = onclick.match(/installSingleUpdate\((\d+)\)/);
        if (match) {
            buttonStates.set(parseInt(match[1]), btn.innerHTML);
        }
    });
    
    // Блокируем все кнопки и чекбоксы сразу
    isInstalling = true;
    document.querySelectorAll('.update-checkbox').forEach(cb => cb.disabled = true);
    document.querySelectorAll('button[onclick^="installSingleUpdate"]').forEach(btn => {
        btn.disabled = true;
        const onclick = btn.getAttribute('onclick');
        const match = onclick.match(/installSingleUpdate\((\d+)\)/);
        if (match) {
            const idx = parseInt(match[1]);
            if (selected.find(u => updatesData[idx] && updatesData[idx].package === u.package)) {
                btn.innerHTML = '<i data-lucide="loader-2" class="spinning"></i>';
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
        }
    });
    
    if (installBtn) {
        installBtn.disabled = true;
        installBtn.innerHTML = '<i data-lucide="loader-2" class="spinning"></i> Отправка...';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    if (checkBtn) {
        checkBtn.disabled = true;
    }
    
    try {
        // Проверяем статус нод перед установкой
        const offlineNodes = [];
        const updatingNodes = [];
        for (const update of selected) {
            const node = allNodes.find(n => n.id.toString() === update.node_id.toString());
            if (node && node.status !== 'online') {
                if (!offlineNodes.find(n => n.id === node.id)) {
                    offlineNodes.push(node);
                }
            }
            // Проверяем что обновление не в процессе
            if (update.install_status === 'pending' || update.install_status === 'installing') {
                if (!updatingNodes.find(n => n.package === update.package)) {
                    updatingNodes.push(update);
                }
            }
        }
        
        if (offlineNodes.length > 0) {
            const nodeNames = offlineNodes.map(n => n.name).join(', ');
            await window.showConfirm(
                `Невозможно установить обновления: ${offlineNodes.length} нод(а) офлайн (${nodeNames}).\n\nОбновления для офлайн нод недоступны.`,
                'Ноды офлайн',
                'warning'
            );
            // Разблокируем кнопки
            isInstalling = false;
            if (installBtn && originalInstallContent) {
                installBtn.disabled = false;
                installBtn.innerHTML = originalInstallContent;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
            if (checkBtn && originalCheckContent) {
                checkBtn.disabled = false;
                checkBtn.innerHTML = originalCheckContent;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
            document.querySelectorAll('.update-checkbox').forEach(cb => cb.disabled = false);
            document.querySelectorAll('button[onclick^="installSingleUpdate"]').forEach(btn => {
                btn.disabled = false;
                const onclick = btn.getAttribute('onclick');
                const match = onclick.match(/installSingleUpdate\((\d+)\)/);
                if (match && buttonStates.has(parseInt(match[1]))) {
                    btn.innerHTML = buttonStates.get(parseInt(match[1]));
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }
            });
            return;
        }
        
        if (updatingNodes.length > 0) {
            const packageNames = updatingNodes.map(u => u.package).join(', ');
            showToast(`Некоторые обновления уже в процессе установки: ${packageNames}`, 'warning');
            // Разблокируем кнопки
            isInstalling = false;
            if (installBtn && originalInstallContent) {
                installBtn.disabled = false;
                installBtn.innerHTML = originalInstallContent;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
            if (checkBtn && originalCheckContent) {
                checkBtn.disabled = false;
                checkBtn.innerHTML = originalCheckContent;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
            document.querySelectorAll('.update-checkbox').forEach(cb => cb.disabled = false);
            document.querySelectorAll('button[onclick^="installSingleUpdate"]').forEach(btn => {
                btn.disabled = false;
                const onclick = btn.getAttribute('onclick');
                const match = onclick.match(/installSingleUpdate\((\d+)\)/);
                if (match && buttonStates.has(parseInt(match[1]))) {
                    btn.innerHTML = buttonStates.get(parseInt(match[1]));
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }
            });
            return;
        }
        
        const confirmed = await window.showConfirm(`Установить ${selected.length} обновлений?`, 'Установка обновлений', 'info');
        if (!confirmed) {
            // Если отменили, разблокируем кнопки
            isInstalling = false;
            if (installBtn && originalInstallContent) {
                installBtn.disabled = false;
                installBtn.innerHTML = originalInstallContent;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
            if (checkBtn && originalCheckContent) {
                checkBtn.disabled = false;
                checkBtn.innerHTML = originalCheckContent;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
            document.querySelectorAll('.update-checkbox').forEach(cb => cb.disabled = false);
            document.querySelectorAll('button[onclick^="installSingleUpdate"]').forEach(btn => {
                btn.disabled = false;
                const onclick = btn.getAttribute('onclick');
                const match = onclick.match(/installSingleUpdate\((\d+)\)/);
                if (match && buttonStates.has(parseInt(match[1]))) {
                    btn.innerHTML = buttonStates.get(parseInt(match[1]));
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }
            });
            return;
        }
    
        // После подтверждения убеждаемся, что кнопки остаются заблокированными с анимацией
        if (installBtn) {
            installBtn.disabled = true;
            installBtn.innerHTML = '<i data-lucide="loader-2" class="spinning"></i> Отправка...';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        document.querySelectorAll('button[onclick^="installSingleUpdate"]').forEach(btn => {
            const onclick = btn.getAttribute('onclick');
            const match = onclick.match(/installSingleUpdate\((\d+)\)/);
            if (match) {
                const idx = parseInt(match[1]);
                if (selected.find(u => updatesData[idx] && updatesData[idx].package === u.package)) {
                    btn.disabled = true;
                    btn.innerHTML = '<i data-lucide="loader-2" class="spinning"></i>';
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }
            }
        });
    
        showToast('Отправка команды установки...', 'info');
        const result = await fetchJson('/updates.php?action=install', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ updates: selected })
        });
        
        if (result.success) {
            const queued = result.queued || result.installed || 0;
            if (result.errors && result.errors.length > 0) {
                showToast(`Поставлено в очередь: ${queued}, ошибок: ${result.errors.length}`, 'warning');
            } else {
                showToast(`Команда отправлена: ${queued} обновлений поставлено в очередь`, 'success');
            }
            // Обновляем список сразу чтобы показать статус "pending"
            await checkUpdates(true); // silent обновление
            loadHistory();
            // Автообновление статуса установки каждые 2 секунды в течение 2 минут
            let refreshCount = 0;
            const statusInterval = setInterval(() => {
                checkUpdates(true); // silent обновление
                loadHistory();
                refreshCount++;
                if (refreshCount >= 60) { // 60 * 2 = 120 секунд
                    clearInterval(statusInterval);
                }
            }, 2000);
        } else {
            showToast(result.error || 'Ошибка установки', 'error');
        }
    } catch (error) {
        console.error('Error installing updates:', error);
        showToast('Ошибка установки обновлений', 'error');
    } finally {
        // Снимаем флаг блокировки
        isInstalling = false;
        
        // Разблокируем кнопки
        if (installBtn && originalInstallContent) {
            installBtn.disabled = false;
            installBtn.innerHTML = originalInstallContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        if (checkBtn && originalCheckContent) {
            checkBtn.disabled = false;
            checkBtn.innerHTML = originalCheckContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        
        // Разблокируем чекбоксы (кроме тех что в процессе обновления)
        document.querySelectorAll('.update-checkbox').forEach(cb => {
            const index = parseInt(cb.value);
            const update = updatesData[index];
            if (update && update.install_status !== 'pending' && update.install_status !== 'installing' && update.install_status !== 'completed') {
                cb.disabled = false;
            }
        });
        
        // Разблокируем кнопки установки (кроме тех что в процессе)
        document.querySelectorAll('button[onclick^="installSingleUpdate"]').forEach(btn => {
            const onclick = btn.getAttribute('onclick');
            const match = onclick.match(/installSingleUpdate\((\d+)\)/);
            if (match) {
                const index = parseInt(match[1]);
                const update = updatesData[index];
                if (update && update.install_status !== 'pending' && update.install_status !== 'installing' && update.install_status !== 'completed') {
                    btn.disabled = false;
                    // Восстанавливаем оригинальную иконку
                    if (buttonStates.has(index)) {
                        btn.innerHTML = buttonStates.get(index);
                        if (typeof lucide !== 'undefined') lucide.createIcons();
                    }
                }
            }
        });
    }
}

async function installSingleUpdate(index) {
    // Блокируем повторные нажатия
    if (isInstalling) {
        showToast('Установка уже выполняется, пожалуйста подождите...', 'warning');
        return;
    }
    
    const update = updatesData[index];
    if (!update) return;
    
    // Блокируем кнопку СРАЗУ при нажатии (до проверок и подтверждения)
    const button = event?.target?.closest('button') || document.querySelector(`button[onclick="installSingleUpdate(${index})"]`);
    const originalContent = button?.innerHTML;
    const installBtn = document.getElementById('install-btn');
    const originalInstallContent = installBtn?.innerHTML;
    const checkBtn = document.querySelector('button[onclick="checkUpdates()"]');
    const originalCheckContent = checkBtn?.innerHTML;
    
    // Блокируем все кнопки и чекбоксы сразу
    isInstalling = true;
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i data-lucide="loader-2" class="spinning"></i>';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    
    document.querySelectorAll('.update-checkbox').forEach(cb => cb.disabled = true);
    document.querySelectorAll('button[onclick^="installSingleUpdate"]').forEach(btn => {
        if (btn !== button) {
            btn.disabled = true;
        }
    });
    if (installBtn) {
        installBtn.disabled = true;
        installBtn.innerHTML = '<i data-lucide="loader-2" class="spinning"></i> Отправка...';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    if (checkBtn) {
        checkBtn.disabled = true;
    }
    
    // Проверяем что обновление не в процессе
    if (update.install_status === 'pending' || update.install_status === 'installing') {
        showToast(`Обновление ${update.package} уже в процессе установки`, 'warning');
        // Разблокируем кнопки
        isInstalling = false;
        if (button && originalContent) {
            button.disabled = false;
            button.innerHTML = originalContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        if (installBtn && originalInstallContent) {
            installBtn.disabled = false;
            installBtn.innerHTML = originalInstallContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        if (checkBtn && originalCheckContent) {
            checkBtn.disabled = false;
            checkBtn.innerHTML = originalCheckContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        document.querySelectorAll('.update-checkbox').forEach(cb => cb.disabled = false);
        document.querySelectorAll('button[onclick^="installSingleUpdate"]').forEach(btn => {
            if (btn !== button) btn.disabled = false;
        });
        return;
    }
    
    // Проверяем статус ноды
    const node = allNodes.find(n => n.id.toString() === update.node_id.toString());
    if (node && node.status !== 'online') {
        await window.showConfirm(
            `Невозможно установить обновление: нода ${node.name} офлайн.\n\nОбновления для офлайн нод недоступны.`,
            'Нода офлайн',
            'warning'
        );
        // Разблокируем кнопки
        isInstalling = false;
        if (button && originalContent) {
            button.disabled = false;
            button.innerHTML = originalContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        if (installBtn && originalInstallContent) {
            installBtn.disabled = false;
            installBtn.innerHTML = originalInstallContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        if (checkBtn && originalCheckContent) {
            checkBtn.disabled = false;
            checkBtn.innerHTML = originalCheckContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        document.querySelectorAll('.update-checkbox').forEach(cb => cb.disabled = false);
        document.querySelectorAll('button[onclick^="installSingleUpdate"]').forEach(btn => {
            if (btn !== button) btn.disabled = false;
        });
        return;
    }
    
    const confirmed = await window.showConfirm(`Установить обновление ${update.package}?`, 'Установка обновления', 'info');
    if (!confirmed) {
        // Если отменили, разблокируем кнопки
        isInstalling = false;
        if (button && originalContent) {
            button.disabled = false;
            button.innerHTML = originalContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        if (installBtn && originalInstallContent) {
            installBtn.disabled = false;
            installBtn.innerHTML = originalInstallContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        if (checkBtn && originalCheckContent) {
            checkBtn.disabled = false;
            checkBtn.innerHTML = originalCheckContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        document.querySelectorAll('.update-checkbox').forEach(cb => cb.disabled = false);
        document.querySelectorAll('button[onclick^="installSingleUpdate"]').forEach(btn => {
            if (btn !== button) btn.disabled = false;
        });
        return;
    }

    // После подтверждения убеждаемся, что кнопка остается заблокированной с анимацией
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i data-lucide="loader-2" class="spinning"></i>';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    if (installBtn) {
        installBtn.disabled = true;
        installBtn.innerHTML = '<i data-lucide="loader-2" class="spinning"></i> Отправка...';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    try {
        showToast('Отправка команды установки...', 'info');
            const result = await fetchJson('/updates.php?action=install', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ updates: [update] })
            });
            
            if (result.success) {
                showToast('Команда отправлена. Обновление поставлено в очередь.', 'success');
                // Обновляем список сразу чтобы показать статус "pending"
                await checkUpdates(true); // silent обновление
                loadHistory();
                // Автообновление статуса установки каждые 2 секунды в течение 2 минут
                let refreshCount = 0;
            const statusInterval = setInterval(() => {
                checkUpdates(true); // silent обновление
                loadHistory();
                refreshCount++;
                if (refreshCount >= 60) { // 60 * 2 = 120 секунд
                    clearInterval(statusInterval);
                }
            }, 2000);
        } else {
            showToast(result.error || 'Ошибка установки', 'error');
        }
    } catch (error) {
        console.error('Error installing update:', error);
        showToast('Ошибка установки обновления', 'error');
    } finally {
        // Снимаем флаг блокировки
        isInstalling = false;
        
        // Разблокируем кнопку
        if (button && originalContent) {
            button.disabled = false;
            button.innerHTML = originalContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        
        // Разблокируем кнопку установки выбранных
        if (installBtn && originalInstallContent) {
            installBtn.disabled = false;
            installBtn.innerHTML = originalInstallContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        
        // Разблокируем кнопку проверки
        if (checkBtn && originalCheckContent) {
            checkBtn.disabled = false;
            checkBtn.innerHTML = originalCheckContent;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        
        // Разблокируем чекбоксы (кроме тех что в процессе обновления)
        document.querySelectorAll('.update-checkbox').forEach(cb => {
            const idx = parseInt(cb.value);
            const upd = updatesData[idx];
            if (upd && upd.install_status !== 'pending' && upd.install_status !== 'installing' && upd.install_status !== 'completed') {
                cb.disabled = false;
            }
        });
        
        // Разблокируем кнопки установки (кроме тех что в процессе)
        document.querySelectorAll('button[onclick^="installSingleUpdate"]').forEach(btn => {
            const onclick = btn.getAttribute('onclick');
            const match = onclick.match(/installSingleUpdate\((\d+)\)/);
            if (match) {
                const idx = parseInt(match[1]);
                const upd = updatesData[idx];
                if (upd && upd.install_status !== 'pending' && upd.install_status !== 'installing' && upd.install_status !== 'completed') {
                    btn.disabled = false;
                }
            }
        });
        
        // Обновляем состояние кнопки "Установить выбранные"
        if (installBtn) {
            const hasSelected = document.querySelectorAll('.update-checkbox:checked').length > 0;
            installBtn.disabled = !hasSelected;
        }
    }
}

async function loadHistory() {
    try {
        const nodeId = document.getElementById('history-node-filter')?.value || '';
        const status = document.getElementById('history-status-filter')?.value || '';
        
        let url = '/updates.php?action=history';
        if (nodeId) url += `&node_id=${nodeId}`;
        if (status) url += `&status=${status}`;
        
        const result = await fetchJson(url);
        renderHistory(result.history || [], result.stats || {});
    } catch (error) {
        console.error('Error loading history:', error);
        showToast('Ошибка загрузки истории', 'error');
    }
}

function renderHistory(history, stats) {
    const tbody = document.getElementById('updates-history-tbody');
    if (!tbody) return;
    
    if (history.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">История пуста</td></tr>';
        return;
    }
    
    tbody.innerHTML = history.map(item => {
        const resultClass = item.success ? 'status-online' : 'status-offline';
        const resultText = item.success ? 'Успешно' : 'Ошибка';
        const message = item.message || '';
        const messageDisplay = message.length > 50 ? message.substring(0, 50) + '...' : message;
        
        return `
            <tr>
                <td>${item.timestamp ? new Date(item.timestamp).toLocaleString('ru-RU') : '-'}</td>
                <td><strong>${item.node_name || '-'}</strong></td>
                <td><strong>${item.package || '-'}</strong></td>
                <td>${item.version || '-'}</td>
                <td><span class="status ${resultClass}">${resultText}</span></td>
                <td title="${message}">${messageDisplay || '-'}</td>
            </tr>
        `;
    }).join('');
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function populateHistoryNodeFilter() {
    const select = document.getElementById('history-node-filter');
    if (!select) return;
    
    const currentValue = select.value;
    select.innerHTML = '<option value="">Все ноды</option>';
    
    allNodes.forEach(node => {
        const option = document.createElement('option');
        option.value = node.id;
        option.textContent = `${node.name} (${node.status === 'online' ? 'онлайн' : 'офлайн'})`;
        if (node.id.toString() === currentValue) {
            option.selected = true;
        }
        select.appendChild(option);
    });
}

window.checkUpdates = checkUpdates;
window.installUpdates = installUpdates;
window.installSingleUpdate = installSingleUpdate;

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('select-all-updates')?.addEventListener('change', (e) => {
        const checkboxes = document.querySelectorAll('.update-checkbox');
        checkboxes.forEach(cb => cb.checked = e.target.checked);
        updateSelection();
    });
    
    // Фильтры обновлений
    document.getElementById('nodeFilter')?.addEventListener('change', () => {
        applyFilters();
        checkUpdates(true); // silent при смене фильтра
    });
    
    document.getElementById('priorityFilter')?.addEventListener('change', applyFilters);
    
    document.getElementById('updates-search')?.addEventListener('input', applyFilters);
    
    // Фильтры истории
    document.getElementById('history-node-filter')?.addEventListener('change', loadHistory);
    document.getElementById('history-status-filter')?.addEventListener('change', loadHistory);
    
    loadNodes().then(() => {
        populateHistoryNodeFilter();
        loadHistory();
    });
    checkUpdates(true); // Автоматически загружаем обновления при открытии страницы (silent)
});

