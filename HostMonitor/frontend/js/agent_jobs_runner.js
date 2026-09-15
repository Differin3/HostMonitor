/**
 * Опрос статуса обновления агентов на любой странице панели.
 * Сами команды выполняются на нодах; браузер только показывает прогресс HostJobs.
 */
(function () {
    if (window.__HOSTMONITOR_AGENT_JOBS_RUNNER) return;
    window.__HOSTMONITOR_AGENT_JOBS_RUNNER = true;

    const STORE_KEY = 'hm_agent_jobs_v1';
    const API = `${window.MONITORING_API_BASE || '/api'}/agent_update.php?action=status`;

    let timer = null;
    let ticks = 0;

    function loadTrack() {
        try {
            const raw = sessionStorage.getItem(STORE_KEY);
            if (!raw) return { ids: [] };
            const data = JSON.parse(raw);
            return {
                ids: Array.isArray(data.ids) ? data.ids.map(String) : [],
                startedAt: data.startedAt || Date.now(),
            };
        } catch (_) {
            return { ids: [] };
        }
    }

    function saveTrack(ids, startedAt) {
        try {
            if (!ids.length) {
                sessionStorage.removeItem(STORE_KEY);
                return;
            }
            sessionStorage.setItem(STORE_KEY, JSON.stringify({
                ids: [...ids],
                startedAt: startedAt || Date.now(),
            }));
        } catch (_) { /* ignore */ }
    }

    function jobOf(node) {
        if (node?.agent_job) {
            const age = Number(node.command_age_sec) || 0;
            if (node.agent_job === 'checking' && age > 180) return 'idle';
            if (node.agent_job === 'updating' && age > 600) return 'idle';
            return node.agent_job;
        }
        const cmd = String(node?.last_command || '');
        const status = String(node?.command_status || '').toLowerCase();
        const age = Number(node?.command_age_sec) || 0;
        if (['failed', 'error'].includes(status)) return 'failed';
        if (['update-agent', 'upgrade-agent'].includes(cmd) && ['pending', 'running', 'installing', 'in_progress', ''].includes(status)) {
            return age > 600 ? 'idle' : 'updating';
        }
        if (['check-agent-update', 'check-agent-updates'].includes(cmd) && ['pending', 'running', 'installing', 'in_progress', ''].includes(status)) {
            return age > 180 ? 'idle' : 'checking';
        }
        return 'idle';
    }

    function track(jobId) {
        const t = loadTrack();
        const id = String(jobId);
        if (!t.ids.includes(id)) t.ids.push(id);
        saveTrack(t.ids, t.startedAt || Date.now());
        ensurePoll();
    }

    function untrack(jobId) {
        const t = loadTrack();
        t.ids = t.ids.filter((x) => x !== String(jobId));
        saveTrack(t.ids, t.startedAt);
        if (!t.ids.length) stopPoll();
    }

    function stopPoll() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
        ticks = 0;
    }

    function ensurePoll() {
        const t = loadTrack();
        if (!t.ids.length) return;
        if (timer) return;
        ticks = 0;
        timer = setInterval(pollOnce, 2000);
        pollOnce();
    }

    async function pollOnce() {
        const trackState = loadTrack();
        if (!trackState.ids.length) {
            stopPoll();
            return;
        }
        ticks += 1;
        let nodes = [];
        try {
            const res = await fetch(API, { credentials: 'include' });
            const data = await res.json();
            nodes = data.nodes || [];
        } catch (_) {
            return;
        }

        const updating = nodes.filter((n) => jobOf(n) === 'updating');
        const checking = nodes.filter((n) => jobOf(n) === 'checking');
        const stillBusy = updating.length + checking.length > 0;
        const maxTicks = updating.length ? 300 : 90;

        const left = [];
        trackState.ids.forEach((id) => {
            const sid = String(id);
            if (sid.startsWith('agent-update-batch') || sid.startsWith('agent-check-')) {
                window.HostJobs?.update(id, {
                    detail: updating.length
                        ? `Обновляются: ${updating.map((n) => n.name).join(', ')}`
                        : (checking.length
                            ? `Проверка: ${checking.map((n) => n.name).join(', ')}`
                            : 'Ожидание…'),
                    pct: Math.min(95, 10 + Math.floor((ticks / maxTicks) * 85)),
                    resumable: true,
                });
                if (stillBusy && ticks < maxTicks) left.push(id);
                else if (!stillBusy) window.HostJobs?.done(id, 'Готово');
                else window.HostJobs?.fail(id, 'Таймаут');
                return;
            }
            const m = sid.match(/^agent-update-(\d+)/);
            if (m) {
                const node = nodes.find((n) => String(n.id) === m[1]);
                const job = node ? jobOf(node) : 'idle';
                const err = String(node?.command_result || '').trim();
                if (job === 'idle' || job === 'failed') {
                    if (job === 'failed') window.HostJobs?.fail(id, err || 'Ошибка обновления');
                    else window.HostJobs?.done(id, `${node?.name || m[1]} — готово`);
                } else {
                    window.HostJobs?.update(id, {
                        detail: job === 'updating' ? 'Обновляется…' : 'Проверка…',
                        pct: Math.min(95, 10 + Math.floor((ticks / maxTicks) * 85)),
                        resumable: true,
                    });
                    left.push(id);
                }
                return;
            }
            left.push(id);
        });

        saveTrack(left, trackState.startedAt);
        if (!left.length || ticks >= maxTicks) {
            if (ticks >= maxTicks && left.length) {
                left.forEach((id) => window.HostJobs?.fail(id, 'Таймаут'));
                saveTrack([], trackState.startedAt);
            }
            stopPoll();
        }
    }

    window.AgentJobsRunner = { track, untrack, ensurePoll };

    const boot = () => {
        // Поднять resumable agent-* из HostJobs session и продолжить опрос
        try {
            const raw = sessionStorage.getItem('hm_bg_jobs_v1');
            if (raw) {
                const data = JSON.parse(raw);
                (data.jobs || []).forEach((j) => {
                    if (j?.status === 'running' && String(j.id || '').startsWith('agent-')) {
                        track(j.id);
                    }
                });
            }
        } catch (_) { /* ignore */ }
        ensurePoll();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        setTimeout(boot, 0);
    }
})();
