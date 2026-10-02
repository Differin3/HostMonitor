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
          // В браузере присваивание textContent удаляет всех потомков.
          // Без этого очистка списка дописывала бы новые строки к старым,
          // и проверки видели бы не те элементы, что видит панель.
          set textContent(v) {
              this._text = String(v);
              this.childNodes = [];
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
          // jobs_runner.js вешает на строку data-job-id, а «Очистить» по
          // ней узнаёт максимальный видимый id. Без dataset стенд не поймёт
          // очистку так же, как браузер.
          dataset: {},
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
          // Поддерживаем только селектор по атрибуту — он один и используется
          // кнопкой очистки.
          querySelectorAll(sel) {
              const m = /^\[([\w-]+)\]$/.exec(String(sel || ''));
              if (!m) return [];
              const key = m[1].replace(/^data-/, '').replace(/-([a-z])/g, (_, c) => c.toUpperCase());
              return this.childNodes.filter((c) => {
                  const src = (c.dataset && c.dataset[key] !== undefined) ? c.dataset : (c._attrs || {});
                  return src[key] !== undefined && src[key] !== null && String(src[key]) !== '';
              });
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
      for (const id of ['jobsBellBadge', 'jobsBellWorker', 'jobsBellList', 'jobsBellDropdown', 'jobsBellCount', 'jobsBellClear']) {
          byId[id] = makeEl(id === 'jobsBellClear' ? 'button' : 'div');
          byId[id].id = id;
      }

    const timers = [];
    const fetchLog = [];
    const toasts = [];
    const icons = [];
    let responder = () => ({ ok: true, body: {} });

      const store = {};
      const localStore = {};
      const sessionStorage = {
          getItem: (k) => (k in store ? store[k] : null),
          setItem: (k, v) => {
              store[k] = String(v);
          },
      };
      // Граница очистки живёт в localStorage: она должна переживать
      // перезагрузку страницы, иначе очищенное выскочило бы обратно.
      const localStorage = {
          getItem: (k) => (k in localStore ? localStore[k] : null),
          setItem: (k, v) => {
              localStore[k] = String(v);
          },
          removeItem: (k) => {
              delete localStore[k];
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
          localStorage,
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
          localStorage,
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
          localStore,
          respond(fn) {
              responder = fn;
          },
          /**
           * Выполняет ровно один отложенный колбэк.
           *
           * jobs_runner.js при readyState=complete стартует через setTimeout,
           * а таймеры здесь подменены на список: без проглатывания первого
           * колбэка start() не вызвался бы и кнопка «Очистить» не получила
           * бы обработчик. Один, а не все: tick() планирует следующий опрос,
           * и полный прогон крутился бы вечно.
           */
          async flushTimer() {
              const t0 = timers.shift();
              if (t0) t0.fn();
              await realSetTimeout(resolve => resolve(), 0);
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

    console.log('\n== Колокольчик: статус не дублируется ==');
    {
        // jobs_finish() пишет в progress_label то же слово, что STATUS_TITLE
        // выводит в строке состояния («Готово», «Ошибка», «Отменено»).
        // Обе строки рендерились подряд, и статус читался дважды.
        const textsOf = (t) => {
            const out = [];
            JSON.stringify(t.byId.jobsBellList.childNodes, (k, v) => {
                if (k === 'textContent' && typeof v === 'string' && v) out.push(v);
                return v;
            });
            return out;
        };

        const t = boot();
        t.respond(() => ({
            body: Object.assign({}, BELL_BODY, {
                recent: [job({ status: 'done', progress_pct: 100, progress_label: 'Готово', finished_at: '2026-09-30 10:05:00' })],
            }),
        }));
        await t.window.HostJobsBell.refresh();
        const doneTexts = textsOf(t);
        eq(doneTexts.filter((s) => s === 'Готово').length, 1, '«Готово» показывается один раз');
        check(!JSON.stringify(t.byId.jobsBellList.childNodes).includes('Готово Готово'), 'нет подряд идущих «Готово Готово»');

        const t2 = boot();
        t2.respond(() => ({
            body: Object.assign({}, BELL_BODY, {
                recent: [job({ status: 'failed', progress_pct: 40, progress_label: 'Ошибка', error: 'apt: не найден пакет' })],
            }),
        }));
        await t2.window.HostJobsBell.refresh();
        eq(textsOf(t2).filter((s) => s === 'Ошибка').length, 1, '«Ошибка» показывается один раз');
        check(
            textsOf(t2).includes('apt: не найден пакет'),
            'текст ошибки при этом остаётся виден',
        );

        const t3 = boot();
        t3.respond(() => ({
            body: Object.assign({}, BELL_BODY, {
                recent: [job({ status: 'done', progress_pct: 100, progress_label: 'Установлено пакетов: 1' })],
            }),
        }));
        await t3.window.HostJobsBell.refresh();
        const keep = textsOf(t3);
        check(keep.includes('Готово'), 'строка состояния остаётся', JSON.stringify(keep));
        check(keep.includes('Установлено пакетов: 1'), 'осмысленный label не теряется', JSON.stringify(keep));
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

  console.log('\n== Кнопка «Очистить» убирает завершённое, не трогая активное ==');
  {
      const finished = [
          job({ id: 41, status: 'done', progress_pct: 100, finished_at: '2026-09-30 19:23:04' }),
          job({ id: 42, status: 'failed', error: 'Превышено время ожидания', finished_at: '2026-09-30 19:24:00' }),
      ];
      const active = job({ id: 43, status: 'running', progress_pct: 8 });

      const t = boot();
      // Ответ задаём до старта: первый же tick() должен отрисовать список.
      t.respond(() => ({ body: Object.assign({}, BELL_BODY, { recent: finished.concat([active]) }) }));
      // Прогоняем отложенный старт: именно он навешивает обработчик кнопки.
      await t.flushTimer();
      await t.settle();
      eq(
          t.byId.jobsBellList.childNodes.length,
          3,
          'завершённые и активная задачи показаны до очистки'
      );

      // Клик по кнопке: обработчик асинхронный, поэтому дожидаемся тиков.
      t.respond((url) => {
          if (String(url).indexOf('ack-all') >= 0) {
              return { body: { ok: true } };
          }
          return { body: Object.assign({}, BELL_BODY, { recent: finished.concat([active]) }) };
      });
      t.byId.jobsBellClear.dispatch('click', { preventDefault() {}, stopPropagation() {} });
      await t.settle(12);

      const ackCalls = t.fetchLog.filter((c) => String(c.url).indexOf('ack-all') >= 0);
      eq(ackCalls.length, 1, 'очистка сбрасывает счётчик одним запросом ack-all');
      // Кнопка зовёт tick(), но он возвращается, если опрос уже идёт.
      // Поэтому проверяем результат на следующем цикле опроса — именно так
      // список обновляется и в браузере.
      await t.window.HostJobsBell.refresh();
      eq(
          t.byId.jobsBellList.childNodes.length,
          1,
          'после очистки осталась только активная задача'
      );
      const left = JSON.stringify(t.byId.jobsBellList.childNodes);
      check(left.indexOf('running') >= 0 || left.indexOf(active.title) >= 0,
            'активная задача не убрана', left.slice(0, 160));
      eq(
          t.localStore.hm_jobs_cleared_v1,
          '42',
          'граница очистки сохранена в localStorage'
      );

      // Новые завершённые задачи с большим id должны появиться сами.
      t.respond(() => ({
          body: Object.assign({}, BELL_BODY, {
              recent: [job({ id: 44, status: 'done', finished_at: '2026-09-30 20:00:00' })].concat([active]),
          }),
      }));
      await t.window.HostJobsBell.refresh();
      eq(
          t.byId.jobsBellList.childNodes.length,
          2,
          'новая завершённая задача появляется после очистки'
      );

      // Граница переживает перезагрузку: новая вкладка читает localStorage.
      const t2 = boot();
      t2.localStore.hm_jobs_cleared_v1 = '42';
      t2.respond(() => ({ body: Object.assign({}, BELL_BODY, { recent: finished.concat([active]) }) }));
      await t2.window.HostJobsBell.refresh();
      eq(
          t2.byId.jobsBellList.childNodes.length,
          1,
          'после перезагрузки очищенное не возвращается'
      );
  }

  console.log('\n== Кнопка «Очистить» не срабатывает дважды ==');
  {
      const t = boot();
      // Без проглатывания отложенного старта обработчик не навешен.
      await t.flushTimer();
      t.respond(() => ({
          body: Object.assign({}, BELL_BODY, { recent: [job({ id: 7, status: 'done', finished_at: 'x' })] }),
      }));
      await t.window.HostJobsBell.refresh();
      let calls = 0;
      t.respond((url) => {
          if (String(url).indexOf('ack-all') >= 0) {
              calls++;
              return { body: { ok: true } };
          }
          return { body: Object.assign({}, BELL_BODY, { recent: [job({ id: 7, status: 'done', finished_at: 'x' })] }) };
      });
      t.byId.jobsBellClear.dispatch('click', { preventDefault() {}, stopPropagation() {} });
      t.byId.jobsBellClear.dispatch('click', { preventDefault() {}, stopPropagation() {} });
      await t.settle(12);
      eq(calls, 1, 'повторный клик игнорируется, пока кнопка занята');
  }

  console.log('\n== Мобильная вёрстка: окно колокольчика и топбар ==');
    {
        // Ошибка была мобильной: окно раскрывалось абсолютно внутри .hm-drop
        // в шапке 54px и вылезало за правый край, накрывая соседние
        // элементы; селектор ветки панели (до 230px) растягивал шапку, и
        // кнопки наезжали друг на друга.
        const mcss = fs.readFileSync(path.join(__dirname, '..', 'frontend', 'css', 'mobile.css'), 'utf8');
        // Селектор может переноситься через запятую (`.topbar .hm-menu,\n
        // .user-dropdown {`), а правило встречается в файле несколько раз,
        // поэтому собираем все вхождения и ищем по объединённому тексту.
        const mblock = (sel) => {
            const out = [];
            let at = 0;
            for (;;) {
                const i = mcss.indexOf(sel, at);
                if (i < 0) break;
                const open = mcss.indexOf('{', i);
                const close = mcss.indexOf('}', open);
                if (open < 0 || close < 0) break;
                out.push(mcss.slice(i, close) + '}');
                at = close;
            }
            return out.join('\n');
        };
        const rule = (sel) => mblock(sel).split('\n').pop() || '';

        const bell = mblock('.jobs-bell-dropdown');
        check(bell !== '', 'в mobile.css есть правило .jobs-bell-dropdown');
        check(/position:\s*fixed/.test(bell), 'окно колокольчика фиксировано от вьюпорта', bell.trim());
        check(/top:\s*54px/.test(bell), 'окно открывается под шапкой', bell.trim());
        check(/left:\s*8px/.test(bell), 'окно отступлено слева от края экрана', bell.trim());
        check(/right:\s*8px/.test(bell), 'окно прижато к правому краю экрана', bell.trim());
        check(/max-width:\s*none/.test(bell), 'десктопное ограничение 360px снято', bell.trim());

        const topMenu = mblock('.topbar .hm-menu,');
        check(topMenu !== '', 'в mobile.css есть правило для меню шапки');
        check(/position:\s*fixed/.test(topMenu), 'меню шапки фиксировано от вьюпорта', topMenu.trim());
        check(/max-width:\s*none/.test(topMenu), 'меню шапки не ограничено десктопными 360px', topMenu.trim());

        const branch = mblock('.panel-update-branch');
        check(branch !== '', 'в mobile.css есть правило для селектора ветки');
        check(/max-width:\s*\d\dpx/.test(branch), 'селектор ветки ограничен по ширине', branch.trim());
        const cap = Number((branch.match(/max-width:\s*(\d+)px/) || [])[1]);
        check(!(cap > 100), `ширина селектора ужата до ${cap}px, чтобы шапка не разъезжалась`);

        const topRight = mblock('.topbar-right');
        check(/min-width:\s*0/.test(topRight), 'правая группа шапки может сжиматься', topRight.trim());
        check(/flex-shrink:\s*0/.test(topRight), 'правая группа не сжимается в ноль', topRight.trim());

        const item = mblock('.jobs-bell-item');
        check(/padding:\s*1\dpx 1\dpx/.test(item), 'строка списка увеличена под палец', item.trim());

        const title = mblock('.jobs-bell-title');
        check(/font-size:\s*1[3-9]px/.test(title), 'название задачи не микроскопическое', title.trim());

        const lbl = mblock('.jobs-bell-label');
        check(
            /white-space:\s*normal/.test(lbl) && /overflow-wrap:\s*anywhere/.test(lbl),
            'label переносится, а не обрезается в многоточие',
            lbl.trim(),
        );

        // У окна overflow:hidden, поэтому max-height у списка обязателен:
        // без него длинный список обрезался бы и стал бы недостижимым.
        const list = mblock('.jobs-bell-list');
        check(/max-height:\s*calc\(100dvh/.test(list), 'список ограничен высотой экрана', list.trim());
        check(/overflow-y:\s*auto/.test(list), 'список прокручивается', list.trim());
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
