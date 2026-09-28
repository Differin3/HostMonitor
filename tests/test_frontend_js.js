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

console.log(`\nИтог: passed=${passed} failed=${failed}`);
process.exit(failed > 0 ? 1 : 0);
