// Управление портами
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;

async function loadPorts() {
    try {
        const response = await fetch(`${API_BASE}/ports.php`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { ports: [] };
        renderPorts(data.ports || []);
    } catch (error) {
        console.error('Error loading ports:', error);
        renderPorts([]);
    }
}

function renderPorts(ports) {
    const tbody = document.getElementById('ports-tbody');
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
            <td><span class="status ${p.status}">${p.status}</span></td>
            <td>
                <button onclick="togglePort(${p.port}, '${p.type}')" class="${p.status === 'open' ? 'btn-danger' : 'primary'}">
                    ${p.status === 'open' ? '<i data-lucide="lock"></i>' : '<i data-lucide="unlock"></i>'}
                </button>
            </td>
        </tr>
    `).join('');
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function scanPorts() {
    // TODO: API call для сканирования портов
    console.log('Scanning ports...');
    loadPorts();
}

function togglePort(port, type) {
    // TODO: API call для открытия/закрытия порта
    console.log('Toggle port', port, type);
}

document.addEventListener('DOMContentLoaded', () => {
    loadPorts();
    setInterval(loadPorts, 15000);
});

