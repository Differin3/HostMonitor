// JavaScript для страницы SMART мониторинга дисков
(function() {
    const API_BASE = window.MONITORING_API_BASE || '/api';
    const esc = (v) => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    let allDrives = [];
    let selectedDrive = null;
    let charts = {};
    let currentRange = '7d';

    const CHART_ATTR_MAP = {
        'smart-reallocated-chart': { attr: 'Reallocated_Sector_Ct', id: 5, label: 'Reallocated Sectors', color: '#f87171', bg: 'rgba(248,113,113,0.15)' },
        'smart-temp-chart': { attr: 'Temperature_Celsius', id: 194, label: 'Temperature (°C)', color: '#fbbf24', bg: 'rgba(251,191,36,0.15)' },
        'smart-hours-chart': { attr: 'Power_On_Hours', id: 9, label: 'Power-On Hours', color: '#60a5fa', bg: 'rgba(96,165,250,0.15)' },
        'smart-pending-chart': { attr: 'Current_Pending_Sector', id: 197, label: 'Pending Sectors', color: '#fb923c', bg: 'rgba(251,146,60,0.15)' },
        'smart-uncorrectable-chart': { attr: 'Offline_Uncorrectable', id: 198, label: 'Uncorrectable Errors', color: '#c084fc', bg: 'rgba(192,132,252,0.15)' },
        'smart-load-cycle-chart': { attr: 'Load_Cycle_Count', id: 193, label: 'Load Cycle Count', color: '#34d399', bg: 'rgba(52,211,153,0.15)' },
    };

    const KEY_ATTRIBUTES = [
        { id: 5, name: 'Reallocated Sectors', icon: 'alert-octagon', color: '#f87171', warn: 10, crit: 50 },
        { id: 194, name: 'Temperature', icon: 'thermometer', color: '#fbbf24', warn: 50, crit: 60 },
        { id: 197, name: 'Pending Sectors', icon: 'clock', color: '#fb923c', warn: 1, crit: 10 },
        { id: 198, name: 'Uncorrectable', icon: 'x-octagon', color: '#c084fc', warn: 1, crit: 10 },
        { id: 9, name: 'Power-On Hours', icon: 'timer', color: '#60a5fa', warn: 30000, crit: 50000 },
        { id: 193, name: 'Load Cycle Count', icon: 'rotate-ccw', color: '#34d399', warn: 300000, crit: 600000 },
    ];

    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) return '—';
        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i];
    }

    function formatHours(h) {
        if (!h && h !== 0) return '—';
        h = parseInt(h);
        if (h < 24) return h + ' ч';
        const days = Math.floor(h / 24);
        if (days < 365) return days + ' д (' + h + ' ч)';
        const years = (days / 365).toFixed(1);
        return years + ' г (' + h + ' ч)';
    }

    function healthBadge(status) {
        const s = (status || 'unknown').toLowerCase();
        const map = {
            ok: '<span class="status status-online">✓ OK</span>',
            passed: '<span class="status status-online">✓ Passed</span>',
            warning: '<span class="status status-warning">⚠ Предупреждение</span>',
            failed: '<span class="status status-offline">✗ Критический</span>',
        };
        return map[s] || '<span class="status">' + (status || 'unknown') + '</span>';
    }

    function attrRowColor(attr, keyAttrs) {
        const ka = keyAttrs.find(k => k.id === attr.id);
        if (!ka) return '';
        const raw = parseInt(attr.raw);
        if (ka.id === 194) {
            if (raw >= ka.crit) return 'smart-row-critical';
            if (raw >= ka.warn) return 'smart-row-warning';
            return '';
        }
        if (raw >= ka.crit) return 'smart-row-critical';
        if (raw >= ka.warn) return 'smart-row-warning';
        return '';
    }

    async function loadDrives(silent = false) {
        const nodeSelect = document.getElementById('nodeFilter');
        const prevNode = nodeSelect?.value || '';

        try {
            const [drivesRes, nodesRes] = await Promise.all([
                fetch(API_BASE + '/smart.php', { credentials: 'include' }),
                fetch(API_BASE + '/nodes.php', { credentials: 'include' })
            ]);

            if (!drivesRes.ok) throw new Error('HTTP ' + drivesRes.status);

            const drivesText = await drivesRes.text();
            const drivesData = drivesText ? JSON.parse(drivesText) : { drives: [] };
            const nodesData = nodesRes.ok ? JSON.parse(await nodesRes.text()) : { nodes: [] };

            allDrives = drivesData.drives || [];
            const nodes = nodesData.nodes || [];

            // Имена нод
            allDrives.forEach(d => {
                const node = nodes.find(n => n.id === d.node_id);
                d._node_name = node ? node.name : 'Node ' + d.node_id;
            });

            populateNodeFilter(nodes, prevNode);
            updateSummary();
            applyFilters();
        } catch (e) {
            console.error('Error loading SMART drives:', e);
            applyFilters([]);
        }
    }

    function populateNodeFilter(nodes, selected) {
        const select = document.getElementById('nodeFilter');
        if (!select) return;
        select.innerHTML = '<option value="">Все ноды</option>';
        nodes.forEach(n => {
            const opt = document.createElement('option');
            opt.value = n.id;
            opt.textContent = n.name;
            select.appendChild(opt);
        });
        if (selected) select.value = selected;
    }

    function updateSummary() {
        const total = allDrives.length;
        const healthy = allDrives.filter(d => (d.health_status || '').toLowerCase() === 'ok' || (d.health_status || '').toLowerCase() === 'passed').length;
        const warnings = allDrives.filter(d => (d.health_status || '').toLowerCase() === 'warning').length;
        const failed = allDrives.filter(d => (d.health_status || '').toLowerCase() === 'failed').length;

        document.getElementById('total-drives').textContent = total;
        document.getElementById('healthy-drives').textContent = healthy;
        document.getElementById('warning-drives').textContent = warnings;
        document.getElementById('failed-drives').textContent = failed;
    }

    function applyFilters() {
        const nodeFilter = document.getElementById('nodeFilter')?.value || '';
        const healthFilter = document.getElementById('healthFilter')?.value || '';
        const search = (document.getElementById('driveSearch')?.value || '').toLowerCase();

        const filtered = allDrives.filter(d => {
            if (nodeFilter && d.node_id != nodeFilter) return false;
            if (healthFilter && (d.health_status || '').toLowerCase() !== healthFilter) return false;
            if (search) {
                const hay = [d.device_name, d.model, d.serial_number, d._node_name].join(' ').toLowerCase();
                if (!hay.includes(search)) return false;
            }
            return true;
        });

        renderDrivesTable(filtered);
    }

    function renderDrivesTable(drives) {
        const tbody = document.getElementById('smart-tbody');
        if (!tbody) return;

        if (!drives || drives.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center">SMART данные не найдены. Убедитесь, что smartmontools установлен на нодах.</td></tr>';
            return;
        }

        tbody.innerHTML = drives.map(d => {
            const temp = d.temperature != null ? d.temperature + '°C' : '—';
            const hours = formatHours(d.power_on_hours);
            const capacity = formatBytes(d.capacity_bytes);
            const bay = d.bay_number != null ? '#' + d.bay_number : '—';
            const isSelected = selectedDrive && selectedDrive.node_id === d.node_id && selectedDrive.device_name === d.device_name;
            const safeDevName = esc(d.device_name);
            const jsDevName = JSON.stringify(d.device_name || '');

            return `<tr class="${isSelected ? 'smart-row-selected' : ''} ${(d.health_status || '').toLowerCase() === 'failed' ? 'smart-row-critical' : ''} ${(d.health_status || '').toLowerCase() === 'warning' ? 'smart-row-warning' : ''}"
                        data-node-id="${d.node_id}" data-device="${safeDevName}" onclick="window._smartSelectFromRow(this)">
                <td>${healthBadge(d.health_status)}</td>
                <td><span class="node-badge">${esc(d._node_name || 'N/A')}</span></td>
                <td><strong>${safeDevName}</strong></td>
                <td>${esc(d.model || '—')}</td>
                <td><code>${esc(d.serial_number || '—')}</code></td>
                <td>${capacity}</td>
                <td>${temp}</td>
                <td>${hours}</td>
                <td>${bay}</td>
                <td>
                    <button onclick="event.stopPropagation(); window._smartSelectDrive(${parseInt(d.node_id)||0}, ${jsDevName})" class="btn-sm" title="Подробнее">
                        <i data-lucide="eye"></i>
                    </button>
                </td>
            </tr>`;
        }).join('');

        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    window.smartSelectDrive = async function(nodeId, deviceName) {
        selectedDrive = allDrives.find(d => d.node_id === nodeId && d.device_name === deviceName);
        if (!selectedDrive) return;

        document.getElementById('drive-detail').style.display = '';
        document.getElementById('detail-title').textContent =
            `${selectedDrive._node_name} / ${selectedDrive.device_name} — ${selectedDrive.model || 'Unknown'}`;

        // Рисуем карточки ключевых атрибутов
        renderKeyAttributes(selectedDrive.attributes || []);

        // Перерисовываем таблицу (выделение)
        applyFilters();

        // Загружаем историю
        await loadHistory();
    };

    window._smartSelectDrive = window.smartSelectDrive;
    window._smartSelectFromRow = function(row) {
        const nid = parseInt(row.dataset.nodeId) || 0;
        const dev = row.dataset.device || '';
        window.smartSelectDrive(nid, dev);
    };

    function renderKeyAttributes(attrs) {
        const container = document.getElementById('key-attrs');
        if (!container) return;

        const html = KEY_ATTRIBUTES.map(ka => {
            const attr = attrs.find(a => a.id === ka.id);
            const raw = attr ? parseInt(attr.raw) : null;
            const value = attr ? parseInt(attr.value) : null;
            let status = 'ok';
            if (raw !== null) {
                if (ka.id === 194) {
                    if (raw >= ka.crit) status = 'critical';
                    else if (raw >= ka.warn) status = 'warning';
                } else {
                    if (raw >= ka.crit) status = 'critical';
                    else if (raw >= ka.warn) status = 'warning';
                }
            }

            const borderColor = status === 'critical' ? '#dc2626' : status === 'warning' ? '#d97706' : ka.color;

            return `<div class="stat-card" style="border-left: 3px solid ${borderColor};">
                <div class="stat-card-icon" style="background: linear-gradient(180deg, ${ka.color}40, ${ka.color}20);">
                    <i data-lucide="${ka.icon}" style="color: ${ka.color};"></i>
                </div>
                <div class="stat-card-content">
                    <h3>${ka.name}</h3>
                    <div class="stat-value" style="color: ${status === 'critical' ? '#dc2626' : status === 'warning' ? '#d97706' : 'inherit'};">
                        ${raw !== null ? raw : '—'}
                    </div>
                    <p class="stat-subtitle">значение: ${value !== null ? value : '—'} · порог: ${ka.crit}</p>
                </div>
            </div>`;
        }).join('');

        container.innerHTML = html;
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    async function loadHistory() {
        if (!selectedDrive) return;

        try {
            const resp = await fetch(
                `${API_BASE}/smart.php?action=history&node_id=${selectedDrive.node_id}&device=${encodeURIComponent(selectedDrive.device_name)}&range=${currentRange}`,
                { credentials: 'include' }
            );
            if (!resp.ok) throw new Error('HTTP ' + resp.status);

            const data = JSON.parse(await resp.text());
            const history = data.history || {};

            // Обновляем графики
            Object.entries(CHART_ATTR_MAP).forEach(([canvasId, cfg]) => {
                const points = history[cfg.attr] || history['attr_' + cfg.id] || [];
                renderChart(canvasId, cfg, points);
            });

            // Полная таблица атрибутов
            renderAttrsTable(selectedDrive.attributes || []);
        } catch (e) {
            console.error('Error loading SMART history:', e);
        }
    }

    function renderChart(canvasId, cfg, points) {
        const canvas = document.getElementById(canvasId);
        const emptyEl = document.getElementById('empty-' + canvasId.split('-').slice(1, -1).join('-'));
        if (!canvas) return;

        if (points.length === 0) {
            canvas.style.display = 'none';
            if (emptyEl) emptyEl.classList.remove('hidden');
            return;
        }
        canvas.style.display = '';
        if (emptyEl) emptyEl.classList.add('hidden');

        // Уничтожаем старый график
        if (charts[canvasId]) {
            charts[canvasId].destroy();
        }

        const labels = points.map(p => {
            const d = new Date(p.ts);
            return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
        });
        const values = points.map(p => p.raw !== undefined ? p.raw : p.value);

        charts[canvasId] = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: cfg.label,
                    data: values,
                    borderColor: cfg.color,
                    backgroundColor: cfg.bg,
                    fill: true,
                    tension: 0.3,
                    pointRadius: values.length < 50 ? 2 : 0,
                    pointHoverRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15,23,42,0.9)',
                        titleColor: '#e2e8f0',
                        bodyColor: '#cbd5e1',
                        borderColor: 'rgba(255,255,255,0.1)',
                        borderWidth: 1,
                    }
                },
                scales: {
                    x: {
                        display: true,
                        ticks: { maxTicksLimit: 8, font: { size: 10 } },
                        grid: { display: false },
                    },
                    y: {
                        display: true,
                        beginAtZero: true,
                        ticks: { font: { size: 10 } },
                    }
                }
            }
        });
    }

    function renderAttrsTable(attrs) {
        const tbody = document.getElementById('attrs-tbody');
        if (!tbody) return;

        if (!attrs || attrs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center">Нет атрибутов</td></tr>';
            return;
        }

        tbody.innerHTML = attrs.map(a => {
            const rowClass = attrRowColor(a, KEY_ATTRIBUTES);
            const thresh = a.threshold > 0 ? a.threshold : '—';
            return `<tr class="${rowClass}">
                <td><code>${esc(String(a.id))}</code></td>
                <td><strong>${esc(a.name)}</strong></td>
                <td>${esc(String(a.value))}</td>
                <td>${esc(String(a.worst))}</td>
                <td>${thresh}</td>
                <td><code>${esc(String(a.raw))}</code></td>
                <td><span class="badge">${esc(a.flags || '—')}</span></td>
            </tr>`;
        }).join('');
    }

    // Range buttons
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('#smart-ranges button');
        if (!btn) return;
        document.querySelectorAll('#smart-ranges button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentRange = btn.dataset.range || '7d';
        if (selectedDrive) loadHistory();
    });

    // Init
    document.addEventListener('DOMContentLoaded', function() {
        loadDrives();

        // Auto-refresh каждые 60 сек
        setInterval(() => loadDrives(true), 60000);

        // Фильтры
        ['nodeFilter', 'healthFilter'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', applyFilters);
        });
        const search = document.getElementById('driveSearch');
        if (search) search.addEventListener('input', applyFilters);

        // Refresh кнопка
        const refreshBtn = document.getElementById('refresh-smart');
        if (refreshBtn) refreshBtn.addEventListener('click', () => loadDrives());
    });
})();
