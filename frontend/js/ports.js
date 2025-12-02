// Управление портами
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;
let allPorts = [];
let allNodes = [];

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
        allNodes = nodesData.nodes || [];
        populateNodeFilter(allNodes);
    } catch (error) {
        console.error('Error loading nodes:', error);
        select.innerHTML = '<option value="">Ошибка загрузки нод</option>';
    } finally {
        select.disabled = false;
    }
}

function populateNodeFilter(nodes) {
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
}

async function loadPorts(silent = false) {
    try {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('ports-tbody', true);
        }
        const response = await fetch(`${API_BASE}/ports.php`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { ports: [] };
        allPorts = data.ports || [];
        
        // Добавляем имена нод к портам
        allPorts.forEach(p => {
            const node = allNodes.find(n => n.id === p.node_id);
            p.node_name = node ? node.name : `Node ${p.node_id}`;
        });
        
        applyFilters();
    } catch (error) {
        console.error('Error loading ports:', error);
        allPorts = [];
        applyFilters();
    } finally {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('ports-tbody', false);
        }
    }
}

function applyFilters() {
    const nodeFilter = document.getElementById('nodeFilter')?.value || '';
    const typeFilter = document.getElementById('portTypeFilter')?.value || '';
    const searchTerm = document.getElementById('portSearch')?.value.toLowerCase() || '';
    
    let filtered = [...allPorts];
    
    if (nodeFilter) {
        filtered = filtered.filter(p => p.node_id == nodeFilter);
    }
    
    if (typeFilter) {
        filtered = filtered.filter(p => p.type?.toLowerCase() === typeFilter.toLowerCase());
    }
    
    if (searchTerm) {
        filtered = filtered.filter(p => 
            p.port?.toString().includes(searchTerm) ||
            p.node_name?.toLowerCase().includes(searchTerm) ||
            p.process_name?.toLowerCase().includes(searchTerm)
        );
    }
    
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
            <td>${p.port}</td>
            <td>${p.type?.toUpperCase() || 'TCP'}</td>
            <td>${p.node_name || 'N/A'}</td>
            <td>${p.process_name || 'N/A'}</td>
            <td>${p.pid || 'N/A'}</td>
            <td><span class="status ${p.status}">${p.status || 'unknown'}</span></td>
            <td>
                <button onclick="togglePort(${p.port}, '${p.type || 'tcp'}', ${p.node_id})" class="${p.status === 'open' ? 'btn-danger' : 'primary'}">
                    ${p.status === 'open' ? '<i data-lucide="lock"></i>' : '<i data-lucide="unlock"></i>'}
                </button>
            </td>
        </tr>
    `).join('');
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

async function scanPorts() {
    try {
        showToast('Сканирование портов...', 'info');
        const response = await fetch(`${API_BASE}/ports.php?action=scan`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const text = await response.text();
        const data = text ? JSON.parse(text) : {};
        if (window.showToast) {
            window.showToast(data.message || 'Сканирование портов запущено', 'success');
        }
        
        // Обновляем список портов через 2 секунды
        setTimeout(() => loadPorts(), 2000);
    } catch (error) {
        console.error('Error scanning ports:', error);
        if (window.showToast) {
            window.showToast(error.message || 'Ошибка сканирования портов', 'error');
        }
    }
}

window.scanPorts = scanPorts;

function showToast(message, type = 'info') {
    if (window.showToast) {
        window.showToast(message, type);
    } else {
        alert(message);
    }
}

async function togglePort(port, type, nodeId) {
    if (!port || !type || !nodeId) return;
    
    const portObj = allPorts.find(p => p.port == port && p.node_id == nodeId);
    const isOpen = portObj?.status === 'open';
    const action = isOpen ? 'deny' : 'allow';
    const actionName = isOpen ? 'закрыть' : 'открыть';
    
    const confirmed = await window.showConfirm(
        `Вы уверены, что хотите ${actionName} порт ${port}/${type.toUpperCase()}?`,
        'Управление портом',
        'warning'
    );
    if (!confirmed) return;
    
    try {
        const response = await fetch(`${API_BASE}/ports.php?node_id=${nodeId}&action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                port: port,
                proto: type.toLowerCase(),
                action: action
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
        
        const text = await response.text();
        const data = text ? JSON.parse(text) : {};
        if (window.showToast) {
            window.showToast(data.message || `Порт ${actionName} успешно`, 'success');
        }
        
        // Обновляем список портов
        setTimeout(() => loadPorts(), 1000);
    } catch (error) {
        console.error('Error toggling port:', error);
        if (window.showToast) {
            window.showToast(error.message || 'Ошибка управления портом', 'error');
}
    }
}

window.togglePort = togglePort;

let currentPortsTab = 'ports';

function switchPortsTab(tab) {
    currentPortsTab = tab;
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    document.getElementById('ports-tab')?.classList.toggle('active', tab === 'ports');
    document.getElementById('interfaces-tab')?.classList.toggle('active', tab === 'interfaces');
    
    if (tab === 'interfaces') {
        loadInterfaces();
    } else {
        loadPorts();
    }
}

window.switchPortsTab = switchPortsTab;

async function loadInterfaces(silent = false) {
    try {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('interfaces-tbody', true);
        }
        const response = await fetch(`${API_BASE}/ports.php?action=interfaces`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { interfaces: [] };
        const interfaces = data.interfaces || [];
        
        renderInterfaces(interfaces);
    } catch (error) {
        console.error('Error loading interfaces:', error);
        renderInterfaces([]);
    } finally {
        if (!silent && window.toggleTableLoader) {
            window.toggleTableLoader('interfaces-tbody', false);
        }
    }
}

function renderInterfaces(interfaces) {
    const tbody = document.getElementById('interfaces-tbody');
    if (!tbody) return;
    
    if (interfaces.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center">Интерфейсы не найдены</td></tr>';
        return;
    }
    
    tbody.innerHTML = interfaces.map(i => `
        <tr>
            <td><strong>${i.name || 'N/A'}</strong></td>
            <td>${i.node_name || 'N/A'}</td>
            <td>${i.ip || 'N/A'}</td>
            <td>${i.netmask || 'N/A'}</td>
            <td><span class="status ${i.status}">${i.status || 'unknown'}</span></td>
            <td>${formatBytes(i.rx_bytes || 0)}</td>
            <td>${formatBytes(i.tx_bytes || 0)}</td>
        </tr>
    `).join('');
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadNodes().then(() => {
        if (currentPortsTab === 'ports') {
            loadPorts();
        } else {
            loadInterfaces();
        }
    });
    
    document.getElementById('nodeFilter')?.addEventListener('change', applyFilters);
    document.getElementById('portTypeFilter')?.addEventListener('change', applyFilters);
    document.getElementById('portSearch')?.addEventListener('input', applyFilters);
    document.getElementById('interfacesNodeFilter')?.addEventListener('change', () => loadInterfaces(true));
    document.getElementById('interfaceSearch')?.addEventListener('input', () => loadInterfaces(true));
    
    setInterval(() => {
        if (currentPortsTab === 'ports') {
            loadPorts(true);
        } else {
            loadInterfaces(true);
        }
    }, 15000);
});

