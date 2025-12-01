// JavaScript для страницы портов нод
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;

async function loadPorts(silent = false) {
    const select = document.getElementById('nodeFilter');
    const previousValue = select?.value || '';
    if (select && !silent) {
        select.disabled = true;
        select.innerHTML = '<option value="">Загрузка нод...</option>';
    }
    
    try {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('ports-tbody', true);
        }
        const [portsRes, nodesRes] = await Promise.all([
            fetch(`${API_BASE}/ports.php`, { credentials: 'include' }),
            fetch(`${API_BASE}/nodes.php`, { credentials: 'include' })
        ]);
        
        if (!portsRes.ok || !nodesRes.ok) throw new Error('HTTP error');
        
        const portsText = await portsRes.text();
        const nodesText = await nodesRes.text();
        const portsData = portsText ? JSON.parse(portsText) : { ports: [] };
        const nodesData = nodesText ? JSON.parse(nodesText) : { nodes: [] };
        
        const ports = portsData.ports || [];
        const nodes = nodesData.nodes || [];
        
        ports.forEach(p => {
            const node = nodes.find(n => n.id === p.node_id);
            p.node_name = node ? node.name : `Node ${p.node_id}`;
        });
        
        allPorts = ports;
        populateNodeFilter(nodes, previousValue);
        applyFilters(ports);
    } catch (error) {
        console.error('Error loading ports:', error);
        populateNodeFilter([]);
        applyFilters([]);
    } finally {
        if (select && !silent) {
            select.disabled = false;
        }
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('ports-tbody', false);
        }
    }
}

function populateNodeFilter(nodes, selectedValue = '') {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    
    select.innerHTML = '<option value="">Все ноды</option>';
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
    
    if (selectedValue) {
        select.value = selectedValue;
    }
}

function applyFilters(allPorts = []) {
    const nodeFilter = document.getElementById('nodeFilter')?.value || '';
    const typeFilter = document.getElementById('portTypeFilter')?.value || '';
    const searchTerm = document.getElementById('portSearch')?.value.toLowerCase() || '';
    
    const filtered = allPorts.filter(p => {
        if (nodeFilter && p.node_id != nodeFilter) return false;
        if (typeFilter && p.type !== typeFilter) return false;
        if (searchTerm && !p.port.toString().includes(searchTerm) && 
            !p.process?.toLowerCase().includes(searchTerm)) return false;
        return true;
    });
    
    renderPorts(filtered);
}

function renderPorts(ports) {
    const tbody = document.getElementById('ports-tbody');
    if (!tbody) return;
    
    if (ports.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center">Порты не найдены</td></tr>';
        return;
    }
    
    tbody.innerHTML = ports.map(p => `
        <tr>
            <td><strong>${p.port}</strong></td>
            <td><span class="badge">${p.type?.toUpperCase() || 'TCP'}</span></td>
            <td><span class="node-badge">${p.node_name || 'N/A'}</span></td>
            <td>${p.process || '-'}</td>
            <td>${p.pid || '-'}</td>
            <td><span class="status ${p.status}">${p.status || 'unknown'}</span></td>
            <td>
                <button onclick="closePort(${p.port}, ${p.node_id})" class="btn-danger" title="Закрыть порт">
                    <i data-lucide="x"></i>
                </button>
            </td>
        </tr>
    `).join('');
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function scanPorts() {
    console.log('Scanning ports...');
    // TODO: API call
}

async function closePort(port, nodeId) {
    if (!port || !nodeId) return;
    
    const confirmed = await window.showConfirm(`Вы уверены, что хотите закрыть порт ${port}?`, 'Закрытие порта', 'warning');
    if (!confirmed) return;
    
    try {
        const response = await fetch(`${API_BASE}/ports.php?node_id=${nodeId}&action=deny`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                port: port,
                proto: 'tcp',
                action: 'deny'
            })
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
        
        const data = await response.json();
        if (window.showToast) {
            window.showToast(data.message || `Порт ${port} закрыт`, 'success');
        }
        
        // Обновляем список портов
        setTimeout(() => loadPorts(), 1000);
    } catch (error) {
        console.error('Error closing port:', error);
        if (window.showToast) {
            window.showToast(error.message || 'Ошибка закрытия порта', 'error');
        }
    }
}

window.closePort = closePort;

let allPorts = [];

document.addEventListener('DOMContentLoaded', () => {
    loadPorts();
    setInterval(() => loadPorts(true), 10000);
    
    const nodeFilter = document.getElementById('nodeFilter');
    const portTypeFilter = document.getElementById('portTypeFilter');
    const portSearch = document.getElementById('portSearch');
    
    if (nodeFilter) {
        nodeFilter.addEventListener('change', (e) => {
            e.preventDefault();
            e.stopPropagation();
            applyFilters(allPorts);
        });
    }
    
    if (portTypeFilter) {
        portTypeFilter.addEventListener('change', (e) => {
            e.preventDefault();
            e.stopPropagation();
            applyFilters(allPorts);
        });
    }
    
    if (portSearch) {
        portSearch.addEventListener('input', () => {
            applyFilters(allPorts);
        });
    }
    
    // Предотвращаем отправку формы, если селект находится в форме
    const form = nodeFilter?.closest('form');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            return false;
        });
    }
});

