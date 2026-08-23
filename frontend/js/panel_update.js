(function () {
    if (window.__HOSTMONITOR_PANEL_UPDATE_INIT) return;
    window.__HOSTMONITOR_PANEL_UPDATE_INIT = true;

    const API = (window.MONITORING_API_BASE || '/api') + '/panel_update.php';
    let updateAvailable = false;
    let isChecking = false;
    let isApplying = false;

    const checkBtn = () => document.getElementById('panelUpdateCheckBtn');
    const applyBtn = () => document.getElementById('panelUpdateApplyBtn');

    const toast = (msg, type = 'info') => {
        if (window.showToast) window.showToast(msg, type);
    };

    const refreshIcons = () => {
        if (typeof lucide !== 'undefined') lucide.createIcons();
    };

    const setBtnLoading = (btn, loading, iconName) => {
        if (!btn) return;
        btn.disabled = loading;
        if (loading) {
            btn.dataset.prevIcon = btn.querySelector('i')?.getAttribute('data-lucide') || iconName;
            btn.innerHTML = '<i data-lucide="loader-2" class="spinning"></i>';
        } else {
            const icon = btn.dataset.prevIcon || iconName;
            btn.innerHTML = `<i data-lucide="${icon}"></i>`;
        }
        refreshIcons();
    };

    const setUpdateAvailable = (available) => {
        updateAvailable = available;
        const apply = applyBtn();
        const check = checkBtn();
        if (apply) {
            apply.classList.toggle('hidden', !available);
            apply.classList.toggle('panel-update-available', available);
        }
        if (check) {
            check.classList.toggle('panel-update-pending', available);
            if (!isChecking && !isApplying) {
                const icon = available ? 'arrow-down-circle' : 'refresh-cw';
                check.innerHTML = `<i data-lucide="${icon}"></i>`;
                check.title = available
                    ? 'Доступно обновление — нажмите «Обновить»'
                    : 'Проверить обновление панели';
                refreshIcons();
            }
        }
    };

    async function fetchJson(url, options = {}) {
        const res = await fetch(url, { credentials: 'include', ...options });
        const text = await res.text();
        const data = text ? JSON.parse(text) : {};
        if (!res.ok) {
            throw new Error(data.error || `HTTP ${res.status}`);
        }
        return data;
    }

    async function checkPanelUpdate(silent = false) {
        if (isChecking || isApplying) return;
        const btn = checkBtn();
        isChecking = true;
        if (!silent) setBtnLoading(btn, true, 'refresh-cw');

        try {
            const data = await fetchJson(`${API}?action=check`);
            if (data.error && !data.available) {
                if (!silent) toast(data.error, 'warning');
                setUpdateAvailable(false);
                return;
            }
            setUpdateAvailable(!!data.available);
            if (!silent) {
                if (data.available) {
                    const n = (data.commits || []).length;
                    toast(n > 0
                        ? `Доступно обновление (${n} коммит${n === 1 ? '' : n < 5 ? 'а' : 'ов'})`
                        : 'Доступно обновление панели', 'success');
                } else {
                    toast('Панель актуальна', 'info');
                }
            }
        } catch (e) {
            if (!silent) toast(e.message || 'Ошибка проверки обновлений', 'error');
        } finally {
            isChecking = false;
            if (!silent) {
                setBtnLoading(btn, false, updateAvailable ? 'arrow-down-circle' : 'refresh-cw');
            }
        }
    }

    async function applyPanelUpdate() {
        if (isApplying || !updateAvailable) return;
        const confirmed = window.showConfirm
            ? await window.showConfirm(
                'Обновить панель из репозитория?\n\nБудет выполнен git pull. Страница перезагрузится после успешного обновления.',
                'Обновление панели',
                'info'
            )
            : confirm('Обновить панель из репозитория?');
        if (!confirmed) return;

        const btn = applyBtn();
        isApplying = true;
        setBtnLoading(btn, true, 'download');
        if (checkBtn()) checkBtn().disabled = true;

        try {
            const data = await fetchJson(`${API}?action=apply`, { method: 'POST' });
            if (data.success) {
                toast(data.message || 'Панель обновлена', 'success');
                setUpdateAvailable(false);
                setTimeout(() => location.reload(), 1500);
            } else {
                toast(data.error || 'Ошибка обновления', 'error');
            }
        } catch (e) {
            toast(e.message || 'Ошибка обновления', 'error');
        } finally {
            isApplying = false;
            setBtnLoading(btn, false, 'download');
            if (checkBtn()) checkBtn().disabled = false;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        checkBtn()?.addEventListener('click', () => checkPanelUpdate(false));
        applyBtn()?.addEventListener('click', applyPanelUpdate);
        checkPanelUpdate(true);
    });
})();
