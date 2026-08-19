<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start(
    'UPnP',
    'upnp',
    '<button class="primary" type="button" id="upnp-scan-local"><i data-lucide="radar"></i> Сканировать LAN панели</button>'
);
?>
    <div class="page-controls">
        <div class="search-control">
            <input type="text" id="upnp-search" placeholder="Поиск устройства, модели, IP...">
        </div>
        <div class="sort-control">
            <select id="upnp-node-filter">
                <option value="">Все ноды</option>
            </select>
        </div>
        <div class="sort-control">
            <select id="upnp-vendor-filter">
                <option value="">Все вендоры</option>
            </select>
        </div>
        <div class="sort-control">
            <select id="upnp-type-filter">
                <option value="">Все типы</option>
                <option value="igd">Шлюзы IGD</option>
                <option value="router">Роутеры</option>
                <option value="switch">Коммутаторы</option>
                <option value="ap">Точки доступа</option>
                <option value="other">Прочие устройства</option>
            </select>
        </div>
        <button class="primary" type="button" id="upnp-scan-node"><i data-lucide="refresh-cw"></i> Скан через агент</button>
    </div>

    <div class="stats-grid" id="upnp-stats">
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #60a5fa, #2563eb);">
                <i data-lucide="router"></i>
            </div>
            <div class="stat-card-content">
                <h3>Устройства</h3>
                <div class="stat-value" id="upnp-count">0</div>
                <p class="stat-subtitle">обнаружено по UPnP</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #34d399, #059669);">
                <i data-lucide="globe"></i>
            </div>
            <div class="stat-card-content">
                <h3>Шлюзы IGD</h3>
                <div class="stat-value" id="upnp-igd-count">0</div>
                <p class="stat-subtitle">роутеры / NAT</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #38bdf8, #0284c7);">
                <i data-lucide="network"></i>
            </div>
            <div class="stat-card-content">
                <h3>Пробросы</h3>
                <div class="stat-value" id="upnp-map-count">0</div>
                <p class="stat-subtitle">port mappings</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon" style="background: linear-gradient(180deg, #fbbf24, #d97706);">
                <i data-lucide="activity"></i>
            </div>
            <div class="stat-card-content">
                <h3>Онлайн</h3>
                <div class="stat-value" id="upnp-online-count">0</div>
                <p class="stat-subtitle">видели &lt; 3 мин</p>
            </div>
        </div>
    </div>

    <div id="upnp-grid" class="gear-grid"></div>

    <div class="modal hidden" id="upnp-map-modal">
        <div class="modal-dialog" style="max-width: 520px;">
            <div class="modal-header">
                <h2>Добавить проброс порта</h2>
                <button class="modal-close" type="button" id="upnp-map-close"><i data-lucide="x"></i></button>
            </div>
            <form id="upnp-map-form">
                <input type="hidden" name="device_id" id="upnp-map-device">
                <div class="form-grid">
                    <label>Внешний порт<input type="number" name="external_port" min="1" max="65535" required></label>
                    <label>Протокол
                        <select name="protocol">
                            <option>TCP</option>
                            <option>UDP</option>
                        </select>
                    </label>
                    <label>Внутренний IP<input type="text" name="internal_client" placeholder="192.168.1.10" required></label>
                    <label>Внутренний порт<input type="number" name="internal_port" min="1" max="65535" required></label>
                    <label class="full">Описание<input type="text" name="description" value="HostMonitor"></label>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="primary">Создать mapping</button>
                </div>
            </form>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/net-gear.js', '/frontend/js/upnp.js']);
