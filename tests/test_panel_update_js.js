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
        },
        appendChild(child) { this.options.push(child); return child; },
        querySelector() { return null; },
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
 * checkResponse — ответ на action=check.
 */
function harness({ checkResponse }) {
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

    const sandbox = {
        console,
        setTimeout: (fn) => fn(),
        JSON, Array, Object, String,
        document: {
            getElementById: (id) => els[id] || null,
            createElement: (tag) => makeElement(tag),
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
        else if (url.includes('action=apply')) data = { success: true, message: 'ок' };
        return { ok: true, status: 200, text: async () => JSON.stringify(data) };
    };

    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(SCRIPT, 'utf8'), sandbox, { filename: 'panel_update.js' });
    if (listeners.DOMContentLoaded) listeners.DOMContentLoaded();
    return { els, toasts, confirms, calls, sandbox };
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

(async () => {
    await testLoadsWithoutReferenceError();
    await testPopulateBranchSelect();
    await testPopulateOnError();
    await testWarningIsShown();
    await testApplySendsBranch();
    await testChangeSavesBranch();

    console.log(`\nИтог: passed=${passed} failed=${failed}`);
    process.exit(failed > 0 ? 1 : 0);
})();
