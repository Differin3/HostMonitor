/**
 * Тесты разбора результата сохранения настроек БД: dbHaSaveProblems()
 * из frontend/js/settings.js.
 *
 * Закрывает регрессию из реальной установки. Реквизиты резерва были введены
 * неверно (пароль попал в поле пользователя, поле пароля оставили пустым),
 * и панель отрапортовала зелёным тостом «Подключения к БД сохранены».
 * Настоящая ошибка доступа всплыла только при следующей загрузке страницы —
 * админ уже считал, что всё настроил.
 *
 * Сервер возвращает результат проверки обеих баз в data.ping; разбор этого
 * результата — единственное, что отличает «сохранилось и работает» от
 * «сохранилось, но не подключается».
 *
 * Запуск:  node tests/test_db_ha_js.js
 * Выход:   0 — все проверки прошли, 1 — есть падения.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SCRIPT = path.join(__dirname, '..', 'frontend', 'js', 'settings.js');

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

/**
 * Вырезает тело функции по имени. Файл большой и при загрузке требует
 * готовый DOM, поэтому целиком в vm не запускается — забираем только
 * нужную функцию, у неё нет внешних зависимостей.
 */
function extractFunction(name) {
    const src = fs.readFileSync(SCRIPT, 'utf8');
    const start = src.indexOf(`function ${name}(`);
    if (start === -1) throw new Error(`функция ${name} не найдена`);
    const bodyStart = src.indexOf('{', start);
    let depth = 0;
    for (let i = bodyStart; i < src.length; i++) {
        if (src[i] === '{') depth++;
        if (src[i] === '}') {
            depth--;
            if (depth === 0) return src.slice(start, i + 1);
        }
    }
    throw new Error(`не удалось найти конец функции ${name}`);
}

const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(extractFunction('dbHaSaveProblems'), sandbox);
const dbHaSaveProblems = sandbox.dbHaSaveProblems;

check(typeof dbHaSaveProblems === 'function', 'dbHaSaveProblems выгружается и вызывается');

const problems = (data) => dbHaSaveProblems(data);

// Всё работает — претензий нет.
check(
    problems({ replica_enabled: true, ping: { primary: { ok: true }, replica: { ok: true } } }).length === 0,
    'обе базы доступны — претензий нет'
);

// Реальный случай: резерв недоступен, основная в порядке.
const onlyReplica = problems({
    replica_enabled: true,
    ping: {
        primary: { ok: true, ms: 4 },
        replica: { ok: false, error: "SQLSTATE[HY000] [1045] Access denied" },
    },
});
check(onlyReplica.length === 1, 'недоступный резерв — одна претензия', JSON.stringify(onlyReplica));
check(
    onlyReplica[0] && onlyReplica[0].includes('Access denied'),
    'в претензии попадает исходная ошибка MySQL',
    JSON.stringify(onlyReplica)
);
check(
    onlyReplica[0] && onlyReplica[0].startsWith('резерв:'),
    'претензия указывает, что именно сломано',
    JSON.stringify(onlyReplica)
);

// Выключенный резерв не считается проблемой, даже если ping его не трогал.
check(
    problems({ replica_enabled: false, ping: { primary: { ok: true }, replica: { ok: false, error: 'Резерв выключен' } } }).length === 0,
    'выключенный резерв не считается претензией'
);

// Упавшая основная — тоже повод для ошибки, а не тихого успеха.
const both = problems({
    replica_enabled: true,
    ping: {
        primary: { ok: false, error: 'нет ответа' },
        replica: { ok: false, error: 'нет ответа' },
    },
});
check(both.length === 2, 'упали обе базы — две претензии', JSON.stringify(both));
check(
    both[0] && both[0].startsWith('основная:'),
    'различаются основная и резерв',
    JSON.stringify(both)
);

// Ответ без ping (старая версия API) не должен ронять разбор.
check(problems({ replica_enabled: true }).length === 0, 'ответ без ping не ломает разбор');
check(problems({}).length === 0, 'пустой ответ не ломает разбор');
check(problems(null).length === 0, 'null не ломает разбор');
check(Array.isArray(problems(null)), 'всегда возвращается массив');

// ok === undefined не считается провалом: иначе любое неполное поле
// превратилось бы в ошибку.
check(
    problems({ replica_enabled: true, ping: { primary: {}, replica: {} } }).length === 0,
    'неизвестное состояние не считается отказом'
);

console.log(`\nИтог: passed=${passed} failed=${failed}`);
process.exit(failed > 0 ? 1 : 0);
