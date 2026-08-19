<?php
require_once __DIR__ . '/includes/layout.php';

render_layout_start(
    'Карта сети',
    'network-map',
    '<button class="primary" type="button" id="netmap-refresh"><i data-lucide="refresh-cw"></i> Обновить</button>'
);
?>
<link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/network_map.css')) ?>">

<div class="netmap">
    <aside class="netmap-tools">
        <button class="netmap-tool active" type="button" data-tool="select" title="Выбор"><i data-lucide="mouse-pointer-2"></i></button>
        <button class="netmap-tool" type="button" data-tool="pan" title="Перемещение холста"><i data-lucide="move"></i></button>
        <button class="netmap-tool" type="button" id="netmap-zoom-in" title="Приблизить"><i data-lucide="zoom-in"></i></button>
        <button class="netmap-tool" type="button" id="netmap-zoom-out" title="Отдалить"><i data-lucide="zoom-out"></i></button>
        <button class="netmap-tool" type="button" id="netmap-fit" title="Вписать"><i data-lucide="maximize"></i></button>
        <button class="netmap-tool" type="button" id="netmap-reset" title="Сбросить позиции"><i data-lucide="rotate-ccw"></i></button>
        <button class="netmap-tool" type="button" id="netmap-pause" title="Пауза анимации трафика"><i data-lucide="pause"></i></button>
    </aside>

    <div class="netmap-stage" id="netmap-stage">
        <svg class="netmap-links" id="netmap-links"></svg>
        <div class="netmap-nodes" id="netmap-nodes"></div>
        <div class="netmap-empty hidden" id="netmap-empty">
            <i data-lucide="share-2"></i>
            <h3>Нет узлов</h3>
            <p>Добавьте ноды, чтобы построить карту сети</p>
        </div>
    </div>

    <aside class="netmap-panel" data-collapsible="netmap-inspector">
        <div class="netmap-panel-head hm-panel-head">
            <h2>Инспектор</h2>
        </div>
        <div class="netmap-stats" id="netmap-stats"></div>
        <div class="netmap-block netmap-block-main" data-collapsible="netmap-selected">
            <div class="hm-panel-head"><h3>Устройство</h3></div>
            <div class="netmap-selected" id="netmap-selected">
                <p class="muted">Выберите узел на карте</p>
            </div>
        </div>
        <div class="netmap-block" data-collapsible="netmap-neighbors">
            <div class="hm-panel-head"><h3>Связи</h3></div>
            <div class="netmap-neighbors" id="netmap-neighbors"></div>
        </div>
        <div class="netmap-block" data-collapsible="netmap-alerts">
            <div class="hm-panel-head"><h3>Проблемы</h3></div>
            <div class="netmap-alerts" id="netmap-alerts"></div>
        </div>
    </aside>
</div>
<?php
render_layout_end(['/frontend/js/net-gear.js', '/frontend/js/network_map.js']);
