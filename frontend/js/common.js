/**
 * Общие утилиты frontend'а HostMonitor.
 *
 * Подключается в layout.php (render_layout_start) ДО любых страничных
 * скриптов, поэтому esc()/escapeHtml() доступны всем — в том числе
 * страницам, у которых нет собственной копии.
 *
 * Раньше эти функции были продублированы в 19 местах по 15 файлам, причём
 * в двух несовместимых вариантах: regex-реализация экранировала & < > ",
 * а вариант через DOM innerHTML кавычку не экранировал вовсе — при том что
 * containers.js подставляет результат в атрибут data-cid="...". Канонической
 * выбрана regex-реализация (она строже и совпадает с большинством файлов).
 *
 * ВАЖНО: не объявляйте здесь top-level const/function — всё вешается на
 * window, иначе страничные скрипты получат SyntaxError на дубликате.
 */
(function (global) {
    'use strict';

    /**
     * Экранирование для вставки в HTML (текстовый узел или значение атрибута).
     * Экранирует & < > " — достаточно для node.innerHTML и для атрибутов
     * в двойных кавычках, которыми пользуется панель.
     */
    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Исторические имена. В коде панели встречаются все четыре; держать их
    // алиасами дешевле, чем переименовывать сотни мест вызова.
    global.esc = esc;
    global.escapeHtml = esc;
    global.escHtmlAttr = esc;
    global.authEscape = esc;

    // База API. layout.php выставляет window.MONITORING_API_BASE через
    // monitoring_asset(), поэтому за ре-reverse-proxy путь остаётся верным.
    global.API_BASE = global.MONITORING_API_BASE || '/api';
})(typeof window !== 'undefined' ? window : globalThis);
