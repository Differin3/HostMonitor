(function () {
    if (window.__HOSTMONITOR_PANEL_UPDATE_INIT) return;
    window.__HOSTMONITOR_PANEL_UPDATE_INIT = true;

    const API = (window.MONITORING_API_BASE || '/api') + '/panel_update.php';
    let updateAvailable = false;
    let isChecking = false;
    let isApplying = false;
    // Коммиты HEAD..origin/<branch> из последней проверки: показываются в
    // диалоге подтверждения, чтобы было видно, что именно встанет.
    let availableCommits = [];
    // Ответ на apply не содержит версию (только commit/branch): запоминаем её,
    // чтобы после обновления бейдж не терял «0.1.0 · main@abc» → «main@abc».
    let knownVersion = '';

    const checkBtn = () => document.getElementById('panelUpdateCheckBtn');
    const applyBtn = () => document.getElementById('panelUpdateApplyBtn');
    const branchSel = () => document.getElementById('panelUpdateBranch');
    const actions = () => document.getElementById('panelUpdateActions');
    const versionEl = () => document.getElementById('panelUpdateVersion');
    // Элементы вкладки «Обновление панели» (Настройки). Есть только на settings.php.
    const tabCheckBtn = () => document.getElementById('panelUpdateTabCheckBtn');
    const tabApplyBtn = () => document.getElementById('panelUpdateTabApplyBtn');
    const tabVersion = () => document.getElementById('panelUpdateTabVersion');
    const tabStatus = () => document.getElementById('panelUpdateTabStatus');
    const tabCommits = () => document.getElementById('panelUpdateTabCommits');
    const tabReleases = () => document.getElementById('panelUpdateTabReleases');
    const tabChangelog = () => document.getElementById('panelUpdateTabChangelog');

    /** Короткий git-хэш (7 символов — как rev-parse --short по умолчанию). */
    const shortCommit = (commit) => String(commit || '').trim().slice(0, 7);

    /** Русское склонение: 1 коммит, 2 коммита, 5 коммитов, 21 коммит. */
    const pluralCommits = (n) => {
        const abs = Math.abs(Number(n) || 0);
        const mod100 = abs % 100;
        const mod10 = abs % 10;
        if (mod10 === 1 && mod100 !== 11) return 'коммит';
        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'коммита';
        return 'коммитов';
    };

    /**
     * Описание обновления из git-лога в виде HTML (для стилизованного диалога).
     *
     * Диалог подтверждения раньше был общим («будет выполнен git pull») и не
     * говорил, что именно встанет. Данные уже приходят в action=check
     * (commits = HEAD..origin/<branch>), поэтому показываем список коммитов.
     */
    const updateDescriptionHtml = () => {
        const commits = Array.isArray(availableCommits) ? availableCommits : [];
        if (!commits.length) return '';
        const MAX = 10;
        const shown = commits.slice(0, MAX);
        const items = shown.map((c) => {
            const hash = shortCommit(c && c.hash);
            const msg = String((c && c.message) || '').trim();
            const safeHash = escapeHtml(hash);
            const safeMsg = escapeHtml(msg);
            return `<li>${safeHash ? `<code>${safeHash}</code>` : ''}<span>${safeMsg}</span></li>`;
        }).join('');
        let more = '';
        if (commits.length > MAX) {
            more = `<li>… и ещё ${commits.length - MAX}</li>`;
        }
        const n = commits.length;
        return `
            <div class="update-confirm-commits">
                <strong>Что обновится (${n} ${pluralCommits(n)}):</strong>
                <ul class="update-confirm-commits-list">${items}${more}</ul>
            </div>`;
    };

    /**
     * Показывает установленную версию панели рядом с кнопкой обновления.
     *
     * Раньше «Панель актуальна» приходилось принимать на веру: ни коммит, ни
     * канал нигде не отображались, и понять, что реально стоит на сервере,
     * было нечем. Данные уже приходят в ответе action=check (current_commit и
     * branch), их достаточно вывести.
     */
    const versionLabel = (data) => {
        const commit = String((data && data.current_commit) || '').trim();
        const version = String((data && data.version) || '').trim();
        if (!commit && !version) return '';
        const short = shortCommit(commit);
        const branch = String((data && (data.branch || data.selected_branch)) || '').trim();
        const ref = short ? (branch ? `${branch}@${short}` : short) : '';
        return [version, ref].filter(Boolean).join(' · ');
    };

    const setVersion = (data) => {
        const el = versionEl();
        if (!el) return;
        const src = data || {};
        const version = String(src.version || '').trim();
        if (version) knownVersion = version;
        const label = versionLabel(Object.assign({}, src, { version: version || knownVersion }));
        if (!label) {
            el.classList.add('hidden');
            el.textContent = '';
            return;
        }
        el.textContent = label;
        el.classList.remove('hidden');
        if (el.dataset) {
            el.dataset.version = label;
        }
        const commit = String((data && data.current_commit) || '').trim();
        const branch = String((data && (data.branch || data.selected_branch)) || '').trim();
        el.title = `Установлена версия ${label}`
            + (branch ? ` · канал ${branch}` : '')
            + (commit ? ` · коммит ${commit}` : '');
    };

    const selectedBranch = () => {
        const sel = branchSel();
        return sel ? sel.value : '';
    };

    /**
     * Заполняет вкладку «Обновление панели» в Настройках: версия, статус, список
     * коммитов, релизы (git-теги) и CHANGELOG.md. Элементов нет на других
     * страницах — тогда функция тихо ничего не делает.
     */
    const renderTab = (data) => {
        if (!tabVersion() && !tabStatus()) return;

        const branch = String((data && (data.selected_branch || data.branch)) || '').trim();
        const short = shortCommit(data && data.current_commit);
        const version = String((data && data.version) || '').trim();
        const ref = short ? (branch ? `${branch}@${short}` : short) : '';
        const verEl = tabVersion();
        if (verEl) verEl.textContent = [version, ref].filter(Boolean).join(' · ') || '—';

        const statusEl = tabStatus();
        if (statusEl) {
            let text = 'Панель актуальна';
            let isError = false;
            if (data && data.error && !data.available) {
                text = String(data.error).split('\n\n')[0];
                isError = true;
            } else if (data && data.available) {
                const n = (data.commits || []).length;
                text = n > 0 ? `Доступно обновление (${n} ${pluralCommits(n)})` : 'Доступно обновление';
            }
            statusEl.textContent = text;
            statusEl.classList.toggle('panel-update-status-error', isError);
        }

        const commitsBox = tabCommits();
        if (commitsBox) {
            const commits = (data && Array.isArray(data.commits)) ? data.commits : [];
            if (commits.length) {
                const items = commits.map((c) => {
                    const hash = shortCommit(c && c.hash);
                    const msg = String((c && c.message) || '').trim();
                    return `<li>${hash ? `<code>${escapeHtml(hash)}</code>` : ''}<span>${escapeHtml(msg)}</span></li>`;
                }).join('');
                commitsBox.innerHTML = `<strong>Что обновится</strong><ul class="update-confirm-commits-list">${items}</ul>`;
                commitsBox.classList.remove('hidden');
            } else {
                commitsBox.innerHTML = '';
                commitsBox.classList.add('hidden');
            }
        }

        const relBox = tabReleases();
        if (relBox) {
            const tags = (data && Array.isArray(data.tags)) ? data.tags : [];
            relBox.innerHTML = tags.length
                ? tags.map((t) => `<span class="panel-update-tag"><i data-lucide="tag"></i>${escapeHtml(String(t))}</span>`).join('')
                : '<span class="panel-update-empty">Релизов пока нет — отметьте версию git-тегом.</span>';
        }

        const clBox = tabChangelog();
        if (clBox) {
            const text = (data && data.changelog) ? String(data.changelog) : '';
            clBox.textContent = text.trim() !== '' ? text : 'Файл CHANGELOG.md не найден в репозитории.';
        }

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            try { window.lucide.createIcons(); } catch (_) {}
        }
    };

    /**
     * Отчёт об обновлении.
     *
     * Раньше после git pull показывался тост, и через полторы секунды
     * страница перезагружалась. Информации в этом почти не было: админ
     * не знал, какой коммит встал и — главное — работает ли теперь воркер.
     *
     * Воркер отдельный долгоживущий процесс, и git pull его не трогает:
     * он сам перезапустится на новом коде через несколько секунд. Поэтому
     * в окне написано именно «перезапустится», а не «перезапущен» —
     * обещать то, чего ещё не произошло, здесь нельзя.
     */
    const WORKER_VIEWS = {
        ok: { icon: 'check-circle', kind: 'success', title: 'Воркер работает на новом коде' },
        stale: { icon: 'refresh-cw', kind: 'info', title: 'Воркер перезапустится на новом коде' },
        unknown: { icon: 'help-circle', kind: 'warning', title: 'Версия кода воркера неизвестна' },
        missing: { icon: 'alert-triangle', kind: 'danger', title: 'Воркер не запущен' },
    };

    function paintIcons(root) {
        if (!window.lucide || typeof window.lucide.createIcons !== 'function') return;
        try {
            window.lucide.createIcons({ root });
        } catch (_) {
            window.lucide.createIcons();
        }
    }

    function workerBlockHtml(worker) {
        const view = WORKER_VIEWS[worker.state] || WORKER_VIEWS.unknown;
        return `<div class="update-report-worker update-report-worker-${view.kind}">
            <i data-lucide="${view.icon}"></i>
            <div>
                <strong>${escapeHtml(view.title)}</strong>
                <p>${escapeHtml(worker.text || '')}</p>
            </div>
        </div>`;
    }

    function showUpdateReport(data) {
        const worker = (data && data.worker) || {};
        // Кнопка «Запустить воркер» уместна, только если воркер не поднят и
        // сервер сообщил, что умеет его поднять (root-обёртка установлена).
        const canEnsure = worker.state === 'missing' && !!worker.can_ensure;
        const commit = String((data && data.commit) || '').trim();
        const short = shortCommit(commit);

        let modal = document.getElementById('update-report-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'update-report-modal';
            modal.className = 'confirm-modal hidden';
            document.body.appendChild(modal);
        }
        modal.innerHTML = `
            <div class="confirm-dialog hm-popover update-report">
                <div class="confirm-header">
                    <span class="confirm-icon"><i data-lucide="download"></i></span>
                    <h3>Панель обновлена</h3>
                </div>
                <div class="confirm-body update-report-body">
                    <dl class="update-report-list">
                        <div><dt>Ветка</dt><dd>${escapeHtml((data && data.branch) || '—')}</dd></div>
                        ${short ? `<div><dt>Коммит</dt><dd><code>${escapeHtml(short)}</code></dd></div>` : ''}
                        <div><dt>Итог</dt><dd>${escapeHtml((data && data.message) || 'Файлы панели обновлены')}</dd></div>
                    </dl>
                    <div class="update-report-worker-slot">${workerBlockHtml(worker)}</div>
                </div>
                <div class="confirm-actions">
                    ${canEnsure ? '<button type="button" class="btn-confirm" data-role="ensure"><i data-lucide="play"></i> Запустить воркер</button>' : ''}
                    <button type="button" class="btn-cancel" data-role="later">Позже</button>
                    <button type="button" class="btn-confirm success" data-role="reload">Обновить страницу</button>
                </div>
            </div>`;

        const close = () => {
            modal.classList.remove('active');
            setTimeout(() => modal.classList.add('hidden'), 200);
        };

        modal.querySelector('[data-role="later"]').addEventListener('click', close);
        modal.querySelector('[data-role="reload"]').addEventListener('click', () => location.reload());
        const ensureBtn = modal.querySelector('[data-role="ensure"]');
        if (ensureBtn) {
            ensureBtn.addEventListener('click', () => runWorkerEnsure(modal, ensureBtn));
        }
        modal.addEventListener('click', (ev) => {
            if (ev.target === modal) close();
        });

        modal.classList.remove('hidden');
        requestAnimationFrame(() => modal.classList.add('active'));
        paintIcons(modal);
    }

    async function runWorkerEnsure(modal, btn) {
        if (btn.disabled) return;
        setBtnLoading(btn, true, 'play');
        try {
            const data = await fetchJson(`${API}?action=ensure-worker`, { method: 'POST' });
            const worker = (data && data.worker) || {};
            const slot = modal.querySelector('.update-report-worker-slot');
            if (slot) slot.innerHTML = workerBlockHtml(worker);
            if (data && data.success) {
                toast('Воркер запущен', 'success');
                if (btn.parentNode) btn.parentNode.removeChild(btn);
                paintIcons(modal);
            } else {
                toast((data && data.output) || 'Не удалось запустить воркер', 'warning');
                setBtnLoading(btn, false, 'play');
            }
        } catch (e) {
            toast(e.message || 'Не удалось запустить воркер', 'error');
            setBtnLoading(btn, false, 'play');
        }
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        }[c]));
    }

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
        const tabApply = tabApplyBtn();
        if (tabApply) {
            tabApply.classList.toggle('hidden', !available);
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
            // Версия показывается до любых ранних выходов: даже при ошибке
            // канал/коммит из ответа полезны.
            setVersion(data);
            renderTab(data);
            availableCommits = (data && Array.isArray(data.commits)) ? data.commits : [];
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
                const label = versionLabel(data);
                const suffix = label ? ` (${label})` : '';
                if (data.available) {
                    const n = (data.commits || []).length;
                    toast(n > 0
                        ? `Доступно обновление (${n} ${pluralCommits(n)})${suffix}`
                        : `Доступно обновление панели${suffix}`, 'success');
                } else {
                    toast(`Панель актуальна${suffix}`, 'info');
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
        const descHtml = updateDescriptionHtml();
        const confirmed = await new Promise((resolve) => {
            let modal = document.getElementById('panel-update-confirm-modal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'panel-update-confirm-modal';
                modal.className = 'confirm-modal hidden';
                document.body.appendChild(modal);
            }
            const warn = force
                ? '<div class="update-confirm-note">Будет выполнено: <code>git reset --hard HEAD</code> + <code>git pull</code>. Файлы <code>data/*.local.php</code> не удаляются.</div>'
                : '<div class="update-confirm-note">Страница перезагрузится после успешного обновления.</div>';
            modal.innerHTML = `
                <div class="confirm-dialog hm-popover update-confirm">
                    <div class="confirm-header">
                        <span class="confirm-icon"><i data-lucide="${force ? 'alert-triangle' : 'download'}"></i></span>
                        <h3>${escapeHtml(force ? 'Сброс и обновление' : 'Обновление панели')}</h3>
                    </div>
                    <div class="confirm-body">
                        <div class="update-confirm-lead">${escapeHtml(force ? 'Сбросить локальные изменения на сервере и обновить панель' : 'Обновить панель из репозитория')}${where ? escapeHtml(where) : ''}?</div>
                        ${descHtml}
                        ${warn}
                    </div>
                    <div class="confirm-actions">
                        <button type="button" class="btn-cancel" data-role="cancel">Отмена</button>
                        <button type="button" class="btn-confirm ${force ? 'warning' : 'success'}" data-role="confirm">${force ? 'Сбросить и обновить' : 'Обновить'}</button>
                    </div>
                </div>`;
            const close = (res) => {
                modal.classList.remove('active');
                setTimeout(() => modal.classList.add('hidden'), 200);
                modal.querySelector('[data-role="confirm"]').onclick = null;
                modal.querySelector('[data-role="cancel"]').onclick = null;
                modal.onclick = null;
                document.removeEventListener('keydown', onKey);
                resolve(res);
            };
            const onKey = (e) => {
                if (e.key === 'Escape') { e.preventDefault(); close(false); }
                else if (e.key === 'Enter') { e.preventDefault(); close(true); }
            };
            modal.querySelector('[data-role="confirm"]').onclick = () => close(true);
            modal.querySelector('[data-role="cancel"]').onclick = () => close(false);
            modal.onclick = (ev) => { if (ev.target === modal) close(false); };
            document.addEventListener('keydown', onKey);
            modal.classList.remove('hidden');
            requestAnimationFrame(() => modal.classList.add('active'));
            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                try { window.lucide.createIcons({ root: modal }); } catch (_) {}
            }
        });
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
                    setUpdateAvailable(false);
                    if (data.already_up_to_date) {
                        // Ничего не применилось: отчёт был бы враньём.
                        const label = (versionEl() && versionEl().textContent) || '';
                        toast((data.message || 'Панель уже актуальна') + (label ? ` (${label})` : ''), 'info');
                        return;
                    }
                    // После pull показываем уже установившийся коммит.
                    setVersion({ current_commit: data.commit, branch: data.branch });
                    // Перезагрузку больше не делаем молча и по таймеру:
                    // админ должен успеть прочитать, что встало и что
                    // происходит с воркером.
                    showUpdateReport(data);
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
        tabCheckBtn()?.addEventListener('click', () => checkPanelUpdate(false));
        // Обёртка обязательна: без неё браузер передаёт в applyPanelUpdate
        // объект MouseEvent первым аргументом, он трактуется как force=true,
        // и всегда показывался диалог «Сброс и обновление» с git reset --hard.
        // Промис намеренно не возвращаем: обработчик события его не ждёт.
        applyBtn()?.addEventListener('click', () => { applyPanelUpdate(); });
        tabApplyBtn()?.addEventListener('click', () => { applyPanelUpdate(); });
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
