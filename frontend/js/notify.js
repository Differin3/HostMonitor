(function () {
    if (window.__HOSTMONITOR_NOTIFY_INIT) return;
    window.__HOSTMONITOR_NOTIFY_INIT = true;

    const ICONS = {
        success: 'check-circle',
        error: 'alert-circle',
        warning: 'alert-triangle',
        info: 'info',
        danger: 'alert-triangle',
    };

    const DURATION = {
        success: 3500,
        info: 4000,
        warning: 5200,
        error: 7000,
        danger: 7000,
    };

    function paintIcons(root) {
        if (!window.lucide || typeof lucide.createIcons !== 'function') return;
        if (root && root.nodeType === 1 && typeof root.querySelectorAll === 'function') {
            lucide.createIcons(root);
            if (root.querySelector('[data-lucide]')) {
                try {
                    lucide.createIcons({ root });
                } catch (err) {
                    lucide.createIcons();
                }
            }
            return;
        }
        lucide.createIcons();
    }

    function toastHost() {
        let host = document.getElementById('toast-host');
        if (!host) {
            host = document.createElement('div');
            host.id = 'toast-host';
            host.className = 'toast-host';
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }
        return host;
    }

    function dismissToast(card) {
        if (!card || card.classList.contains('out')) return;
        card.classList.add('out');
        card.classList.remove('in');
        setTimeout(() => card.remove(), 280);
    }

    window.showToast = function (message, type = 'info', timeout) {
        const kind = ICONS[type] ? type : 'info';
        const host = toastHost();
        while (host.children.length >= 5) {
            dismissToast(host.firstElementChild);
        }
        const card = document.createElement('div');
        card.className = `toast-card toast-${kind}`;
        card.dataset.type = kind;
        const ms = typeof timeout === 'number' ? timeout : (DURATION[kind] || 4000);

        const iconWrap = document.createElement('span');
        iconWrap.className = 'toast-icon';
        iconWrap.innerHTML = `<i data-lucide="${ICONS[kind]}"></i>`;

        const body = document.createElement('div');
        body.className = 'toast-body';
        const text = document.createElement('p');
        text.textContent = String(message || '');
        body.appendChild(text);

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', 'Закрыть');
        close.innerHTML = '<i data-lucide="x"></i>';

        const bar = document.createElement('span');
        bar.className = 'toast-bar';
        bar.style.animationDuration = `${ms}ms`;

        card.appendChild(iconWrap);
        card.appendChild(body);
        card.appendChild(close);
        card.appendChild(bar);
        host.appendChild(card);
        paintIcons(card);
        requestAnimationFrame(() => card.classList.add('in'));

        let remaining = ms;
        let startedAt = Date.now();
        let timer = setTimeout(() => dismissToast(card), remaining);

        card.addEventListener('mouseenter', () => {
            clearTimeout(timer);
            remaining -= Date.now() - startedAt;
            bar.style.animationPlayState = 'paused';
        });
        card.addEventListener('mouseleave', () => {
            startedAt = Date.now();
            bar.style.animationPlayState = 'running';
            timer = setTimeout(() => dismissToast(card), Math.max(remaining, 400));
        });
        close.addEventListener('click', () => {
            clearTimeout(timer);
            dismissToast(card);
        });
        return card;
    };

    window.showConfirm = function (message, title = 'Подтверждение', type = 'danger') {
        return new Promise((resolve) => {
            let modal = document.getElementById('confirm-modal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'confirm-modal';
                modal.className = 'confirm-modal hidden';
                modal.innerHTML = `
                    <div class="confirm-dialog hm-popover">
                        <div class="confirm-header">
                            <span class="confirm-icon"><i data-lucide="alert-triangle"></i></span>
                            <h3 id="confirm-title"></h3>
                        </div>
                        <div class="confirm-body" id="confirm-message"></div>
                        <div class="confirm-actions">
                            <button type="button" class="btn-cancel" id="confirm-cancel">Отмена</button>
                            <button type="button" class="btn-confirm" id="confirm-ok">Подтвердить</button>
                        </div>
                    </div>`;
                document.body.appendChild(modal);
            }

            const kind = ['danger', 'warning', 'info', 'success'].includes(type) ? type : 'danger';
            const icons = {
                danger: 'alert-triangle',
                warning: 'alert-triangle',
                info: 'info',
                success: 'check-circle',
            };
            const labels = {
                danger: 'Подтвердить',
                warning: 'Продолжить',
                info: 'Подтвердить',
                success: 'OK',
            };
            modal.dataset.confirmType = kind;
            const iconSlot = modal.querySelector('.confirm-icon');
            if (iconSlot) iconSlot.innerHTML = `<i data-lucide="${icons[kind]}"></i>`;
            document.getElementById('confirm-title').textContent = title;
            const msgEl = document.getElementById('confirm-message');
            msgEl.textContent = '';
            String(message ?? '').split(/\n/).forEach((line, i, arr) => {
                msgEl.appendChild(document.createTextNode(line));
                if (i < arr.length - 1) msgEl.appendChild(document.createElement('br'));
            });
            const cancelBtn = document.getElementById('confirm-cancel');
            const confirmBtn = document.getElementById('confirm-ok');
            confirmBtn.className = `btn-confirm ${kind}`;
            confirmBtn.textContent = labels[kind] || 'Подтвердить';
            paintIcons(modal);

            const finish = (result) => {
                if (!modal._hmConfirmFinish) return;
                modal._hmConfirmFinish = null;
                cancelBtn.removeEventListener('click', onCancel);
                confirmBtn.removeEventListener('click', onConfirm);
                modal.removeEventListener('click', onBackdrop);
                document.removeEventListener('keydown', onKey);
                modal.classList.remove('active');
                setTimeout(() => modal.classList.add('hidden'), 200);
                resolve(result);
            };
            const onCancel = () => finish(false);
            const onConfirm = () => finish(true);
            const onBackdrop = (e) => {
                if (e.target === modal) finish(false);
            };
            const onKey = (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    finish(false);
                } else if (e.key === 'Enter' && !e.target.closest('textarea, [contenteditable]')) {
                    e.preventDefault();
                    finish(true);
                }
            };

            modal._hmConfirmFinish = finish;
            cancelBtn.addEventListener('click', onCancel);
            confirmBtn.addEventListener('click', onConfirm);
            modal.addEventListener('click', onBackdrop);
            document.addEventListener('keydown', onKey);
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.add('active');
                confirmBtn.focus();
            }, 10);
        });
    };

    function closeHmMenus(except) {
        document.querySelectorAll('.hm-menu, .user-dropdown').forEach((menu) => {
            if (menu.classList.contains('hm-select-menu')) return;
            if (except && menu === except) return;
            menu.classList.add('hidden');
        });
        document.querySelectorAll('[data-drop].active, .user-menu.active, .view-pill.active, .project-switcher.active, .pt-pill.active').forEach((btn) => {
            if (except && btn.dataset.drop && document.getElementById(btn.dataset.drop) === except) return;
            btn.classList.remove('active');
        });
    }

    window.closeHmMenus = closeHmMenus;

    document.addEventListener('click', (e) => {
        if (e.target.closest('.hm-select, .hm-select-menu, .context-menu')) return;
        const btn = e.target.closest('[data-drop]');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            const menu = document.getElementById(btn.dataset.drop);
            if (!menu) return;
            const willOpen = menu.classList.contains('hidden');
            closeHmMenus(willOpen ? menu : null);
            menu.classList.toggle('hidden', !willOpen);
            btn.classList.toggle('active', willOpen);
            paintIcons(menu);
            return;
        }
        if (e.target.closest('.hm-menu-item')) {
            closeHmMenus();
            return;
        }
        if (!e.target.closest('.hm-menu, .user-dropdown, .hm-drop')) {
            closeHmMenus();
        }
    });

    document.addEventListener('keydown', (e) => {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.closest('[data-drop]')) {
            e.preventDefault();
            e.target.closest('[data-drop]').click();
            return;
        }
        if (e.key !== 'Escape') return;
        const confirmModal = document.getElementById('confirm-modal');
        if (confirmModal && typeof confirmModal._hmConfirmFinish === 'function') {
            confirmModal._hmConfirmFinish(false);
            return;
        }
        const host = document.getElementById('toast-host');
        if (host && host.lastElementChild) {
            dismissToast(host.lastElementChild);
        }
        closeHmMenus();
        document.querySelectorAll('.modal.active').forEach((modal) => {
            modal.classList.remove('active');
            setTimeout(() => modal.classList.add('hidden'), 200);
        });
    });
})();
