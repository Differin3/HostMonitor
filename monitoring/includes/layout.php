<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

$menuItems = [
    ['slug' => 'dashboard', 'label' => 'Дашборд', 'href' => 'index.php', 'icon' => 'home'],
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
        <title><?= htmlspecialchars($title) ?> · HostMonitor</title>
        <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/style.css')) ?>">
        <link rel="stylesheet" href="<?= htmlspecialchars(monitoring_asset('/frontend/css/icons.css')) ?>">
        <script>
            window.MONITORING_BASE_PATH = <?= json_encode(monitoring_base_path(), JSON_UNESCAPED_SLASHES) ?>;
            window.MONITORING_API_BASE = <?= json_encode(monitoring_asset('/api'), JSON_UNESCAPED_SLASHES) ?>;
        </script>
        <script src="https://unpkg.com/lucide@latest"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    </head>
    <body>
        <div class="page-loader" id="page-loader">
            <span class="loader-spinner"></span>
            <p>Загрузка интерфейса...</p>
        </div>
        <div class="container">
            <nav class="sidebar">
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i data-lucide="menu"></i>
                </button>
                <div class="logo">
                    <i data-lucide="activity"></i>
                    <span>HostMonitor</span>
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
                        <p class="header-subtitle" id="headerSubtitle"><?= $activeSlug === 'dashboard' ? 'Обзор HostMonitor' : 'Панель управления' ?></p>
                    </div>
                    <div class="header-right">
                        <?php if (!empty($actionsHtml)): ?>
                            <div class="header-actions">
                                <?= $actionsHtml ?>
                            </div>
                        <?php endif; ?>
                        <div class="user-menu-wrapper">
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
                        </div>
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

            // Единая функция для анимации загрузки таблиц
            window.toggleTableLoader = function(tableId, show) {
                const tbody = document.getElementById(tableId);
                if (!tbody) return;
                const tableContainer = tbody.closest('.table-container');
                if (tableContainer) {
                    tableContainer.classList.toggle('is-loading', show);
                }
            };

            // Toast уведомления
            window.showToast = function(message, type = 'info') {
                let toast = document.getElementById('toast');
                if (!toast) {
                    toast = document.createElement('div');
                    toast.id = 'toast';
                    toast.className = 'toast hidden';
                    document.body.appendChild(toast);
                }
                
                const iconMap = {
                    success: 'check-circle',
                    error: 'alert-circle',
                    warning: 'alert-triangle',
                    info: 'info'
                };
                const icon = iconMap[type] || 'info';
                
                toast.dataset.type = type;
                toast.innerHTML = `<i data-lucide="${icon}"></i><span>${message}</span>`;
                toast.classList.remove('hidden');
                
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
                
                setTimeout(() => {
                    toast.classList.add('hidden');
                }, 3000);
            };

            // Стилизованное окно подтверждения
            window.showConfirm = function(message, title = 'Подтверждение', type = 'danger') {
                return new Promise((resolve) => {
                    let modal = document.getElementById('confirm-modal');
                    if (!modal) {
                        modal = document.createElement('div');
                        modal.id = 'confirm-modal';
                        modal.className = 'confirm-modal hidden';
                        modal.innerHTML = `
                            <div class="confirm-dialog">
                                <div class="confirm-header">
                                    <i data-lucide="${type === 'danger' ? 'alert-triangle' : 'info'}"></i>
                                    <h3 id="confirm-title">${title}</h3>
                                </div>
                                <div class="confirm-body" id="confirm-message">${message}</div>
                                <div class="confirm-actions">
                                    <button class="btn-cancel" id="confirm-cancel">Отмена</button>
                                    <button class="btn-confirm ${type === 'success' ? 'success' : ''}" id="confirm-ok">Подтвердить</button>
                                </div>
                            </div>
                        `;
                        document.body.appendChild(modal);
                        if (typeof lucide !== 'undefined') {
                            lucide.createIcons();
                        }
                    }
                    
                    document.getElementById('confirm-title').textContent = title;
                    document.getElementById('confirm-message').textContent = message;
                    const confirmBtn = document.getElementById('confirm-ok');
                    confirmBtn.className = `btn-confirm ${type === 'success' ? 'success' : ''}`;
                    
                    const cleanup = () => {
                        modal.classList.remove('active');
                        setTimeout(() => modal.classList.add('hidden'), 200);
                        document.getElementById('confirm-cancel').removeEventListener('click', onCancel);
                        confirmBtn.removeEventListener('click', onConfirm);
                    };
                    
                    const onCancel = () => {
                        cleanup();
                        resolve(false);
                    };
                    
                    const onConfirm = () => {
                        cleanup();
                        resolve(true);
                    };
                    
                    document.getElementById('confirm-cancel').addEventListener('click', onCancel);
                    confirmBtn.addEventListener('click', onConfirm);
                    
                    modal.classList.remove('hidden');
                    setTimeout(() => modal.classList.add('active'), 10);
                });
            };

            // Глобальный обработчик Escape для закрытия модальных окон
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    // Закрываем все открытые модальные окна
                    document.querySelectorAll('.modal.active').forEach(modal => {
                        modal.classList.remove('active');
                        setTimeout(() => modal.classList.add('hidden'), 200);
                    });
                    // Закрываем окно подтверждения
                    const confirmModal = document.getElementById('confirm-modal');
                    if (confirmModal && confirmModal.classList.contains('active')) {
                        confirmModal.classList.remove('active');
                        setTimeout(() => confirmModal.classList.add('hidden'), 200);
                    }
                }
            });

            // Инициализация иконок Lucide
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

