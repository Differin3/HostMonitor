// Управление процессами
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;
let allProcesses = [];
let filteredProcesses = [];
let selectedNodeId = null;

async function loadNodes() {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    
    // Показываем индикатор загрузки
    select.disabled = true;
    select.innerHTML = '<option value="">Загрузка нод...</option>';
    
    try {
        const nodesRes = await fetch(`${API_BASE}/nodes.php`, { credentials: 'include' });
        if (!nodesRes.ok) throw new Error(`HTTP ${nodesRes.status}`);
        const nodesText = await nodesRes.text();
        const nodesData = nodesText ? JSON.parse(nodesText) : {};
        const nodes = nodesData.nodes || [];
        populateNodeFilter(nodes);
    } catch (error) {
        console.error('Error loading nodes:', error);
        select.innerHTML = '<option value="">Ошибка загрузки нод</option>';
    } finally {
        select.disabled = false;
    }
}

async function loadProcesses(nodeId, silent = false) {
    if (!nodeId) {
        showEmptyState();
        return;
    }
    
    try {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('processes-tbody', true);
        }
        const processesRes = await fetch(`${API_BASE}/processes.php?node_id=${nodeId}`, { credentials: 'include' });
        if (!processesRes.ok) throw new Error(`HTTP ${processesRes.status}`);
        const text = await processesRes.text();
        const processesData = text ? JSON.parse(text) : { processes: [] };
        allProcesses = processesData.processes || [];
        applyFilters();
    } catch (error) {
        console.error('Error loading processes:', error);
        allProcesses = [];
        applyFilters();
    } finally {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('processes-tbody', false);
        }
    }
}

function populateNodeFilter(nodes) {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    
    select.innerHTML = '<option value="">Выберите ноду</option>';
    if (nodes.length === 0) {
        select.innerHTML = '<option value="">Ноды не найдены</option>';
        return;
    }
    
    nodes.forEach(node => {
        const option = document.createElement('option');
        option.value = node.id;
        option.textContent = node.name;
        select.appendChild(option);
    });
}

function showEmptyState() {
    document.getElementById('processes-empty').style.display = 'flex';
    document.getElementById('processes-table').style.display = 'none';
}

function showProcessesTable() {
    document.getElementById('processes-empty').style.display = 'none';
    document.getElementById('processes-table').style.display = 'block';
}

function applyFilters() {
    const statusFilter = document.getElementById('statusFilter')?.value || '';
    const searchTerm = document.getElementById('processSearch')?.value.toLowerCase() || '';
    
    filteredProcesses = allProcesses.filter(p => {
        if (statusFilter && p.status !== statusFilter) return false;
        if (searchTerm && !p.name.toLowerCase().includes(searchTerm)) return false;
        return true;
    });
    
    renderProcesses(filteredProcesses);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function renderProcesses(processes) {
    const tbody = document.getElementById('processes-tbody');
    if (!tbody) return;
    
    if (processes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">Процессы не найдены</td></tr>';
        showProcessesTable();
        return;
    }
    
    // Сортируем по использованию CPU (по убыванию)
    processes.sort((a, b) => (b.cpu_percent || 0) - (a.cpu_percent || 0));
    
    tbody.innerHTML = processes.map(p => {
        const escapedName = escapeHtml(p.name || '');
        return `
        <tr>
            <td>${p.pid}</td>
            <td><strong>${escapedName}</strong></td>
            <td>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${Math.min(p.cpu_percent || 0, 100)}%; background: ${getCpuColor(p.cpu_percent || 0)};"></div>
                    <span class="progress-text">${p.cpu_percent?.toFixed(1) || 0}%</span>
                </div>
            </td>
            <td>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${Math.min(p.memory_percent || 0, 100)}%; background: ${getMemoryColor(p.memory_percent || 0)};"></div>
                    <span class="progress-text">${p.memory_percent?.toFixed(1) || 0}%</span>
                </div>
            </td>
            <td><span class="status ${p.status}">${p.status}</span></td>
            <td>
                <button onclick="loadProcessLogsModal(${p.pid}, '${escapedName}', ${selectedNodeId})" class="btn-outline" title="Загрузить логи процесса">
                    <i data-lucide="file-text"></i>
                </button>
                <button onclick="killProcess(${p.pid}, ${selectedNodeId})" class="btn-danger" title="Завершить">
                    <i data-lucide="x"></i>
                </button>
            </td>
        </tr>
    `;
    }).join('');
    
    showProcessesTable();
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function getCpuColor(percent) {
    if (percent > 80) return 'var(--danger)';
    if (percent > 50) return 'var(--warning)';
    return 'var(--success)';
}

function getMemoryColor(percent) {
    if (percent > 80) return 'var(--danger)';
    if (percent > 50) return 'var(--warning)';
    return 'var(--info)';
}

function refreshProcesses() {
    if (selectedNodeId) {
        loadProcesses(selectedNodeId);
    }
}

let processLogsPaused = false;
let processLogsPage = 1; // страница логов процессов
let processLogsPerPage = 100; // размер страницы логов процессов (загружается из настроек)
let processLogsTotal = 0; // всего логов процессов
// currentProcessTab больше не нужен - убрана вкладка логов

async function loadProcessLogsPerPageSetting() {
    try {
        const response = await fetch(`${API_BASE}/settings.php`, { credentials: 'include' });
        if (!response.ok) return;
        const data = await response.json();
        const settings = data.settings || {};
        if (settings.logs_per_page) {
            const perPage = parseInt(settings.logs_per_page);
            if (perPage >= 10 && perPage <= 2000) {
                processLogsPerPage = perPage;
            }
        }
    } catch (error) {
        console.error('Error loading logs per page setting:', error);
    }
}

// Функция switchProcessTab больше не нужна - убрана вкладка логов

async function loadProcessLogs(nodeId, page = 1, fromTime = null, toTime = null, fromNode = false) {
    // Проверяем что модальное окно открыто (элементы существуют)
    const modal = document.getElementById('process-logs-modal');
    if (!modal || modal.style.display === 'none') {
        // Модальное окно не открыто, не загружаем логи
        return;
    }
    
    if (!nodeId) {
        const container = document.getElementById('processes-logs-container');
        if (container) container.style.display = 'none';
        updateProcessPagination();
        return;
    }
    
    const container = document.getElementById('processes-logs-container');
    if (container) {
        container.style.display = 'block';
    } else {
        // Элемент не найден, возможно модальное окно не открыто
        console.warn('processes-logs-container not found, modal may not be open');
        return;
    }
    
    if (processLogsPaused && page === processLogsPage && !fromNode) return;
    
    try {
        const currentPid = window.currentProcessPid;
        
        // Если запрашиваем логи напрямую с ноды (при загрузке по кнопке)
        if (fromNode && currentPid) {
            // Показываем индикатор загрузки
            const loadingIndicator = document.getElementById('processes-logs-loading');
            const logsContainer = document.getElementById('processes-logs-container');
            const logsContent = document.getElementById('processes-logs-content');
            if (loadingIndicator) {
                loadingIndicator.style.display = 'flex';
            }
            if (logsContainer) {
                logsContainer.style.display = 'none';
            }
            if (logsContent) {
                logsContent.style.display = 'none';
            }
            
            const params = new URLSearchParams();
            params.set('node_id', nodeId);
            params.set('pid', currentPid);
            params.set('logs', '1');
            params.set('limit', processLogsPerPage * 10); // Загружаем больше для пагинации
            
            if (fromTime !== null) {
                params.set('from', fromTime);
            }
            if (toTime !== null) {
                params.set('to', toTime);
            }
            
            // Ставим команду агенту
            const response = await fetch(`${API_BASE}/processes.php?${params.toString()}`, { credentials: 'include' });
            if (!response.ok) {
                // Скрываем индикатор загрузки при ошибке
                if (loadingIndicator) loadingIndicator.style.display = 'none';
                const logsContainer = document.getElementById('processes-logs-container');
                if (logsContainer) logsContainer.style.display = 'block';
                throw new Error(`HTTP ${response.status}`);
            }
            const text = await response.text();
            const commandData = text ? JSON.parse(text) : {};
            
            if (commandData.status === 'pending') {
                // Команда поставлена, делаем polling для получения результата
                let attempts = 0;
                const maxAttempts = 30; // максимум 30 попыток (30 секунд)
                
                const pollResult = async () => {
                    const resultParams = new URLSearchParams();
                    resultParams.set('node_id', nodeId);
                    resultParams.set('pid', currentPid);
                    resultParams.set('get-result', '1');
                    
                    const resultResponse = await fetch(`${API_BASE}/processes.php?${resultParams.toString()}`, { credentials: 'include' });
                    if (!resultResponse.ok) {
                        // Скрываем индикатор загрузки при ошибке
                        const loadingIndicator = document.getElementById('processes-logs-loading');
                        const logsContainer = document.getElementById('processes-logs-container');
                        if (loadingIndicator) loadingIndicator.style.display = 'none';
                        if (logsContainer) logsContainer.style.display = 'block';
                        throw new Error(`HTTP ${resultResponse.status}`);
                    }
                    const resultText = await resultResponse.text();
                    const resultData = resultText ? JSON.parse(resultText) : {};
                    
                    // Получаем элементы заново на случай если они изменились
                    const loadingIndicator = document.getElementById('processes-logs-loading');
                    const logsContainer = document.getElementById('processes-logs-container');
                    const logsContent = document.getElementById('processes-logs-content');
                    
                    if (resultData.status === 'completed' && resultData.logs) {
                        // Логи получены - скрываем индикатор загрузки
                        if (loadingIndicator) loadingIndicator.style.display = 'none';
                        if (logsContainer) logsContainer.style.display = 'block';
                        
                        const logs = resultData.logs || [];
                        processLogsTotal = logs.length;
                        processLogsPage = 1;
                        
                        // Очищаем контейнер и показываем логи
                        if (logsContent) {
                            logsContent.innerHTML = '';
                            appendProcessLogs(logs.slice(0, processLogsPerPage), true);
                        }
                        updateProcessPagination();
                        
                        // Сохраняем все логи для пагинации
                        window.allProcessLogs = logs;
                        
                        if (window.showToast) {
                            window.showToast(`Загружено ${logs.length} логов с ноды`, 'success');
                        }
                        return true;
                    } else if (resultData.status === 'failed') {
                        // Скрываем индикатор загрузки при ошибке
                        if (loadingIndicator) loadingIndicator.style.display = 'none';
                        if (logsContainer) logsContainer.style.display = 'block';
                        
                        if (window.showToast) {
                            window.showToast('Ошибка загрузки логов с ноды', 'error');
                        }
                        return false;
                    } else {
                        // Еще выполняется, продолжаем polling
                        attempts++;
                        if (attempts < maxAttempts) {
                            setTimeout(pollResult, 1000);
                        } else {
                            // Таймаут - скрываем индикатор загрузки
                            if (loadingIndicator) loadingIndicator.style.display = 'none';
                            if (logsContainer) logsContainer.style.display = 'block';
                            
                            if (window.showToast) {
                                window.showToast('Таймаут загрузки логов с ноды, загружаю из БД', 'warning');
                            }
                            // Загружаем из БД
                            loadProcessLogs(nodeId, 1, fromTime, toTime, false);
                        }
                        return false;
                    }
                };
                
                // Начинаем polling через 2 секунды (даем агенту время выполнить команду)
                setTimeout(pollResult, 2000);
                return;
            } else {
                // Ошибка постановки команды - скрываем индикатор загрузки
                if (loadingIndicator) loadingIndicator.style.display = 'none';
                if (logsContainer) logsContainer.style.display = 'block';
                
                if (window.showToast) {
                    window.showToast('Ошибка запроса логов с ноды', 'error');
                }
                // Fallback: загружаем из БД
                loadProcessLogs(nodeId, 1, fromTime, toTime, false);
                return;
            }
        }
        
        // Обычная загрузка из БД
        processLogsPage = page;
        const params = new URLSearchParams();
        params.set('node_id', nodeId);
        params.set('type', 'processes');
        params.set('page', page);
        params.set('per_page', processLogsPerPage);
        
        if (currentPid) {
            params.set('pid', currentPid);
        }
        
        // Добавляем временной диапазон если указан
        if (fromTime !== null) {
            params.set('from', fromTime);
        }
        if (toTime !== null) {
            params.set('to', toTime);
        }
        
        const response = await fetch(`${API_BASE}/logs.php?${params.toString()}`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { logs: [] };
        
        const logs = data.logs || [];
        
        // Если логи загружены с ноды и сохранены в window.allProcessLogs
        if (window.allProcessLogs && window.allProcessLogs.length > 0) {
            // Используем сохраненные логи для пагинации
            const startIdx = (page - 1) * processLogsPerPage;
            const endIdx = startIdx + processLogsPerPage;
            const pageLogs = window.allProcessLogs.slice(startIdx, endIdx);
            
            processLogsPage = page;
            processLogsTotal = window.allProcessLogs.length;
            
            // Очищаем контейнер только если это первая страница
            const logsContent = document.getElementById('processes-logs-content');
            if (page === 1 && logsContent) {
                logsContent.innerHTML = '';
            }
            
            if (logsContent) {
                appendProcessLogs(pageLogs, page === 1);
            }
            updateProcessPagination();
        } else {
            // Обычная загрузка из БД
            processLogsPage = data.page || page;
            processLogsPerPage = data.per_page || processLogsPerPage;
            processLogsTotal = data.total ?? 0;
            
            console.log(`[loadProcessLogs] Loaded ${logs.length} logs, total: ${processLogsTotal}, page: ${processLogsPage}`);
            if (logs.length > 0) {
                console.log(`[loadProcessLogs] Sample log:`, logs[0]);
            }
            
            // Очищаем контейнер только если это новая страница или первая загрузка
            const logsContent = document.getElementById('processes-logs-content');
            if ((page === 1 || page !== processLogsPage) && logsContent) {
                logsContent.innerHTML = '';
            }
            
            if (logsContent) {
                appendProcessLogs(logs, page === 1);
            }
            updateProcessPagination();
        }
    } catch (error) {
        console.error('Error loading process logs:', error);
    }
}

function appendProcessLogs(logs, replace = false) {
    const container = document.getElementById('processes-logs-content');
    if (!container) return;
    
    if (replace) {
        container.innerHTML = '';
    }
    
    // Фильтруем логи по PID если указан (но это уже делается на сервере)
    const currentPid = window.currentProcessPid;
    let filteredLogs = logs;
    if (currentPid) {
        filteredLogs = logs.filter(log => {
            const logPid = log.pid || log.process_id;
            return logPid == currentPid || (log.message && log.message.includes(`pid=${currentPid}`));
        });
    }
    
    filteredLogs.forEach(log => {
        const entry = document.createElement('div');
        entry.className = 'log-entry';
        entry.innerHTML = `
            <span class="log-time">${new Date(log.timestamp || Date.now()).toLocaleTimeString('ru-RU')}</span>
            <span class="log-level ${log.level || 'info'}">${(log.level || 'info').toUpperCase()}</span>
            <span class="log-source">${log.process || 'system'}</span>
            <span class="log-message">${escapeHtml(log.message || log.text || '')}</span>
        `;
        container.appendChild(entry);
    });
    
    const autoScroll = document.getElementById('autoScrollProcessLogs');
    if (autoScroll && autoScroll.checked && replace) {
        container.scrollTop = container.scrollHeight;
    }
}


function updateProcessPagination() {
    const totalPages = processLogsTotal > 0 ? Math.ceil(processLogsTotal / processLogsPerPage) : (processLogsPage || 1);
    const info = document.getElementById('process-logs-page-info');
    const prev = document.getElementById('process-logs-prev');
    const next = document.getElementById('process-logs-next');
    if (info) {
        if (totalPages > 0) {
            info.textContent = `Стр. ${processLogsPage} из ${totalPages}`;
        } else {
            info.textContent = 'Нет данных';
        }
    }
    if (prev) prev.disabled = processLogsPage <= 1 || totalPages === 0;
    if (next) next.disabled = processLogsPage >= totalPages || totalPages === 0;
}

function pauseProcessLogs() {
    processLogsPaused = !processLogsPaused;
    const btn = document.getElementById('pauseProcessLogsBtn');
    if (btn) {
        btn.innerHTML = processLogsPaused 
            ? '<i data-lucide="play"></i> Продолжить'
            : '<i data-lucide="pause"></i> Пауза';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

async function clearProcessLogs() {
    const confirmed = await window.showConfirm('Вы уверены, что хотите очистить все логи процессов?', 'Очистка логов', 'warning');
    if (!confirmed) return;
    const container = document.getElementById('processes-logs-content');
    if (container) container.innerHTML = '';
}

async function exportProcessLogs() {
    if (!selectedNodeId) {
        if (window.showToast && window.showToast !== showToast) {
            window.showToast('Выберите ноду для экспорта логов', 'warning');
        } else {
            console.log('[WARNING] Выберите ноду для экспорта логов');
        }
        return;
    }
    
    try {
        if (window.showToast && window.showToast !== showToast) {
            window.showToast('Экспорт логов...', 'info');
        }
        
        const allLogs = [];
        let page = 1;
        const perPage = 1000;
        let hasMore = true;
        const currentPid = window.currentProcessPid;
        
        while (hasMore) {
            const params = new URLSearchParams();
            params.set('node_id', selectedNodeId);
            params.set('type', 'processes');
            params.set('page', page);
            params.set('per_page', perPage);
            if (currentPid) {
                params.set('pid', currentPid);
            }
            
            const response = await fetch(`${API_BASE}/logs.php?${params.toString()}`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { logs: [] };
        const logs = data.logs || [];
        
        if (logs.length === 0) {
                hasMore = false;
            } else {
                allLogs.push(...logs);
                const total = data.total || 0;
                const totalPages = Math.ceil(total / perPage);
                if (page >= totalPages || logs.length < perPage) {
                    hasMore = false;
                } else {
                    page++;
                }
            }
        }
        
        if (allLogs.length === 0) {
            if (window.showToast && window.showToast !== showToast) {
                window.showToast('Нет логов для экспорта', 'info');
            } else {
                console.log('[INFO] Нет логов для экспорта');
            }
            return;
        }
        
        // Получаем имя ноды
        const nodesRes = await fetch(`${API_BASE}/nodes.php`, { credentials: 'include' });
        const nodesText = await nodesRes.text();
        const nodesData = nodesText ? JSON.parse(nodesText) : {};
        const node = (nodesData.nodes || []).find(n => n.id == selectedNodeId);
        const nodeName = node?.name || `node-${selectedNodeId}`;
        
        // Формируем содержимое файла
        let content = `# Логи процессов ноды: ${nodeName}\n`;
        content += `# Дата экспорта: ${new Date().toLocaleString('ru-RU')}\n`;
        content += `# Всего записей: ${allLogs.length}\n\n`;
        
        allLogs.forEach(log => {
            const timestamp = new Date(log.timestamp || Date.now()).toLocaleString('ru-RU');
            const level = (log.level || 'INFO').toUpperCase();
            const process = log.process || 'system';
            const message = log.message || log.text || '';
            content += `[${timestamp}] [${level}] [${process}] ${message}\n`;
        });
        
        // Создаем и скачиваем файл
        const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `process-logs-${nodeName}-${new Date().toISOString().split('T')[0]}.txt`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        if (window.showToast && window.showToast !== showToast) {
            window.showToast(`Экспортировано ${allLogs.length} записей`, 'success');
        } else {
            console.log(`[SUCCESS] Экспортировано ${allLogs.length} записей`);
        }
    } catch (error) {
        console.error('Error exporting process logs:', error);
        if (window.showToast && window.showToast !== showToast) {
            window.showToast('Ошибка экспорта логов', 'error');
        } else {
            console.error('[ERROR] Ошибка экспорта логов');
        }
    }
}

const showToast = (message, type = 'info') => {
    if (typeof window.showToast === 'function') window.showToast(message, type);
};

// Модальное окно для загрузки логов процесса
let modalProcessPid = null;
let modalProcessName = null;
let modalNodeId = null;

function loadProcessLogsModal(pid, processName, nodeId) {
    modalProcessPid = pid;
    modalProcessName = processName;
    modalNodeId = nodeId;
    
    const modal = document.getElementById('process-logs-modal');
    const nameHeader = document.getElementById('modal-process-name');
    const timeRange = document.getElementById('modal-time-range');
    const logsContainer = document.getElementById('processes-logs-container');
    const loadingIndicator = document.getElementById('processes-logs-loading');
        const logsContent = document.getElementById('processes-logs-content');
    
    if (modal && nameHeader) {
        nameHeader.textContent = `Логи процесса: ${processName} (PID: ${pid})`;
        if (timeRange) timeRange.value = '1h'; // По умолчанию последний час
        updateCustomDateRange();
        
        // Очищаем предыдущие логи
        if (logsContent) logsContent.innerHTML = '';
        if (logsContainer) logsContainer.style.display = 'none';
        if (loadingIndicator) loadingIndicator.style.display = 'none';
        
        modal.style.display = 'flex';
    }
}

function closeProcessLogsModal() {
    const modal = document.getElementById('process-logs-modal');
    if (modal) {
        modal.style.display = 'none';
    }
    modalProcessPid = null;
    modalProcessName = null;
    modalNodeId = null;
}

function updateCustomDateRange() {
    const timeRange = document.getElementById('modal-time-range');
    const customRange = document.getElementById('custom-date-range');
    if (timeRange && customRange) {
        customRange.style.display = timeRange.value === 'custom' ? 'block' : 'none';
    }
}

function confirmLoadProcessLogs() {
    if (!modalProcessPid || !modalNodeId) {
        if (window.showToast) {
            window.showToast('Ошибка: не выбран процесс', 'error');
        }
        return;
    }
    
    const timeRange = document.getElementById('modal-time-range')?.value || '1h';
    let fromTime = null;
    let toTime = null;
    
    if (timeRange === 'custom') {
        const fromDate = document.getElementById('modal-from-date')?.value;
        const toDate = document.getElementById('modal-to-date')?.value;
        if (!fromDate || !toDate) {
            if (window.showToast) {
                window.showToast('Укажите диапазон дат', 'warning');
            }
            return;
        }
        fromTime = new Date(fromDate).getTime() / 1000;
        toTime = new Date(toDate).getTime() / 1000;
    } else {
        // Вычисляем временной диапазон
        const now = Date.now();
        let hours = 1;
        if (timeRange === '6h') hours = 6;
        else if (timeRange === '12h') hours = 12;
        else if (timeRange === '24h') hours = 24;
        else if (timeRange === '7d') hours = 24 * 7;
        else if (timeRange === '30d') hours = 24 * 30;
        
        toTime = Math.floor(now / 1000);
        fromTime = Math.floor((now - hours * 60 * 60 * 1000) / 1000);
    }
    
    // Очищаем старые логи
    const logsContent = document.getElementById('processes-logs-content');
    if (logsContent) {
        logsContent.innerHTML = '';
    }
    
    // Скрываем контейнер логов, показываем загрузку
    const logsContainer = document.getElementById('processes-logs-container');
    const loadingIndicator = document.getElementById('processes-logs-loading');
    if (logsContainer) logsContainer.style.display = 'none';
    if (loadingIndicator) loadingIndicator.style.display = 'flex';
    
    // Сохраняем PID и временной диапазон
    window.currentProcessPid = modalProcessPid;
    window.currentProcessFromTime = fromTime;
    window.currentProcessToTime = toTime;
    
    // Загружаем логи напрямую с ноды (fromNode=true)
    processLogsPage = 1;
    processLogsTotal = 0;
    loadProcessLogs(modalNodeId, 1, fromTime, toTime, true);
}

// switchProcessTab больше не нужна
window.refreshProcesses = refreshProcesses;
window.pauseProcessLogs = pauseProcessLogs;
window.clearProcessLogs = clearProcessLogs;
window.exportProcessLogs = exportProcessLogs;
window.loadProcessLogsModal = loadProcessLogsModal;
window.closeProcessLogsModal = closeProcessLogsModal;
window.updateCustomDateRange = updateCustomDateRange;
window.confirmLoadProcessLogs = confirmLoadProcessLogs;

async function killProcess(pid, nodeId) {
    if (!pid || !nodeId) return;
    
    const confirmed = await window.showConfirm(
        `Вы уверены, что хотите завершить процесс ${pid}? Это действие нельзя отменить.`,
        'Завершение процесса',
        'danger'
    );
    if (!confirmed) return;
    
    try {
        const response = await fetch(`${API_BASE}/processes.php?node_id=${nodeId}&pid=${pid}&action=kill`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'kill', pid: pid })
        });
        
        if (!response.ok) {
            const text = await response.text();
            let error;
            try {
                error = JSON.parse(text);
            } catch {
                error = { error: `HTTP ${response.status}` };
            }
            throw new Error(error.error || `Ошибка ${response.status}`);
        }
        
        const text = await response.text();
        const data = text ? JSON.parse(text) : {};
        if (window.showToast) {
            window.showToast(data.message || `Процесс ${pid} завершен`, 'success');
        }
        
        // Обновляем список процессов
        setTimeout(() => loadProcesses(nodeId), 1000);
    } catch (error) {
        console.error('Error killing process:', error);
        if (window.showToast) {
            window.showToast(error.message || 'Ошибка завершения процесса', 'error');
}
    }
}

window.killProcess = killProcess;

document.addEventListener('DOMContentLoaded', () => {
    loadProcessLogsPerPageSetting();
    loadNodes();
    showEmptyState();
    
    // Обработчик выбора ноды
    document.getElementById('nodeFilter')?.addEventListener('change', (e) => {
        selectedNodeId = e.target.value;
        if (selectedNodeId) {
            loadProcesses(selectedNodeId);
        } else {
            showEmptyState();
        }
    });
    
    // Обработчики фильтров
    document.getElementById('statusFilter')?.addEventListener('change', applyFilters);
    document.getElementById('processSearch')?.addEventListener('input', applyFilters);
    
    // Пагинация логов процессов
    document.getElementById('process-logs-prev')?.addEventListener('click', () => {
        // Проверяем что модальное окно открыто
        const modal = document.getElementById('process-logs-modal');
        if (!modal || modal.style.display === 'none') {
            return;
        }
        
        if (!window.currentProcessPid || !modalNodeId) {
            return;
        }
        if (processLogsPage <= 1) return;
        const newPage = processLogsPage - 1;
        // Если логи загружены с ноды, используем сохраненные
        if (window.allProcessLogs && window.allProcessLogs.length > 0) {
            loadProcessLogs(modalNodeId, newPage);
        } else {
            loadProcessLogs(modalNodeId, newPage, window.currentProcessFromTime, window.currentProcessToTime);
        }
    });
    
    document.getElementById('process-logs-next')?.addEventListener('click', () => {
        // Проверяем что модальное окно открыто
        const modal = document.getElementById('process-logs-modal');
        if (!modal || modal.style.display === 'none') {
            return;
        }
        
        if (!window.currentProcessPid || !modalNodeId) {
            return;
        }
        const totalPages = processLogsTotal > 0 ? Math.ceil(processLogsTotal / processLogsPerPage) : 1;
        if (processLogsPage >= totalPages) return;
        const newPage = processLogsPage + 1;
        // Если логи загружены с ноды, используем сохраненные
        if (window.allProcessLogs && window.allProcessLogs.length > 0) {
            loadProcessLogs(modalNodeId, newPage);
        } else {
            loadProcessLogs(modalNodeId, newPage, window.currentProcessFromTime, window.currentProcessToTime);
        }
    });
    
    // Инициализация пагинации при загрузке
    updateProcessPagination();
    
    // Автообновление при выбранной ноде
    setInterval(() => {
        if (selectedNodeId) {
                loadProcesses(selectedNodeId, true);
        }
    }, 5000);
});

