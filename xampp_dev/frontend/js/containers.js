// Управление контейнерами
const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = API_BASE;
let selectedNodeId = null;
let allContainers = [];
let filteredContainers = [];
// Определяем текущую вкладку из URL
const urlParams = new URLSearchParams(window.location.search);
let currentTab = urlParams.get('tab') || 'containers';

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
    document.getElementById('containers-empty').style.display = 'flex';
    document.getElementById('containers-grid').style.display = 'none';
}

function showContainersGrid() {
    document.getElementById('containers-empty').style.display = 'none';
    document.getElementById('containers-grid').style.display = 'grid';
}

async function loadContainers(nodeId) {
    if (!nodeId) {
        showEmptyState();
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}/containers.php?node_id=${nodeId}`, { credentials: 'include' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const text = await response.text();
        const data = text ? JSON.parse(text) : { containers: [] };
        allContainers = data.containers || [];
        applyFilters();
    } catch (error) {
        console.error('Error loading containers:', error);
        allContainers = [];
        applyFilters();
    }
}

function applyFilters() {
    const statusFilter = document.getElementById('statusFilter')?.value || '';
    const searchTerm = document.getElementById('containerSearch')?.value.toLowerCase() || '';
    
    filteredContainers = allContainers.filter(c => {
        if (statusFilter && c.status !== statusFilter) return false;
        if (searchTerm && !c.name?.toLowerCase().includes(searchTerm) && 
            !c.image?.toLowerCase().includes(searchTerm)) return false;
        return true;
    });
    
    renderContainers(filteredContainers);
}

function renderContainers(containers) {
    const grid = document.getElementById('containers-grid');
    if (!grid) return;
    
    if (containers.length === 0) {
        grid.innerHTML = '<div class="text-center" style="grid-column: 1/-1; padding: 40px; color: var(--text-muted);">Контейнеры не найдены</div>';
        showContainersGrid();
        return;
    }
    
    grid.innerHTML = containers.map(c => `
        <div class="container-card">
            <div class="container-card-header">
                <div class="container-name">${c.name || c.container_id.substring(0, 12)}</div>
                <span class="status ${c.status}">${c.status}</span>
            </div>
            <div class="container-info">
                <div class="info-row">
                    <i data-lucide="package"></i>
                    <span><strong>Образ:</strong> ${c.image}</span>
                </div>
                <div class="info-row">
                    <i data-lucide="cpu"></i>
                    <span><strong>CPU:</strong> ${c.cpu_percent?.toFixed(1) || 0}%</span>
                </div>
                <div class="info-row">
                    <i data-lucide="hard-drive"></i>
                    <span><strong>Память:</strong> ${c.memory_percent?.toFixed(1) || 0}%</span>
                </div>
                ${c.networks ? `
                <div class="info-row">
                    <i data-lucide="network"></i>
                    <span><strong>Сети:</strong> ${c.networks.join(', ')}</span>
                </div>
                ` : ''}
                ${c.ports ? `
                <div class="info-row">
                    <i data-lucide="link"></i>
                    <span><strong>Порты:</strong> ${c.ports.map(p => `${p.host}:${p.container}`).join(', ')}</span>
                </div>
                ` : ''}
            </div>
            <div class="container-actions" style="margin-top: 16px; display: flex; gap: 8px;">
                <button onclick="containerAction('${c.container_id}', 'start', ${selectedNodeId})" class="primary" ${c.status === 'running' ? 'disabled' : ''}>
                    <i data-lucide="play"></i> Start
                </button>
                <button onclick="containerAction('${c.container_id}', 'stop', ${selectedNodeId})" ${c.status !== 'running' ? 'disabled' : ''}>
                    <i data-lucide="square"></i> Stop
                </button>
                <button onclick="containerAction('${c.container_id}', 'logs', ${selectedNodeId})">
                    <i data-lucide="file-text"></i> Logs
                </button>
            </div>
        </div>
    `).join('');
    
    showContainersGrid();
    
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.containers-menu-item').forEach(item => {
        item.classList.toggle('active', item.dataset.tab === tab);
    });
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.toggle('active', content.id === `${tab}-tab`);
    });
    
    if (tab === 'network' && selectedNodeId) {
        loadNetworkInfo(selectedNodeId);
    } else if (tab === 'containers' && selectedNodeId) {
        loadContainers(selectedNodeId);
    }
}

async function loadNetworkInfo(nodeId) {
    const networkContent = document.getElementById('network-content');
    const networkEmpty = document.getElementById('network-empty');
    
    if (!nodeId) {
        if (networkContent) networkContent.style.display = 'none';
        if (networkEmpty) networkEmpty.style.display = 'flex';
        return;
    }
    
    if (networkEmpty) networkEmpty.style.display = 'none';
    if (networkContent) networkContent.style.display = 'block';
    
    if (!networkContent) return;
    
    // Загрузка данных сети из API
    let networkData = { networks: [], connections: [], ports: [] };
    try {
        const response = await fetch(`${API_BASE}/containers.php?node_id=${nodeId}&network=1`, { credentials: 'include' });
        if (response.ok) {
            const text = await response.text();
            networkData = text ? JSON.parse(text) : { networks: [], connections: [], ports: [] };
        }
    } catch (error) {
        console.error('Error loading network data:', error);
    }
    
    networkContent.innerHTML = `
        <div class="grid-2" style="margin-top: 20px;">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="network"></i>
                        <span>Сети</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Имя сети</th>
                                    <th>Драйвер</th>
                                    <th>Контейнеры</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${networkData.networks.map(n => `
                                    <tr>
                                        <td><i data-lucide="network"></i> ${n.name}</td>
                                        <td>${n.driver}</td>
                                        <td>${n.containers}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="link"></i>
                        <span>Связи контейнеров</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>От</th>
                                    <th>К</th>
                                    <th>Сеть</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${networkData.connections.map(c => `
                                    <tr>
                                        <td><i data-lucide="arrow-right"></i> ${c.from}</td>
                                        <td>${c.to}</td>
                                        <td>${c.network}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="card" style="grid-column: 1 / -1;">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="link-2"></i>
                        <span>Проброшенные порты</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Контейнер</th>
                                    <th>Хост порт</th>
                                    <th>Порт контейнера</th>
                                    <th>Протокол</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${networkData.ports.map(p => `
                                    <tr>
                                        <td><i data-lucide="box"></i> ${p.container}</td>
                                        <td>${p.host}</td>
                                        <td>${p.container_port}</td>
                                        <td>${p.protocol.toUpperCase()}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    if (networkContent) networkContent.style.display = 'block';
    if (networkEmpty) networkEmpty.style.display = 'none';
    
    // Создание иконок после небольшой задержки для рендеринга
    setTimeout(() => {
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }, 100);
}

// Навигация теперь происходит через боковое меню

function refreshContainers() {
    if (selectedNodeId) {
        loadContainers(selectedNodeId);
    }
}

function containerAction(containerId, action, nodeId) {
    console.log('Container action', containerId, action, 'on node', nodeId);
    // TODO: API call
}

document.addEventListener('DOMContentLoaded', () => {
    loadNodes();
    
    // Определяем текущую вкладку из URL
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab') || 'containers';
    currentTab = tab;
    
    // Показываем нужную вкладку
    const containersTab = document.getElementById('containers-tab');
    const networkTab = document.getElementById('network-tab');
    
    if (tab === 'network') {
        if (containersTab) containersTab.classList.remove('active');
        if (networkTab) networkTab.classList.add('active');
    } else {
        if (networkTab) networkTab.classList.remove('active');
        if (containersTab) containersTab.classList.add('active');
        showEmptyState();
    }
    
    document.getElementById('nodeFilter')?.addEventListener('change', (e) => {
        selectedNodeId = e.target.value;
        if (selectedNodeId) {
            if (currentTab === 'containers') {
                loadContainers(selectedNodeId);
            } else if (currentTab === 'network') {
                loadNetworkInfo(selectedNodeId);
            }
        } else {
            showEmptyState();
            const networkContent = document.getElementById('network-content');
            const networkEmpty = document.getElementById('network-empty');
            if (networkContent) networkContent.style.display = 'none';
            if (networkEmpty) networkEmpty.style.display = 'flex';
        }
    });
    
    document.getElementById('statusFilter')?.addEventListener('change', applyFilters);
    document.getElementById('containerSearch')?.addEventListener('input', applyFilters);
    
    setInterval(() => {
        if (selectedNodeId && currentTab === 'containers') {
            loadContainers(selectedNodeId);
        }
    }, 10000);
});

