// Управление процессами
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;
let allProcesses = [];
let filteredProcesses = [];
let selectedNodeId = null;

async function loadNodes() {
    try {
        const nodesRes = await fetch(`${API_BASE}/nodes.php`, { credentials: 'include' });
        if (!nodesRes.ok) throw new Error(`HTTP ${nodesRes.status}`);
        const nodesData = await nodesRes.json();
        const nodes = nodesData.nodes || [];
        populateNodeFilter(nodes);
    } catch (error) {
        console.error('Error loading nodes:', error);
        populateNodeFilter([]);
    }
}

async function loadProcesses(nodeId) {
    if (!nodeId) {
        showEmptyState();
        return;
    }
    
    try {
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
    }
}

function populateNodeFilter(nodes) {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    
    select.innerHTML = '<option value="">Выберите ноду</option>';
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
    
    tbody.innerHTML = processes.map(p => `
        <tr>
            <td>${p.pid}</td>
            <td><strong>${p.name}</strong></td>
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
                <button onclick="viewProcessLog(${p.pid}, ${selectedNodeId})" class="btn-outline" title="Лог процесса">
                    <i data-lucide="file-text"></i>
                </button>
                <button onclick="killProcess(${p.pid}, ${selectedNodeId})" class="btn-danger" title="Завершить">
                    <i data-lucide="x"></i>
                </button>
            </td>
        </tr>
    `).join('');
    
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

function viewProcessLog(pid, nodeId) {
    // TODO: Открыть модальное окно или страницу с логом процесса
    console.log('View log for process', pid, 'on node', nodeId);
    alert(`Лог процесса ${pid} на ноде ${nodeId}`);
}

function killProcess(pid, nodeId) {
    if (!confirm(`Завершить процесс ${pid}?`)) return;
    // TODO: API call
    console.log('Kill process', pid, 'on node', nodeId);
}

document.addEventListener('DOMContentLoaded', () => {
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
    
    // Автообновление при выбранной ноде
    setInterval(() => {
        if (selectedNodeId) {
            loadProcesses(selectedNodeId);
        }
    }, 10000);
});

