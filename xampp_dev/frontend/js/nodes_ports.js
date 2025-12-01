// JavaScript для страницы портов нод
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;

async function loadPorts() {
    try {
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
        
        populateNodeFilter(nodes);
        applyFilters(ports);
    } catch (error) {
        console.error('Error loading ports:', error);
        populateNodeFilter([]);
        applyFilters([]);
    }
}

function populateNodeFilter(nodes) {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    
    select.innerHTML = '<option value="">Все ноды</option>';
    nodes.forEach(node => {
        const option = document.createElement('option');
        option.value = node.id;
        option.textContent = node.name;
        select.appendChild(option);
    });
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

function closePort(port, nodeId) {
    if (!confirm(`Закрыть порт ${port}?`)) return;
    console.log('Close port', port, nodeId);
    // TODO: API call
}

let allPorts = [];

document.addEventListener('DOMContentLoaded', () => {
    loadPorts();
    setInterval(loadPorts, 10000);
    
    document.getElementById('nodeFilter')?.addEventListener('change', () => {
        loadPorts().then(() => {
            const nodeFilter = document.getElementById('nodeFilter')?.value;
            if (nodeFilter) {
                applyFilters(allPorts);
            }
        });
    });
    document.getElementById('portTypeFilter')?.addEventListener('change', () => applyFilters(allPorts));
    document.getElementById('portSearch')?.addEventListener('input', () => applyFilters(allPorts));
});

