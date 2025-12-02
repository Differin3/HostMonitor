// JavaScript для страницы статистики нод
const API_BASE = window.MONITORING_API_BASE || '/api'; // базовый URL API
const API_URL = `${API_BASE}/nodes.php`; // эндпоинт статистики нод
const METRICS_URL = `${API_BASE}/metrics.php`; // эндпоинт истории метрик

let nodesCpuChart = null; // график CPU по нодам
let nodesUsageChart = null; // график RAM/диск
let historyCpuChart = null; // исторический график CPU
let historyRamChart = null; // исторический график RAM
let historyDiskChart = null; // исторический график диска
let historyNetChart = null; // исторический график сети
let cachedNodes = []; // кеш нод для фильтра истории
let historyCalendarState = { monthCursor: new Date(), from: null, to: null }; // состояние кастомного календаря

const loadStats = async (silent = false) => { // загрузка агрегированной статистики, таблицы и графиков
    try {
        if (!silent && window.toggleTableLoader) window.toggleTableLoader('stats-tbody', true); // показываем лоадер только при ручной загрузке
        const res = await fetch(API_URL, { credentials: 'include' }); // запрос к API
        if (!res.ok) throw new Error(`HTTP ${res.status}`); // проверка статуса
        const text = await res.text(); // получаем текст ответа
        const data = text ? JSON.parse(text) : {}; // парсим JSON безопасно
        const nodes = data?.nodes ?? data?.data ?? []; // массив нод
        cachedNodes = nodes; // сохраняем ноды для фильтров
        const total = nodes.length; // всего нод
        const online = nodes.filter(n => n.status === 'online').length; // онлайн ноды
        const offline = total - online; // оффлайн ноды
        const avgCpu = nodes.length > 0 // средняя загрузка CPU
            ? Math.round(nodes.reduce((sum, n) => sum + (parseFloat(n.cpu_usage) || 0), 0) / nodes.length)
            : 0;
        document.getElementById('total-nodes').textContent = total; // выводим всего
        document.getElementById('online-nodes').textContent = online; // выводим онлайн
        document.getElementById('offline-nodes').textContent = offline; // выводим оффлайн
        document.getElementById('avg-load').textContent = avgCpu + '%'; // выводим средний CPU
        renderStatsTable(nodes); // рендерим таблицу
        renderCpuChart(nodes); // рендерим график CPU
        renderUsageChart(nodes); // рендерим график RAM/диск
    } catch (error) {
        console.error('Ошибка загрузки статистики:', error); // лог ошибки
        document.getElementById('total-nodes').textContent = '0'; // сброс значений
        document.getElementById('online-nodes').textContent = '0';
        document.getElementById('offline-nodes').textContent = '0';
        document.getElementById('avg-load').textContent = '0%';
        renderStatsTable([]); // очищаем таблицу
    } finally {
        if (!silent && window.toggleTableLoader) window.toggleTableLoader('stats-tbody', false); // скрываем лоадер
    }
};

const renderStatsTable = (nodes) => { // рендер таблицы по нодам
    const tbody = document.getElementById('stats-tbody'); // тело таблицы
    if (!tbody) return; // выходим если таблицы нет
    
    // Оптимизация: обновляем только измененные строки
    const existingRows = tbody.querySelectorAll('tr[data-node-id]');
    const existingIds = new Set(Array.from(existingRows).map(tr => tr.dataset.nodeId));
    const newIds = new Set(nodes.map(n => String(n.id)));
    
    // Удаляем строки, которых больше нет
    existingRows.forEach(tr => {
        if (!newIds.has(tr.dataset.nodeId)) {
            tr.remove();
        }
    });
    
    if (nodes.length === 0) { // если данных нет
        if (tbody.children.length === 0 || !tbody.querySelector('.text-center')) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center">Нет данных</td></tr>';
        }
        return; // выходим
    }
    
    // Удаляем заглушку "Нет данных" если она есть
    const emptyRow = tbody.querySelector('.text-center');
    if (emptyRow) emptyRow.remove();
    
    nodes.forEach(node => { // проходим по нодам
        const nodeId = String(node.id);
        let tr = tbody.querySelector(`tr[data-node-id="${nodeId}"]`);
        
        if (!tr) {
            // Создаем новую строку только если её нет
            tr = document.createElement('tr');
            tr.dataset.nodeId = nodeId;
            tbody.appendChild(tr);
        }
        
        // Обновляем содержимое строки
        tr.innerHTML = `
            <td>${node.name || '-'}</td>
            <td><span class="status pill ${node.status || 'offline'}">${node.status || 'unknown'}</span></td>
            <td>${node.cpu_usage || 0}%</td>
            <td>${node.memory_usage || 0}%</td>
            <td>${node.disk_usage || 0}%</td>
            <td>${formatBytes(node.network_in || 0)} / ${formatBytes(node.network_out || 0)}</td>
            <td>${node.last_seen ? new Date(node.last_seen).toLocaleString('ru-RU') : '-'}</td>
        `;
    });
    
    if (typeof lucide !== 'undefined') lucide.createIcons(); // обновляем иконки если lucide есть
};

const formatBytes = (bytes) => { // форматирование байтов в читаемый вид
    if (!bytes) return '0 Б'; // если 0 байт
    const k = 1024; // основание
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ']; // единицы
    const i = Math.floor(Math.log(bytes) / Math.log(k)); // индекс единицы
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]; // возвращаем строку
};

const renderCpuChart = (nodes) => { // график CPU по нодам (bar)
    const ctx = document.getElementById('nodes-cpu-chart'); // холст
    if (!ctx || typeof Chart === 'undefined') return; // выходим если нет Chart.js
    const labels = nodes.map(n => n.name || `node-${n.id}`); // имена нод
    const data = nodes.map(n => parseFloat(n.cpu_usage) || 0); // CPU
    if (nodesCpuChart) nodesCpuChart.destroy(); // пересоздаём график
    nodesCpuChart = new Chart(ctx, { // создаём bar chart
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'CPU %',
                data,
                backgroundColor: 'rgba(59,130,246,0.7)',
                borderRadius: 6,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, max: 100 },
            },
        },
    });
};

const renderUsageChart = (nodes) => { // комбинированный график RAM/диск
    const ctx = document.getElementById('nodes-usage-chart'); // холст
    if (!ctx || typeof Chart === 'undefined') return; // нет графика
    const labels = nodes.map(n => n.name || `node-${n.id}`); // имена
    const ram = nodes.map(n => parseFloat(n.memory_usage) || 0); // RAM
    const disk = nodes.map(n => parseFloat(n.disk_usage) || 0); // диск
    if (nodesUsageChart) nodesUsageChart.destroy(); // пересоздаём
    nodesUsageChart = new Chart(ctx, { // создаём stacked bar
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'RAM %',
                    data: ram,
                    backgroundColor: 'rgba(16,185,129,0.7)',
                    borderRadius: 6,
                },
                {
                    label: 'Диск %',
                    data: disk,
                    backgroundColor: 'rgba(234,179,8,0.7)',
                    borderRadius: 6,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { labels: { color: '#e5e7eb', font: { size: 11 } } } },
            scales: {
                x: { stacked: true, ticks: { color: '#9ca3af', font: { size: 10 } } },
                y: { stacked: true, beginAtZero: true, max: 100, ticks: { color: '#9ca3af' } },
            },
        },
    });
};

const buildHistoryQuery = (overrideRange) => { // формируем query истории: нода + интервал + диапазон
    const fromInput = document.getElementById('history-from'); // поле начала
    const toInput = document.getElementById('history-to'); // поле окончания
    const rangeSelect = document.getElementById('history-range'); // селект диапазона
    const nodeSelect = document.getElementById('history-node'); // выбор ноды
    const params = new URLSearchParams(); // параметры

    const fromVal = fromInput?.value; // значение начала
    const toVal = toInput?.value; // значение окончания
    const range = overrideRange || rangeSelect?.value || '1h'; // диапазон (приоритет параметра)
    const nodeId = nodeSelect?.value || ''; // выбранная нода

    if (!nodeId) { // если нода не выбрана — историю не грузим вообще
        return ''; // пустой query отключает запрос
    }
    params.set('node_id', nodeId); // всегда работаем только по одной конкретной ноде

    const rangeToSeconds = (val) => { // маппинг диапазона в секунды
        switch (val) {
            case '6 hours': return 6 * 3600;
            case '12 hours': return 12 * 3600;
            case '1 day': return 24 * 3600;
            case '7 days': return 7 * 24 * 3600;
            case '1h':
            default: return 3600;
        }
    };

    const parseTs = (val) => Math.floor(new Date(val).getTime() / 1000) || 0; // строка -> unix

    const hasFrom = !!fromVal;
    const hasTo = !!toVal;

    if (hasFrom && hasTo) { // заданы обе даты явно
        const fromTs = parseTs(fromVal);
        const toTs = parseTs(toVal);
        if (fromTs > 0 && toTs > 0 && toTs > fromTs) {
            params.set('from', String(fromTs));
            params.set('to', String(toTs));
            return params.toString();
        }
    }

    if (hasFrom && !hasTo) { // только начало — считаем конец как from + range
        const fromTs = parseTs(fromVal);
        if (fromTs > 0) {
            params.set('from', String(fromTs));
            params.set('to', String(fromTs + rangeToSeconds(range)));
            return params.toString();
        }
    }

    if (!hasFrom && hasTo) { // только окончание — считаем начало как to - range
        const toTs = parseTs(toVal);
        if (toTs > 0) {
            params.set('to', String(toTs));
            params.set('from', String(toTs - rangeToSeconds(range)));
            return params.toString();
        }
    }

    // если даты не заданы — считаем их из range (чтобы не зависеть от парсинга строки на бэке)
    const nowSec = Math.floor(Date.now() / 1000); // текущее время в секундах
    const seconds = rangeToSeconds(range); // длительность диапазона в секундах
    params.set('from', String(nowSec - seconds)); // начало = сейчас - диапазон
    params.set('to', String(nowSec)); // конец = сейчас
    return params.toString(); // готовый query c from/to
};

const loadHistory = async (range = '1h') => { // загрузка исторических метрик по диапазону / датам
    try {
        toggleHistoryLoader(true); // включаем анимацию загрузки
        const query = buildHistoryQuery(range); // итоговый query
        if (!query) { // если нет выбранной ноды — просто чистим графики
            clearHistoryCharts();
            return;
        }
        const res = await fetch(`${METRICS_URL}?${query}`, { credentials: 'include' }); // запрос к API метрик
        if (!res.ok) throw new Error(`HTTP ${res.status}`); // проверка статуса
        const text = await res.text(); // читаем текст
        const json = text ? JSON.parse(text) : {}; // парсим JSON
        console.log('nodes_stats history raw response:', json); // лог сырых данных истории
        const points = Array.isArray(json) ? json : (json.data || json.metrics || []); // нормализуем массив точек
        console.log('nodes_stats history points:', points); // лог массива точек
        if (!points || points.length === 0) { // если нет данных
            clearHistoryCharts(); // очищаем графики
            return; // выходим
        }
        const labelDates = points.map(p => new Date(p.ts || p.timestamp || Date.now())); // исходные даты для подписей
        const spanMs = labelDates.length > 1 ? (labelDates[labelDates.length - 1] - labelDates[0]) : 0; // длительность диапазона
        const spanHours = spanMs / 36e5; // длительность в часах
        const formatLabel = (d) => { // формат оси X в зависимости от диапазона
            if (!d || isNaN(d.getTime())) return '';
            if (spanHours <= 24) { // до суток — только время
                return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
            }
            if (spanHours <= 24 * 7) { // до недели — дата + короткое время
                return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
            }
            // больше недели — только дата с годом, чтобы не путаться
            return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
        };
        const labels = labelDates.map(formatLabel); // подписи оси X
        const cpu = points.map(p => p.cpu ?? 0); // CPU
        const ram = points.map(p => p.ram ?? p.memory ?? 0); // RAM
        const disk = points.map(p => p.disk ?? 0); // диск
        const net = points.map(p => (p.network_in ?? 0) + (p.network_out ?? 0)); // сеть

        const cpuCtx = document.getElementById('history-cpu-chart'); // холст CPU истории
        const ramCtx = document.getElementById('history-ram-chart'); // холст RAM истории
        const diskCtx = document.getElementById('history-disk-chart'); // холст диска истории
        const netCtx = document.getElementById('history-net-chart'); // холст сети истории
        if (!cpuCtx || !ramCtx || !diskCtx || !netCtx || typeof Chart === 'undefined') return; // выходим если нет Chart.js

        const baseOptions = { // базовые опции с анимацией
            type: 'line',
            options: {
                responsive: true,
                animation: {
                    duration: 600,
                    easing: 'easeOutCubic'
                },
                scales: {
                    x: { ticks: { maxTicksLimit: 8 } }, // немного больше подписей по оси X
                    y: { beginAtZero: true }, // форматируем подписи отдельно для каждого графика
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: (ctx) => { // всегда полный формат даты/времени в тултипе
                                const idx = ctx[0].dataIndex;
                                const d = labelDates[idx];
                                return d && !isNaN(d.getTime())
                                    ? d.toLocaleString('ru-RU', {
                                        day: '2-digit',
                                        month: '2-digit',
                                        year: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })
                                    : '';
                            },
                            label: (ctx) => {
                                const name = ctx.dataset.label || '';
                                const value = ctx.raw ?? 0;
                                if (name.toLowerCase().includes('сеть')) return `${name}: ${value.toFixed(2)} Б/с`; // сеть
                                return `${name}: ${value.toFixed(1)} %`; // проценты
                            },
                        },
                    },
                },
            },
        };

        if (historyCpuChart) historyCpuChart.destroy(); // пересоздаём CPU
        historyCpuChart = new Chart(cpuCtx, {
            ...baseOptions,
            options: {
                ...baseOptions.options,
                scales: {
                    ...baseOptions.options.scales,
                    y: {
                        ...baseOptions.options.scales.y,
                        ticks: { callback: (v) => `${v}%` }, // CPU в процентах
                    },
                },
            },
            data: {
                labels,
                datasets: [{
                    label: 'CPU, %',
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,0.2)',
                    tension: 0.3,
                    data: cpu,
                }],
            },
        });

        if (historyRamChart) historyRamChart.destroy(); // пересоздаём RAM
        historyRamChart = new Chart(ramCtx, {
            ...baseOptions,
            options: {
                ...baseOptions.options,
                scales: {
                    ...baseOptions.options.scales,
                    y: {
                        ...baseOptions.options.scales.y,
                        ticks: { callback: (v) => `${v}%` }, // RAM в процентах
                    },
                },
            },
            data: {
                labels,
                datasets: [{
                    label: 'RAM, %',
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.2)',
                    tension: 0.3,
                    data: ram,
                }],
            },
        });

        if (historyDiskChart) historyDiskChart.destroy(); // пересоздаём диск
        historyDiskChart = new Chart(diskCtx, {
            ...baseOptions,
            options: {
                ...baseOptions.options,
                scales: {
                    ...baseOptions.options.scales,
                    y: {
                        ...baseOptions.options.scales.y,
                        ticks: { callback: (v) => `${v}%` }, // диск в процентах
                    },
                },
            },
            data: {
                labels,
                datasets: [{
                    label: 'Диск, %',
                    borderColor: '#eab308',
                    backgroundColor: 'rgba(234,179,8,0.2)',
                    tension: 0.3,
                    data: disk,
                }],
            },
        });

        if (historyNetChart) historyNetChart.destroy(); // пересоздаём сеть
        historyNetChart = new Chart(netCtx, {
            ...baseOptions,
            options: {
                ...baseOptions.options,
                scales: {
                    ...baseOptions.options.scales,
                    y: {
                        ...baseOptions.options.scales.y,
                        ticks: { callback: (v) => formatBytes(v) }, // сеть в байтах
                    },
                },
            },
            data: {
                labels,
                datasets: [{
                    label: 'Сеть in+out',
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249,115,22,0.2)',
                    tension: 0.3,
                    data: net,
                }],
            },
        });
    } catch (e) {
        console.error('Ошибка загрузки истории метрик:', e); // лог ошибки
        clearHistoryCharts(); // очищаем графики при ошибке
    } finally {
        toggleHistoryLoader(false); // выключаем анимацию
    }
};

const clearHistoryCharts = () => { // очистка всех исторических графиков
    [historyCpuChart, historyRamChart, historyDiskChart, historyNetChart].forEach(chart => {
        if (chart) {
            chart.data.labels = []; // пустые подписи
            chart.data.datasets.forEach(ds => ds.data = []); // пустые данные
            chart.update('none'); // обновление без анимации
        }
    });
};

const toggleHistoryLoader = (show) => { // включение/выключение лоадера для блока истории
    const chartsContainer = document.querySelector('#history-tab .charts'); // контейнер графиков истории
    if (chartsContainer) {
        chartsContainer.classList.toggle('is-loading', show); // класс для CSS-анимации
    }
};

const updateHistoryNodeWarning = () => { // текст "надо выбрать ноду"
    const select = document.getElementById('history-node');
    const warning = document.getElementById('history-node-warning');
    if (!warning) return;
    const needSelect = !select || !select.value;
    warning.style.opacity = needSelect ? '1' : '0';
};

const setHistoryRangeFromCalendar = (fromDate, toDate) => { // установка диапазона из календаря
    const fromInput = document.getElementById('history-from');
    const toInput = document.getElementById('history-to');
    const displayInput = document.getElementById('history-range-display');
    if (!fromInput || !toInput || !displayInput) return;
    historyCalendarState.from = fromDate;
    historyCalendarState.to = toDate;
    const iso = (d, end = false) => {
        if (!d) return '';
        const dt = new Date(d);
        if (end) {
            dt.setHours(23, 59, 59, 0);
        } else {
            dt.setHours(0, 0, 0, 0);
        }
        return dt.toISOString().slice(0, 16); // YYYY-MM-DDTHH:MM
    };
    fromInput.value = iso(fromDate, false);
    toInput.value = iso(toDate || fromDate, true);
    if (fromDate && toDate) {
        displayInput.value = `${fromDate.toLocaleDateString('ru-RU')} — ${toDate.toLocaleDateString('ru-RU')}`;
    } else if (fromDate) {
        displayInput.value = fromDate.toLocaleDateString('ru-RU');
    } else {
        displayInput.value = '';
    }
};

const renderHistoryCalendar = () => { // отрисовка сетки календаря
    const container = document.getElementById('history-calendar');
    if (!container) return;
    const state = historyCalendarState;
    const base = new Date(state.monthCursor.getFullYear(), state.monthCursor.getMonth(), 1);
    const year = base.getFullYear();
    const month = base.getMonth();
    const firstDay = new Date(year, month, 1).getDay() || 7; // 1..7, понедельник
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const prevDays = firstDay - 1;
    const today = new Date();
    const sameDay = (a, b) => a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    const inRange = (d, from, to) => from && to && d >= from && d <= to;

    let html = '';
    html += `<div class="history-calendar-header">
        <button type="button" class="icon-btn" style="padding:0 8px;min-width:auto;" id="history-cal-prev"><i data-lucide="chevron-left"></i></button>
        <span>${base.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' })}</span>
        <button type="button" class="icon-btn" style="padding:0 8px;min-width:auto;" id="history-cal-next"><i data-lucide="chevron-right"></i></button>
    </div>`;
    const weekDays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    html += '<div class="history-calendar-grid">';
    weekDays.forEach(w => {
        html += `<div class="history-calendar-weekday">${w}</div>`;
    });
    for (let i = 0; i < prevDays; i++) {
        html += `<div class="history-calendar-day other-month"></div>`;
    }
    for (let day = 1; day <= daysInMonth; day++) {
        const d = new Date(year, month, day);
        let cls = 'history-calendar-day';
        const from = state.from;
        const to = state.to;
        if (sameDay(d, from) && sameDay(d, to || from)) {
            cls += ' range-start range-end';
        } else if (sameDay(d, from)) {
            cls += ' range-start';
        } else if (sameDay(d, to)) {
            cls += ' range-end';
        } else if (inRange(d, from, to)) {
            cls += ' range-inner';
        }
        if (sameDay(d, today)) {
            cls += ' today';
        }
        html += `<button type="button" class="${cls}" data-day="${day}">${day}</button>`;
    }
    html += '</div>';
    html += `<div class="history-calendar-footer">
        <span>${state.from ? state.from.toLocaleDateString('ru-RU') : '—'} ▸ ${state.to ? state.to.toLocaleDateString('ru-RU') : '—'}</span>
        <button type="button" class="btn-outline" id="history-cal-today">Сегодня</button>
    </div>`;
    container.innerHTML = html;
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    const prevBtn = document.getElementById('history-cal-prev');
    const nextBtn = document.getElementById('history-cal-next');
    const todayBtn = document.getElementById('history-cal-today');
    if (prevBtn) {
        prevBtn.onclick = () => {
            historyCalendarState.monthCursor = new Date(year, month - 1, 1);
            renderHistoryCalendar();
        };
    }
    if (nextBtn) {
        nextBtn.onclick = () => {
            historyCalendarState.monthCursor = new Date(year, month + 1, 1);
            renderHistoryCalendar();
        };
    }
    if (todayBtn) {
        todayBtn.onclick = () => {
            const t = new Date();
            historyCalendarState.monthCursor = new Date(t.getFullYear(), t.getMonth(), 1);
            historyCalendarState.from = t;
            historyCalendarState.to = null;
            setHistoryRangeFromCalendar(t, null);
            renderHistoryCalendar();
        };
    }
    container.querySelectorAll('.history-calendar-day[data-day]').forEach(btn => {
        btn.addEventListener('click', () => {
            const day = Number(btn.dataset.day);
            const clicked = new Date(year, month, day);
            let { from, to } = historyCalendarState;
            if (!from || (from && to)) {
                from = clicked;
                to = null;
            } else {
                if (clicked < from) {
                    to = from;
                    from = clicked;
                } else {
                    to = clicked;
                }
            }
            historyCalendarState.from = from;
            historyCalendarState.to = to;
            setHistoryRangeFromCalendar(from, to);
            renderHistoryCalendar();
        });
    });
};

const populateHistoryNodeFilter = () => { // заполняем селект нод для истории
    const select = document.getElementById('history-node'); // селект
    if (!select) return; // если селекта нет, выходим

    const fillFromCache = () => { // заполнение из кеша
        const current = select.value; // запоминаем выбранное
        select.innerHTML = '<option value="" disabled>Выберите ноду...</option>'; // только подсказка
        cachedNodes.forEach(n => { // добавляем ноды
            if (!n || typeof n.id === 'undefined') return; // пропуск битых записей
            const opt = document.createElement('option');
            opt.value = n.id;
            opt.textContent = n.name || n.host || `node-${n.id}`;
            if (String(n.id) === current) opt.selected = true;
            select.appendChild(opt);
        });
        if (!select.value) { // если нода не выбрана
            const placeholder = select.querySelector('option[value=""]');
            if (placeholder) placeholder.selected = true;
        }
        updateHistoryNodeWarning(); // обновляем текст-подсказку
    };

    if (cachedNodes && cachedNodes.length > 0) { // если кеш уже есть
        fillFromCache();
        return;
    }

    // если кеш пустой — подгружаем ноды с API
    fetch(API_URL, { credentials: 'include' })
        .then(res => res.ok ? res.text() : Promise.reject(new Error(`HTTP ${res.status}`)))
        .then(text => text ? JSON.parse(text) : {})
        .then(data => {
            const nodes = data?.nodes ?? data?.data ?? [];
            cachedNodes = Array.isArray(nodes) ? nodes : [];
            fillFromCache();
        })
        .catch(err => {
            console.error('Ошибка подгрузки нод для фильтра истории:', err);
            select.innerHTML = '<option value="" disabled selected>Выберите ноду...</option>'; // только подсказка
            updateHistoryNodeWarning();
        });
};

const initHistoryChartsLayout = () => { // d&d + компактный режим для графиков истории
    const container = document.querySelector('#history-tab .charts');
    if (!container) return;

    // drag & drop для карточек
    let draggedCard = null;

    container.querySelectorAll('.chart-card').forEach(card => {
        card.setAttribute('draggable', 'true');

        card.addEventListener('dragstart', (e) => {
            draggedCard = card;
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        card.addEventListener('dragend', () => {
            if (draggedCard) draggedCard.classList.remove('dragging');
            draggedCard = null;
        });

        card.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (!draggedCard || draggedCard === card) return;
            const rect = card.getBoundingClientRect();
            const before = e.clientY < rect.top + rect.height / 2;
            if (before) {
                container.insertBefore(draggedCard, card);
            } else {
                container.insertBefore(draggedCard, card.nextSibling);
            }
        });

        // двойной клик — «развернуть»/«свернуть» как окно (обрабатываем и карточку, и содержимое)
        const toggleMaximized = () => {
            card.classList.toggle('maximized');
        };

        // кнопка в шапке
        const headerBtn = card.querySelector('.chart-expand-btn');
        if (headerBtn) {
            headerBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleMaximized();
            });
        }
    });
};

function switchNodesStatsTabImpl(tab) { // внутренняя реализация переключения вкладок
    document.querySelectorAll('.tab-btn').forEach(btn => { // переключаем активную кнопку
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    document.querySelectorAll('.tab-content').forEach(content => { // переключаем содержимое
        content.classList.toggle('active', content.id === `${tab}-tab`);
    });
    if (tab === 'history') { // при переходе на историю подгружаем графики
        const rangeSelect = document.getElementById('history-range'); // селект диапазона
        const range = rangeSelect?.value || '1h'; // диапазон по умолчанию
        loadHistory(range); // загрузка истории
    }
    if (typeof lucide !== 'undefined') { // обновляем иконки
        lucide.createIcons();
    }
}

window.switchNodesStatsTabImpl = switchNodesStatsTabImpl; // экспорт реализации во внешний мир

document.addEventListener('DOMContentLoaded', () => { // инициализация после загрузки DOM
    loadStats(); // первая загрузка с анимацией
    setInterval(() => loadStats(true), 30000); // автообновление каждые 30 секунд без анимации

    // Обработчики фильтров истории
    const rangeSelect = document.getElementById('history-range'); // селект диапазона
    if (rangeSelect) {
        rangeSelect.addEventListener('change', () => { // при смене диапазона пересчитываем период
            loadHistory(rangeSelect.value || '1h').catch(console.error);
        });
    }

    const fromInput = document.getElementById('history-from'); // начало
    const toInput = document.getElementById('history-to'); // конец
    const displayInput = document.getElementById('history-range-display'); // видимое поле периода
    const panel = document.getElementById('history-range-panel'); // панель выбора периода
    const applyBtn = document.getElementById('history-range-apply'); // кнопка применить
    const clearBtn = document.getElementById('history-range-clear'); // кнопка сброс

    const formatRangeLabel = () => { // форматируем подпись диапазона
        if (!displayInput) return;
        const fromVal = fromInput?.value;
        const toVal = toInput?.value;
        if (!fromVal && !toVal) {
            displayInput.value = '';
            return;
        }
        const fmt = (val) => new Date(val).toLocaleString('ru-RU');
        if (fromVal && toVal) {
            displayInput.value = `${fmt(fromVal)} — ${fmt(toVal)}`;
        } else if (fromVal) {
            displayInput.value = `с ${fmt(fromVal)}`;
        } else {
            displayInput.value = `до ${fmt(toVal)}`;
        }
    };

    if (displayInput && panel) {
        displayInput.addEventListener('click', (e) => { // открываем панель по клику
            e.stopPropagation();
            panel.classList.remove('hidden');
            panel.classList.add('active');
            renderHistoryCalendar(); // рисуем календарь при открытии
        });
        panel.addEventListener('click', (e) => { // клики внутри панели не закрывают её
            e.stopPropagation();
        });
        document.addEventListener('click', (e) => { // закрываем при клике вне
            if (!panel.contains(e.target) && !displayInput.contains(e.target)) {
                panel.classList.remove('active');
                panel.classList.add('hidden');
            }
        });
    }

    if (applyBtn) {
        applyBtn.addEventListener('click', () => { // применяем выбранный период
            formatRangeLabel();
            loadHistory().catch(console.error);
            if (panel) { // закрываем панель только по кнопке "Применить"
                panel.classList.remove('active');
                panel.classList.add('hidden');
            }
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', () => { // сброс периода
            if (fromInput) fromInput.value = '';
            if (toInput) toInput.value = '';
            if (displayInput) displayInput.value = '';
            loadHistory().catch(console.error);
        });
    }

    const nodeSelect = document.getElementById('history-node'); // селект ноды
    if (nodeSelect) {
        populateHistoryNodeFilter(); // первичное заполнение (если ноды уже есть)
        nodeSelect.addEventListener('change', () => { // при смене ноды обновляем историю
            updateHistoryNodeWarning();
            loadHistory().catch(console.error);
        });
        updateHistoryNodeWarning();
    }

    // d&d и компактный режим графиков истории
    initHistoryChartsLayout();
});
