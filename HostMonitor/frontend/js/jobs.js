/**
 * Всплывающая панель фоновых процессов (синхронизация БД, обновления и т.п.).
 * API: HostJobs.start / update / done / fail / cancel
 *
 * Таймауты:
 * - maxMs > 0 — жёсткий лимит от старта (0 = без лимита, для миграции БД)
 * - staleMs — нет update() дольше N мс → «зависла» (по умолчанию 10 мин)
 */
(function () {
    if (window.__HOSTMONITOR_JOBS_INIT) return;
    window.__HOSTMONITOR_JOBS_INIT = true;

    const STORE_KEY = 'hm_bg_jobs_v1';
    const DEFAULT_MAX_MS = 180000;
    const DEFAULT_STALE_MS = 600000; // 10 мин без прогресса
    const jobs = new Map();
    const cancelHandlers = new Map();
    let collapsed = false;
    let panel = null;
    let watchdogTimer = null;

    function paintIcons(root) {
        if (!window.lucide || typeof lucide.createIcons !== 'function') return;
        try {
            lucide.createIcons({ root: root || document });
        } catch (_) {
            lucide.createIcons();
        }
    }

    function save() {
        try {
            const list = [...jobs.values()].map((j) => ({
                id: j.id,
                title: j.title,
                detail: j.detail,
                pct: j.pct,
                status: j.status,
                cancelable: !!j.cancelable,
                resumable: !!j.resumable,
                startedAt: j.startedAt,
                updatedAt: j.updatedAt,
                maxMs: j.maxMs,
                staleMs: j.staleMs,
            }));
            sessionStorage.setItem(STORE_KEY, JSON.stringify({ collapsed, jobs: list }));
        } catch (_) { /* ignore */ }
    }

    function load() {
        try {
            const raw = sessionStorage.getItem(STORE_KEY);
            if (!raw) return;
            const data = JSON.parse(raw);
            collapsed = !!data.collapsed;
            const now = Date.now();
            (data.jobs || []).forEach((j) => {
                if (!j?.id) return;
                // Старые done/fail старше 2 мин — не поднимаем
                if (['done', 'fail'].includes(j.status) && now - (j.updatedAt || 0) > 120000) return;
                const resumable = !!j.resumable || j.id === 'db-sync' || String(j.id).startsWith('agent-');
                // Не-resumable running после смены страницы — клиентский цикл мёртв
                if (j.status === 'running' && !resumable) {
                    j.status = 'fail';
                    j.detail = 'Прервано (страница перезагружена или вкладка закрыта)';
                    j.pct = j.pct ?? 0;
                }
                // resumable: оставляем running — runner на новой странице продолжит
                if (j.status === 'running' && resumable) {
                    j.detail = j.detail || 'Продолжение…';
                }
                jobs.set(j.id, {
                    id: j.id,
                    title: j.title || 'Задача',
                    detail: j.detail || '',
                    pct: j.pct == null ? null : Number(j.pct),
                    status: j.status || 'running',
                    cancelable: !!j.cancelable,
                    resumable,
                    startedAt: j.startedAt || j.updatedAt || now,
                    updatedAt: j.updatedAt || now,
                    maxMs: j.maxMs == null ? DEFAULT_MAX_MS : Number(j.maxMs),
                    staleMs: j.staleMs == null ? DEFAULT_STALE_MS : Number(j.staleMs),
                });
            });
        } catch (_) { /* ignore */ }
    }

    function ensurePanel() {
        if (panel && document.body.contains(panel)) return panel;
        panel = document.createElement('aside');
        panel.id = 'hm-jobs-panel';
        panel.className = 'hm-jobs-panel hidden';
        panel.setAttribute('aria-live', 'polite');
        panel.innerHTML = `
            <div class="hm-jobs-head">
                <div class="hm-jobs-brand">
                    <span class="hm-jobs-icon"><i data-lucide="activity"></i></span>
                    <div>
                        <strong>Фоновые задачи</strong>
                        <small id="hm-jobs-count">нет активных</small>
                    </div>
                </div>
                <div class="hm-jobs-head-actions">
                    <button type="button" class="hm-jobs-chip" id="hm-jobs-toggle" title="Свернуть / развернуть">
                        <i data-lucide="chevron-down"></i>
                    </button>
                    <button type="button" class="hm-jobs-chip" id="hm-jobs-clear-done" title="Убрать завершённые">
                        <i data-lucide="check-check"></i>
                    </button>
                </div>
            </div>
            <div class="hm-jobs-list" id="hm-jobs-list"></div>
        `;
        document.body.appendChild(panel);
        panel.querySelector('#hm-jobs-toggle')?.addEventListener('click', () => {
            collapsed = !collapsed;
            render();
            save();
        });
        panel.querySelector('#hm-jobs-clear-done')?.addEventListener('click', () => {
            [...jobs.entries()].forEach(([id, j]) => {
                if (j.status === 'done' || j.status === 'fail') jobs.delete(id);
            });
            render();
            save();
        });
        panel.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-job-cancel]');
            if (!btn) return;
            const id = btn.getAttribute('data-job-cancel');
            const fn = cancelHandlers.get(id);
            if (typeof fn === 'function') {
                try { fn(); } catch (_) { /* ignore */ }
            }
            update(id, { detail: 'Отмена…' });
        });
        return panel;
    }

    function statusIcon(status) {
        if (status === 'done') return 'check-circle';
        if (status === 'fail') return 'alert-circle';
        return 'loader-2';
    }

    function render() {
        const el = ensurePanel();
        const list = [...jobs.values()].sort((a, b) => (b.updatedAt || 0) - (a.updatedAt || 0));
        const active = list.filter((j) => j.status === 'running');
        const countEl = el.querySelector('#hm-jobs-count');
        const listEl = el.querySelector('#hm-jobs-list');
        const toggle = el.querySelector('#hm-jobs-toggle i, #hm-jobs-toggle svg');

        if (!list.length) {
            el.classList.add('hidden');
            el.classList.remove('is-collapsed', 'has-active');
            return;
        }

        el.classList.remove('hidden');
        el.classList.toggle('is-collapsed', collapsed);
        el.classList.toggle('has-active', active.length > 0);

        if (countEl) {
            countEl.textContent = active.length
                ? `${active.length} в работе`
                : `${list.length} завершённых`;
        }
        if (toggle) {
            toggle.setAttribute('data-lucide', collapsed ? 'chevron-up' : 'chevron-down');
        }

        if (collapsed) {
            listEl.innerHTML = active.slice(0, 2).map((j) => `
                <div class="hm-jobs-mini">
                    <i data-lucide="${statusIcon(j.status)}" class="${j.status === 'running' ? 'spinning' : ''}"></i>
                    <span>${escapeHtml(j.title)}</span>
                    ${j.pct != null ? `<em>${Math.round(j.pct)}%</em>` : ''}
                </div>
            `).join('') || '<div class="hm-jobs-mini muted">Нет активных — разверните список</div>';
        } else {
            listEl.innerHTML = list.map((j) => {
                const pct = j.pct != null && !Number.isNaN(Number(j.pct))
                    ? Math.min(100, Math.max(0, Number(j.pct)))
                    : null;
                return `
                <article class="hm-jobs-item is-${escapeHtml(j.status)}" data-job-id="${escapeHtml(j.id)}">
                    <div class="hm-jobs-item-top">
                        <i data-lucide="${statusIcon(j.status)}" class="${j.status === 'running' ? 'spinning' : ''}"></i>
                        <div class="hm-jobs-item-text">
                            <strong>${escapeHtml(j.title)}</strong>
                            <small>${escapeHtml(j.detail || '')}</small>
                        </div>
                        ${pct != null ? `<span class="hm-jobs-pct">${Math.round(pct)}%</span>` : ''}
                        ${j.cancelable && j.status === 'running'
                            ? `<button type="button" class="hm-jobs-chip" data-job-cancel="${escapeHtml(j.id)}" title="Отменить"><i data-lucide="x"></i></button>`
                            : ''}
                    </div>
                    ${pct != null ? `<div class="hm-meter hm-jobs-meter" data-tone="${j.status === 'fail' ? 'bad' : 'ok'}"><span style="width:${pct}%"></span></div>` : ''}
                </article>`;
            }).join('');
        }
        paintIcons(el);
    }

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function jobTimedOut(job, now = Date.now()) {
        if (!job || job.status !== 'running') return null;
        const maxMs = Number(job.maxMs);
        // maxMs === 0 → без жёсткого лимита (миграция БД)
        if (maxMs > 0 && job.startedAt && now - job.startedAt > maxMs) {
            return 'Таймаут фоновой задачи';
        }
        const staleMs = Number(job.staleMs);
        if (staleMs > 0 && job.updatedAt && now - job.updatedAt > staleMs) {
            return 'Нет прогресса — задача зависла';
        }
        return null;
    }

    function enforceTimeouts() {
        const now = Date.now();
        [...jobs.values()].forEach((job) => {
            const reason = jobTimedOut(job, now);
            if (!reason) return;
            const fn = cancelHandlers.get(job.id);
            if (typeof fn === 'function') {
                try { fn(); } catch (_) { /* ignore */ }
            }
            finish(job.id, 'fail', reason);
        });
    }

    function ensureWatchdog() {
        if (watchdogTimer) return;
        watchdogTimer = setInterval(() => {
            const hasRunning = [...jobs.values()].some((j) => j.status === 'running');
            if (!hasRunning) return;
            enforceTimeouts();
        }, 15000);
    }

    function start(id, opts = {}) {
        const key = String(id || `job-${Date.now()}`);
        const now = Date.now();
        const existing = jobs.get(key);
        const maxMs = opts.maxMs == null
            ? (existing?.maxMs ?? DEFAULT_MAX_MS)
            : Number(opts.maxMs);
        const staleMs = opts.staleMs == null
            ? (existing?.staleMs ?? DEFAULT_STALE_MS)
            : Number(opts.staleMs);
        const job = {
            id: key,
            title: opts.title || existing?.title || 'Задача',
            detail: opts.detail != null ? opts.detail : (existing?.detail || ''),
            pct: opts.pct == null ? (existing?.pct ?? 0) : Number(opts.pct),
            status: 'running',
            cancelable: opts.cancelable != null ? !!opts.cancelable : !!existing?.cancelable,
            resumable: opts.resumable != null
                ? !!opts.resumable
                : !!(existing?.resumable || key === 'db-sync' || String(key).startsWith('agent-')),
            startedAt: existing?.startedAt || now,
            updatedAt: now,
            maxMs: Number.isFinite(maxMs) ? maxMs : DEFAULT_MAX_MS,
            staleMs: Number.isFinite(staleMs) ? staleMs : DEFAULT_STALE_MS,
        };
        jobs.set(key, job);
        if (typeof opts.onCancel === 'function') {
            cancelHandlers.set(key, opts.onCancel);
        }
        collapsed = false;
        ensureWatchdog();
        render();
        save();
        return key;
    }

    function update(id, opts = {}) {
        const key = String(id);
        const job = jobs.get(key);
        if (!job) return;
        if (job.status === 'fail' || job.status === 'done') return;
        if (opts.title != null) job.title = opts.title;
        if (opts.detail != null) job.detail = opts.detail;
        if (opts.pct != null && !Number.isNaN(Number(opts.pct))) job.pct = Number(opts.pct);
        if (opts.cancelable != null) job.cancelable = !!opts.cancelable;
        if (typeof opts.onCancel === 'function') cancelHandlers.set(key, opts.onCancel);
        if (opts.maxMs != null && Number.isFinite(Number(opts.maxMs))) job.maxMs = Number(opts.maxMs);
        if (opts.staleMs != null && Number.isFinite(Number(opts.staleMs))) job.staleMs = Number(opts.staleMs);
        job.updatedAt = Date.now();
        if (job.status !== 'running') job.status = 'running';
        const reason = jobTimedOut(job);
        if (reason) {
            const fn = cancelHandlers.get(key);
            if (typeof fn === 'function') {
                try { fn(); } catch (_) { /* ignore */ }
            }
            finish(key, 'fail', reason);
            return;
        }
        render();
        save();
    }

    function finish(id, status, message) {
        const key = String(id);
        const job = jobs.get(key);
        if (!job) return;
        job.status = status;
        if (message != null) job.detail = message;
        if (status === 'done' && (job.pct == null || job.pct < 100)) job.pct = 100;
        job.cancelable = false;
        job.updatedAt = Date.now();
        cancelHandlers.delete(key);
        render();
        save();
        // Автоочистка завершённых
        setTimeout(() => {
            const cur = jobs.get(key);
            if (cur && (cur.status === 'done' || cur.status === 'fail')) {
                jobs.delete(key);
                render();
                save();
            }
        }, status === 'fail' ? 20000 : 12000);
    }

    function done(id, message) { finish(id, 'done', message); }
    function fail(id, message) { finish(id, 'fail', message); }

    function cancel(id) {
        const fn = cancelHandlers.get(String(id));
        if (typeof fn === 'function') fn();
    }

    function remove(id) {
        jobs.delete(String(id));
        cancelHandlers.delete(String(id));
        render();
        save();
    }

    window.HostJobs = { start, update, done, fail, cancel, remove, render };

    load();
    ensureWatchdog();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', render);
    } else {
        render();
    }
})();
