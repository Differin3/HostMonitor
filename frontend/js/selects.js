(function () {
    if (window.__HOSTMONITOR_SELECTS_INIT) return;
    window.__HOSTMONITOR_SELECTS_INIT = true;

    const CHEVRON = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
    const openMenus = new Set();

    const optionLabel = (opt) => (opt && (opt.label || opt.textContent) || '').trim();

    const closeMenu = (state) => {
        if (!state) return;
        state.menu.hidden = true;
        state.menu.classList.add('hidden');
        state.btn.setAttribute('aria-expanded', 'false');
        state.wrap.classList.remove('is-open');
        openMenus.delete(state);
    };

    const closeAll = (except) => {
        [...openMenus].forEach((state) => {
            if (state !== except) closeMenu(state);
        });
    };

    const placeMenu = (state) => {
        const rect = state.btn.getBoundingClientRect();
        const menu = state.menu;
        const viewportWidth = window.visualViewport?.width || window.innerWidth;
        const viewportHeight = window.visualViewport?.height || window.innerHeight;
        const edge = 8;
        const gap = 6;
        const maxMenuHeight = Math.min(360, Math.max(120, viewportHeight - edge * 2));

        menu.style.minWidth = `${Math.max(rect.width, 168)}px`;
        menu.style.maxWidth = `${Math.min(viewportWidth - edge * 2, 420)}px`;
        menu.style.maxHeight = `${maxMenuHeight}px`;
        menu.classList.remove('hidden');
        menu.hidden = false;

        const wantedHeight = menu.offsetHeight;
        const below = viewportHeight - rect.bottom - gap - edge;
        const above = rect.top - gap - edge;
        const openUp = below < wantedHeight && above > below;
        const available = Math.max(48, openUp ? above : below);
        menu.style.maxHeight = `${Math.min(maxMenuHeight, available)}px`;

        const height = menu.offsetHeight;
        const top = openUp ? rect.top - height - gap : rect.bottom + gap;
        const maxTop = Math.max(edge, viewportHeight - height - edge);
        const left = Math.min(
            Math.max(edge, rect.left),
            Math.max(edge, viewportWidth - menu.offsetWidth - edge)
        );
        menu.style.top = `${Math.round(Math.min(Math.max(edge, top), maxTop))}px`;
        menu.style.left = `${Math.round(left)}px`;
    };

    const paintOptions = (state) => {
        const { select, menu, btn } = state;
        const current = select.value;
        menu.innerHTML = '';
        [...select.options].forEach((opt) => {
            if (opt.hidden) return;
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'hm-select-option';
            item.dataset.value = opt.value;
            item.textContent = optionLabel(opt) || '—';
            item.disabled = opt.disabled;
            if (opt.value === current) item.classList.add('active');
            item.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (opt.disabled) return;
                const prev = select.value;
                select.value = opt.value;
                paintButton(state);
                paintOptions(state);
                closeMenu(state);
                if (prev !== select.value) {
                    select.dispatchEvent(new Event('input', { bubbles: true }));
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            menu.appendChild(item);
        });
        paintButton(state);
    };

    const paintButton = (state) => {
        const selected = state.select.selectedOptions[0];
        const label = optionLabel(selected) || '—';
        const span = document.createElement('span');
        span.className = 'hm-select-label';
        span.textContent = label;
        state.btn.replaceChildren(span);
        state.btn.insertAdjacentHTML('beforeend', CHEVRON);
        state.btn.disabled = state.select.disabled;
        state.wrap.classList.toggle('is-disabled', state.select.disabled);
        state.btn.title = label;
    };

    const openMenu = (state) => {
        if (state.select.disabled) return;
        closeAll(state);
        paintOptions(state);
        state.btn.setAttribute('aria-expanded', 'true');
        state.wrap.classList.add('is-open');
        placeMenu(state);
        openMenus.add(state);
    };

    const enhance = (select) => {
        if (!select || select.dataset.hmSelect === '1') return;
        if (select.multiple || Number(select.size) > 1) return;
        if (select.closest('.hm-select')) return;
        select.dataset.hmSelect = '1';
        select.classList.add('hm-select-native');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');

        const wrap = document.createElement('div');
        wrap.className = 'hm-select';
        ['compact-select', 'provider-select', 'country-select', 'filter-select'].forEach((cls) => {
            if (select.classList.contains(cls)) wrap.classList.add(cls);
        });
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'hm-select-btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        wrap.appendChild(btn);

        const menu = document.createElement('div');
        menu.className = 'hm-select-menu';
        menu.hidden = true;
        document.body.appendChild(menu);

        const state = { select, wrap, btn, menu };
        wrap._hmSelect = state;

        const sync = () => paintOptions(state);
        paintOptions(state);

        wrap.addEventListener('click', (event) => {
            event.preventDefault();
        });
        select.addEventListener('mousedown', (event) => event.preventDefault());
        select.addEventListener('click', (event) => event.preventDefault());
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (menu.hidden) openMenu(state);
            else closeMenu(state);
        });

        select.addEventListener('change', () => paintOptions(state));
        new MutationObserver(sync).observe(select, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['disabled', 'selected', 'label', 'value'],
        });
    };

    const scan = (root = document) => {
        root.querySelectorAll('select').forEach(enhance);
    };

    document.addEventListener('click', (event) => {
        if (event.target.closest('.hm-select, .hm-select-menu')) return;
        closeAll();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeAll();
    });
    window.addEventListener('resize', () => closeAll());

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => scan());
    } else {
        scan();
    }

    const mo = new MutationObserver((records) => {
        for (const rec of records) {
            rec.addedNodes.forEach((node) => {
                if (node.nodeType !== 1) return;
                if (node.matches?.('select')) enhance(node);
                else if (node.querySelectorAll) scan(node);
            });
        }
    });
    mo.observe(document.documentElement, { childList: true, subtree: true });

    window.HostMonitorSelects = { enhance, scan };
})();
