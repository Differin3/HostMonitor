/**
 * Тесты выгрузки данных со страницы SMART: frontend/js/smart.js.
 *
 * Проверяем, что новый экспорт действительно формирует файл:
 *  - CSV списка дисков (разделитель «;», BOM, заголовок, строка диска);
 *  - JSON с дисками и атрибутами;
 *  - экспорт атрибутов/истории без выбранного диска отказывает;
 *  - пункты меню выключены, пока диск не выбран.
 *
 * smart.js — IIFE без экспортов, поэтому поднимаем минимальную песочницу
 * (fetch, DOM, Blob/URL) и дёргаем публичный window.smartExport.
 *
 * Запуск:  node tests/test_smart_export_js.js
 * Выход:   0 — все проверки прошли, 1 — есть падения.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const JS_DIR = path.join(__dirname, '..', 'frontend', 'js');
const COMMON = path.join(JS_DIR, 'common.js');
const SCRIPT = path.join(JS_DIR, 'smart.js');

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

const tick = () => new Promise((r) => setImmediate(r));

function makeElement(id) {
    const el = {
        id: id || '',
        value: '',
        textContent: '',
        disabled: false,
        title: '',
        dataset: {},
        download: '',
        _html: '',
        classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
        appendChild() {},
        removeChild() {},
        click() { this._clicked = true; },
        addEventListener(type, fn) { (this._h = this._h || {})[type] = fn; },
        getContext() { return {}; },
        querySelectorAll() { return []; },
        style: {},
    };
    Object.defineProperty(el, 'innerHTML', {
        get() { return this._html; },
        set(v) { this._html = String(v); },
    });
    return el;
}

function harness(drives, nodes) {
    const els = {};
    const getEl = (id) => (els[id] = els[id] || makeElement(id));

    const toasts = [];
    const downloads = [];
    let lastBlob = null;

    const listeners = {};
    const sandbox = {
        console,
        JSON, Array, Object, String, Number, Math, Date, parseInt, isNaN, encodeURIComponent,
        setInterval: () => 0,
        Blob: function (parts, opts) {
            this.parts = parts;
            this.content = parts.join('');
            this.type = (opts && opts.type) || '';
        },
        URL: {
            createObjectURL(blob) { lastBlob = blob; return 'blob:mock'; },
            revokeObjectURL() {},
        },
        document: {
            getElementById: getEl,
            createElement: (tag) => {
                const el = makeElement(tag);
                if (tag === 'a') {
                    el.click = () => downloads.push({ name: el.download, content: lastBlob ? lastBlob.content : '' });
                }
                return el;
            },
            body: makeElement('body'),
            addEventListener: (type, fn) => { listeners[type] = fn; },
        },
        window: {
            showToast: (msg, type) => toasts.push({ msg, type }),
        },
        fetch: async (url) => {
            let data = {};
            if (url.includes('smart.php')) data = { drives };
            else if (url.includes('nodes.php')) data = { nodes };
            return { ok: true, status: 200, text: async () => JSON.stringify(data) };
        },
    };
    sandbox.window.window = sandbox.window;
    sandbox.globalThis = sandbox;

    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(COMMON, 'utf8'), sandbox, { filename: 'common.js' });
    vm.runInContext(fs.readFileSync(SCRIPT, 'utf8'), sandbox, { filename: 'smart.js' });
    if (listeners.DOMContentLoaded) listeners.DOMContentLoaded();

    return { els, toasts, downloads, sandbox, win: sandbox.window };
}

const DRIVES = [
    {
        node_id: 7, device_name: 'sda', model: 'WDC WD10EZEX', serial_number: 'SN123',
        firmware_version: '01.01A01', capacity_bytes: 1000204886016, rotation_rate: 7200,
        interface_type: 'SATA', sata_version: '3.0', temperature: 42, power_on_hours: 30000,
        health_status: 'ok', bay_number: 1, last_seen: '2026-10-15 09:00:00',
        attributes: [
            { id: 5, name: 'Reallocated_Sector_Ct', value: 100, worst: 100, threshold: 10, raw: 0, flags: '0x0033' },
            { id: 194, name: 'Temperature_Celsius', value: 58, worst: 40, threshold: 0, raw: 42, flags: '0x0022' },
        ],
    },
    {
        node_id: 7, device_name: 'sdd', model: 'SAMSUNG HD103SJ', serial_number: 'SN999',
        health_status: 'warning', temperature: 30, power_on_hours: 20000, capacity_bytes: 1000204886016,
        attributes: [],
    },
];

async function testDrivesCsv() {
    console.log('smart.js: CSV списка дисков');
    const h = harness(DRIVES, [{ id: 7, name: 'Proxmox' }]);
    await tick(); await tick(); await tick();

    check(typeof h.win.smartExport === 'function', 'smartExport доступен на window');

    await h.win.smartExport('drives-csv');
    check(h.downloads.length === 1, 'файл сформирован', `получено ${h.downloads.length}`);
    const dl = h.downloads[0];
    check(/^smart-drives-\d{8}-\d{4}\.csv$/.test(dl.name), 'имя файла с датой', dl.name);
    check(dl.content.charCodeAt(0) === 0xFEFF, 'BOM в начале (для Excel)');
    const lines = dl.content.replace(/^\uFEFF/, '').split('\r\n');
    check(lines[0].includes('Устройство') && lines[0].split(';').length === 14, 'шапка с 14 колонками через «;»');
    check(lines[1].startsWith('Proxmox;sda;'), 'первая строка — диск с именем ноды', lines[1]);
    check(lines[1].includes('42'), 'температура попала');
    check(lines[2].includes('warning'), 'статус второй строки');

    // Фильтр по health сужает выгрузку.
    h.els.healthFilter.value = 'warning';
    await h.win.smartExport('drives-csv');
    const filtered = h.downloads[1].content.replace(/^\uFEFF/, '').split('\r\n');
    check(filtered.length === 2, 'фильтр «warning» оставил только один диск', `строк: ${filtered.length}`);
    check(filtered[1].includes('sdd'), 'остался проблемный диск');
}

async function testDrivesJson() {
    console.log('\nsmart.js: JSON с дисками и атрибутами');
    const h = harness(DRIVES, [{ id: 7, name: 'Proxmox' }]);
    await tick(); await tick(); await tick();

    await h.win.smartExport('drives-json');
    const dl = h.downloads[0];
    check(/\.json$/.test(dl.name), 'имя файла .json', dl.name);
    const data = JSON.parse(dl.content);
    check(data.count === 2, 'count = 2', String(data.count));
    check(data.drives[0].node_name === 'Proxmox', 'подставлено имя ноды');
    check(data.drives[0].capacity_bytes === 1000204886016, 'ёмкость числом');
    check(Array.isArray(data.drives[0].attributes) && data.drives[0].attributes.length === 2,
        'атрибуты включены', JSON.stringify(data.drives[0].attributes));
}

async function testGuardWithoutSelection() {
    console.log('\nsmart.js: экспорт без выбранного диска отказывает');
    const h = harness(DRIVES, [{ id: 7, name: 'Proxmox' }]);
    await tick(); await tick(); await tick();

    check(h.els.smartExportAttrsBtn.disabled === true, 'пункт «Атрибуты» выключен без выбора');
    check(h.els.smartExportHistoryBtn.disabled === true, 'пункт «История» выключен без выбора');

    await h.win.smartExport('attrs-csv');
    check(h.downloads.length === 0, 'файл не создан');
    check(h.toasts.some((t) => /выберите диск/i.test(t.msg)), 'показано предупреждение', JSON.stringify(h.toasts));
}

(async () => {
    await testDrivesCsv();
    await testDrivesJson();
    await testGuardWithoutSelection();
    console.log(`\nИтог: passed=${passed} failed=${failed}`);
    process.exit(failed > 0 ? 1 : 0);
})();
