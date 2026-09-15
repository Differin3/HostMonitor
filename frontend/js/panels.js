(function () {
    if (window.__HOSTMONITOR_PANELS_INIT) return;
    window.__HOSTMONITOR_PANELS_INIT = true;

    const STORE_KEY = 'hostmonitor.ui.panels';

    const PATHS = {
        'chevron-left': '<polyline points="15 18 9 12 15 6"/>',
        'chevron-right': '<polyline points="9 18 15 12 9 6"/>',
        'chevron-up': '<polyline points="18 15 12 9 6 15"/>',
        'chevron-down': '<polyline points="6 9 12 15 18 9"/>',
        'chevrons-left': '<polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/>',
        'chevrons-right': '<polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/>',
    };

    const iconSvg = (name) => {
        const inner = PATHS[name] || PATHS['chevron-left'];
        return `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${inner}</svg>`;
    };

    const readStore = () => {
        try {
            return JSON.parse(localStorage.getItem(STORE_KEY) || '{}');
        } catch {
            return {};
        }
    };

    const writeStore = (id, collapsed) => {
        const all = readStore();
        all[id] = !!collapsed;
        localStorage.setItem(STORE_KEY, JSON.stringify(all));
    };

    const setIcon = (btn, name) => {
        btn.innerHTML = iconSvg(name);
    };

    const makeBtn = (className) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = className || 'hm-collapse-btn';
        return btn;
    };

    const emit = (id, collapsed, el) => {
        window.dispatchEvent(new CustomEvent('hm-panel-toggle', { detail: { id, collapsed, el } }));
    };

    const applyCollapsed = (el, collapsed) => {
        const isNav = el.classList.contains('sidebar') || el.classList.contains('mk-sidebar');
        el.classList.toggle('is-collapsed', collapsed);
        if (isNav) el.classList.toggle('collapsed', collapsed);
        if (el.classList.contains('netmap-panel')) {
            el.closest('.netmap')?.classList.toggle('panel-collapsed', collapsed);
        }
    };

    const wireToggle = (el, id, btn, icons) => {
        const collapsed = readStore()[id] === true;
        applyCollapsed(el, collapsed);
        setIcon(btn, collapsed ? icons.expand : icons.collapse);
        btn.title = collapsed ? (btn.dataset.expandTitle || 'Развернуть') : (btn.dataset.collapseTitle || 'Свернуть');
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const next = !(el.classList.contains('is-collapsed') || el.classList.contains('collapsed'));
            applyCollapsed(el, next);
            writeStore(id, next);
            setIcon(btn, next ? icons.expand : icons.collapse);
            btn.title = next ? (btn.dataset.expandTitle || 'Развернуть') : (btn.dataset.collapseTitle || 'Свернуть');
            btn.setAttribute('aria-expanded', next ? 'false' : 'true');
            emit(id, next, el);
        });
    };

    const ensureHead = (el) => {
        let head = el.querySelector(':scope > .hm-panel-head, :scope > .chart-header, :scope > .netmap-panel-head');
        if (head) {
            head.classList.add('hm-panel-head');
            return head;
        }
        const title = el.querySelector(':scope > h2, :scope > h3');
        head = document.createElement('div');
        head.className = 'hm-panel-head';
        if (title) head.appendChild(title);
        el.insertBefore(head, el.firstChild);
        return head;
    };

    const wireCollapsible = (el) => {
        if (!el || el.dataset.hmWired) return;
        const id = el.getAttribute('data-collapsible') || `auto:${el.id || el.querySelector('h2,h3')?.textContent?.trim() || ''}`;
        if (!id || id === 'auto:') return;
        el.dataset.hmWired = '1';
        el.classList.add('hm-collapsible');
        const head = ensureHead(el);
        const btn = makeBtn('hm-collapse-btn');
        btn.dataset.expandTitle = 'Развернуть';
        btn.dataset.collapseTitle = 'Свернуть';
        head.appendChild(btn);
        const icons = el.classList.contains('netmap-panel')
            ? { collapse: 'chevron-right', expand: 'chevron-left' }
            : { collapse: 'chevron-up', expand: 'chevron-down' };
        wireToggle(el, id, btn, icons);
    };

    const wireSidebar = (el, id) => {
        if (!el || el.dataset.hmWired) return;
        el.dataset.hmWired = '1';
        let btn = el.querySelector('.sidebar-toggle, .hm-nav-toggle');
        if (!btn) {
            btn = makeBtn(el.classList.contains('mk-sidebar') ? 'hm-nav-toggle hm-collapse-btn' : 'sidebar-toggle hm-collapse-btn');
            el.insertBefore(btn, el.firstChild);
        } else {
            btn.classList.add('hm-collapse-btn');
        }
        btn.type = 'button';
        btn.dataset.expandTitle = 'Показать меню';
        btn.dataset.collapseTitle = 'Свернуть меню';
        wireToggle(el, id, btn, { collapse: 'chevrons-left', expand: 'chevrons-right' });
    };

    const init = () => {
        wireSidebar(document.querySelector('nav.sidebar'), 'sidebar');
        wireSidebar(document.querySelector('nav.mk-sidebar'), 'mk-sidebar');
        document.querySelectorAll('[data-collapsible]').forEach(wireCollapsible);
        document.querySelectorAll('.chart-card').forEach((card, index) => {
            const name = card.querySelector('h3')?.textContent?.trim() || String(index);
            card.setAttribute('data-collapsible', card.getAttribute('data-collapsible') || `chart:${name}`);
            wireCollapsible(card);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
