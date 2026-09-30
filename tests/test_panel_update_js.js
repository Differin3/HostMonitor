/**
 * Тесты UI страницы «Обновление панели»: frontend/js/panel_update.js.
 *
 * Закрывает регрессии, из-за которых страница была нерабочей:
 *  - populateBranchSelect() был вызван, но нигде не определён (ReferenceError
 *    на каждой проверке, select оставался пустым);
 *  - ветка не уходила в action=apply, поэтому применялась текущая;
 *  - сохранённый канал не подставлялся обратно в select;
 *  - warning о слетевшем канале не показывался админу.
 *
 * Запуск:  node tests/test_panel_update_js.js
 * Выход:   0 — все проверки прошли, 1 — есть падения.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SCRIPT = path.join(__dirname, '..', 'frontend', 'js', 'panel_update.js');

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
    check(actual === expected, name,
        `получено ${JSON.stringify(actual)}, ожидалось ${JSON.stringify(expected)}`);
}

const tick = () => new Promise((r) => setImmediate(r));

// ─── Минимальный DOM ─────────────────────────────────────────────────────────

function makeElement(id) {
    const el = {
        id: id || '',
        value: '',
        textContent: '',
        disabled: false,
        title: '',
        dataset: {},
        options: [],
classList: {
              _s: new Set(),
              toggle(c, on) { on ? this._s.add(c) : this._s.delete(c); },
              contains(c) { return this._s.has(c); },
              add(c) { this._s.add(c); },
              remove(c) { this._s.delete(c); },
          },
          appendChild(child) { this.options.push(child); return child; },
          // Настоящий DOM разбирает innerHTML в дерево. Разбирать HTML в тесте
          // незачем: кнопки окна адресуются по data-role, и их достаточно
          // отдать как устойчивые мемоизированные элементы — проверяем мы
          // поведение и текст, а не парсер.
          querySelector(sel) {
              const m = /\[data-role="([^"]+)"\]/.exec(sel || '');
              if (!m) return null;
              const role = m[1];
              const cache = this._q || (this._q = {});
              if (!cache[role]) {
                  cache[role] = makeElement(role);
                  cache[role]._role = role;
              }
              return cache[role];
          },
          addEventListener(type, fn) { (this._h = this._h || {})[type] = fn; },
    };
    // В настоящем DOM присваивание innerHTML удаляет всех потомков, иначе
    // старые <option> навсегда оставались бы в списке.
    el._html = '';
    Object.defineProperty(el, 'innerHTML', {
        get() { return this._html; },
        set(v) {
            this._html = String(v);
            if (this._html === '') this.options.length = 0;
        },
    });
    return el;
}

/**
   * Готовит песочницу: элементы, fetch и журнал вызовов.
   * checkResponse — ответ на action=check, applyResponse — на action=apply.
   */
  function harness({ checkResponse, applyResponse }) {
      const els = {
          panelUpdateCheckBtn: makeElement('panelUpdateCheckBtn'),
          panelUpdateApplyBtn: makeElement('panelUpdateApplyBtn'),
          panelUpdateBranch: makeElement('panelUpdateBranch'),
          panelUpdateActions: makeElement('panelUpdateActions'),
      };
      // Первоначально в разметке лежит единственный пустой пункт.
      els.panelUpdateBranch.appendChild({ value: '', textContent: '— ветка не выбрана —' });

      const toasts = [];
      const confirms = [];
      const calls = [];
      const listeners = {};

      const body = makeElement('body');
      // Окно отчёта создаётся через createElement и получает id уже после
      // создания, поэтому getElementById должен уметь находить его там же.
      const created = [];

      const sandbox = {
          console,
          setTimeout: (fn) => fn(),
          requestAnimationFrame: (fn) => fn(),
          JSON, Array, Object, String, Number,
          document: {
              getElementById: (id) => els[id] || created.find((e) => e.id === id) || null,
              createElement: (tag) => {
                  const el = makeElement(tag);
                  created.push(el);
                  return el;
              },
              body,
              addEventListener: (type, fn) => { listeners[type] = fn; },
          },
          location: { reload() { calls.push({ type: 'reload' }); } },
          window: {
              showToast: (msg, type) => toasts.push({ msg, type }),
              showConfirm: async (msg) => { confirms.push(msg); return true; },
          },
      };
      sandbox.window.window = sandbox.window;
      sandbox.fetch = async (url, opts) => {
          const method = (opts && opts.method) || 'GET';
          const body = opts && opts.body ? JSON.parse(opts.body) : null;
          calls.push({ url, method, body });
          let data = {};
          if (url.includes('action=check')) data = checkResponse;
          else if (url.includes('action=select')) data = { success: true };
          else if (url.includes('action=apply')) {
              data = applyResponse === undefined
                  ? { success: true, message: 'ок' }
                  : applyResponse;
          }
          return { ok: true, status: 200, text: async () => JSON.stringify(data) };
      };

      vm.createContext(sandbox);
      vm.runInContext(fs.readFileSync(SCRIPT, 'utf8'), sandbox, { filename: 'panel_update.js' });
      if (listeners.DOMContentLoaded) listeners.DOMContentLoaded();
      const findById = (id) => els[id] || created.find((e) => e.id === id) || null;
      return { els, toasts, confirms, calls, sandbox, modal: () => findById('update-report-modal') };
  }

  /** Ответ сервера об успешном обновлении с заданным состоянием воркера. */
  function applyOk(worker, extra) {
      return Object.assign({
          success: true,
          message: 'Панель обновлена',
          commit: '1a2b3c4d5e6f7890abcdef1234567890abcdef12',
          branch: 'main',
          worker,
      }, extra);
  }

// ─── Тесты ───────────────────────────────────────────────────────────────────

async function testLoadsWithoutReferenceError() {
    console.log('panel_update.js: загрузка без ReferenceError');
    const h = harness({
        checkResponse: {
            available: false, error: null, branch: 'main', selected_branch: 'main',
            branches: [{ name: 'main' }, { name: 'dev' }],
        },
    });
    await tick();
    check(h.calls.some((c) => c.url.includes('action=check')),
        'автопроверка ушла в action=check');
    check(h.toasts.every((t) => !(t.msg || '').includes('not defined')),
        'нет ReferenceError в консольных предупреждениях',
        JSON.stringify(h.toasts.map((t) => t.msg)));
}

async function testPopulateBranchSelect() {
    console.log('\npanel_update.js: наполнение списка веток');
    const h = harness({
        checkResponse: {
            available: false, error: null,
            branch: 'main', selected_branch: 'dev',
            branches: [{ name: 'main' }, { name: 'dev' }, { name: 'feature/my-branch' }],
        },
    });
    await tick();
    const sel = h.els.panelUpdateBranch;
    const names = sel.options.map((o) => o.value);
    check(sel.options.length === 4,
        'select содержит пункт «не выбрана» + 3 ветки',
        `получено ${sel.options.length}: ${JSON.stringify(names)}`);
    check(names.includes('dev') && names.includes('main') && names.includes('feature/my-branch'),
        'все ветки из ответа попали в select', JSON.stringify(names));
    eq(sel.value, 'dev', 'select восстановил сохранённый канал из selected_branch');
    check(sel.options.some((o) => o.textContent.includes('(текущая)')),
        'текущая ветка помечена в списке');
}

async function testPopulateOnError() {
    console.log('\npanel_update.js: select заполняется даже при ошибке');
    const h = harness({
        checkResponse: {
            available: false, error: 'Ветка нет-такой не найдена на origin.',
            branch: 'main', selected_branch: '',
            branches: [{ name: 'main' }, { name: 'dev' }],
        },
    });
    await tick();
    const names = h.els.panelUpdateBranch.options.map((o) => o.value);
    check(names.includes('dev'),
        'ветки показаны, чтобы можно было выбрать другую', JSON.stringify(names));
}

async function testWarningIsShown() {
    console.log('\npanel_update.js: warning о сохранённом канале');
    const h = harness({
        checkResponse: {
            available: false, error: null, branch: 'main', selected_branch: 'main',
            warning: 'Сохранённая ветка обновлений «feature/gone» больше не найдена на origin',
            branches: [{ name: 'main' }, { name: 'dev' }],
        },
    });
    await tick();
    const warn = h.toasts.find((t) => (t.msg || '').includes('feature/gone'));
    check(!!warn, 'warning показан тостом, даже при автопроверке (silent)',
        `тосты: ${JSON.stringify(h.toasts.map((t) => t.msg))}`);
    if (warn) eq(warn.type, 'warning', 'warning помечен типом warning');
}

async function testApplySendsBranch() {
    console.log('\npanel_update.js: apply передаёт ветку');
    const h = harness({
        checkResponse: {
            available: true, error: null, branch: 'main', selected_branch: 'dev',
            branches: [{ name: 'main' }, { name: 'dev' }],
        },
    });
    await tick();
    h.els.panelUpdateApplyBtn._h.click({ target: h.els.panelUpdateApplyBtn });
    await tick();

    const applyCall = h.calls.find((c) => c.url.includes('action=apply'));
    check(!!applyCall, 'запрос action=apply отправлен',
        `вызовы: ${JSON.stringify(h.calls.map((c) => c.url))}`);
    if (applyCall) {
        eq(applyCall.body && applyCall.body.branch, 'dev',
            'в теле apply уходит выбранная ветка (без неё сервер обновлял текущую)');
    }
    const confirmMsg = h.confirms[0] || '';
    check(confirmMsg.includes('«dev»'), 'в подтверждении названа целевая ветка',
        JSON.stringify(confirmMsg.slice(0, 90)));
}

async function testChangeSavesBranch() {
    console.log('\npanel_update.js: смена ветки сохраняется');
    const h = harness({
        checkResponse: {
            available: false, error: null, branch: 'main', selected_branch: 'main',
            branches: [{ name: 'main' }, { name: 'dev' }],
        },
    });
    await tick();
    const sel = h.els.panelUpdateBranch;
    sel.value = 'dev';
    await sel._h.change({ target: { value: 'dev' } });
    await tick();

    const selectCall = h.calls.find((c) => c.url.includes('action=select'));
    check(!!selectCall, 'смена ветки вызывает action=select (раньше канал никуда не сохранялся)');
    if (selectCall) eq(selectCall.body && selectCall.body.branch, 'dev', 'сохраняется выбранная ветка');
    check(h.calls.filter((c) => c.url.includes('action=check')).length >= 2,
        'после смены ветки выполняется перепроверка под новый канал');
}

/**
 * После обновления показывается окно с отчётом, а не молчаливый тост.
 *
 * Раньше был тост и reload через 1,5 секунды — админ не успевал узнать ни
 * какой коммит встал, ни работает ли воркер, который git pull не перезапускает.
 */
async function testUpdateReportShowsInsteadOfSilentReload() {
    console.log('\npanel_update.js: окно отчёта вместо молчаливой перезагрузки');
    const h = harness({
        checkResponse: {
            available: true, branch: 'main', selected_branch: 'main',
            branches: [{ name: 'main' }],
        },
        applyResponse: applyOk({
            state: 'stale', alive: true, up_to_date: false,
            text: 'Воркер работает на старом коде и перезапустится сам.',
        }),
    });
    await tick();
    await h.els.panelUpdateApplyBtn._h.click();
    await tick();
    await tick();

    const modal = h.modal();
    check(!!modal, 'после обновления открывается окно отчёта');
    if (!modal) return;
    check(modal.classList.contains('active'), 'окно показано, а не осталось скрытым');
    check(h.toasts.length === 0, 'информация не потеряна в молчаливом тосте');
    check(!h.calls.some((c) => c.type === 'reload'), 'страница не перезагружается молча и по таймеру');

    check(modal.innerHTML.includes('Панель обновлена'), 'в окне есть заголовок результата');
    check(modal.innerHTML.includes('main'), 'в окне указана ветка');
    check(modal.innerHTML.includes('1a2b3c4d'), 'в окне указан сокращённый коммит');
    check(!modal.innerHTML.includes('1a2b3c4d5e6f7890abcdef1234567890abcdef12'),
        'коммит показан коротко, а не целиком');
    check(modal.innerHTML.includes('перезапустится'), 'окно объясняет, что воркер перезапустится');
}

/**
 * Кнопки окна: «Позже» закрывает, «Обновить страницу» перезагружает.
 */
async function testUpdateReportButtons() {
    console.log('\npanel_update.js: кнопки окна отчёта');
    const h = harness({
        checkResponse: {
            available: true, branch: 'main', selected_branch: 'main',
            branches: [{ name: 'main' }],
        },
        applyResponse: applyOk({ state: 'ok', alive: true, up_to_date: true, text: 'Воркер работает на новом коде.' }),
    });
    await tick();
    await h.els.panelUpdateApplyBtn._h.click();
    await tick();
    await tick();

    const modal = h.modal();
    check(!!modal, 'окно создано');
    if (!modal) return;
    check(modal.innerHTML.includes('Воркер работает на новом коде'), 'окно сообщает, что воркер на новом коде');

    modal.querySelector('[data-role="reload"]')._h.click();
    check(h.calls.some((c) => c.type === 'reload'), '«Обновить страницу» перезагружает панель');

    modal.querySelector('[data-role="later"]')._h.click();
    check(!modal.classList.contains('active'), '«Позже» закрывает окно');
}

/**
 * «Уже актуально» — обновления не было, отчёт был бы враньём.
 */
async function testAlreadyUpToDateStaysToast() {
    console.log('\npanel_update.js: «уже актуально» остаётся тостом');
    const h = harness({
        checkResponse: {
            available: true, branch: 'main', selected_branch: 'main',
            branches: [{ name: 'main' }],
        },
        applyResponse: { success: true, already_up_to_date: true, message: 'Панель уже актуальна' },
    });
    await tick();
    await h.els.panelUpdateApplyBtn._h.click();
    await tick();
    await tick();

    check(!h.modal(), 'окно отчёта не показывается, если ничего не применилось');
    check(h.toasts.length === 1 && h.toasts[0].msg === 'Панель уже актуальна',
        'показан тост «уже актуальна»');
    check(!h.calls.some((c) => c.type === 'reload'), 'перезагрузки при «уже актуальна» нет');
}

/**
 * Ответ сервера нельзя вставлять в innerHTML как есть: коммит и текст
 * приходят из git и могут содержать что угодно.
 */
async function testReportEscapesServerText() {
    console.log('\npanel_update.js: текст ответа экранируется');
    const h = harness({
        checkResponse: {
            available: true, branch: 'main', selected_branch: 'main',
            branches: [{ name: 'main' }],
        },
        applyResponse: applyOk({
            state: 'ok', alive: true, up_to_date: true,
            text: '<img src=x onerror=alert(1)>',
        }),
    });
    await tick();
    await h.els.panelUpdateApplyBtn._h.click();
    await tick();
    await tick();

    const modal = h.modal();
    check(!!modal, 'окно создано');
    if (!modal) return;
    check(!modal.innerHTML.includes('<img src=x'), 'HTML из ответа не попадает в разметку');
    check(modal.innerHTML.includes('&lt;img src=x'), 'HTML из ответа экранирован');
}

/**
 * Воркер не запущен — окно обязано сказать, что именно делать.
 */
async function testMissingWorkerExplainsFix() {
    console.log('\npanel_update.js: неработающий воркер не молчит');
    const h = harness({
        checkResponse: {
            available: true, branch: 'main', selected_branch: 'main',
            branches: [{ name: 'main' }],
        },
        applyResponse: applyOk({
            state: 'missing', alive: false, up_to_date: null,
            text: 'Воркер не отвечает. Выполнить sudo bash scripts/install_jobs_worker.sh',
        }),
    });
    await tick();
    await h.els.panelUpdateApplyBtn._h.click();
    await tick();
    await tick();

    const modal = h.modal();
    check(!!modal, 'окно создано');
    if (!modal) return;
    check(modal.innerHTML.includes('install_jobs_worker.sh'), 'окно подсказывает, как поднять воркер');
    check(modal.innerHTML.includes('Воркер не запущен'), 'окно явно говорит, что воркер не запущен');
}

  (async () => {
      await testLoadsWithoutReferenceError();
      await testPopulateBranchSelect();
      await testPopulateOnError();
      await testWarningIsShown();
      await testApplySendsBranch();
      await testChangeSavesBranch();
      await testUpdateReportShowsInsteadOfSilentReload();
      await testUpdateReportButtons();
      await testAlreadyUpToDateStaysToast();
      await testReportEscapesServerText();
      await testMissingWorkerExplainsFix();

      console.log(`\nИтог: passed=${passed} failed=${failed}`);
      process.exit(failed > 0 ? 1 : 0);
  })();
