(function () {
    if (window.__HM_MOBILE_INIT) return;
    window.__HM_MOBILE_INIT = true;

    const shell = document.querySelector('.app-shell');
    if (!shell) return;

    const btn = document.getElementById('hmMobileNav');
    const backdrop = document.getElementById('hmNavBackdrop');
    const sidebar = document.getElementById('appSidebar') || shell.querySelector('.sidebar');

    const isMobile = () => window.matchMedia('(max-width: 820px)').matches;

    const openNav = () => {
        shell.classList.add('nav-open');
        if (btn) btn.setAttribute('aria-expanded', 'true');
        document.body.classList.add('hm-nav-locked');
    };

    const closeNav = () => {
        shell.classList.remove('nav-open');
        if (btn) btn.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('hm-nav-locked');
    };

    const toggleNav = () => {
        if (shell.classList.contains('nav-open')) closeNav();
        else openNav();
    };

    if (btn) {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            toggleNav();
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeNav);
    }

    // Закрываем drawer при клике по пункту меню
    if (sidebar) {
        sidebar.querySelectorAll('a').forEach((a) => {
            a.addEventListener('click', () => {
                if (isMobile()) closeNav();
            });
        });
    }

    // Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeNav();
    });

    // Сброс при переходе на десктопную ширину
    const mq = window.matchMedia('(min-width: 821px)');
    const onChange = (e) => { if (e.matches) closeNav(); };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange);
})();
