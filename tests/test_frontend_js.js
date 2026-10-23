/**
 * Тесты frontend-утилит: frontend/js/common.js.
 *
 * Проверяем, что после выноса esc()/escapeHtml() в общий модуль
 * экранирование ведёт себя как прежняя regex-реализация и что алиасы
 * действительно ссылаются на одну функцию.
 *
 * Запуск:  node tests/test_frontend_js.js
 * Выход:   0 — все проверки прошли, 1 — есть падения.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const JS_DIR = path.join(__dirname, '..', 'frontend', 'js');
const COMMON = path.join(JS_DIR, 'common.js');

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
    check(actual === expected, name, `получено ${JSON.stringify(actual)}, ожидалось ${JSON.stringify(expected)}`);
}

// --- загрузка common.js в изолированном контексте -------------------------
function loadCommon(apiBase) {
    const sandbox = { window: {} };
    if (apiBase !== undefined) {
        sandbox.window.MONITORING_API_BASE = apiBase;
    }
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(COMMON, 'utf8'), sandbox, { filename: 'common.js' });
    return sandbox.window;
}

console.log('common.js: загрузка без ошибок');
const w = loadCommon();
check(typeof w.esc === 'function', 'esc доступен на window');
check(typeof w.escapeHtml === 'function', 'escapeHtml доступен на window');
check(typeof w.escHtmlAttr === 'function', 'escHtmlAttr доступен на window');
check(typeof w.authEscape === 'function', 'authEscape доступен на window');

console.log('common.js: алиасы — одна и та же функция');
eq(w.escapeHtml, w.esc, 'escapeHtml === esc');
eq(w.escHtmlAttr, w.esc, 'escHtmlAttr === esc');
eq(w.authEscape, w.esc, 'authEscape === esc');

console.log('esc(): экранирование спецсимволов');
eq(w.esc('<script>alert(1)</script>'),
    '&lt;script&gt;alert(1)&lt;/script&gt;', 'теги экранируются');
eq(w.esc('a & b'), 'a &amp; b', 'ampersand экранируется');
eq(w.esc('say "hi"'), 'say &quot;hi&quot;', 'двойные кавычки экранируются');
eq(w.esc("it's"), "it's", 'одинарные кавычки не трогаются (панель использует только двойные)');

console.log('esc(): обработка нестроковых значений');
eq(w.esc(null), '', 'null -> пустая строка');
eq(w.esc(undefined), '', 'undefined -> пустая строка');
eq(w.esc(0), '0', 'число 0 не теряется');
eq(w.esc(false), 'false', 'false не теряется');
eq(w.esc(NaN), 'NaN', 'NaN не теряется');

console.log('esc(): идемпотентность против двойного экранирования');
// & экранируется первым, поэтому «&lt;» не превращается в «&amp;lt;».
eq(w.esc('&lt;'), '&amp;lt;', 'уже экранированное не экранируется дважды');

console.log('API_BASE: разрешение базы API');
eq(loadCommon('/panel/api').API_BASE, '/panel/api', 'берётся из MONITORING_API_BASE (за reverse-proxy)');
eq(loadCommon().API_BASE, '/api', 'дефолт /api');

console.log('hmCsvCell()/hmToCsv(): сборка CSV');
check(typeof w.hmCsvCell === 'function', 'hmCsvCell доступен на window');
check(typeof w.hmToCsv === 'function', 'hmToCsv доступен на window');
eq(w.hmCsvCell('plain'), 'plain', 'обычное значение без кавычек');
eq(w.hmCsvCell('a;b'), '"a;b"', 'точка с запятой в кавычках');
eq(w.hmCsvCell('a,b'), '"a,b"', 'запятая в кавычках');
eq(w.hmCsvCell('say "hi"'), '"say ""hi"""', 'кавычки удваиваются');
eq(w.hmCsvCell('line1\nline2'), '"line1\nline2"', 'перенос строки в кавычках');
eq(w.hmCsvCell(null), '', 'null -> пустая строка');
eq(w.hmToCsv(['a', 'b'], [[1, 2], [3, 4]]), 'a,b\r\n1,2\r\n3,4', 'запятая — разделитель по умолчанию');
eq(w.hmToCsv(['a', 'b'], [[1, 2]], ';'), 'a;b\r\n1;2', 'явный разделитель «;»');
eq(w.hmToCsv(['x'], []), 'x', 'только заголовок, когда строк нет');

console.log('common.js: не осталось top-level дублей объявлений');
const files = fs.readdirSync(JS_DIR).filter((f) => f.endsWith('.js') && f !== 'common.js');
const declRe = /^(?:const|let|var|function)\s+(esc|escapeHtml|escHtmlAttr|authEscape|API_BASE)\s*[=(]/;
const offenders = [];
for (const f of files) {
    const src = fs.readFileSync(path.join(JS_DIR, f), 'utf8');
    src.split('\n').forEach((line, i) => {
        if (declRe.test(line)) {
            offenders.push(`${f}:${i + 1}`);
        }
    });
}
check(offenders.length === 0,
    'ни один страничный скрипт не объявляет esc/escapeHtml/escHtmlAttr/authEscape/API_BASE',
    offenders.join(', '));

console.log('common.js: страничные скрипты не содержат собственных реализаций экранирования');
// Старая DOM-реализация была такой:
//   const div = document.createElement('div');
//   div.textContent = String(text);   // или без String()
//   return div.innerHTML;
// Она не экранировала «"», что опасно для подстановки в атрибут data-cid="...".
// Ищем именно эту форму (textContent -> innerHTML), а не любое createElement('div').
const domImpl = [];
for (const f of files) {
    const src = fs.readFileSync(path.join(JS_DIR, f), 'utf8');
    if (/\.textContent\s*=\s*String\([^;]*\);?[\s\S]{0,120}?return\s+[\w.]*\.innerHTML/.test(src)) {
        domImpl.push(f);
    }
}
check(domImpl.length === 0, 'DOM-реализация escapeHtml убрана', domImpl.join(', '));

console.log('dashboard.js: инициализация доходит до API-запросов');

const DASHBOARD = path.join(JS_DIR, 'dashboard.js');
const dashSrc = fs.readFileSync(DASHBOARD, 'utf8');

check(/\b(?:let|const|var)\s+widgetConfigs\b/.test(dashSrc),
    'dashboard.js объявляет widgetConfigs');
check(/\b(?:let|const|var)\s+openConfigWidget\b/.test(dashSrc),
    'dashboard.js объявляет openConfigWidget');
['createWidget', 'openAddPanel', 'duplicateWidget', 'moveWidget', 'inferWidgetType'].forEach((name) => {
    check(new RegExp(`function ${name}\\b`).test(dashSrc), `dashboard.js объявляет ${name}`);
});
check(/dash-editor-backdrop/.test(dashSrc), 'редактор использует backdrop');
check(/if \(c\.type === 'line-chart'\) return;/.test(dashSrc),
    'line-графики не перерисовываются на каждом overview');
check(/\b(?:const|let|var|function)\s+shadeColor\b/.test(dashSrc),
    'dashboard.js объявляет shadeColor');
check(!/shadeColor\s*\?/.test(dashSrc),
    'renderGenericStat не проверяет shadeColor через тернарник');

console.log('dashboard.js: live-обновления и защита от регрессий');

check(/path\.slice\('\/summary'\.length\)/.test(dashSrc),
    'fetchJson сохраняет query string для /summary');
check(dashSrc.includes('fetchJson(`/summary?action=network_per_node'),
    'сетевой график запрашивает /summary с action');
check(/getElementById\('dash-refresh'\)\?\.addEventListener\('click'/.test(dashSrc),
    'кнопка dash-refresh привязана к обработчику');
check(/return bOff - aOff;/.test(dashSrc),
    'ноды сортируются online-first');
check(/const parseApiDate =/.test(dashSrc),
    'есть безопасный parseApiDate для MySQL datetime');
check(/payload\?\.net_nodes/.test(dashSrc),
    'SSE charts применяет net_nodes');
check(/const LIVE_WINDOW_SECONDS = 3600;/.test(dashSrc),
    'живой график показывает окно в один час');
check(!/RANGE_SECONDS|layout\.range|dash-ranges/.test(dashSrc),
    'фиксированные диапазоны и кнопки убраны из дашборда');
check(/function bindChartPan\(canvas, chart\)/.test(dashSrc),
    'графики привязывают перетаскивание по времени');
check(/function mergeChartPoints\(base, incoming/.test(dashSrc),
    'точки графиков объединяются в общий кэш');
check(/from=\$\{win\.from\}&to=\$\{win\.to\}/.test(dashSrc),
    'история графиков запрашивается по окну from/to');
check(/setInterval\(refreshNetNodesChart, 20000\)/.test(dashSrc),
    'fallback-поллинг обновляет сетевой график');

function makeEl() {
    return {
        dataset: {}, style: {}, hidden: false, draggable: false,
        innerHTML: '', textContent: '',
        _listeners: {},
        classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
        addEventListener(name, cb) { (this._listeners[name] = this._listeners[name] || []).push(cb); },
        removeEventListener() {}, appendChild() {}, setAttribute() {},
        getAttribute() { return null; }, querySelector() { return null; },
        querySelectorAll() { return []; }, closest() { return null; },
        getBoundingClientRect() { return { left: 0, top: 0, width: 0, height: 0 }; },
        getContext() { return {}; },
        remove() {},
    };
}

function runDashboard(src) {
    const fetchCalls = [];
    const listeners = {};
    const byId = {};
    const sandbox = {
        console,
        structuredClone: (value) => JSON.parse(JSON.stringify(value)),
        requestAnimationFrame: (cb) => cb(),
        setTimeout: () => 0,
        setInterval: () => 0,
        clearInterval: () => {},
        localStorage: {
            _data: {},
            getItem(key) {
                return Object.prototype.hasOwnProperty.call(this._data, key) ? this._data[key] : null;
            },
            setItem(key, value) { this._data[key] = String(value); },
        },
        fetch: async (url) => {
            fetchCalls.push(String(url));
            return { ok: true, status: 200, text: async () => '{}' };
        },
        document: {
            body: makeEl(),
            getElementById: (id) => (byId[id] = byId[id] || makeEl()),
            querySelector: () => null,
            querySelectorAll: () => [],
            createElement: () => makeEl(),
            addEventListener: (name, cb) => { (listeners[name] = listeners[name] || []).push(cb); },
        },
        EventSource: undefined,
        Chart: undefined,
        lucide: undefined,
    };
    sandbox.window = sandbox;
    sandbox.window.addEventListener = () => {};
    let thrown = null;
    vm.createContext(sandbox);
    try {
        vm.runInContext(fs.readFileSync(COMMON, 'utf8'), sandbox, { filename: 'common.js' });
        vm.runInContext(src, sandbox, { filename: 'dashboard.js' });
        (listeners.DOMContentLoaded || []).forEach((cb) => cb());
    } catch (err) {
        thrown = err;
    }
    return { thrown, fetchCalls, byId, sandbox };
}

const initRun = runDashboard(dashSrc);
check(!initRun.thrown, 'DOMContentLoaded проходит без исключения',
    initRun.thrown && initRun.thrown.message);
check(initRun.fetchCalls.length >= 2, 'после init уходят запросы summary/metrics',
    JSON.stringify(initRun.fetchCalls));
eq(initRun.fetchCalls[0], '/api/summary.php', 'первый запрос — /api/summary.php');

const brokenSrc = dashSrc.replace(/\nlet widgetConfigs = loadWidgetConfigs\(\);/, '\n');
const brokenRun = runDashboard(brokenSrc);
check(!!brokenRun.thrown || brokenRun.fetchCalls.length === 0,
    'без объявления widgetConfigs init не доходит до API (ловит регрессию 97978ff)',
    `thrown=${brokenRun.thrown && brokenRun.thrown.message}, fetch=${JSON.stringify(brokenRun.fetchCalls)}`);

console.log('dashboard.js: клик по дашборду не падает');

function clickDashboard(run) {
    const el = run.byId && run.byId.dashboard;
    const handlers = (el && el._listeners.click) || [];
    let error = null;
    handlers.forEach((cb) => {
        try {
            cb({ target: { closest: () => null }, preventDefault() {}, stopPropagation() {} });
        } catch (err) {
            error = error || err;
        }
    });
    return { error, count: handlers.length };
}

const clickRun = runDashboard(dashSrc);
const clickResult = clickDashboard(clickRun);
check(clickResult.count > 0, 'на #dashboard навешен обработчик click');
check(!clickResult.error, 'клик по дашборду не падает (openConfigWidget объявлен)',
    clickResult.error && clickResult.error.message);

const brokenClickSrc = dashSrc.replace(/\nlet openConfigWidget = null;/, '');
const brokenClick = clickDashboard(runDashboard(brokenClickSrc));
check(!!brokenClick.error, 'без объявления openConfigWidget клик падает (ловит регрессию)',
    'click не бросил исключение');

console.log('dashboard.js: дефолтная метрика плашки и пороги');

const configRun = runDashboard(dashSrc);
check(!configRun.thrown, 'контекст для проверки конфигуратора создан',
    configRun.thrown && configRun.thrown.message);
const cfgSandbox = configRun.sandbox;
const cfgEval = (expr) => vm.runInContext(expr, cfgSandbox);

cfgSandbox.ramEl = { dataset: { widget: 'stat-ram', title: 'RAM' }, querySelector: () => null };
eq(cfgEval("defaultConfigForWidget('stat-ram', ramEl).series[0].metric"), 'ram',
    'RAM-плашка открывается с метрикой ram');
cfgSandbox.diskEl = { dataset: { widget: 'stat-disk', title: 'Диск' }, querySelector: () => null };
eq(cfgEval("defaultConfigForWidget('stat-disk', diskEl).series[0].metric"), 'disk',
    'Диск-плашка открывается с метрикой disk');
cfgSandbox.gpuEl = { dataset: { widget: 'stat-gpu', title: 'GPU' }, querySelector: () => null };
eq(cfgEval("defaultConfigForWidget('stat-gpu', gpuEl).series[0].metric"), 'gpu',
    'GPU-плашка открывается с метрикой gpu');
cfgSandbox.chartEl = { dataset: { widget: 'chart-res', title: 'Ресурсы' }, querySelector: () => null };
eq(cfgEval("defaultConfigForWidget('chart-res', chartEl).series.map((s) => s.metric).join(',')"),
    'cpu,ram,disk', 'график ресурсов сохраняет три серии');

cfgSandbox.__oldPoints = [
    { ts: '2026-10-23 10:00:00', cpu: 1 },
    { ts: '2026-10-23 10:01:00', cpu: 2 },
];
cfgSandbox.__newPoints = [
    { ts: '2026-10-23 10:01:00', cpu: 3 },
    { ts: '2026-10-23 10:02:00', cpu: 4 },
];
eq(cfgEval('mergeChartPoints(__oldPoints, __newPoints).map((p) => p.cpu).join(",")'),
    '1,3,4', 'кэш графика заменяет точку с тем же временем и сохраняет порядок');
cfgSandbox.__replaceFrom = cfgEval("new Date('2026-10-23T10:01:00').getTime()");
eq(cfgEval('mergeChartPoints(__oldPoints, __newPoints, __replaceFrom).map((p) => p.cpu).join(",")'),
    '1,3,4', 'SSE заменяет только последнее окно кэша');

cfgSandbox.__viewPoints = [
    { ts: '2026-10-23 09:59:00', cpu: 0 },
    { ts: '2026-10-23 10:00:00', cpu: 1 },
    { ts: '2026-10-23 10:05:00', cpu: 5 },
];
eq(cfgEval("chartView = { from: new Date('2026-10-23T10:00:00').getTime() / 1000, to: new Date('2026-10-23T10:05:00').getTime() / 1000 }; chartCache = __viewPoints; chartPointsInView().map((p) => p.cpu).join(',')"),
    '1,5', 'панорама показывает только точки видимого окна');

eq(cfgEval('toneForPct(80, { warn: 85, bad: 95 })'), 'ok', 'значение ниже warn — ok');
eq(cfgEval('toneForPct(86, { warn: 85, bad: 95 })'), 'warn', 'значение на warn — warn');
eq(cfgEval('toneForPct(95, { warn: 85, bad: 95 })'), 'bad', 'значение на bad — bad');
eq(cfgEval('toneForPct(80)'), 'warn', 'без порогов сохраняется прежнее поведение 75/90');
eq(cfgEval("thresholdsFor('stat-load', 'load').warn"), 3, 'порог load по умолчанию 3');
eq(cfgEval("thresholdsFor('stat-alerts', 'alerts').bad"), 5, 'порог alerts по умолчанию 5');
eq(cfgEval("normalizeThresholds({ warn: '10', bad: '5' }, { warn: 3, bad: 3.6, unit: '' }).bad"), 10.1,
    'критичный порог поднимается выше предупреждения');
eq(cfgEval("normalizeThresholds({ warn: '', bad: '' }, { warn: 75, bad: 90, unit: '%' }).warn"), 75,
    'пустые поля возвращают значение по умолчанию');

cfgEval("layout.thresholds['stat-cpu'] = { warn: 50, bad: 70 }");
eq(cfgEval("thresholdsFor('stat-cpu', 'cpu').warn"), 50, 'сохранённый порог читается для плашки');

cfgEval("layout.thresholds['stat-cpu'] = { warn: 95, bad: 99 }");
cfgEval("updateStats({ nodes_online: 1, nodes_total: 1, alerts_count: 0, cpu_avg: 90, cpu_max: 90, ram_avg: 10, ram_max: 10, disk_avg: 10, disk_max: 10, load_avg: 1, swap_max: 0, gpu_count: 0 })");
eq(configRun.byId['stat-cpu'].dataset.tone, 'ok', '90% CPU остаётся ok при пороге 95/99');
cfgEval("updateStats({ nodes_online: 1, nodes_total: 1, alerts_count: 0, cpu_avg: 96, cpu_max: 96, ram_avg: 10, ram_max: 10, disk_avg: 10, disk_max: 10, load_avg: 1, swap_max: 0, gpu_count: 0 })");
eq(configRun.byId['stat-cpu'].dataset.tone, 'warn', '96% CPU даёт warn при пороге 95/99');
cfgEval("updateStats({ nodes_online: 1, nodes_total: 1, alerts_count: 0, cpu_avg: 99, cpu_max: 99, ram_avg: 10, ram_max: 10, disk_avg: 10, disk_max: 10, load_avg: 1, swap_max: 0, gpu_count: 0 })");
eq(configRun.byId['stat-cpu'].dataset.tone, 'bad', '99% CPU даёт bad при пороге 95/99');

check(/defaultConfigForWidget\(widgetId, el\)/.test(dashSrc),
    'openConfigPanel берёт дефолт конкретной плашки');
check(/keepStandard/.test(dashSrc),
    'применение только порогов не превращает стандартную плашку в generic');
check(/dash-config-thresholds/.test(dashSrc),
    'в конфигураторе есть поля порогов');

console.log(`\nИтог: passed=${passed} failed=${failed}`);
process.exit(failed > 0 ? 1 : 0);
