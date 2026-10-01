/**
 * Колокольчик фоновых операций.
 *
 * Работает на всех страницах панели и только опрашивает сервер: сами
 * задачи выполняет scripts/job_worker.php. Поэтому прогресс виден из
 * любой вкладки, а переход на другую страницу копирование не прерывает.
 *
 * Раньше копирование базы вёл сам браузер через db_sync_runner.js —
 * курсор лежал в sessionStorage, и уход со страницы или выгрузка вкладки
 * браузером обрывали миграцию без возможности продолжить.
 */
(function () {
    if (window.__HOSTMONITOR_JOBS_RUNNER) return;
    window.__HOSTMONITOR_JOBS_RUNNER = true;

    const API = `${window.MONITORING_API_BASE || '/api'}/jobs.php`;
    // Пока идёт активная задача, опрашиваем чаще: прогресс должен
    // обновляться заметно, а не раз в полминуты.
    const IDLE_MS = 30000;
    const ACTIVE_MS = 2000;
    const TOASTED_KEY = 'hm_jobs_toasted_v1';
    const FINISHED = ['done', 'failed', 'canceled'];
    // Ключ границы очистки в localStorage: она должна переживать перезагрузку,
    // иначе очищенное выскочило бы обратно в списке.
    const CLEARED_KEY = 'hm_jobs_cleared_v1';

    let timer = null;
    let inFlight = false;

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content') || '';
        return window.CSRF_TOKEN || '';
    }

    async function api(action, options = {}) {
        const opts = { credentials: 'include', ...options };
        const method = (opts.method || 'GET').toUpperCase();
        if (method !== 'GET') {
            opts.headers = opts.headers || {};
            opts.headers['X-CSRF-Token'] = csrfToken();
        }
        const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, opts);
        const text = await res.text();
        let data = {};
        try {
            data = text ? JSON.parse(text) : {};
        } catch (_) {
            data = { error: 'Некорректный ответ сервера' };
        }
        if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    function fmtTime(value) {
        if (!value) return '';
        const d = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return '';
        return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    const STATUS_TITLE = {
        queued: 'В очереди',
        running: 'Выполняется',
        done: 'Готово',
        failed: 'Ошибка',
        canceled: 'Отменено',
    };

    function statusIcon(status) {
        if (status === 'done') return 'check-circle';
        if (status === 'failed') return 'x-circle';
        if (status === 'canceled') return 'ban';
        if (status === 'queued') return 'clock';
        return 'loader';
    }

    // paintIcons в notify.js/jobs.js — локальные функции, в window их нет,
    // поэтому рисуем иконки сами: список перерисовывается на каждом опросе.
    function paintIcons(root) {
        if (!window.lucide || typeof window.lucide.createIcons !== 'function') return;
        try {
            window.lucide.createIcons({ root });
        } catch (_) {
            window.lucide.createIcons();
        }
    }

    function seenIds() {
        try {
            return JSON.parse(sessionStorage.getItem(TOASTED_KEY) || '[]').map(String);
        } catch (_) {
            return [];
        }
    }

    function rememberToast(id) {
        try {
            const list = seenIds();
            list.push(String(id));
            sessionStorage.setItem(TOASTED_KEY, JSON.stringify(list.slice(-200)));
        } catch (_) { /* ignore */ }
    }

    function renderBadge(unseen) {
        const badge = document.getElementById('jobsBellBadge');
        if (!badge) return;
        const n = Number(unseen) || 0;
        badge.textContent = n > 99 ? '99+' : String(n);
        badge.classList.toggle('hidden', n <= 0);
    }

    function renderWorkerState(alive) {
        const el = document.getElementById('jobsBellWorker');
        if (!el) return;
        el.classList.toggle('hidden', !!alive);
    }

    /**
     * До какого id пользователь нажимал «Очистить».
     *
     * Храним границу, а не список: id растут монотонно, поэтому всё с меньшим
     * id — уже закрытое. Новые завершённые задачи (id больше границы) появляются
     * в списке сами, без перезагрузки страницы.
     */
    function clearedUpTo() {
        try {
            return Number(localStorage.getItem(CLEARED_KEY)) || 0;
        } catch (_) {
            return 0;
        }
    }

    function rememberCleared(id) {
        try {
            const n = Number(id) || 0;
            if (n > clearedUpTo()) localStorage.setItem(CLEARED_KEY, String(n));
        } catch (_) { /* приватный режим — просто не запомним */ }
    }

    function renderList(jobs) {
          const list = document.getElementById('jobsBellList');
          if (!list) return;
          const cleared = clearedUpTo();
          // Прячем только завершённые ниже границы «Очистить». Активные остаются
          // всегда: их нельзя убирать, пока они идут.
          const visible = (Array.isArray(jobs) ? jobs : []).filter(
              (j) => !FINISHED.includes(j.status) || Number(j.id) > cleared
          );
          list.textContent = '';
          if (!visible.length) {
              const empty = document.createElement('div');
              empty.className = 'jobs-bell-empty';
              empty.textContent = 'Операций пока нет';
              list.appendChild(empty);
              return;
          }
          for (const job of visible) {
            const row = document.createElement('div');
            row.className = `jobs-bell-item jobs-bell-item-${job.status}`;
            row.dataset.jobId = String(job.id);
            row.dataset.jobStatus = String(job.status);

            const icon = document.createElement('i');
            icon.setAttribute('data-lucide', statusIcon(job.status));
            row.appendChild(icon);

            const body = document.createElement('div');
            body.className = 'jobs-bell-body';

            const title = document.createElement('div');
            title.className = 'jobs-bell-title';
            title.textContent = job.title || job.kind;
            body.appendChild(title);

            const meta = document.createElement('div');
            meta.className = 'jobs-bell-meta';
            const pct = Number(job.progress_pct) || 0;
            const state = STATUS_TITLE[job.status] || job.status;
            meta.textContent = job.status === 'running'
                ? `${state} · ${pct}%`
                : state;
            body.appendChild(meta);

            if (job.progress_label) {
                const label = document.createElement('div');
                label.className = 'jobs-bell-label';
                label.textContent = job.progress_label;
                body.appendChild(label);
            }
            if (job.status === 'failed' && job.error) {
                const err = document.createElement('div');
                err.className = 'jobs-bell-error';
                err.textContent = job.error;
                body.appendChild(err);
            }

            const bar = document.createElement('div');
            bar.className = 'jobs-bell-bar';
            const fill = document.createElement('div');
            fill.className = 'jobs-bell-bar-fill';
            fill.style.width = `${pct}%`;
            bar.appendChild(fill);
            if (job.status === 'running' || job.status === 'queued') {
                body.appendChild(bar);
            }
            row.appendChild(body);

            const time = document.createElement('div');
            time.className = 'jobs-bell-time';
            time.textContent = fmtTime(job.finished_at || job.started_at || job.created_at);
            row.appendChild(time);

            list.appendChild(row);
        }
        paintIcons(list);
    }

    function toastFinished(job) {
        const title = job.title || job.kind;
        if (job.status === 'done') {
            const rows = Number(job.result && job.result.rows) || 0;
            const tables = Number(job.result && job.result.tables) || 0;
            const tail = tables ? ` · ${tables} табл., ${rows.toLocaleString('ru-RU')} строк` : '';
            if (window.showToast) window.showToast(`Готово: ${title}${tail}`, 'success');
        } else if (job.status === 'failed') {
            if (window.showToast) window.showToast(`Ошибка: ${title} — ${job.error || 'см. журнал'}`, 'error');
        } else if (job.status === 'canceled') {
            if (window.showToast) window.showToast(`Отменено: ${title}`, 'info');
        }
    }

    function markSeen(seen) {
          if (!seen.length) return;
          api('ack', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ ids: seen }),
          }).catch(() => { /* счётчик переживёт до следующего раза */ });
      }

    /**
     * Кнопка «Очистить» в шапке окна.
     *
     * Завершённые и упавшие задачи не удаляются из базы: их ещё можно
     * посмотреть в истории. Здесь мы лишь отмечаем их прочитанными, чтобы
     * они ушли из списка и счётчика. Активные (queued/running) остаются
     * на месте — их убирать нельзя, они ещё идут.
     */
    function setupClearButton() {
        const btn = document.getElementById('jobsBellClear');
        if (!btn || btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', async (e) => {
            // Клик по кнопке не должен закрывать окно: клик вне dropdown
            // обрабатывает notify.js.
            e.preventDefault();
            e.stopPropagation();
            if (btn.disabled) return;
            const list = document.getElementById('jobsBellList');
            // Границу двигаем по самой свежей из видимых ЗАВЕРШЁННЫХ.
            // Активные в неё не входят: они ещё идут, и если сдвинуть границу
            // до их id, задача исчезнет из списка сразу после завершения,
            // не показав результат. Если завершённых нет — ничего не меняем,
            // чтобы пустая очистка не съедала будущие задачи.
            const finishedIds = list
                ? Array.from(list.querySelectorAll('[data-job-id]'))
                    .filter((el) => FINISHED.includes(el.dataset.jobStatus))
                    .map((el) => Number(el.dataset.jobId))
                    .filter((id) => id > 0)
                : [];
            const highest = finishedIds.length ? Math.max.apply(null, finishedIds) : 0;
            btn.disabled = true;
            if (highest > 0) rememberCleared(highest);
            try {
                // Счётчик на колокольчике тоже сбрасываем: иначе бейдж
                // продолжит считать уже скрытые задачи.
                await api('ack-all', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: '{}',
                });
            } catch (err) {
                if (window.showToast) {
                    window.showToast('Не удалось очистить: ' + (err.message || 'ошибка запроса'), 'error');
                }
                btn.disabled = false;
                return;
            }
            await tick();
            btn.disabled = false;
        });
    }

    async function tick() {
        if (inFlight) return;
        inFlight = true;
        let active = false;
        try {
            const data = await api('bell');
            renderWorkerState(data.worker_alive);
            renderBadge(data.unseen);
            renderList(data.recent);
            active = Array.isArray(data.active) && data.active.length > 0;

            // Тост только один раз на задачу за вкладку, иначе каждое
            // обновление опрашивало бы его заново.
            const already = new Set(seenIds());
            const fresh = (data.recent || []).filter(
                (j) => ['done', 'failed', 'canceled'].includes(j.status) && !already.has(String(j.id))
            );
            for (const job of fresh.slice(0, 3)) {
                toastFinished(job);
                rememberToast(job.id);
            }

            const unreadIds = (data.recent || [])
                .filter((j) => ['done', 'failed', 'canceled'].includes(j.status))
                .filter((j) => !already.has(String(j.id)))
                .map((j) => j.id);
            markSeen(unreadIds);

            // Страницы, показывающие прогресс копирования, не опрашивают
            // сервер сами: получают готовое состояние отсюда.
            window.dispatchEvent(new CustomEvent('hm:jobs', {
                detail: {
                    active: data.active || [],
                    recent: data.recent || [],
                    worker_alive: !!data.worker_alive,
                    unseen: Number(data.unseen) || 0,
                },
            }));
        } catch (e) {
            // 401 на фоне — панель ещё не перезалил страницу после логина,
            // падать с тостом на каждой вкладке не нужно.
            if (e && e.message === 'Unauthorized') active = false;
        } finally {
            inFlight = false;
            schedule(active);
        }
    }

    function schedule(active) {
        if (timer) clearTimeout(timer);
        timer = setTimeout(tick, active ? ACTIVE_MS : IDLE_MS);
    }

    function start() {
        setupClearButton();
        tick();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        setTimeout(start, 0);
    }

    // Страница могла уснуть в фоне: перечитываем при возврате фокуса,
    // иначе прогресс догонял бы только через интервал опроса.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') tick();
    });

    window.HostJobsBell = {
        refresh: tick,
        start(kind, payload, title) {
            return api('start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ kind, payload: payload || {}, title: title || '' }),
            });
        },
        cancel(id) {
            return api('cancel', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id }),
            });
        },
    };
})();
