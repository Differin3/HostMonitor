// JavaScript для системы обновлений
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;

let updatesData = [];
let checkPollTimer = null;
let allNodes = [];
let toast = null;
let isInstalling = false; // Флаг для блокировки повторных нажатий
let isChecking = false; // Флаг для блокировки проверки обновлений
/** Выбранные пакеты: ключ `${node_id}::${package}` — не сбрасывается при перерисовке */
let selectedUpdateKeys = new Set();

function updateSelectionKey(update) {
    if (!update) return '';
    return `${update.node_id}::${update.package}`;
}

function syncSelectedKeysFromDom() {
    document.querySelectorAll('#updates-tbody .update-checkbox').forEach((cb) => {
        const row = cb.closest('tr');
        if (!row?.dataset?.nodeId || !row?.dataset?.package) return;
        // Disabled (офлайн / installing) не трогаем сохранённый выбор
        if (cb.disabled) return;
        const key = `${row.dataset.nodeId}::${row.dataset.package}`;
        if (cb.checked) selectedUpdateKeys.add(key);
        else selectedUpdateKeys.delete(key);
    });
}

function pruneSelectedKeys() {
    const valid = new Set(
        (updatesData || [])
            .filter((u) => u && u.install_status !== 'completed' && u.install_status !== 'pending' && u.install_status !== 'installing')
            .map(updateSelectionKey)
            .filter(Boolean)
    );
    for (const key of [...selectedUpdateKeys]) {
        if (!valid.has(key)) selectedUpdateKeys.delete(key);
    }
}

// Функция показа уведомлений в стиле nodes.js
const showToast = (message, type = 'info') => {
    if (window.showToast) window.showToast(message, type);
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

async function refreshUpdatesList(silent = true) {
    try {
        const nodeId = document.getElementById('nodeFilter')?.value || '';
        const url = nodeId ? `/updates.php?action=list&node_id=${encodeURIComponent(nodeId)}` : '/updates.php?action=list';
        const result = await fetchJson(url, { method: 'GET' });
        if (!result.success) return;
        syncSelectedKeysFromDom();
        updatesData = result.updates || [];
        pruneSelectedKeys();
        applyFilters();
        updateStats(result);
    } catch (error) {
        if (!silent) {
            console.error('Error refreshing updates list:', error);
        }
    }
}

async function checkUpdates(silent = false) {
    // Блокируем повторные нажатия при ручной проверке
    if (!silent && isChecking) {
        showToast('Проверка обновлений уже выполняется...', 'warning');
        return;
    }
    
    try {
        const nodeId = document.getElementById('nodeFilter')?.value || '';
        
        // Silent = только чтение списка, без постановки check-updates на все ноды
        if (silent) {
            await refreshUpdatesList(true);
            return;
        }

        // Блокируем кнопку проверки при ручной проверке
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
        
        const url = nodeId ? `/updates.php?action=check&node_id=${encodeURIComponent(nodeId)}` : '/updates.php?action=check';
        const result = await fetchJson(url, { method: 'POST' });
        
        if (result.success) {
            syncSelectedKeysFromDom();
            updatesData = result.updates || [];
            pruneSelectedKeys();
            
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
            
            if (offlineNodesInUpdates.length > 0) {
                const nodeNames = offlineNodesInUpdates.map(n => n.name).join(', ');
                showToast(`Внимание: ${offlineNodesInUpdates.length} нод(а) офлайн (${nodeNames}). Обновления для них недоступны.`, 'warning');
            }
            
            applyFilters();
            updateStats(result);
            if (updatesData.length > 0) {
                const nodeName = nodeId ? allNodes.find(n => n.id.toString() === nodeId)?.name : '';
                showToast(`Найдено обновлений: ${result.total_updates || 0}${nodeName ? ` для ${nodeName}` : ''}`, 'success');
            } else {
                showToast('Команда проверки отправлена. Список обновится по мере ответа агентов.', 'info');
            }
            // После ручной проверки пару минут подтягиваем список без re-queue
            if (checkPollTimer) clearInterval(checkPollTimer);
            let refreshCount = 0;
            checkPollTimer = setInterval(async () => {
                await refreshUpdatesList(true);
                refreshCount++;
                if (refreshCount >= 30) { clearInterval(checkPollTimer); checkPollTimer = null; }
            }, 3000);
        } else {
            showToast(result.error || 'Ошибка проверки обновлений', 'error');
        }
    } catch (error) {
        console.error('Error checking updates:', error);
        showToast('Ошибка проверки обновлений', 'error');
    } finally {
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

function applyFilters() {
    syncSelectedKeysFromDom();

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
        const selectAll = document.getElementById('select-all-updates');
        if (selectAll) selectAll.checked = false;
        updateSelection();
        return;
    }
    
    tbody.innerHTML = updates.map((update) => {
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
        const key = updateSelectionKey(update);
        
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

        const checkedAttr = (!checkboxDisabled && selectedUpdateKeys.has(key)) ? 'checked' : '';
        
        return `
            <tr data-package="${escHtml(update.package || '')}" data-node-id="${escHtml(String(update.node_id ?? ''))}" class="${rowClass}">
                <td><input type="checkbox" class="update-checkbox" data-key="${escHtml(key)}" onchange="updateSelection()" ${checkboxDisabled ? 'disabled' : ''} ${checkedAttr}></td>
                <td><strong>${escHtml(update.package || '-')}</strong></td>
                <td>${escHtml(update.current_version || '-')}</td>
                <td>${escHtml(update.new_version || '-')}</td>
                <td><span class="status pill ${priorityClass}">${priorityText}</span></td>
                <td>${escHtml(update.node_name || '-')}</td>
                <td>${statusHtml}</td>
            </tr>
        `;
    }).join('');

    const enabled = document.querySelectorAll('#updates-tbody .update-checkbox:not(:disabled)');
    const checkedEnabled = document.querySelectorAll('#updates-tbody .update-checkbox:not(:disabled):checked');
    const selectAll = document.getElementById('select-all-updates');
    if (selectAll) {
        selectAll.checked = enabled.length > 0 && checkedEnabled.length === enabled.length;
        selectAll.indeterminate = checkedEnabled.length > 0 && checkedEnabled.length < enabled.length;
    }
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
    updateSelection();
}

function updateSelection() {
    syncSelectedKeysFromDom();
    const installBtn = document.getElementById('install-btn');
    if (installBtn) {
        // Кнопка активна, если есть выбор (в т.ч. скрытый фильтром)
        installBtn.disabled = selectedUpdateKeys.size === 0 || isInstalling;
    }
    const enabled = document.querySelectorAll('#updates-tbody .update-checkbox:not(:disabled)');
    const checkedEnabled = document.querySelectorAll('#updates-tbody .update-checkbox:not(:disabled):checked');
    const selectAll = document.getElementById('select-all-updates');
    if (selectAll && !isInstalling) {
        selectAll.checked = enabled.length > 0 && checkedEnabled.length === enabled.length;
        selectAll.indeterminate = checkedEnabled.length > 0 && checkedEnabled.length < enabled.length;
    }
}

function getSelectedUpdates() {
    syncSelectedKeysFromDom();
    return (updatesData || []).filter((u) => {
        const key = updateSelectionKey(u);
        if (!selectedUpdateKeys.has(key)) return false;
        const status = u.install_status || 'available';
        return status === 'available' || !u.install_status;
    });
}

async function installUpdates() {
    // Блокируем повторные нажатия
    if (isInstalling) {
        showToast('Установка уже выполняется, пожалуйста подождите...', 'warning');
        return;
    }
    
    const selected = getSelectedUpdates();
    if (selected.length === 0) return;
    
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
            if (selected.find(u => updatesData[idx] && updateSelectionKey(updatesData[idx]) === updateSelectionKey(u))) {
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
                if (selected.find(u => updatesData[idx] && updateSelectionKey(updatesData[idx]) === updateSelectionKey(u))) {
                    btn.disabled = true;
                    btn.innerHTML = '<i data-lucide="loader-2" class="spinning"></i>';
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }
            }
        });
    
        showToast('Отправка команды установки...', 'info');
        window.HostJobs?.start('pkg-install', {
            title: 'Установка пакетов',
            detail: `${selected.length} пакет(ов) в очереди`,
            pct: 5,
        });
        const result = await fetchJson('/updates.php?action=install', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ updates: selected })
        });
        
        if (result.success) {
            const queued = result.queued || result.installed || 0;
            selected.forEach((u) => selectedUpdateKeys.delete(updateSelectionKey(u)));
            if (result.message) {
                showToast(result.message, queued > 0 ? (result.errors?.length ? 'warning' : 'success') : 'error');
            } else if (result.errors && result.errors.length > 0) {
                showToast(`Поставлено в очередь: ${queued}, ошибок: ${result.errors.length}`, 'warning');
            } else {
                showToast(`Команда отправлена: ${queued} обновлений поставлено в очередь`, 'success');
            }
            window.HostJobs?.update('pkg-install', {
                detail: queued > 0 ? `В очереди: ${queued}` : (result.message || 'Ошибка очереди'),
                pct: queued > 0 ? 15 : 0,
            });
            // Обновляем список сразу чтобы показать статус "pending"
            await checkUpdates(true); // silent обновление
            loadHistory();
            // Автообновление статуса установки каждые 2 секунды в течение 2 минут
            let refreshCount = 0;
            const statusInterval = setInterval(async () => {
                await checkUpdates(true); // silent обновление
                loadHistory();
                refreshCount++;
                const pending = (updatesData || []).filter((u) => ['pending', 'installing'].includes(u.install_status)).length;
                if (window.HostJobs) {
                    if (pending > 0) {
                        window.HostJobs.update('pkg-install', {
                            detail: `Устанавливается / в очереди: ${pending}`,
                            pct: Math.min(95, 15 + refreshCount),
                        });
                    } else if (refreshCount >= 3) {
                        window.HostJobs.done('pkg-install', 'Установка завершена');
                        clearInterval(statusInterval);
                        return;
                    }
                }
                if (refreshCount >= 60) { // 60 * 2 = 120 секунд
                    window.HostJobs?.done('pkg-install', 'Опрос завершён');
                    clearInterval(statusInterval);
                }
            }, 2000);
        } else {
            window.HostJobs?.fail('pkg-install', result.error || 'Ошибка установки');
            showToast(result.error || 'Ошибка установки', 'error');
        }
    } catch (error) {
        console.error('Error installing updates:', error);
        window.HostJobs?.fail('pkg-install', error.message || 'Ошибка установки');
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
            const row = cb.closest('tr');
            const key = row ? `${row.dataset.nodeId}::${row.dataset.package}` : '';
            const update = (updatesData || []).find((u) => updateSelectionKey(u) === key);
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
        updateSelection();
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
        window.HostJobs?.start('pkg-install', {
            title: `Установка: ${update.package}`,
            detail: update.node_name || 'нода',
            pct: 5,
        });
            const result = await fetchJson('/updates.php?action=install', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ updates: [update] })
            });
            
            if (result.success) {
                showToast('Команда отправлена. Обновление поставлено в очередь.', 'success');
                window.HostJobs?.update('pkg-install', { detail: 'В очереди агента', pct: 15 });
                // Обновляем список сразу чтобы показать статус "pending"
                await checkUpdates(true); // silent обновление
                loadHistory();
                // Автообновление статуса установки каждые 2 секунды в течение 2 минут
                let refreshCount = 0;
            const statusInterval = setInterval(async () => {
                await checkUpdates(true); // silent обновление
                loadHistory();
                refreshCount++;
                const pending = (updatesData || []).some(
                    (u) => u.package === update.package
                        && String(u.node_id) === String(update.node_id)
                        && ['pending', 'installing'].includes(u.install_status)
                );
                if (!pending && refreshCount >= 2) {
                    window.HostJobs?.done('pkg-install', `${update.package} — готово`);
                    clearInterval(statusInterval);
                    return;
                }
                window.HostJobs?.update('pkg-install', {
                    detail: pending ? 'Устанавливается…' : 'Ожидание статуса…',
                    pct: Math.min(95, 15 + refreshCount),
                });
                if (refreshCount >= 60) { // 60 * 2 = 120 секунд
                    window.HostJobs?.done('pkg-install', 'Опрос завершён');
                    clearInterval(statusInterval);
                }
            }, 2000);
        } else {
            window.HostJobs?.fail('pkg-install', result.error || 'Ошибка установки');
            showToast(result.error || 'Ошибка установки', 'error');
        }
    } catch (error) {
        console.error('Error installing update:', error);
        window.HostJobs?.fail('pkg-install', error.message || 'Ошибка установки');
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
            const row = cb.closest('tr');
            const key = row ? `${row.dataset.nodeId}::${row.dataset.package}` : '';
            const upd = (updatesData || []).find((u) => updateSelectionKey(u) === key);
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
        updateSelection();
    }
}

async function loadHistory() {
    try {
        const nodeId = document.getElementById('history-node-filter')?.value || '';
        const status = document.getElementById('history-status-filter')?.value || '';
        
        let url = '/updates.php?action=history';
        if (nodeId) url += `&node_id=${encodeURIComponent(nodeId)}`;
        if (status) url += `&status=${encodeURIComponent(status)}`;
        
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
                <td><strong>${escHtml(item.node_name || '-')}</strong></td>
                <td><strong>${escHtml(item.package || '-')}</strong></td>
                <td>${escHtml(item.version || '-')}</td>
                <td><span class="status ${resultClass}">${resultText}</span></td>
                <td title="${escHtml(message)}">${escHtml(messageDisplay) || '-'}</td>
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
window.updateSelection = updateSelection;
window.loadHistory = loadHistory;
window.checkAgentUpdates = checkAgentUpdates;
window.applyAgentUpdates = applyAgentUpdates;
window.applyAgentUpdateOne = applyAgentUpdateOne;
window.loadAgentUpdates = loadAgentUpdates;

let agentPollTimer = null;
let agentPollTicks = 0;
let agentBusy = false;
let agentNodesCache = [];
/** @type {Set<string>} */
const agentJobIds = new Set();

function agentJobOf(node) {
    if (node?.agent_job) {
        const age = Number(node.command_age_sec) || 0;
        // Подстраховка UI: не крутить «Проверка» дольше 3м / update 10м
        if (node.agent_job === 'checking' && age > 180) return 'idle';
        if (node.agent_job === 'updating' && age > 600) return 'idle';
        return node.agent_job;
    }
    const cmd = String(node?.last_command || '');
    const status = String(node?.command_status || '').toLowerCase();
    const age = Number(node?.command_age_sec) || 0;
    const result = String(node?.command_result || '').trim();
    if (['failed', 'error'].includes(status) && (
        ['update-agent', 'upgrade-agent', 'check-agent-update', 'check-agent-updates'].includes(cmd)
        || result
    )) {
        return 'failed';
    }
    if (['update-agent', 'upgrade-agent'].includes(cmd) && ['pending', 'running', 'installing', 'in_progress', ''].includes(status)) {
        return age > 600 ? 'idle' : 'updating';
    }
    if (['check-agent-update', 'check-agent-updates'].includes(cmd) && ['pending', 'running', 'installing', 'in_progress', ''].includes(status)) {
        return age > 180 ? 'idle' : 'checking';
    }
    return 'idle';
}

function formatAgentAge(sec) {
    const n = Number(sec) || 0;
    if (n < 5) return '';
    if (n < 60) return ` ${n}с`;
    return ` ${Math.floor(n / 60)}м`;
}

function agentProgressHtml(job, ageSec, errMsg) {
    if (job === 'updating') {
        return `<div class="progress-bar agent-progress" title="Агент обновляется">
            <div class="agent-progress-fill updating"></div>
            <span><i data-lucide="loader-2" class="spinning"></i> Обновление${escHtml(formatAgentAge(ageSec))}</span>
        </div>`;
    }
    if (job === 'checking') {
        return `<div class="progress-bar agent-progress" title="Проверка версии агента">
            <div class="agent-progress-fill checking"></div>
            <span><i data-lucide="loader-2" class="spinning"></i> Проверка${escHtml(formatAgentAge(ageSec))}</span>
        </div>`;
    }
    if (job === 'failed') {
        const tip = errMsg ? escHtml(errMsg) : 'Ошибка обновления/проверки агента';
        return `<span class="status pill" style="background:#ef4444;color:#fff;" title="${tip}">ошибка</span>`;
    }
    return '';
}

function setAgentHeaderBusy(busy, mode = 'update') {
    const checkBtn = document.getElementById('agent-check-btn');
    const applyBtn = document.getElementById('agent-apply-btn');
    if (checkBtn) {
        checkBtn.disabled = busy;
        if (busy && mode === 'check') {
            checkBtn.innerHTML = '<i data-lucide="loader-2" class="spinning"></i> Проверка...';
        } else if (!busy) {
            checkBtn.innerHTML = '<i data-lucide="search"></i> Проверить агенты';
        }
    }
    if (applyBtn) {
        applyBtn.disabled = busy;
        if (busy && mode === 'update') {
            applyBtn.innerHTML = '<i data-lucide="loader-2" class="spinning"></i> Обновление...';
        } else if (!busy) {
            applyBtn.innerHTML = '<i data-lucide="download"></i> Обновить устаревшие';
        }
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function stopAgentPoll() {
    if (agentPollTimer) {
        clearInterval(agentPollTimer);
        agentPollTimer = null;
    }
    agentPollTicks = 0;
}

function finishTrackedAgentJobs(ok, message) {
    [...agentJobIds].forEach((id) => {
        if (ok) window.HostJobs?.done(id, message || 'Готово');
        else window.HostJobs?.fail(id, message || 'Таймаут');
    });
    agentJobIds.clear();
}

function untrackAgentJob(jobId) {
    if (jobId) agentJobIds.delete(String(jobId));
}

function startAgentPoll(jobId) {
    if (jobId) {
        agentJobIds.add(String(jobId));
        window.AgentJobsRunner?.track(jobId);
    }
    // Глобальный AgentJobsRunner ведёт опрос на всех страницах;
    // локальный таймер — только если runner недоступен (старый layout).
    if (window.AgentJobsRunner) return;
    if (agentPollTimer) return;
    agentPollTicks = 0;
    agentPollTimer = setInterval(async () => {
        agentPollTicks += 1;
        await loadAgentUpdates(true);
        const stillBusy = agentNodesCache.some((n) => ['updating', 'checking'].includes(agentJobOf(n)));
        const updating = agentNodesCache.filter((n) => agentJobOf(n) === 'updating');
        const checking = agentNodesCache.filter((n) => agentJobOf(n) === 'checking');
        // check ~2м (60 тиков), update ~10м (300 тиков)
        const maxTicks = updating.length || [...agentJobIds].some((id) => String(id).includes('update'))
            ? 300
            : 60;

        [...agentJobIds].forEach((id) => {
            const sid = String(id);
            if (sid.startsWith('agent-update-batch') || sid.startsWith('agent-check-')) {
                const isCheck = sid.startsWith('agent-check-');
                window.HostJobs?.update(id, {
                    title: isCheck ? 'Проверка агентов' : 'Обновление агентов',
                    detail: updating.length
                        ? `Обновляются: ${updating.map((n) => n.name).join(', ')}`
                        : (checking.length ? `Проверка: ${checking.map((n) => n.name).join(', ')}` : 'Ожидание…'),
                    pct: Math.min(95, 10 + Math.floor((agentPollTicks / maxTicks) * 85)),
                });
                return;
            }
            const m = sid.match(/^agent-update-(\d+)/);
            if (m) {
                const node = agentNodesCache.find((n) => String(n.id) === m[1]);
                const job = node ? agentJobOf(node) : 'idle';
                const err = String(node?.command_result || '').trim();
                if (job === 'idle' || job === 'failed') {
                    if (job === 'failed') window.HostJobs?.fail(id, err || 'Ошибка обновления');
                    else window.HostJobs?.done(id, `${node?.name || m[1]} — готово`);
                    agentJobIds.delete(id);
                } else {
                    window.HostJobs?.update(id, {
                        detail: job === 'updating' ? 'Обновляется…' : 'Проверка…',
                        pct: Math.min(95, 10 + Math.floor((agentPollTicks / maxTicks) * 85)),
                    });
                }
            }
        });

        if (!stillBusy || agentPollTicks >= maxTicks) {
            stopAgentPoll();
            setAgentHeaderBusy(false);
            if (!stillBusy) finishTrackedAgentJobs(true, 'Готово');
            else finishTrackedAgentJobs(false, 'Таймаут — нажмите Проверить ещё раз');
        }
    }, 2000);
}

function renderAgentNodes(nodes, desired, outdatedCount) {
    const tbody = document.getElementById('agent-updates-tbody');
    const label = document.getElementById('agent-desired-label');
    if (label) {
        label.textContent = `Целевая версия: ${desired.desired_version || '—'} (${desired.desired_commit || '—'}), устаревших: ${outdatedCount ?? 0}`;
    }
    if (!tbody) return;
    if (!nodes.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">Нет нод</td></tr>';
        return;
    }
        tbody.innerHTML = nodes.map((n) => {
        const job = agentJobOf(n);
        const state = n.update_state
            || ((n.outdated || Number(n.agent_update_available) === 1) ? 'outdated' : 'current');
        const outdated = state === 'outdated' || state === 'unknown'
            || n.outdated || Number(n.agent_update_available) === 1;
        const online = n.status === 'online';
        const age = n.command_age_sec;
        const errMsg = String(n.command_result || '').trim();
        const local = String(n.agent_commit || '');
        const remote = String(n.agent_remote_commit || '');
        const desiredCommit = String(desired.desired_commit || '');
        const onOrigin = !!(local && remote && (local === remote || remote.startsWith(local) || local.startsWith(remote)));
        const panelAhead = !!(onOrigin && desiredCommit && !(desiredCommit === local || local.startsWith(desiredCommit) || desiredCommit.startsWith(local)));
        let badge = agentProgressHtml(job, age, errMsg);
        if (!badge) {
            if (state === 'unknown') {
                badge = '<span class="status pill" style="background:#64748b;color:#fff;" title="Агент не сообщил commit — нужна проверка/обновление">нет данных</span>';
            } else if (panelAhead) {
                badge = `<span class="status pill" style="background:#f59e0b;color:#111;" title="Агент на origin (${escHtml(remote)}), панель впереди (${escHtml(desiredCommit)}) — сделайте git push с машины разработки">ждёт push</span>`;
            } else if (outdated) {
                badge = '<span class="status pill" style="background:#f59e0b;color:#111;">доступно</span>';
            } else {
                badge = '<span class="status pill" style="background:#22c55e;color:#fff;">актуален</span>';
            }
        }
        const canUpdate = online && outdated && !panelAhead && (job === 'idle' || job === 'failed');
        const rowClass = job === 'updating' ? 'agent-job-updating' : (job === 'checking' ? 'agent-job-checking' : (job === 'failed' ? 'agent-job-failed' : ''));
        const action = canUpdate
            ? `<button type="button" class="btn-outline" onclick="applyAgentUpdateOne(${Number(n.id)})" title="Обновить агент на этой ноде"><i data-lucide="download"></i></button>`
            : '';
        const errLine = (job === 'failed' && errMsg)
            ? `<small class="agent-err-text" title="${escHtml(errMsg)}">${escHtml(errMsg.length > 90 ? `${errMsg.slice(0, 90)}…` : errMsg)}</small>`
            : '';
        const remoteShown = n.agent_remote_commit || desired.desired_commit || '—';
        return `<tr class="${rowClass}" data-node-id="${escHtml(String(n.id))}">
            <td>${escHtml(n.name || '-')}</td>
            <td><span class="status pill ${online ? 'online' : 'offline'}">${escHtml(n.status === 'online' ? 'онлайн' : 'офлайн')}</span></td>
            <td>${escHtml(n.agent_version || '—')}</td>
            <td><code>${escHtml(n.agent_commit || '—')}</code></td>
            <td><code title="Remote агента или целевой commit панели">${escHtml(remoteShown)}</code></td>
            <td><div class="agent-node-actions">${badge}${action}${errLine}</div></td>
        </tr>`;
    }).join('');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function loadAgentUpdates(silent = false) {
    const tbody = document.getElementById('agent-updates-tbody');
    const label = document.getElementById('agent-desired-label');
    if (!silent && tbody && !agentNodesCache.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">Загрузка...</td></tr>';
    }
    try {
        const result = await fetchJson('/agent_update.php?action=status');
        if (result.error) {
            throw new Error(result.error);
        }
        agentNodesCache = result.nodes || [];
        renderAgentNodes(agentNodesCache, result.desired || {}, result.outdated_count ?? 0);
        const busy = agentNodesCache.some((n) => ['updating', 'checking'].includes(agentJobOf(n)));
        if (busy) {
            const updating = agentNodesCache.some((n) => agentJobOf(n) === 'updating');
            setAgentHeaderBusy(true, updating ? 'update' : 'check');
            startAgentPoll();
        } else if (!agentBusy) {
            setAgentHeaderBusy(false);
        }
    } catch (e) {
        console.error('loadAgentUpdates', e);
        if (label && !silent) {
            label.textContent = `Целевая версия: — (ошибка: ${e.message || 'API'})`;
        }
        if (tbody && !silent) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center">Ошибка загрузки статуса агентов: ${escHtml(e.message || 'API')}</td></tr>`;
        }
    }
}

function escHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

async function queueAgentCommand(ids, action) {
    if (!ids.length) return null;
    return fetchJson(`/agent_update.php?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ node_ids: ids, force: true }),
    });
}

function markNodesJob(ids, job) {
    const set = new Set(ids.map((id) => Number(id)));
    agentNodesCache = agentNodesCache.map((n) => (
        set.has(Number(n.id))
            ? { ...n, agent_job: job, last_command: job === 'updating' ? 'update-agent' : 'check-agent-update', command_status: 'pending', command_age_sec: 0 }
            : n
    ));
    const desiredLabel = document.getElementById('agent-desired-label');
    const desired = { desired_version: '—', desired_commit: '—' };
    const match = (desiredLabel?.textContent || '').match(/Целевая версия: (.+) \((.+)\), устаревших: (\d+)/);
    if (match) {
        desired.desired_version = match[1];
        desired.desired_commit = match[2];
    }
    const outdatedCount = agentNodesCache.filter((n) => n.outdated || Number(n.agent_update_available) === 1).length;
    renderAgentNodes(agentNodesCache, desired, outdatedCount);
}

async function checkAgentUpdates() {
    if (agentBusy) return;
    try {
        agentBusy = true;
        setAgentHeaderBusy(true, 'check');
        showToast('Проверка обновлений агентов...', 'info');
        const status = await fetchJson('/agent_update.php?action=status');
        const ids = (status.nodes || [])
            .filter((n) => n.status === 'online')
            .map((n) => Number(n.id))
            .filter((id) => id > 0);
        if (!ids.length) {
            showToast('Нет онлайн-нод для проверки', 'info');
            await loadAgentUpdates(true);
            return;
        }
        markNodesJob(ids, 'checking');
        const jobId = `agent-check-${Date.now()}`;
        window.HostJobs?.start(jobId, {
            title: 'Проверка агентов',
            detail: `${ids.length} нод(ы)`,
            pct: 5,
            maxMs: 120000,
            resumable: true,
        });
        startAgentPoll(jobId);
        const result = await queueAgentCommand(ids, 'check');
        showToast(result?.message || `В очередь: ${result?.queued || 0}`, 'success');
        await loadAgentUpdates(true);
    } catch (e) {
        showToast(e.message || 'Ошибка проверки агентов', 'error');
        setAgentHeaderBusy(false);
    } finally {
        agentBusy = false;
    }
}

async function applyAgentUpdates() {
    if (agentBusy) return;
    try {
        const confirmed = await window.showConfirm(
            'Обновить агенты на всех онлайн-нодах с доступным обновлением?',
            'Обновление агентов',
            'warning'
        );
        if (!confirmed) return;

        agentBusy = true;
        setAgentHeaderBusy(true, 'update');

        const status = await fetchJson('/agent_update.php?action=status');
        const desiredCommit = String(status.desired?.desired_commit || '');
        const ids = (status.nodes || [])
            .filter((n) => {
                if (n.status !== 'online') return false;
                if (!(n.outdated || Number(n.agent_update_available) === 1)) return false;
                const local = String(n.agent_commit || '');
                const remote = String(n.agent_remote_commit || '');
                const onOrigin = !!(local && remote && (local === remote || remote.startsWith(local) || local.startsWith(remote)));
                const panelAhead = !!(onOrigin && desiredCommit && !(
                    desiredCommit === local || local.startsWith(desiredCommit) || desiredCommit.startsWith(local)
                ));
                return !panelAhead;
            })
            .map((n) => Number(n.id))
            .filter((id) => id > 0);
        if (!ids.length) {
            showToast('Нет устаревших онлайн-нод для обновления', 'info');
            await loadAgentUpdates(true);
            return;
        }

        markNodesJob(ids, 'updating');
        const jobId = `agent-update-batch-${Date.now()}`;
        window.HostJobs?.start(jobId, {
            title: 'Обновление агентов',
            detail: `${ids.length} нод(ы): ${(status.nodes || []).filter((n) => ids.includes(Number(n.id))).map((n) => n.name).join(', ')}`,
            pct: 5,
            maxMs: 600000,
            resumable: true,
        });
        startAgentPoll(jobId);
        showToast(`Обновление агентов (${ids.length})...`, 'info');
        const result = await queueAgentCommand(ids, 'apply');
        const queued = result?.queued ?? 0;
        const skipped = result?.skipped ?? 0;
        showToast(
            result?.message || `В очередь: ${queued}` + (skipped ? `, пропущено: ${skipped}` : ''),
            queued > 0 ? 'success' : 'warning'
        );
        if (queued > 0) {
            window.HostJobs?.update(jobId, { detail: `В очереди: ${queued}`, pct: 15 });
        } else {
            untrackAgentJob(jobId);
            window.HostJobs?.fail(jobId, result?.message || 'Не поставлено в очередь');
        }
        await loadAgentUpdates(true);
    } catch (e) {
        showToast(e.message || 'Ошибка обновления агентов', 'error');
        setAgentHeaderBusy(false);
    } finally {
        agentBusy = false;
    }
}

async function applyAgentUpdateOne(nodeId) {
    const id = Number(nodeId);
    if (!id) return;
    const node = agentNodesCache.find((n) => Number(n.id) === id);
    const name = node?.name || `#${id}`;
    const confirmed = await window.showConfirm(
        `Обновить агент на «${name}»?`,
        'Обновление агента',
        'warning'
    );
    if (!confirmed) return;
    let jobId = null;
    try {
        setAgentHeaderBusy(true, 'update');
        markNodesJob([id], 'updating');
        jobId = `agent-update-${id}-${Date.now()}`;
        window.HostJobs?.start(jobId, {
            title: `Обновление: ${name}`,
            detail: 'В очереди',
            pct: 5,
            maxMs: 600000,
            resumable: true,
        });
        startAgentPoll(jobId);
        const result = await queueAgentCommand([id], 'apply');
        const queued = result?.queued ?? 0;
        showToast(result?.message || (queued ? `Обновление «${name}» в очереди` : `Не удалось поставить обновление «${name}»`), queued > 0 ? 'success' : 'warning');
        if (queued > 0) {
            window.HostJobs?.update(jobId, { detail: 'Ожидание агента…', pct: 20 });
        } else {
            untrackAgentJob(jobId);
            window.HostJobs?.fail(jobId, result?.message || 'Не поставлено');
        }
        await loadAgentUpdates(true);
    } catch (e) {
        showToast(e.message || 'Ошибка обновления агента', 'error');
        if (jobId) {
            untrackAgentJob(jobId);
            window.HostJobs?.fail(jobId, e.message || 'Ошибка');
        }
        setAgentHeaderBusy(false);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('select-all-updates')?.addEventListener('change', (e) => {
        const checked = !!e.target.checked;
        document.querySelectorAll('#updates-tbody .update-checkbox:not(:disabled)').forEach((cb) => {
            cb.checked = checked;
            const row = cb.closest('tr');
            if (!row?.dataset?.nodeId || !row?.dataset?.package) return;
            const key = `${row.dataset.nodeId}::${row.dataset.package}`;
            if (checked) selectedUpdateKeys.add(key);
            else selectedUpdateKeys.delete(key);
        });
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
    loadAgentUpdates();
    checkUpdates(true); // Автоматически загружаем обновления при открытии страницы (silent)
});

