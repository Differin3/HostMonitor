const API_BASE = window.MONITORING_API_BASE || '/api';
const API_URL = `${API_BASE}/nodes.php`;
let selectedNodeId = null;

const toneForPct = (value) => {
    const n = Number(value) || 0;
    if (n >= 90) return 'bad';
    if (n >= 75) return 'warn';
    return 'ok';
};

const setMeter = (id, value) => {
    const el = document.getElementById(id);
    if (!el) return;
    const pct = Math.max(0, Math.min(100, Number(value) || 0));
    const bar = el.querySelector('span');
    if (bar) bar.style.width = `${pct}%`;
    el.dataset.tone = toneForPct(pct);
};

async function loadNodes() {
    const select = document.getElementById('nodeFilter');
    if (!select) return;
    select.disabled = true;
    select.innerHTML = '<option value="">Загрузка нод...</option>';
    try {
        const nodesRes = await fetch(API_URL, { credentials: 'include' });
        if (!nodesRes.ok) throw new Error(`HTTP ${nodesRes.status}`);
        const nodesData = JSON.parse(await nodesRes.text() || '{}');
        populateNodeFilter(nodesData.nodes || []);
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
    select.innerHTML = '<option value="">Выберите ноду</option>';
    if (nodes.length === 0) {
        select.innerHTML = '<option value="">Ноды не найдены</option>';
        return;
    }
    nodes.forEach((node) => {
        const option = document.createElement('option');
        option.value = node.id;
        option.textContent = node.name;
        select.appendChild(option);
    });
}

function showEmptyState() {
    document.getElementById('metrics-empty').style.display = 'flex';
    document.getElementById('metrics-data').style.display = 'none';
}

function showMetricsData() {
    document.getElementById('metrics-empty').style.display = 'none';
    document.getElementById('metrics-data').style.display = 'block';
}

const loadMetrics = async (nodeId) => {
    if (!nodeId) {
        showEmptyState();
        return;
    }
    try {
        const res = await fetch(`${API_URL}?id=${nodeId}`, { credentials: 'include' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = JSON.parse(await res.text() || '{}');
        const node = data?.node || null;
        if (!node) {
            showEmptyState();
            return;
        }
        const cpu = Number(node.cpu_usage) || 0;
        const ram = Number(node.memory_usage) || 0;
        const disk = Number(node.disk_usage) || 0;
        document.getElementById('avg-cpu').textContent = cpu.toFixed(1) + '%';
        document.getElementById('avg-ram').textContent = ram.toFixed(1) + '%';
        document.getElementById('avg-disk').textContent = disk.toFixed(1) + '%';
        document.getElementById('node-status').textContent = node.status || 'offline';
        document.getElementById('node-net').textContent =
            `${formatBytes(node.network_in || 0)} ↓  ${formatBytes(node.network_out || 0)} ↑`;
        const cpuSub = document.getElementById('cpu-sub');
        if (cpuSub) {
            const load = Number(node.load_avg) || 0;
            const cores = Number(node.cpu_count) || 0;
            cpuSub.textContent = cores ? `load ${load.toFixed(2)} · ${cores} CPU` : `load ${load.toFixed(2)}`;
        }
        const ramSub = document.getElementById('ram-sub');
        if (ramSub) {
            const used = Number(node.memory_used) || 0;
            const total = Number(node.memory_total) || 0;
            const swap = Number(node.swap_percent) || 0;
            ramSub.textContent = total
                ? `${formatBytes(used)} / ${formatBytes(total)} · swap ${swap.toFixed(0)}%`
                : `swap ${swap.toFixed(0)}%`;
        }
        const diskSub = document.getElementById('disk-sub');
        if (diskSub) {
            const used = Number(node.disk_used) || 0;
            const total = Number(node.disk_total) || 0;
            diskSub.textContent = total ? `${formatBytes(used)} / ${formatBytes(total)}` : '—';
        }
        setMeter('meter-cpu', cpu);
        setMeter('meter-ram', ram);
        setMeter('meter-disk', disk);
        document.getElementById('metric-cpu').dataset.tone = toneForPct(cpu);
        document.getElementById('metric-ram').dataset.tone = toneForPct(ram);
        document.getElementById('metric-disk').dataset.tone = toneForPct(disk);
        document.getElementById('metric-status').dataset.tone = node.status === 'online' ? 'ok' : 'bad';
        const gpuCard = document.getElementById('metric-gpu');
        const gpu = node.gpu_usage;
        if (gpuCard && gpu != null && gpu !== '') {
            gpuCard.style.display = '';
            const gpuN = Number(gpu) || 0;
            document.getElementById('avg-gpu').textContent = gpuN.toFixed(1) + '%';
            setMeter('meter-gpu', gpuN);
            gpuCard.dataset.tone = toneForPct(gpuN);
            const gpus = Array.isArray(node.gpu) ? node.gpu : [];
            const names = gpus.map((g) => g.gpu_name || g.name).filter(Boolean);
            const temp = gpus[0] && gpus[0].temperature != null ? `${Number(gpus[0].temperature).toFixed(0)}°C` : '';
            const mem = gpus[0] && gpus[0].memory_total
                ? `${formatBytes((gpus[0].memory_used || 0) * 1024 * 1024)} / ${formatBytes(gpus[0].memory_total * 1024 * 1024)}`
                : '';
            document.getElementById('gpu-sub').textContent = [names[0] || 'GPU', temp, mem].filter(Boolean).join(' · ');
        } else if (gpuCard) {
            gpuCard.style.display = 'none';
        }
        showMetricsData();
        if (typeof lucide !== 'undefined') lucide.createIcons();
    } catch (error) {
        console.error('Ошибка загрузки метрик:', error);
        showEmptyState();
    }
};

const formatBytes = (bytes) => {
    if (!bytes) return '0 Б';
    const k = 1024;
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

document.addEventListener('DOMContentLoaded', () => {
    loadNodes();
    showEmptyState();
    document.getElementById('nodeFilter')?.addEventListener('change', (e) => {
        selectedNodeId = e.target.value;
        if (selectedNodeId) loadMetrics(selectedNodeId);
        else showEmptyState();
    });
    document.getElementById('refresh-metrics')?.addEventListener('click', () => {
        if (selectedNodeId) loadMetrics(selectedNodeId);
    });
    setInterval(() => {
        if (selectedNodeId) loadMetrics(selectedNodeId);
    }, 8000);
});
