(function () {
    if (window.__HOSTMONITOR_PANEL_UPDATE_INIT) return;
    window.__HOSTMONITOR_PANEL_UPDATE_INIT = true;

    const API = (window.MONITORING_API_BASE || '/api') + '/panel_update.php';
    let updateAvailable = false;
    let isChecking = false;
    let isApplying = false;

    const checkBtn = () => document.getElementById('panelUpdateCheckBtn');
    const applyBtn = () => document.getElementById('panelUpdateApplyBtn');
    const branchSel = () => document.getElementById('panelUpdateBranch');
    const actions = () => document.getElementById('panelUpdateActions');

    const selectedBranch = () => {
        const sel = branchSel();
        return sel ? sel.value : '';
    };

    /**
     * Заполняет список веток из ответа check.
     *
     * Функция была объявлена здесь в вызове, но нигде не определена, поэтому
     * любая проверка падала с ReferenceError, а select оставался с единственным
     * пунктом «— ветка не выбрана —»: выбрать канал было нечем.
     */
    const populateBranchSelect = (data) => {
        const sel = branchSel();
        if (!sel || !data) return;
        const branches = Array.isArray(data.branches) ? data.branches : [];
        const current = selectedBranch() || (data.selected_branch || '');
        const checkedOut = data.branch || '';

        const names = branches
            .map((b) => (b && typeof b === 'object' ? b.name : b))
            .filter((n) => typeof n === 'string' && n !== '')
            .sort();
        if (checkedOut && !names.includes(checkedOut)) names.unshift(checkedOut);
        if (!names.length) return;

        const existing = Array.from(sel.options).map((o) => o.value);
        if (existing.length === names.length + 1 && current === sel.value
            && names.every((n) => existing.includes(n))) {
            // Список уже актуален — не трогаем select, чтобы не сбрасывать выбор.
        } else {
            sel.innerHTML = '';
            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = '— ветка не выбрана —';
            sel.appendChild(empty);
            names.forEach((n) => {
                const opt = document.createElement('option');
                opt.value = n;
                opt.textContent = n + (n === checkedOut ? ' (текущая)' : '');
                sel.appendChild(opt);
            });
        }
        if (current) sel.value = current;
    };

    /** Сохраняет выбранный канал на сервере, чтобы он переживал перезагрузку. */
    async function saveBranch(branch) {
        try {
            await fetchJson(`${API}?action=select`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ branch: branch }),
            });
            return true;
        } catch (e) {
            toast(e.message || 'Не удалось сохранить канал', 'warning');
            return false;
        }
    }

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
            const branch = (branchSel()?.value || '').trim();
            // Короткий таймаут: UI CGI ~20с; длинный fetch вешал каждую вкладку админа.
            // Кнопка «Проверить»: полный fetch + выбор ветки из select.
            const qs = silent
                ? `?action=check&local=1${branch !== '' ? '&branch=' + encodeURIComponent(branch) : ''}`
                : `?action=check${branch !== '' ? '&branch=' + encodeURIComponent(branch) : ''}`;
            const data = await fetchJson(`${API}${qs}`);
            // Список веток приходит и в ошибочном ответе (например, ветка не
            // найдена), поэтому наполняем select до раннего выхода.
            if (data && data.branches) populateBranchSelect(data);
            // Сохранённый канал мог пропасть с origin — backend откатился на
            // текущую ветку и вернул warning. Показываем даже при silent, иначе
            // после перезагрузки страницы админ не узнает, что канал слетел.
            if (data.warning) {
                console.warn('[panel-update]', data.warning);
                toast(data.warning, 'warning');
            }
            if (data.error && !data.available) {
                console.warn('[panel-update]', data.error);
                if (!silent) toast(data.error.split('\n\n')[0], 'warning');
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

    async function applyPanelUpdate(force = false) {
        if (isApplying || (!updateAvailable && !force)) return;
        const branch = selectedBranch();
        const where = branch ? ` на ветку «${branch}»` : '';
        const confirmed = await window.showConfirm(
            force
                ? `Сбросить локальные изменения на сервере и обновить панель${where}?\n\ngit reset --hard + git pull. Файлы data/*.local.php не удаляются.`
                : `Обновить панель из репозитория${where}?\n\nБудет выполнен git pull${branch ? ' с переключением ветки' : ''}. Страница перезагрузится после успешного обновления.`,
            force ? 'Сброс и обновление' : 'Обновление панели',
            force ? 'warning' : 'info'
        );
        if (!confirmed) return;

        const btn = applyBtn();
        isApplying = true;
        setBtnLoading(btn, true, 'download');
        if (checkBtn()) checkBtn().disabled = true;

        try {
            const data = await fetchJson(`${API}?action=apply`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // Ветка обязана уходить в запрос: без неё сервер обновлял
                // текущую ветку, а выбранный канал молча игнорировался.
                body: JSON.stringify({ force: !!force, branch: selectedBranch() }),
            });
            if (data.success) {
                toast(data.message || 'Панель обновлена', 'success');
                setUpdateAvailable(false);
                setTimeout(() => location.reload(), 1500);
            } else if (data.dirty && !force) {
                const files = (data.dirty_files || []).slice(0, 6).join('\n');
                const again = await window.showConfirm(
                    (data.error || 'Локальные изменения') +
                        (files ? '\n\n' + files : '') +
                        '\n\nСбросить их и обновить панель?',
                    'Локальные изменения',
                    'warning'
                );
                if (again) {
                    isApplying = false;
                    setBtnLoading(btn, false, 'download');
                    if (checkBtn()) checkBtn().disabled = false;
                    return applyPanelUpdate(true);
                }
                toast(data.error || 'Обновление отменено', 'warning');
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
        branchSel()?.addEventListener('change', async (e) => {
            const branch = e.target.value;
            setUpdateAvailable(false);
            if (await saveBranch(branch)) {
                // Перепроверяем под выбранный канал: доступность обновления
                // считается именно для него.
                checkPanelUpdate(false);
            }
        });
        checkPanelUpdate(true);
    });
})();
