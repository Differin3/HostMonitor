<?php
require_once __DIR__ . '/includes/layout.php';

// Определяем активную вкладку из URL
$tab = $_GET['tab'] ?? 'containers';
$activeSlug = $tab === 'network' ? 'containers-network' : 'containers';

render_layout_start('Контейнеры', $activeSlug, '<button class="primary" onclick="refreshContainers()"><i data-lucide="refresh-cw"></i> Обновить</button>');
?>
    <div class="compact-filters">
        <select id="nodeFilter" class="compact-select">
            <option value="">Выберите ноду</option>
        </select>
        <select id="statusFilter" class="compact-select">
            <option value="">Все статусы</option>
            <option value="running">Запущены</option>
            <option value="stopped">Остановлены</option>
            <option value="paused">Приостановлены</option>
        </select>
        <div class="compact-search">
            <input type="text" id="containerSearch" placeholder="Поиск контейнера...">
        </div>
    </div>

    <div class="containers-main">
        <div id="containers-tab" class="tab-content <?= $tab === 'containers' ? 'active' : '' ?>">
            <div id="containers-empty" class="empty-state">
                <i data-lucide="search"></i>
                <h3>Выберите ноду</h3>
                <p>Для просмотра контейнеров выберите ноду из списка выше</p>
            </div>
            <div id="containers-grid" class="containers-grid" style="display: none;"></div>
        </div>
        
        <div id="network-tab" class="tab-content <?= $tab === 'network' ? 'active' : '' ?>">
            <div id="network-empty" class="empty-state">
                <i data-lucide="network"></i>
                <h3>Выберите ноду</h3>
                <p>Для просмотра сетевой информации выберите ноду из списка выше</p>
            </div>
            <div id="network-content" style="display: none;"></div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/containers.js']);

