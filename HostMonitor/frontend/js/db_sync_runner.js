/**
 * Фоновая синхронизация БД (миграция таблиц) — живёт на всех страницах панели.
 * Состояние в sessionStorage; при переходе на другую вкладку цикл продолжается
 * после загрузки layout (jobs.js + этот скрипт).
 */
(function () {
    if (window.__HOSTMONITOR_DB_SYNC_RUNNER) return;
    window.__HOSTMONITOR_DB_SYNC_RUNNER = true;

    const STATE_KEY = 'hm_db_sync_state_v1';
    const JOB_ID = 'db-sync';
    const API = `${window.MONITORING_API_BASE || '/api'}/db_ha.php`;

    let running = false;
    let abort = false;
    let loopPromise = null;

    function loadState() {
        try {
            const raw = sessionStorage.getItem(STATE_KEY);
            if (!raw) return null;
            const s = JSON.parse(raw);
            if (!s || s.status !== 'running') return null;
            return s;
        } catch (_) {
            return null;
        }
    }

    function saveState(state) {
        try {
            if (!state || state.status === 'done' || state.status === 'idle') {
                sessionStorage.removeItem(STATE_KEY);
                return;
            }
            sessionStorage.setItem(STATE_KEY, JSON.stringify(state));
        } catch (_) { /* ignore */ }
    }

    function clearState() {
        try { sessionStorage.removeItem(STATE_KEY); } catch (_) { /* ignore */ }
    }

    async function api(payload, { retries = 2 } = {}) {
        const opts = {
            method: payload ? 'POST' : 'GET',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
        };
        if (payload) opts.body = JSON.stringify(payload);

        let lastErr = null;
        for (let attempt = 0; attempt <= retries; attempt++) {
            try {
                const res = await fetch(API, opts);
                const text = await res.text();
                let data = {};
                try { data = text ? JSON.parse(text) : {}; } catch (_) {
                    throw new Error(text?.slice(0, 200) || `HTTP ${res.status}`);
                }
                if (!res.ok || data.error) {
                    throw new Error(data.error || data.message || `HTTP ${res.status}`);
                }
                return data;
            } catch (e) {
                lastErr = e;
                if (attempt < retries) await new Promise((r) => setTimeout(r, 800 * (attempt + 1)));
            }
        }
        throw lastErr || new Error('API sync failed');
    }

    function publishProgress(state) {
        const title = state.direction === 'to_primary'
            ? 'Синхронизация: резерв → основная'
            : 'Синхронизация: основная → резерв';
        const payload = {
            ...state,
            status: state.status || 'running',
        };
        window.HostJobs?.start(JOB_ID, {
            title,
            detail: payload.detail || 'Копирование…',
            pct: payload.pct ?? 0,
            cancelable: true,
            resumable: true,
            maxMs: 0,
            staleMs: 20 * 60 * 1000,
            onCancel: () => { abort = true; },
        });
        window.HostJobs?.update(JOB_ID, {
            detail: payload.detail || 'Копирование…',
            pct: payload.pct ?? 0,
        });
        window.dispatchEvent(new CustomEvent('hm:db-sync', { detail: payload }));
    }

    function isRunning() {
        return running || !!loadState();
    }

    async function runLoop(initial) {
        if (running) return;
        running = true;
        abort = false;

        let state = initial || loadState();
        if (!state || state.status !== 'running') {
            running = false;
            return;
        }

        publishProgress(state);

        try {
            // Подготовка ещё не сделана
            if (!Array.isArray(state.tables) || !state.tables.length) {
                publishProgress({ ...state, detail: 'Подготовка…', pct: 0 });
                const prep = await api({ action: 'sync_prepare', direction: state.direction });
                state = {
                    ...state,
                    direction: prep.direction || state.direction,
                    tables: prep.tables || [],
                    chunkLimit: Number(prep.chunk_limit) || 200,
                    srcLabel: prep.source_label || 'источник',
                    dstLabel: prep.target_label || 'приёмник',
                    srcName: prep.source_name || '',
                    dstName: prep.target_name || '',
                    index: 0,
                    offset: 0,
                    cursor: null,
                    schemaDone: false,
                    tableRows: 0,
                    totalRows: 0,
                    status: 'running',
                };
                if (!state.tables.length) throw new Error('В источнике нет таблиц для копирования');
                const sn = state.srcName ? ` (${state.srcName})` : '';
                const dn = state.dstName ? ` (${state.dstName})` : '';
                state.detail = `${state.srcLabel}${sn} → ${state.dstLabel}${dn} · таблиц: ${state.tables.length}`;
                state.pct = 0;
                saveState(state);
                publishProgress(state);
            }

            const total = state.tables.length;
            const chunkLimit = state.chunkLimit || 200;

            while (state.index < total) {
                if (abort) throw new Error('Отменено пользователем');

                const i = state.index;
                const table = state.tables[i];

                if (!state.schemaDone) {
                    state.detail = `Создание схемы «${table}»…`;
                    state.pct = (i / total) * 100;
                    saveState(state);
                    publishProgress(state);
                    await api({
                        action: 'sync_table_schema',
                        direction: state.direction,
                        table,
                    });
                    state.schemaDone = true;
                    state.offset = 0;
                    state.cursor = null;
                    state.tableRows = 0;
                    saveState(state);
                }

                let guard = Number(state.guard) || 0;
                while (true) {
                    if (abort) throw new Error('Отменено пользователем');
                    if (++guard > 50000) {
                        throw new Error(`Слишком много порций для таблицы «${table}» — прервано`);
                    }
                    state.guard = guard;

                    const basePct = (i / total) * 100;
                    const within = Math.min(99, (state.tableRows / Math.max(state.tableRows + chunkLimit, 1)) * (100 / total));
                    state.detail = `«${table}» · уже ${(state.tableRows || 0).toLocaleString('ru-RU')} строк…`;
                    state.pct = basePct + within;
                    state.label = `Таблица ${i + 1} из ${total}`;
                    saveState(state);
                    publishProgress({ ...state, detail: `${state.label}: ${state.detail}` });

                    const res = await api({
                        action: 'sync_table',
                        direction: state.direction,
                        table,
                        index: i,
                        total,
                        offset: state.offset,
                        cursor: state.cursor,
                        limit: Math.min(chunkLimit, 200),
                    });

                    const rows = Number(res.rows) || 0;
                    state.tableRows = (state.tableRows || 0) + rows;
                    state.totalRows = (state.totalRows || 0) + rows;
                    state.offset = Number(res.next_offset) || (state.offset + rows);
                    state.cursor = res.next_cursor ?? null;
                    state.detail = `«${table}» — ${state.tableRows.toLocaleString('ru-RU')} строк`;
                    state.pct = ((i + (res.table_done ? 1 : 0.55)) / total) * 100;
                    saveState(state);
                    publishProgress({ ...state, detail: `Таблица ${i + 1} из ${total}: ${state.detail}` });

                    if (res.table_done || rows === 0) break;
                }

                // следующая таблица
                state.index = i + 1;
                state.schemaDone = false;
                state.offset = 0;
                state.cursor = null;
                state.tableRows = 0;
                state.guard = 0;
                saveState(state);
            }

            const summary = `Готово: ${total} табл., ${(state.totalRows || 0).toLocaleString('ru-RU')} строк`;
            window.HostJobs?.done(JOB_ID, summary);
            if (window.showToast) window.showToast('Копирование базы завершено', 'success');
            clearState();
            window.dispatchEvent(new CustomEvent('hm:db-sync', {
                detail: { status: 'done', detail: summary, pct: 100 },
            }));
        } catch (e) {
            const msg = e?.message || 'Ошибка синхронизации';
            if (abort || msg === 'Отменено пользователем') {
                try {
                    await api({ action: 'sync_abort', direction: state?.direction || 'to_replica' }, { retries: 0 });
                } catch (_) { /* ignore */ }
                window.HostJobs?.fail(JOB_ID, 'Отменено');
                window.dispatchEvent(new CustomEvent('hm:db-sync', {
                    detail: { status: 'cancelled', detail: 'Отменено', pct: state?.pct || 0 },
                }));
            } else {
                window.HostJobs?.fail(JOB_ID, msg);
                if (window.showToast) window.showToast(msg, 'error');
                window.dispatchEvent(new CustomEvent('hm:db-sync', {
                    detail: { status: 'error', detail: msg, pct: state?.pct || 0 },
                }));
            }
            clearState();
        } finally {
            running = false;
            abort = false;
            loopPromise = null;
        }
    }

    async function start(direction, { skipConfirm } = {}) {
        if (running || loadState()) {
            if (window.showToast) window.showToast('Синхронизация уже выполняется', 'info');
            return false;
        }
        const dir = direction === 'to_primary' ? 'to_primary' : 'to_replica';
        if (!skipConfirm && window.showConfirm) {
            const titles = {
                to_replica: 'Скопировать основную базу на резерв',
                to_primary: 'Скопировать резервную базу на основную',
            };
            const ok = await window.showConfirm(
                `${titles[dir]}.\n\nВсе таблицы на приёмнике будут удалены и созданы заново. Можно переходить по страницам панели — копирование продолжится.\n\nПродолжить?`,
                'Копирование базы',
                'warning'
            );
            if (!ok) return false;
        }

        const state = {
            status: 'running',
            direction: dir,
            tables: null,
            index: 0,
            offset: 0,
            cursor: null,
            schemaDone: false,
            tableRows: 0,
            totalRows: 0,
            detail: 'Подготовка…',
            pct: 0,
            startedAt: Date.now(),
        };
        saveState(state);
        publishProgress(state);
        loopPromise = runLoop(state);
        return true;
    }

    function cancel() {
        abort = true;
        const s = loadState();
        if (s) {
            s.detail = 'Отмена…';
            saveState(s);
            publishProgress(s);
        }
    }

    function resumeIfNeeded() {
        const state = loadState();
        if (!state || state.status !== 'running') return;
        if (running) return;
        publishProgress(state);
        loopPromise = runLoop(state);
    }

    window.DbSyncRunner = {
        start,
        cancel,
        resumeIfNeeded,
        isRunning,
        getState: loadState,
        JOB_ID,
    };

    // После jobs.js на любой странице — продолжить миграцию
    const boot = () => resumeIfNeeded();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        setTimeout(boot, 0);
    }
})();
