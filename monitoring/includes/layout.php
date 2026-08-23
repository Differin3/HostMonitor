<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

$menuItems = [
    ['slug' => 'dashboard', 'label' => 'Дашборд', 'href' => 'index.php', 'icon' => 'home'],
    ['slug' => 'network-map', 'label' => 'Карта сети', 'href' => 'network_map.php', 'icon' => 'share-2'],
    [
        'slug' => 'nodes',
        'label' => 'Сервера',
        'icon' => 'server',
        'children' => [
            ['slug' => 'nodes', 'label' => 'Управление', 'href' => 'nodes.php', 'icon' => 'settings'],
            ['slug' => 'nodes-stats', 'label' => 'Статистика', 'href' => 'nodes_stats.php', 'icon' => 'bar-chart-2'],
            ['slug' => 'nodes-billing', 'label' => 'биллинг', 'href' => 'billing.php', 'icon' => 'wallet'],
            ['slug' => 'nodes-traffic', 'label' => 'Расход трафика', 'href' => 'nodes_traffic.php', 'icon' => 'trending-up'],
            ['slug' => 'nodes-metrics', 'label' => 'Метрики', 'href' => 'nodes_metrics.php', 'icon' => 'activity'],
            ['slug' => 'nodes-ports', 'label' => 'Порты', 'href' => 'nodes_ports.php', 'icon' => 'network'],
            ['slug' => 'upnp', 'label' => 'UPnP', 'href' => 'upnp.php', 'icon' => 'router'],
            ['slug' => 'databases', 'label' => 'Базы данных', 'href' => 'databases.php', 'icon' => 'database'],
        ]
    ],
    ['slug' => 'processes', 'label' => 'Процессы', 'href' => 'processes.php', 'icon' => 'cpu'],
    [
        'slug' => 'containers',
        'label' => 'Контейнеры',
        'icon' => 'box',
        'children' => [
            ['slug' => 'containers', 'label' => 'Контейнеры', 'href' => 'containers.php?tab=containers', 'icon' => 'box'],
            ['slug' => 'containers-network', 'label' => 'Сеть', 'href' => 'containers.php?tab=network', 'icon' => 'network'],
        ]
    ],
    ['slug' => 'logs', 'label' => 'Логи', 'href' => 'logs.php', 'icon' => 'file-text'],
    ['slug' => 'updates', 'label' => 'Обновления', 'href' => 'updates.php', 'icon' => 'package'],
];

function render_layout_start(string $title, string $activeSlug, string $actionsHtml = ''): void
{
    global $menuItems;
    $brandName = setting_get('system_name', 'HostMonitor') ?: 'HostMonitor';
    $tz = setting_get('timezone', 'Europe/Moscow');
    if (@date_default_timezone_set($tz) === false) {
        date_default_timezone_set('Europe/Moscow');
    }
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> · <?= htmlspecialchars($brandName) ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
        <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/style.css')) ?>">
        <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/nexus.css')) ?>">
        <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/net-gear.css')) ?>">
        <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/icons.css')) ?>">
        <script>
            window.MONITORING_BASE_PATH = <?= json_encode(monitoring_base_path(), JSON_UNESCAPED_SLASHES) ?>;
            window.MONITORING_API_BASE = <?= json_encode(monitoring_asset('/api'), JSON_UNESCAPED_SLASHES) ?>;
        </script>
        <script src="https://unpkg.com/lucide@latest"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
            if (window.Chart) {
                Chart.defaults.color = '#9aa3b2';
                Chart.defaults.borderColor = 'rgba(255,255,255,0.08)';
                Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
                Chart.defaults.font.size = 11;
                Chart.defaults.plugins.legend.labels.boxWidth = 10;
                Chart.defaults.plugins.legend.labels.usePointStyle = true;
                Chart.defaults.plugins.legend.labels.padding = 12;
                Chart.defaults.elements.line.borderWidth = 2;
                Chart.defaults.elements.line.tension = 0.35;
                Chart.defaults.elements.point.radius = 0;
                Chart.defaults.elements.point.hoverRadius = 4;
                Chart.defaults.scale.grid.color = 'rgba(255,255,255,0.06)';
                Chart.defaults.scale.ticks.maxRotation = 0;
            }
        </script>
    </head>
    <body>
        <div class="page-loader" id="page-loader">
            <span class="loader-spinner"></span>
            <p>Загрузка интерфейса...</p>
        </div>
        <div class="app-shell">
            <header class="topbar">
                <div class="topbar-left">
                    <a class="brand" href="index.php">
                        <span class="brand-mark"><i data-lucide="activity"></i></span>
                        <span><?= htmlspecialchars($brandName) ?></span>
                    </a>
                    <div class="hm-drop">
                        <button class="view-pill" type="button" data-drop="viewDropMenu" aria-haspopup="true">
                            <i data-lucide="layers"></i>
                            <span>Мониторинг</span>
                            <i data-lucide="chevron-down"></i>
                        </button>
                        <div class="hm-menu hidden" id="viewDropMenu">
                            <a class="hm-menu-item <?= $activeSlug === 'dashboard' ? 'active' : '' ?>" href="index.php">
                                <i data-lucide="home"></i><span>Дашборд</span>
                            </a>
                            <a class="hm-menu-item <?= $activeSlug === 'network-map' ? 'active' : '' ?>" href="network_map.php">
                                <i data-lucide="share-2"></i><span>Карта сети</span>
                            </a>
                            <a class="hm-menu-item <?= $activeSlug === 'nodes' ? 'active' : '' ?>" href="nodes.php">
                                <i data-lucide="server"></i><span>Сервера</span>
                            </a>
                            <a class="hm-menu-item <?= $activeSlug === 'logs' ? 'active' : '' ?>" href="logs.php">
                                <i data-lucide="file-text"></i><span>Логи</span>
                            </a>
                            <a class="hm-menu-item <?= $activeSlug === 'settings' ? 'active' : '' ?>" href="settings.php">
                                <i data-lucide="settings"></i><span>Настройки</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="topbar-center">
                    <div class="hm-drop hm-drop-center">
                        <button class="project-switcher" type="button" data-drop="projectDropMenu" aria-haspopup="true">
                            <span><?= htmlspecialchars($brandName) ?> / <?= htmlspecialchars($title) ?></span>
                            <i data-lucide="chevron-down"></i>
                        </button>
                        <div class="hm-menu hidden" id="projectDropMenu">
                            <?php foreach ($menuItems as $item): ?>
                                <?php if (isset($item['children'])): ?>
                                    <div class="hm-menu-label"><?= htmlspecialchars($item['label']) ?></div>
                                    <?php foreach ($item['children'] as $child): ?>
                                        <a class="hm-menu-item <?= $child['slug'] === $activeSlug ? 'active' : '' ?>" href="<?= htmlspecialchars($child['href']) ?>">
                                            <i data-lucide="<?= htmlspecialchars($child['icon']) ?>"></i>
                                            <span><?= htmlspecialchars($child['label']) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                <?php elseif (!empty($item['href'])): ?>
                                    <a class="hm-menu-item <?= $item['slug'] === $activeSlug ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>">
                                        <i data-lucide="<?= htmlspecialchars($item['icon']) ?>"></i>
                                        <span><?= htmlspecialchars($item['label']) ?></span>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <div class="hm-menu-sep"></div>
                            <a class="hm-menu-item <?= $activeSlug === 'settings' ? 'active' : '' ?>" href="settings.php">
                                <i data-lucide="settings"></i><span>Настройки</span>
                            </a>
                            <a class="hm-menu-item <?= $activeSlug === 'profile' ? 'active' : '' ?>" href="profile.php">
                                <i data-lucide="user"></i><span>Профиль</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="topbar-right">
                    <label class="search-chip">
                        <i data-lucide="search"></i>
                        <input type="search" placeholder="Поиск узлов..." aria-label="Поиск">
                    </label>
                    <div class="avatar-stack" title="Онлайн">
                        <span class="mini-avatar"><img src="https://api.dicebear.com/9.x/notionists/svg?seed=Alex&backgroundColor=1e293b" alt=""></span>
                        <span class="mini-avatar"><img src="https://api.dicebear.com/9.x/notionists/svg?seed=Mia&backgroundColor=1e293b" alt=""></span>
                        <span class="mini-avatar"><img src="https://api.dicebear.com/9.x/notionists/svg?seed=Ken&backgroundColor=1e293b" alt=""></span>
                    </div>
                    <?php if (($_SESSION['role'] ?? 'admin') === 'admin'): ?>
                    <div class="panel-update-actions" id="panelUpdateActions">
                        <button type="button" class="icon-btn panel-update-check" id="panelUpdateCheckBtn" title="Проверить обновление панели" aria-label="Проверить обновление панели">
                            <i data-lucide="refresh-cw"></i>
                        </button>
                        <button type="button" class="icon-btn panel-update-apply hidden" id="panelUpdateApplyBtn" title="Обновить панель из репозитория" aria-label="Обновить панель">
                            <i data-lucide="download"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                    <div class="user-menu-wrapper hm-drop">
                        <div class="user-menu" id="userMenuToggle" data-drop="userDropdown" role="button" tabindex="0">
                            <div class="user-avatar">
                                <i data-lucide="user"></i>
                            </div>
                            <span class="username"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></span>
                            <i data-lucide="chevron-down"></i>
                        </div>
                        <div class="hm-menu user-dropdown hidden" id="userDropdown">
                            <a href="settings.php" class="hm-menu-item">
                                <i data-lucide="settings"></i>
                                <span>Настройки</span>
                            </a>
                            <a href="profile.php" class="hm-menu-item">
                                <i data-lucide="user"></i>
                                <span>Профиль</span>
                            </a>
                            <div class="hm-menu-sep"></div>
                            <a href="logout.php" class="hm-menu-item">
                                <i data-lucide="log-out"></i>
                                <span>Выход</span>
                            </a>
                        </div>
                    </div>
                </div>
            </header>
            <?php if (function_exists('db_active_role') && db_active_role() === 'replica'): ?>
            <div class="db-ha-banner">
                Панель работает на <strong>резервной базе</strong>. Основная MySQL недоступна.
                <a href="databases.php#db-ha-panel">Настройки БД</a>
            </div>
            <?php endif; ?>
            <div class="container">
            <nav class="sidebar">
                <button class="sidebar-toggle hm-collapse-btn" id="sidebarToggle" type="button" title="Свернуть меню" aria-label="Свернуть меню"></button>
                <div class="logo">
                    <i data-lucide="activity"></i>
                    <span><?= htmlspecialchars($brandName) ?></span>
                </div>
                <ul class="menu">
                    <?php foreach ($menuItems as $item): ?>
                        <?php if (isset($item['children'])): ?>
                            <?php
                            $hasActiveChild = false;
                            foreach ($item['children'] as $child) {
                                if ($child['slug'] === $activeSlug) {
                                    $hasActiveChild = true;
                                    break;
                                }
                            }
                            $isExpanded = $hasActiveChild || $item['slug'] === $activeSlug;
                            ?>
                            <li class="menu-group <?= $isExpanded ? 'expanded' : '' ?>">
                                <div class="menu-group-header">
                                    <i data-lucide="<?= htmlspecialchars($item['icon']) ?>"></i>
                                    <span><?= htmlspecialchars($item['label']) ?></span>
                                    <i data-lucide="chevron-down" class="chevron"></i>
                                </div>
                                <ul class="menu-submenu" style="display: <?= $isExpanded ? 'block' : 'none' ?>;">
                                    <?php foreach ($item['children'] as $child): ?>
                                        <li>
                                            <a href="<?= $child['href'] ?>"
                                               class="<?= $child['slug'] === $activeSlug ? 'active' : '' ?>">
                                                <i data-lucide="<?= htmlspecialchars($child['icon']) ?>"></i>
                                                <span><?= htmlspecialchars($child['label']) ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php else: ?>
                            <li>
                                <a href="<?= $item['href'] ?>"
                                   class="<?= $item['slug'] === $activeSlug ? 'active' : '' ?>">
                                    <i data-lucide="<?= htmlspecialchars($item['icon']) ?>"></i>
                                    <span><?= htmlspecialchars($item['label']) ?></span>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <div class="sidebar-footer">
                    <button class="theme-toggle" type="button">
                        <i data-lucide="sun"></i>
                        <span>Светлая тема</span>
                    </button>
                </div>
            </nav>
            <main class="content">
                <header class="header">
                    <div class="header-title">
                        <h1><?= htmlspecialchars($title) ?></h1>
                        <p class="header-subtitle" id="headerSubtitle"><?php
                            $subtitles = [
                                'dashboard' => 'Обзор парка · карточки можно настроить',
                                'network-map' => 'Топология узлов в реальном времени',
                                'upnp' => 'Сетевое оборудование UPnP / IGD',
                                'databases' => 'MySQL, MariaDB и PostgreSQL · опрос с панели',
                                'nodes-stats' => 'Нагрузка серверов без лишних графиков',
                                'nodes-metrics' => 'CPU, RAM и диск выбранной ноды',
                                'nodes-traffic' => 'Вход и выход по нодам',
                                'nodes-billing' => 'Сроки и платежи',
                                'updates' => 'Пакеты и безопасность',
                            ];
                            echo $subtitles[$activeSlug] ?? 'Панель управления';
                        ?></p>
                    </div>
                    <div class="header-right">
                        <?php if (!empty($actionsHtml)): ?>
                            <div class="header-actions">
                                <?= $actionsHtml ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </header>
    <?php
}

function render_layout_end(array $scripts = []): void
{
    ?>
            </main>
        </div>
        </div>
        <div class="toast-host" id="toast-host" aria-live="polite"></div>
        <script src="<?= htmlspecialchars(monitoring_asset('/frontend/js/notify.js')) ?>"></script>
        <script>
            const pageLoader = document.getElementById('page-loader');
            const hidePageLoader = () => {
                pageLoader?.classList.add('hidden');
            };
            if (document.readyState === 'complete') {
                hidePageLoader();
            } else {
                window.addEventListener('load', hidePageLoader);
                setTimeout(hidePageLoader, 2000);
            }

            window.toggleTableLoader = function(tableId, show) {
                const tbody = document.getElementById(tableId);
                if (!tbody) return;
                const tableContainer = tbody.closest('.table-container');
                if (tableContainer) {
                    tableContainer.classList.toggle('is-loading', show);
                }
            };

            document.addEventListener('DOMContentLoaded', () => {
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });

            const themeToggle = document.querySelector('.theme-toggle');
            const applyTheme = (mode = 'dark') => {
                document.body.classList.toggle('light', mode === 'light');
                if (!themeToggle) return;
                const icon = themeToggle.querySelector('i[data-lucide]');
                const label = themeToggle.querySelector('span');
                if (icon) {
                    icon.setAttribute('data-lucide', mode === 'light' ? 'moon' : 'sun');
                }
                if (label) {
                    label.textContent = mode === 'light' ? 'Тёмная тема' : 'Светлая тема';
                }
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            };
            const savedTheme = localStorage.getItem('theme') || 'dark';
            applyTheme(savedTheme);
            themeToggle?.addEventListener('click', () => {
                const nextTheme = document.body.classList.contains('light') ? 'dark' : 'light';
                localStorage.setItem('theme', nextTheme);
                applyTheme(nextTheme);
            });

            document.querySelectorAll('.menu-group-header').forEach(header => {
                header.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const group = this.parentElement;
                    const submenu = group.querySelector('.menu-submenu');
                    const isExpanded = group.classList.contains('expanded');
                    if (isExpanded) {
                        group.classList.remove('expanded');
                        if (submenu) submenu.style.display = 'none';
                    } else {
                        group.classList.add('expanded');
                        if (submenu) submenu.style.display = 'block';
                    }
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                });
            });
        </script>
        <script src="<?= htmlspecialchars(monitoring_asset('/frontend/js/panels.js')) ?>"></script>
        <?php if (($_SESSION['role'] ?? 'admin') === 'admin'): ?>
        <script src="<?= htmlspecialchars(monitoring_asset('/frontend/js/panel_update.js')) ?>"></script>
        <?php endif; ?>
        <script src="<?= htmlspecialchars(monitoring_asset('/frontend/js/selects.js')) ?>"></script>
        <?php foreach ($scripts as $script): ?>
            <script src="<?= htmlspecialchars(monitoring_asset($script)) ?>"></script>
        <?php endforeach; ?>
    </body>
    </html>
    <?php
}

