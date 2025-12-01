<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

$menuItems = [
    ['slug' => 'dashboard', 'label' => 'Дашборд', 'href' => 'index.php', 'icon' => 'home'],
    [
        'slug' => 'nodes',
        'label' => 'Ноды',
        'icon' => 'server',
        'children' => [
            ['slug' => 'nodes', 'label' => 'Управление', 'href' => 'nodes.php', 'icon' => 'settings'],
            ['slug' => 'nodes-stats', 'label' => 'Статистика', 'href' => 'nodes_stats.php', 'icon' => 'bar-chart-2'],
            ['slug' => 'nodes-billing', 'label' => 'Инфра-биллинг', 'href' => 'billing.php', 'icon' => 'wallet'],
            ['slug' => 'nodes-traffic', 'label' => 'Расход трафика', 'href' => 'nodes_traffic.php', 'icon' => 'trending-up'],
            ['slug' => 'nodes-metrics', 'label' => 'Метрики', 'href' => 'nodes_metrics.php', 'icon' => 'activity'],
            ['slug' => 'nodes-ports', 'label' => 'Порты', 'href' => 'nodes_ports.php', 'icon' => 'network'],
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
];

function render_layout_start(string $title, string $activeSlug, string $actionsHtml = ''): void
{
    global $menuItems;
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> · Monitoring</title>
        <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/style.css')) ?>">
        <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/icons.css')) ?>">
        <script>
            window.MONITORING_BASE_PATH = <?= json_encode(monitoring_base_path(), JSON_UNESCAPED_SLASHES) ?>;
            window.MONITORING_API_BASE = <?= json_encode(monitoring_asset('/api'), JSON_UNESCAPED_SLASHES) ?>;
        </script>
        <script src="https://unpkg.com/lucide@latest"></script>
    </head>
    <body>
        <div class="container">
            <nav class="sidebar">
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i data-lucide="menu"></i>
                </button>
                <div class="logo">
                    <i data-lucide="activity"></i>
                    <span>Мониторинг</span>
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
                    <li><a href="logout.php"><i data-lucide="log-out"></i><span>Выход</span></a></li>
                </ul>
                <div class="sidebar-footer">
                    <button class="theme-toggle" onclick="document.body.classList.toggle('dark')">
                        <i data-lucide="moon"></i>
                        <span>Тёмная тема</span>
                    </button>
                </div>
            </nav>
            <main class="content">
                <header class="header">
                    <div class="header-title">
                        <h1><?= htmlspecialchars($title) ?></h1>
                        <p class="header-subtitle" id="headerSubtitle"><?= $activeSlug === 'dashboard' ? 'Обзор системы мониторинга' : 'Панель управления' ?></p>
                    </div>
                    <div class="header-right">
                        <div class="user-menu" id="userMenuToggle">
                            <div class="user-avatar">
                                <i data-lucide="user"></i>
                            </div>
                            <span class="username"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></span>
                            <i data-lucide="chevron-down"></i>
                        </div>
                        <div class="user-dropdown hidden" id="userDropdown">
                            <a href="settings.php" class="user-dropdown-item">
                                <i data-lucide="settings"></i>
                                <span>Настройки</span>
                            </a>
                            <a href="profile.php" class="user-dropdown-item">
                                <i data-lucide="user"></i>
                                <span>Профиль</span>
                            </a>
                            <div class="user-dropdown-divider"></div>
                            <a href="logout.php" class="user-dropdown-item">
                                <i data-lucide="log-out"></i>
                                <span>Выход</span>
                            </a>
                        </div>
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
        <script>
            // Инициализация иконок Lucide
            document.addEventListener('DOMContentLoaded', () => {
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });
            
            // Тема
            const savedTheme = localStorage.getItem('theme') || 'dark';
            if (savedTheme === 'dark') {
                document.body.classList.add('dark');
            }
            const themeToggle = document.querySelector('.theme-toggle');
            if (themeToggle) {
                themeToggle.addEventListener('click', () => {
                    document.body.classList.toggle('dark');
                    const mode = document.body.classList.contains('dark') ? 'dark' : 'light';
                    localStorage.setItem('theme', mode);
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                });
            }
            
            // Боковое меню
            const sidebarToggle = document.getElementById('sidebarToggle');
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', () => {
                    document.querySelector('.sidebar').classList.toggle('collapsed');
                });
            }
            
            // Раскрытие/сворачивание подменю
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
            
            // Выпадающее меню профиля
            const userMenuToggle = document.getElementById('userMenuToggle');
            const userDropdown = document.getElementById('userDropdown');
            if (userMenuToggle && userDropdown) {
                userMenuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userMenuToggle.classList.toggle('active');
                    userDropdown.classList.toggle('hidden');
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                });
                
                // Закрытие при клике вне меню
                document.addEventListener('click', function(e) {
                    if (!userMenuToggle.contains(e.target) && !userDropdown.contains(e.target)) {
                        userMenuToggle.classList.remove('active');
                        userDropdown.classList.add('hidden');
                    }
                });
            }
        </script>
        <?php foreach ($scripts as $script): ?>
            <script src="<?= htmlspecialchars($script) ?>"></script>
        <?php endforeach; ?>
    </body>
    </html>
    <?php
}

