/**
 * Тесты клиентской части очереди: frontend/js/jobs_runner.js и
 * frontend/js/db_sync_runner.js.
 *
 * Копирование базы раньше выполнял браузер. Теперь его делает воркер на
 * сервере, а колокольчик только опрашивает очередь и рассылает события.
 * Связка между ними и есть то, что ломается тихо: если статус задачи не
 * перевести в ожидаемый интерфейсом вид, провал миграции навсегда
 * рисуется как «идёт», и админ смотрит на вечно идущий прогресс.
 *
 * Оба файла — IIFE, работающие с DOM, поэтому здесь поднимается
 * минимальный стенд и они загружаются в общий sandbox: заодно
 * проверяется, что адаптер действительно получает состояние от
 * колокольчика, а не опрашивает сервер сам.
 *
 * Запуск:  node tests/test_jobs_js.js
 * Выход:   0 — все проверки прошли, 1 — есть падения.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const RUNNER = path.join(__dirname, '..', 'frontend', 'js', 'jobs_runner.js');
const ADAPTER = path.join(__dirname, '..', 'frontend', 'js', 'db_sync_runner.js');

const realSetTimeout = setTimeout;

let passed = 0;
let failed = 0;

function check(ok, name, detail) {
    if (ok) {
        passed++;
        console.log(`  ok   ${name}`);
    } else {
        failed++;
        console.log(`  FAIL ${name}${detail ? ` — ${detail}` : ''}`);
    }
}

function eq(actual, expected, name) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    check(ok, name, ok ? '' : `получено ${JSON.stringify(actual)}, ожидалось ${JSON.stringify(expected)}`);
}

/* ---------------------------------------------------------------- стенд */

/**
 * Убирает комментарии, чтобы проверки по тексту не цеплялись за
 * описания прошлого поведения в шапках файлов.
 */
function stripComments(src) {
    return src
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .split('\n')
        .filter((l) => !/^\s*(\/\/|\*)/.test(l))
        .join('\n');
}

function makeEl(tag) {
    const el = {
        nodeType: 1,
        tagName: String(tag || 'div').toUpperCase(),
        childNodes: [],
        style: {},
        id: '',
        _classes: new Set(),
        _attrs: {},
        _handlers: {},
        className: '',
        set textContent(v) {
            this._text = String(v);
        },
        get textContent() {
            return this._text !== undefined ? this._text : this.childNodes.map((c) => c.textContent).join('');
        },
        setAttribute(k, v) {
            this._attrs[k] = String(v);
        },
        getAttribute(k) {
            return k in this._attrs ? this._attrs[k] : null;
        },
        appendChild(child) {
            this.childNodes.push(child);
            return child;
        },
        addEventListener(type, fn) {
            (this._handlers[type] = this._handlers[type] || []).push(fn);
        },
        dispatch(type, ev) {
            (this._handlers[type] || []).forEach((fn) => fn(ev));
        },
        querySelector() {
            return null;
        },
        querySelectorAll() {
            return [];
        },
    };
    Object.defineProperty(el, 'classList', {
        get() {
            const set = el._classes;
            return {
                add: (c) => set.add(c),
                remove: (c) => set.delete(c),
                contains: (c) => set.has(c),
                toggle: (c, on) => {
                    const want = on === undefined ? !set.has(c) : !!on;
                    if (want) set.add(c);
                    else set.delete(c);
                    return want;
                },
            };
        },
    });
    return el;
}

function makeBus() {
    const handlers = {};
    return {
        addEventListener(type, fn) {
            (handlers[type] = handlers[type] || []).push(fn);
        },
        dispatchEvent(ev) {
            (handlers[ev.type] || []).slice().forEach((fn) => fn(ev));
            return true;
        },
        fire(type, detail) {
            (handlers[type] || []).slice().forEach((fn) => fn(new CustomEventShim(type, { detail })));
        },
        has(type) {
            return !!handlers[type];
        },
    };
}

class CustomEventShim {
    // Второй аргумент — как в браузере: объект с полем detail.
    constructor(type, init) {
        this.type = type;
        this.detail = init && 'detail' in init ? init.detail : undefined;
    }
}

function boot() {
    const byId = {};
    for (const id of ['jobsBellBadge', 'jobsBellWorker', 'jobsBellList', 'jobsBellDropdown', 'jobsBellCount']) {
        byId[id] = makeEl('div');
        byId[id].id = id;
    }

    const timers = [];
    const fetchLog = [];
    const toasts = [];
    const icons = [];
    let responder = () => ({ ok: true, body: {} });

    const store = {};
    const sessionStorage = {
        getItem: (k) => (k in store ? store[k] : null),
        setItem: (k, v) => {
            store[k] = String(v);
        },
    };

    const documentStub = Object.assign(makeBus(), {
        readyState: 'complete',
        visibilityState: 'visible',
        getElementById: (id) => (id in byId ? byId[id] : null),
        querySelector: () => null,
        querySelectorAll: () => [],
        createElement: (tag) => makeEl(tag),
    });

    const windowStub = Object.assign(makeBus(), {
        MONITORING_API_BASE: '/api',
        CSRF_TOKEN: 'test-csrf',
        document: documentStub,
        sessionStorage,
        showToast: (msg, kind) => toasts.push({ msg, kind }),
        lucide: {
            createIcons: (arg) => {
                icons.push(arg && arg.root ? 'scoped' : 'global');
            },
        },
    });

    const sandbox = {
        window: windowStub,
        document: documentStub,
        sessionStorage,
        CustomEvent: CustomEventShim,
        console,
        JSON,
        Object,
        Number,
        Array,
        String,
        Set,
        Math,
        Date,
        Promise,
        setTimeout: (fn, ms) => {
            timers.push({ fn, ms });
            return timers.length;
        },
        clearTimeout: () => {},
        fetch: (url, opts = {}) => {
            fetchLog.push({ url, opts });
            const r = responder(url, opts);
            return Promise.resolve({
                ok: r.ok !== false,
                status: r.status || 200,
                text: () => Promise.resolve(r.body === undefined ? '{}' : JSON.stringify(r.body)),
            });
        },
    };
    sandbox.globalThis = sandbox;
    windowStub.window = windowStub;
    windowStub.fetch = sandbox.fetch;
    windowStub.setTimeout = sandbox.setTimeout;
    windowStub.clearTimeout = sandbox.clearTimeout;
    windowStub.console = console;
    windowStub.JSON = JSON;

    const context = vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(RUNNER, 'utf8'), context, { filename: RUNNER });
    vm.runInContext(fs.readFileSync(ADAPTER, 'utf8'), context, { filename: ADAPTER });

    return {
        window: windowStub,
        document: documentStub,
        byId,
        timers,
        fetchLog,
        toasts,
        icons,
        store,
        respond(fn) {
            responder = fn;
        },
        async settle(n = 6) {
            for (let i = 0; i < n; i++) {
                await realSetTimeout(resolve => resolve(), 0);
            }
        },
    };
}

const BELL_BODY = {
    worker_alive: true,
    unseen: 0,
    active: [],
    recent: [],
};

function job(over) {
    return Object.assign(
        {
            id: 1,
            kind: 'db.sync',
            title: 'Копирование основной в резерв',
            status: 'running',
            progress_pct: 42,
            progress_label: 'Таблица 2 из 5 · metrics · 4 200 строк',
            result: null,
            error: '',
            payload: { cursor: 'секретный курсор' },
            created_at: '2026-09-30 10:00:00',
            started_at: '2026-09-30 10:00:01',
            finished_at: null,
        },
        over || {}
    );
}

/* ---------------------------------------------------------------- тесты */

async function main() {
    console.log('== Колокольчик: счётчик и состояние воркера ==');
    {
        const t = boot();
        t.respond(() => ({ body: Object.assign({}, BELL_BODY, { unseen: 3 }) }));
        await t.window.HostJobsBell.refresh();
        t.window.dispatchEvent(new CustomEventShim('hm:jobs', { detail: {} }));
        eq(t.byId.jobsBellBadge.textContent, '3', 'бейдж показывает число непрочитанных');
        check(t.byId.jobsBellBadge._classes.has('hidden') === false, 'бейдж виден при непрочитанных');

        t.respond(() => ({ body: Object.assign({}, BELL_BODY, { unseen: 0 }) }));
        await t.window.HostJobsBell.refresh();
        eq(t.byId.jobsBellBadge.textContent, '0', 'бейдж обнуляется');
        check(t.byId.jobsBellBadge._classes.has('hidden'), 'бейдж прячется без непрочитанных');

        t.respond(() => ({ body: Object.assign({}, BELL_BODY, { unseen: 250 }) }));
        await t.window.HostJobsBell.refresh();
        eq(t.byId.jobsBellBadge.textContent, '99+', 'большие числа обрезаются');

        t.respond(() => ({ body: Object.assign({}, BELL_BODY, { worker_alive: false }) }));
        await t.window.HostJobsBell.refresh();
        check(
            t.byId.jobsBellWorker._classes.has('hidden') === false,
            'при мёртвом воркере панель предупреждает'
        );
        t.respond(() => ({ body: Object.assign({}, BELL_BODY, { worker_alive: true }) }));
        await t.window.HostJobsBell.refresh();
        check(t.byId.jobsBellWorker._classes.has('hidden'), 'при живом воркере предупреждение скрыто');
    }

    console.log('\n== Колокольчик: курсор наружу не отдаётся ==');
    {
        const t = boot();
        t.respond(() => ({ body: Object.assign({}, BELL_BODY, { recent: [job()] }) }));
        await t.window.HostJobsBell.refresh();
        const rendered = JSON.stringify(t.byId.jobsBellList.childNodes);
        check(rendered.indexOf('секретный курсор') === -1, 'внутренний курсор не попадает в разметку');
    }

    console.log('\n== Колокольчик: иконки рисуются ==');
    {
        const t = boot();
        t.respond(() => ({ body: Object.assign({}, BELL_BODY, { recent: [job()] }) }));
        await t.window.HostJobsBell.refresh();
        check(t.icons.length > 0, 'иконки перерисовываются после опроса', `вызовов: ${t.icons.length}`);
    }

    console.log('\n== Колокольчик: тост один раз на задачу ==');
    {
        const t = boot();
        t.respond(() => ({
            body: Object.assign({}, BELL_BODY, {
                unseen: 1,
                recent: [job({ status: 'done', result: { tables: 5, rows: 54271 } })],
            }),
        }));
        await t.window.HostJobsBell.refresh();
        eq(t.toasts.length, 1, 'появился один тост о завершении');
        check(/5 табл/.test(t.toasts[0].msg), 'в тосте есть итог', t.toasts[0].msg);
        check(t.toasts[0].kind === 'success', 'тост успешный', t.toasts[0].kind);

        // Следующий опрос той же завершённой задачи молчать не должен.
        await t.window.HostJobsBell.refresh();
        eq(t.toasts.length, 1, 'повторный опрос не дублирует тост');
    }

    console.log('\n== Колокольчик: ошибка и отмена ==');
    {
        const t = boot();
        t.respond(() => ({
            body: Object.assign({}, BELL_BODY, {
                recent: [job({ id: 5, status: 'failed', error: 'нет связи с резервом' })],
            }),
        }));
        await t.window.HostJobsBell.refresh();
        eq(t.toasts.length, 1, 'тост об ошибке');
        check(t.toasts[0].kind === 'error', 'тост помечен как ошибка');
        check(/нет связи/.test(t.toasts[0].msg), 'текст ошибки в тосте', t.toasts[0].msg);
    }

    console.log('\n== Колокольчик: частота опроса ==');
    {
        const t = boot();
        t.timers.length = 0;
        t.respond(() => ({ body: Object.assign({}, BELL_BODY, { active: [job()] }) }));
        await t.window.HostJobsBell.refresh();
        eq(t.timers.map((x) => x.ms), [2000], 'при активной задаче опрос чаще');

        t.timers.length = 0;
        t.respond(() => ({ body: BELL_BODY }));
        await t.window.HostJobsBell.refresh();
        eq(t.timers.map((x) => x.ms), [30000], 'без задач опрос редкий');
    }

    console.log('\n== Адаптер: перевод статусов ==');
    {
        const cases = [
            { from: 'queued', status: 'running' },
            { from: 'running', status: 'running' },
            { from: 'done', status: 'done' },
            { from: 'failed', status: 'error' },
            { from: 'canceled', status: 'cancelled' },
        ];
        for (const c of cases) {
            const t = boot();
            const seen = [];
            t.window.addEventListener('hm:db-sync', (ev) => seen.push(ev.detail));
            t.respond(() => ({ body: Object.assign({}, BELL_BODY, { recent: [job({ status: c.from })] }) }));
            await t.window.HostJobsBell.refresh();
            eq(seen.map((s) => s.status), [c.status], `${c.from} → ${c.status}`);
        }
    }

    console.log('\n== Адаптер: прогресс и итог ==');
    {
        const t = boot();
        const seen = [];
        t.window.addEventListener('hm:db-sync', (ev) => seen.push(ev.detail));

        t.respond(() => ({ body: Object.assign({}, BELL_BODY, { recent: [job({ progress_pct: 42 })] }) }));
        await t.window.HostJobsBell.refresh();
        eq(seen[seen.length - 1].pct, 42, 'процент доезжает до интерфейса');
        check(!!seen[seen.length - 1].label, 'подпись прогресса непустая');

        t.respond(() => ({
            body: Object.assign({}, BELL_BODY, {
                recent: [job({ status: 'done', progress_pct: 100, result: { tables: 5, rows: 54271 } })],
            }),
        }));
        await t.window.HostJobsBell.refresh();
        const done = seen[seen.length - 1];
        eq(done.status, 'done', 'финальный статус done');
        eq(done.pct, 100, 'финальный процент 100');
        check(/54[\s\u00a0]?271|54 271/.test(done.detail), 'в итоге видно число строк', done.detail);
    }

    console.log('\n== Адаптер: isRunning и старт ==');
    {
        const t = boot();
        t.respond(() => ({ body: Object.assign({}, BELL_BODY, { recent: [job({ status: 'running' })] }) }));
        await t.window.HostJobsBell.refresh();
        check(t.window.DbSyncRunner.isRunning() === true, 'isRunning true на идущей задаче');

        t.timers.length = 0;
        t.respond(() => ({ body: BELL_BODY }));
        await t.window.HostJobsBell.refresh();
        check(t.window.DbSyncRunner.isRunning() === false, 'isRunning сбрасывается после завершения');

        t.fetchLog.length = 0;
        t.respond(() => ({ body: { id: 9, status: 'queued' } }));
        const started = await t.window.DbSyncRunner.start('to_replica');
        check(started === true, 'start сообщает об успехе');
        const post = t.fetchLog.find((c) => c.url.indexOf('action=start') !== -1);
        check(!!post, 'start уходит на серверную очередь');
        const body = JSON.parse(post.opts.body);
        eq(body.kind, 'db.sync', 'вид задачи db.sync');
        eq(body.payload.direction, 'to_replica', 'направление в payload');
        // В шапке файла про sessionStorage написано намеренно — прошлый
        // браузерный вариант. Проверяем обращения к нему в коде, а не текст.
        check(
            !/sessionStorage\s*\./.test(stripComments(fs.readFileSync(ADAPTER, 'utf8'))),
            'состояние не хранится в sessionStorage'
        );
    }

    console.log('\n== Адаптер: повторный старт и отмена ==');
    {
        const t = boot();
        t.respond(() => ({ body: Object.assign({}, BELL_BODY, { recent: [job({ status: 'running' })] }) }));
        await t.window.HostJobsBell.refresh();

        t.fetchLog.length = 0;
        const again = await t.window.DbSyncRunner.start('to_replica');
        check(again === false, 'второй старт отклонён, пока задача идёт');
        check(
            !t.fetchLog.some((c) => c.url.indexOf('action=start') !== -1),
            'дубль в очередь не отправлен'
        );

        t.fetchLog.length = 0;
        t.respond(() => ({ body: { ok: true } }));
        await t.window.DbSyncRunner.cancel();
        const cancel = t.fetchLog.find((c) => c.url.indexOf('action=cancel') !== -1);
        check(!!cancel, 'отмена уходит на сервер');
        eq(JSON.parse(cancel.opts.body).id, 1, 'отменяется именно текущая задача');
        check(cancel.opts.method === 'POST', 'отмена методом POST');
        check(!!cancel.opts.headers['X-CSRF-Token'], 'отмена с CSRF-токеном');
    }

    console.log('\n== Адаптер: чужой вид задачи игнорируется ==');
    {
        const t = boot();
        const seen = [];
        t.window.addEventListener('hm:db-sync', (ev) => seen.push(ev.detail));
        t.respond(() => ({
            body: Object.assign({}, BELL_BODY, {
                recent: [job({ id: 3, kind: 'db.backup', title: 'Резервная копия' })],
            }),
        }));
        await t.window.HostJobsBell.refresh();
        eq(seen.length, 0, 'событие копирования не создано чужой задачей');
        check(t.window.DbSyncRunner.isRunning() === false, 'isRunning не реагирует на чужой вид');
    }

    console.log('\n== Адаптер: unauthorized не превращается в тост ==');
    {
        const t = boot();
        t.respond(() => ({ ok: false, status: 401, body: { error: 'Unauthorized' } }));
        await t.window.HostJobsBell.refresh();
        eq(t.toasts.length, 0, '401 на фоне не засоряет тостами');
        t.timers.length = 0;
        await t.window.HostJobsBell.refresh();
        check(t.timers.length > 0, 'опрос продолжается, панель не замирает после 401');
    }

    console.log('\n== Обновления агентов и пакетов ведёт сервер, а не вкладка ==');
{
    // Колокольчик рисует только background_jobs. Пока обновления шли через
    // HostJobs (sessionStorage вкладки), переход на другую страницу или
    // новая вкладка обрывали отображение, хотя установка продолжалась.
    const jobsSrc = stripComments(fs.readFileSync(
        path.join(__dirname, '..', 'frontend', 'js', 'jobs.js'), 'utf8',
    ));
    const updatesSrc = stripComments(fs.readFileSync(
        path.join(__dirname, '..', 'frontend', 'js', 'updates.js'), 'utf8',
    ));

    check(
        !/String\(j\.id\)\.startsWith\('agent-'\)/.test(jobsSrc)
            && !/String\(key\)\.startsWith\('agent-'\)/.test(jobsSrc),
        'agent-* больше не помечается resumable: за задачей стоит воркер',
    );
    check(
        !/pkg-install/.test(updatesSrc),
        'фейковой локальной задачи pkg-install больше нет',
    );
    // Счётчик тиков в проверке обновлений — это просто предохранитель от
    // вечного опроса, прогресс он не рисует. Проверяем именно запрет
    // подставления счётчика в процент задачи.
    check(
        !/pct:[^;]*refreshCount/.test(updatesSrc),
        'счётчик таймера не подставляется в процент задачи',
    );
    check(
        !/Math\.min\(95,\s*15\s*\+/.test(updatesSrc),
        'процент установки больше не растёт от тиков setInterval',
    );
    check(
        /function watchInstallRows\(\)/.test(updatesSrc)
            && /function watchAgentRows\(/.test(updatesSrc),
        'таблицы обновляются локальным таймером, а прогресс ведёт сервер',
    );
    check(
        /pagehide/.test(updatesSrc) && /beforeunload/.test(updatesSrc),
        'таймеры таблиц снимаются на уходе со страницы',
    );
}

console.log('\n== Окно фоновых операций не уезжает за край страницы ==');
{
    // Ошибка была чисто позиционной: .hm-menu по умолчанию раскрывается
    // вправо от кнопки (left: 0), а колокольчик стоит у правого края
    // шапки. Окно шириной 330px уезжало за правый край страницы.
    const css = fs.readFileSync(path.join(__dirname, '..', 'frontend', 'css', 'nexus.css'), 'utf8');
    const block = (sel) => {
        const i = css.indexOf(sel + ' {');
        if (i < 0) return '';
        const j = css.indexOf('}', i);
        return j < 0 ? '' : css.slice(i, j);
    };

    const drop = block('.jobs-bell-dropdown');
    check(drop !== '', 'правило .jobs-bell-dropdown есть');
    check(/left:\s*auto/.test(drop), 'окно не раскрывается вправо от кнопки', drop.trim());
    check(/right:\s*0/.test(drop), 'окно прижато к правому краю', drop.trim());
    check(/max-width:[^;]*100vw/.test(drop), 'ширина ограничена шириной окна браузера', drop.trim());
    check(/width:\s*3\d\dpx/.test(drop), 'окно имеет разумную фиксированную ширину', drop.trim());

    check(
        /overflow-wrap:\s*anywhere/.test(block('.jobs-bell-title')),
        'название задачи переносится, а не распирает окно',
        block('.jobs-bell-title').trim(),
    );
    check(
        /overflow-wrap:\s*anywhere/.test(block('.jobs-bell-meta')),
        'строка состояния переносится',
        block('.jobs-bell-meta').trim(),
    );
    check(
        /flex-wrap:\s*wrap/.test(block('.jobs-bell-head')),
        'заголовок окна переносится на узком экране',
        block('.jobs-bell-head').trim(),
    );
}

      console.log(`\nПройдено: ${passed}, провалено: ${failed}`);
    process.exit(failed === 0 ? 0 : 1);
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});
