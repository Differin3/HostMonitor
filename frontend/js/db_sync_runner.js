/**
 * Адаптер копирования базы поверх фоновой очереди.
 *
 * Раньше этот файл сам ходил в api/db_ha.php порциями по ~200 строк и
 * хранил курсор в sessionStorage. Из-за этого миграция умирала при уходе
 * на другую страницу, закрытии вкладки или падении панели: исполнителем
 * был браузер, а состояние нигде не сохранялось.
 *
 * Теперь работа уходит в очередь (scripts/job_worker.php), а отсюда
 * остаётся только показать прогресс. Публичные start / isRunning /
 * cancel и событие hm:db-sync прежние, поэтому databases.js и
 * settings.js продолжают работать без правок.
 */
(function () {
    if (window.__HOSTMONITOR_DB_SYNC_RUNNER) return;
    window.__HOSTMONITOR_DB_SYNC_RUNNER = true;

    const KIND = 'db.sync';

    let lastState = null;

    function isRunning() {
        return !!(lastState && (lastState.status === 'running' || lastState.status === 'queued'));
    }

    // Статусы задачи и статусы, которые ждёт существующий интерфейс, не
    // совпадают: в очереди failed/canceled, в hm:db-sync — error/cancelled.
    // Без перевода провал задачи навсегда рисовался бы как «идёт».
    const STATUS_MAP = {
        queued: 'running',
        running: 'running',
        done: 'done',
        failed: 'error',
        canceled: 'cancelled',
    };

    function num(v) {
        const n = Number(v) || 0;
        return n.toLocaleString('ru-RU');
    }

    function jobToState(job) {
        const mapped = STATUS_MAP[job.status] || 'running';
        const result = job.result || {};

        if (mapped === 'done') {
            const tables = Number(result.tables) || 0;
            const rows = Number(result.rows) || 0;
            const detail = tables
                ? `Готово: ${tables} табл., ${num(rows)} строк`
                : 'Готово';
            return { status: 'done', pct: 100, label: 'Готово', detail, id: job.id };
        }
        if (mapped === 'error') {
            const detail = job.error || 'Ошибка копирования';
            return { status: 'error', pct: Number(job.progress_pct) || 0, label: 'Ошибка', detail, id: job.id };
        }
        if (mapped === 'cancelled') {
            return {
                status: 'cancelled',
                pct: Number(job.progress_pct) || 0,
                label: 'Отменено',
                detail: job.error || 'Отменено',
                id: job.id,
            };
        }

        const label = job.progress_label || (job.status === 'queued' ? 'В очереди…' : 'Копирование…');
        return {
            status: 'running',
            pct: Number(job.progress_pct) || 0,
            label,
            detail: label,
            id: job.id,
        };
    }

    function emit(state) {
        lastState = state;
        window.dispatchEvent(new CustomEvent('hm:db-sync', { detail: state }));
    }

    // Колокольчик уже опрашивает очередь — берём состояние оттуда, чтобы
    // не было второго параллельного запроса каждую секунду.
    window.addEventListener('hm:jobs', (ev) => {
        const d = ev.detail || {};
        const list = [...(d.active || []), ...(d.recent || [])];
        const job = list.find((j) => j && j.kind === KIND);
        if (!job) {
            if (isRunning()) emit({ status: 'idle', pct: 0, detail: '' });
            return;
        }
        emit(jobToState(job));
    });

    // Событие нужно и до первого опроса колокольчика: при открытии
    // страницы с уже идущим копированием прогресс должен появиться сразу.
    window.addEventListener('DOMContentLoaded', () => {
        window.HostJobsBell?.refresh();
    });

    window.DbSyncRunner = {
        isRunning,

        async start(direction) {
            if (isRunning()) {
                window.showToast?.('Синхронизация уже выполняется', 'info');
                return false;
            }
            try {
                await window.HostJobsBell.start(
                    KIND,
                    { direction: direction || 'to_replica' },
                    direction === 'to_primary'
                        ? 'Копирование резервной в основную'
                        : 'Копирование основной в резерв'
                );
                emit({ status: 'running', pct: 0, detail: 'Поставлено в очередь…' });
                return true;
            } catch (e) {
                window.showToast?.(e.message || 'Не удалось поставить задачу', 'error');
                return false;
            }
        },

        async cancel() {
            if (!isRunning() || !lastState || !lastState.id) return;
            try {
                await window.HostJobsBell.cancel(lastState.id);
                emit({ status: 'canceling', pct: lastState.pct || 0, detail: 'Останавливаем…' });
            } catch (e) {
                window.showToast?.(e.message || 'Не удалось отменить', 'error');
            }
        },
    };
})();
