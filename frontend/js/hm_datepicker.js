(function () {
    'use strict';

    const MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
    const WEEKDAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    const VALUE_RE = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/;

    const pad = (n) => String(n).padStart(2, '0');

    function parseValue(value) {
        const m = VALUE_RE.exec(value || '');
        return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) : null;
    }

    function formatValue(d) {
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    function sameDay(a, b) {
        return !!a && !!b && a.getFullYear() === b.getFullYear()
            && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function el(tag, cls, text) {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text != null) node.textContent = text;
        return node;
    }

    function attach(input) {
        if (!input || input.dataset.hmDpBound) return;
        input.dataset.hmDpBound = '1';
        input.classList.add('hm-dp-input');

        let popup = null;
        let pending = null;
        let view = null;
        let opened = false;

        function onDocDown(e) {
            if (popup && !popup.contains(e.target) && e.target !== input) close();
        }

        function onKey(e) {
            if (e.key === 'Escape') close();
        }

        function commit() {
            const val = pending ? formatValue(pending) : '';
            input.value = val;
            // Синхронизируем с текстовым инпутом
            const textInput = input.closest('.metrics-daterange-field')?.querySelector('.hm-dp-input[readonly]');
            if (textInput) {
                textInput.textContent = val;
            }
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function place() {
            if (!popup) return;
            const r = input.getBoundingClientRect();
            const pw = popup.offsetWidth || 268;
            const ph = popup.offsetHeight || 330;
            const left = Math.min(Math.max(8, r.left), Math.max(8, window.innerWidth - pw - 8));
            let top = r.bottom + 6;
            if (top + ph > window.innerHeight - 8) {
                const up = r.top - ph - 6;
                top = up >= 8 ? up : Math.max(8, window.innerHeight - ph - 8);
            }
            popup.style.left = `${Math.round(left)}px`;
            popup.style.top = `${Math.round(top)}px`;
        }

        function close() {
            if (!opened || !popup) return;
            opened = false;
            popup.classList.remove('is-open');
            document.removeEventListener('mousedown', onDocDown, true);
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
        }

        function open() {
            if (opened) return;
            if (!popup) popup = build();
            const current = parseValue(input.value);
            pending = current || new Date();
            view = new Date(pending.getFullYear(), pending.getMonth(), 1);
            renderDays();
            popup.classList.add('is-open');
            place();
            opened = true;
            document.addEventListener('mousedown', onDocDown, true);
            document.addEventListener('keydown', onKey);
            window.addEventListener('resize', place);
            window.addEventListener('scroll', place, true);
        }

        function syncControls() {
            const hours = pending ? pending.getHours() : 0;
            const mins = pending ? pending.getMinutes() : 0;
            const hoursSel = popup.querySelector('.hm-dp-hours');
            const minsSel = popup.querySelector('.hm-dp-minutes');
            if (!Array.prototype.some.call(minsSel.options, (o) => +o.value === mins)) {
                minsSel.appendChild(new Option(pad(mins), String(mins)));
            }
            hoursSel.value = String(hours);
            minsSel.value = String(mins);
        }

        function renderDays() {
            if (!popup) return;
            const year = view.getFullYear();
            const month = view.getMonth();
            const firstIdx = (new Date(year, month, 1).getDay() + 6) % 7;
            const today = new Date();
            popup.querySelector('.hm-dp-title').textContent = `${MONTHS[month]} ${year}`;
            const grid = popup.querySelector('.hm-dp-days');
            grid.textContent = '';
            for (let i = 0; i < 42; i++) {
                const day = new Date(year, month, i - firstIdx + 1);
                const btn = el('button', 'hm-dp-day', String(day.getDate()));
                btn.type = 'button';
                if (day.getMonth() !== month) btn.classList.add('is-out');
                if (sameDay(day, today)) btn.classList.add('is-today');
                if (sameDay(day, pending)) btn.classList.add('is-sel');
                btn.addEventListener('click', () => {
                    const base = pending || new Date();
                    pending = new Date(day.getFullYear(), day.getMonth(), day.getDate(), base.getHours(), base.getMinutes());
                    commit();
                    renderDays();
                });
                grid.appendChild(btn);
            }
            syncControls();
        }

        function build() {
            const root = el('div', 'hm-dp');
            root.setAttribute('role', 'dialog');
            root.setAttribute('aria-label', 'Выбор даты и времени');

            const head = el('div', 'hm-dp-head');
            const prev = el('button', 'hm-dp-nav', '‹');
            const next = el('button', 'hm-dp-nav', '›');
            prev.type = 'button';
            next.type = 'button';
            prev.setAttribute('aria-label', 'Предыдущий месяц');
            next.setAttribute('aria-label', 'Следующий месяц');
            head.append(prev, el('div', 'hm-dp-title'), next);
            prev.addEventListener('click', () => {
                view = new Date(view.getFullYear(), view.getMonth() - 1, 1);
                renderDays();
            });
            next.addEventListener('click', () => {
                view = new Date(view.getFullYear(), view.getMonth() + 1, 1);
                renderDays();
            });

            const week = el('div', 'hm-dp-week');
            WEEKDAYS.forEach((w) => week.appendChild(el('span', null, w)));
            const days = el('div', 'hm-dp-days');

            const time = el('div', 'hm-dp-time');
            const hours = el('select', 'hm-dp-hours');
            hours.setAttribute('aria-label', 'Часы');
            for (let h = 0; h < 24; h++) hours.appendChild(new Option(pad(h), String(h)));
            const minutes = el('select', 'hm-dp-minutes');
            minutes.setAttribute('aria-label', 'Минуты');
            for (let m = 0; m < 60; m += 5) minutes.appendChild(new Option(pad(m), String(m)));
            const applyTime = () => {
                const base = pending || new Date();
                pending = new Date(base.getFullYear(), base.getMonth(), base.getDate(), +hours.value, +minutes.value);
                commit();
            };
            hours.addEventListener('change', applyTime);
            minutes.addEventListener('change', applyTime);
            time.append(hours, el('span', 'hm-dp-lbl', 'ч'), minutes, el('span', 'hm-dp-lbl', 'мин'));

            const todayBtn = el('button', 'hm-dp-link', 'Сегодня');
            const clearBtn = el('button', 'hm-dp-link hm-dp-clear', 'Сбросить');
            const doneBtn = el('button', 'hm-dp-done', 'Готово');
            [todayBtn, clearBtn, doneBtn].forEach((b) => { b.type = 'button'; });

            todayBtn.addEventListener('click', () => {
                const now = new Date();
                const base = pending || now;
                pending = new Date(now.getFullYear(), now.getMonth(), now.getDate(), base.getHours(), base.getMinutes());
                commit();
                renderDays();
            });
            clearBtn.addEventListener('click', () => {
                pending = null;
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                close();
            });
            doneBtn.addEventListener('click', () => {
                commit();
                close();
            });

            const footLeft = el('div', 'hm-dp-foot-left');
            footLeft.append(todayBtn);
            const footRight = el('div', 'hm-dp-foot-right');
            footRight.append(clearBtn, doneBtn);
            const foot = el('div', 'hm-dp-foot');
            foot.append(footLeft, footRight);

            root.append(head, week, days, time, foot);
            document.body.appendChild(root);
            return root;
        }

        input.addEventListener('focus', open);
        input.addEventListener('click', open);
    }

    // Инициализация для всех hm-dp-input элементов
    document.querySelectorAll('.hm-dp-input').forEach(attach);

    window.HmDatePicker = { attach };
})();
