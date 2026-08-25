const API_BASE = window.MONITORING_API_BASE || '/api';

let allDevices = [];
let allNodes = [];

const els = {
    grid: document.getElementById('upnp-grid'),
    search: document.getElementById('upnp-search'),
    nodeFilter: document.getElementById('upnp-node-filter'),
    typeFilter: document.getElementById('upnp-type-filter'),
    vendorFilter: document.getElementById('upnp-vendor-filter'),
    count: document.getElementById('upnp-count'),
    igdCount: document.getElementById('upnp-igd-count'),
    mapCount: document.getElementById('upnp-map-count'),
    onlineCount: document.getElementById('upnp-online-count'),
    modal: document.getElementById('upnp-map-modal'),
    form: document.getElementById('upnp-map-form'),
};

const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

const formatBytes = (n) => {
    const v = Number(n) || 0;
    if (v >= 1099511627776) return `${(v / 1099511627776).toFixed(1)} ТиБ`;
    if (v >= 1073741824) return `${(v / 1073741824).toFixed(1)} ГиБ`;
    if (v >= 1048576) return `${(v / 1048576).toFixed(1)} МиБ`;
    if (v >= 1024) return `${(v / 1024).toFixed(1)} КиБ`;
    return `${v} Б`;
};

const formatBitrate = (n) => {
    const v = Number(n) || 0;
    if (!v) return '—';
    if (v >= 1000000) return `${(v / 1000000).toFixed(0)} Мбит/с`;
    if (v >= 1000) return `${(v / 1000).toFixed(0)} Кбит/с`;
    return `${v} бит/с`;
};

const deviceIcon = (device) => {
    const t = `${device.device_type || ''} ${device.friendly_name || ''}`.toLowerCase();
    if (device.is_igd == 1 || /gateway|router|igd/.test(t)) return 'router';
    if (/media|renderer|server/.test(t)) return 'tv';
    if (/printer/.test(t)) return 'printer';
    if (/phone|mobile/.test(t)) return 'smartphone';
    return 'radio';
};

async function fetchJson(url, options = {}) {
    const res = await fetch(url, { credentials: 'include', ...options });
    const text = await res.text();
    const data = text ? JSON.parse(text) : {};
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
}

function filteredDevices() {
    const q = (els.search?.value || '').toLowerCase();
    const nodeId = els.nodeFilter?.value || '';
    const type = els.typeFilter?.value || '';
    const vendor = els.vendorFilter?.value || '';
    return allDevices.filter((d) => {
        if (nodeId && String(d.node_id) !== nodeId) return false;
        if (type === 'igd' && Number(d.is_igd) !== 1) return false;
        if (type === 'other') {
            const k = window.HostMonitorGear ? HostMonitorGear.detectKind(d) : 'device';
            if (Number(d.is_igd) === 1 || ['router', 'switch', 'ap', 'core'].includes(k)) return false;
        }
        if (type && type !== 'igd' && type !== 'other') {
            const k = window.HostMonitorGear ? HostMonitorGear.detectKind(d) : '';
            if (k !== type && !(type === 'router' && k === 'core')) return false;
        }
        if (vendor) {
            const v = window.HostMonitorGear ? HostMonitorGear.detectVendor(d) : 'generic';
            if (v !== vendor) return false;
        }
        if (!q) return true;
        return `${d.friendly_name} ${d.model_name} ${d.host} ${d.wan_ip} ${d.manufacturer}`.toLowerCase().includes(q);
    });
}

function renderStats(list) {
    els.count.textContent = String(list.length);
    els.igdCount.textContent = String(list.filter((d) => Number(d.is_igd) === 1).length);
    els.mapCount.textContent = String(list.reduce((sum, d) => sum + (d.port_mappings || []).length, 0));
        els.onlineCount.textContent = String(list.filter((d) => window.HostMonitorGear ? HostMonitorGear.isOnline(d) : !!d.online).length);
}

function render() {
    const list = filteredDevices();
    renderStats(list);
    if (!list.length) {
        els.grid.innerHTML = '<div class="card"><p class="list-empty">UPnP-устройства не найдены. Запустите скан через агент или LAN панели.</p></div>';
        if (typeof lucide !== 'undefined') lucide.createIcons();
        return;
    }
    els.grid.innerHTML = list.map((d) => {
        if (window.HostMonitorGear) {
            return HostMonitorGear.cardHtml(d, { variant: 'panel' });
        }
        const online = d.online ? 'status-online' : 'status-offline';
        const maps = (d.port_mappings || []).map((m) => `
            <tr>
                <td>${escapeHtml(m.protocol)}</td>
                <td>${escapeHtml(m.external_port)}</td>
                <td>${escapeHtml(m.internal_client)}:${escapeHtml(m.internal_port)}</td>
                <td>${escapeHtml(m.description || '')}</td>
                <td>
                    ${d.node_id ? `<button class="icon-btn" data-del-map data-id="${d.id}" data-port="${m.external_port}" data-proto="${m.protocol}" title="Удалить"><i data-lucide="trash-2"></i></button>` : ''}
                </td>
            </tr>
        `).join('');
        const services = (d.services || []).map((s) => `<span class="pill">${escapeHtml((s.service_type || '').split(':').slice(-2).join(':'))}</span>`).join(' ');
        return `
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="${deviceIcon(d)}"></i>
                        <span>${escapeHtml(d.friendly_name || d.model_name || d.udn)}</span>
                    </div>
                    <span class="status ${online}">${d.online ? 'online' : 'offline'}</span>
                </div>
                <div class="info-row"><i data-lucide="server"></i><span>${escapeHtml(d.node_name || 'panel')} · ${escapeHtml(d.host || '—')}</span></div>
                <div class="info-row"><i data-lucide="cpu"></i><span>${escapeHtml(d.manufacturer || '—')} ${escapeHtml(d.model_name || '')}</span></div>
                <div class="info-row"><i data-lucide="globe"></i><span>WAN ${escapeHtml(d.wan_ip || '—')} · ${escapeHtml(d.connection_status || 'n/a')}</span></div>
                <div class="info-row"><i data-lucide="activity"></i><span>↓ ${formatBitrate(d.link_bitrate_down)} · ↑ ${formatBitrate(d.link_bitrate_up)}</span></div>
                <div class="info-row"><i data-lucide="hard-drive"></i><span>RX ${formatBytes(d.bytes_received)} · TX ${formatBytes(d.bytes_sent)}</span></div>
                <div style="margin: 10px 0; display:flex; flex-wrap:wrap; gap:6px;">${services || '<span class="text-muted">нет сервисов</span>'}</div>
                ${d.node_id ? `<button class="primary" data-add-map="${d.id}"><i data-lucide="plus"></i> Проброс порта</button>` : ''}
                <div class="table-container compact-table" style="margin-top:12px; max-height:220px;">
                    <table>
                        <thead><tr><th>Proto</th><th>Ext</th><th>Internal</th><th>Desc</th><th></th></tr></thead>
                        <tbody>${maps || '<tr><td colspan="5">Нет port mapping</td></tr>'}</tbody>
                    </table>
                </div>
            </div>
        `;
    }).join('');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function load() {
    const [upnp, nodes] = await Promise.all([
        fetchJson(`${API_BASE}/upnp.php`),
        fetchJson(`${API_BASE}/nodes.php`).catch(() => ({ nodes: [] })),
    ]);
    allDevices = upnp.devices || [];
    allNodes = nodes.nodes || nodes.data || [];
    const prev = els.nodeFilter.value;
    els.nodeFilter.innerHTML = '<option value="">Все ноды</option>' + allNodes.map((n) => `<option value="${escapeHtml(n.id)}">${escapeHtml(n.name)}</option>`).join('');
    if (prev) els.nodeFilter.value = prev;
    fillVendorFilter();
    render();
}

function fillVendorFilter() {
    if (!els.vendorFilter) return;
    const prev = els.vendorFilter.value;
    const found = window.HostMonitorGear ? HostMonitorGear.listVendors(allDevices) : [];
    const options = [['', 'Все вендоры'], ...found];
    els.vendorFilter.innerHTML = options.map(([id, label]) => `<option value="${escapeHtml(id)}">${escapeHtml(label)}</option>`).join('');
    if (prev && found.some(([id]) => id === prev)) els.vendorFilter.value = prev;
}

function openModal(deviceId) {
    document.getElementById('upnp-map-device').value = deviceId;
    els.modal.classList.remove('hidden');
    els.modal.classList.add('active');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeModal() {
    els.modal.classList.remove('active');
    setTimeout(() => els.modal.classList.add('hidden'), 180);
}

els.vendorFilter?.addEventListener('change', render);
els.search?.addEventListener('input', render);
els.nodeFilter?.addEventListener('change', render);
els.typeFilter?.addEventListener('change', render);

document.getElementById('upnp-scan-local')?.addEventListener('click', async () => {
    try {
        const result = await fetchJson(`${API_BASE}/upnp.php?action=scan-local`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        window.showToast?.(`Найдено ${result.found ?? 0} устройств в LAN панели`, 'success');
        await load();
    } catch (e) {
        window.showToast?.(e.message, 'error');
    }
});

document.getElementById('upnp-scan-node')?.addEventListener('click', async () => {
    const nodeId = els.nodeFilter.value;
    if (!nodeId) {
        window.showToast?.('Выберите ноду для скана через агент', 'warning');
        return;
    }
    try {
        await fetchJson(`${API_BASE}/upnp.php?action=scan`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ node_id: Number(nodeId) }),
        });
        window.showToast?.('Скан UPnP поставлен в очередь агента', 'success');
    } catch (e) {
        window.showToast?.(e.message, 'error');
    }
});

els.grid?.addEventListener('click', async (event) => {
    const addBtn = event.target.closest('[data-add-map]');
    if (addBtn) {
        openModal(addBtn.getAttribute('data-add-map'));
        return;
    }
    const delBtn = event.target.closest('[data-del-map]');
    if (!delBtn) return;
    const ok = await window.showConfirm('Удалить port mapping?', 'UPnP', 'danger');
    if (!ok) return;
    try {
        await fetchJson(`${API_BASE}/upnp.php?action=delete-mapping`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                device_id: Number(delBtn.dataset.id),
                external_port: Number(delBtn.dataset.port),
                protocol: delBtn.dataset.proto,
            }),
        });
        window.showToast?.('Команда удаления отправлена агенту', 'success');
    } catch (e) {
        window.showToast?.(e.message, 'error');
    }
});

document.getElementById('upnp-map-close')?.addEventListener('click', closeModal);
els.form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fd = new FormData(els.form);
    try {
        await fetchJson(`${API_BASE}/upnp.php?action=add-mapping`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                device_id: Number(fd.get('device_id')),
                external_port: Number(fd.get('external_port')),
                protocol: fd.get('protocol'),
                internal_client: fd.get('internal_client'),
                internal_port: Number(fd.get('internal_port')),
                description: fd.get('description'),
            }),
        });
        closeModal();
        window.showToast?.('Команда проброса отправлена агенту', 'success');
    } catch (e) {
        window.showToast?.(e.message, 'error');
    }
});

load();
setInterval(load, 15000);
