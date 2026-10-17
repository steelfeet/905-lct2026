(function () {
    'use strict';
    var payloadEl = document.getElementById('dash-payload');
    if (!payloadEl) return;
    var DASH = JSON.parse(payloadEl.textContent);

    var accent = '#8b6914';
    var accentDark = '#6b5010';
    var gridColor = 'rgba(26, 26, 26, 0.08)';
    var textColor = '#4a4a4a';

    Chart.defaults.font.family = "'Inter', -apple-system, sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.color = textColor;

    var state = { months: 12, chartType: 'line', phaseId: null, uniId: null, aggregate: null };
    var charts = { activity: null, phases: null, unis: null, stacked: null };

    function phaseById(id) { for (var i = 0; i < DASH.phases.length; i++) if (DASH.phases[i].id === id) return DASH.phases[i]; return null; }
    function uniById(id) { for (var i = 0; i < DASH.universities.length; i++) if (DASH.universities[i].id === id) return DASH.universities[i]; return null; }
    function anyUniById(id) { for (var i = 0; i < DASH.allUniversities.length; i++) if (DASH.allUniversities[i].id === id) return DASH.allUniversities[i]; return null; }

    function hexWithAlpha(hex, alpha) {
        if (!hex || hex.charAt(0) !== '#') return hex;
        var h = hex.substring(1);
        if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
        var r = parseInt(h.substring(0,2),16), g = parseInt(h.substring(2,4),16), b = parseInt(h.substring(4,6),16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    }

    function aggregateMonths(n) {
        var slice = DASH.monthly.slice(-n);
        var byUniPhase = {}, labels = [], activity = [], newProjects = [];
        slice.forEach(function (m, idx) {
            labels.push(m.label);
            var monthTotal = 0;
            Object.keys(m.byUniPhase).forEach(function (uid) {
                if (!byUniPhase[uid]) byUniPhase[uid] = {};
                var u = m.byUniPhase[uid];
                Object.keys(u).forEach(function (pid) {
                    byUniPhase[uid][pid] = (byUniPhase[uid][pid] || 0) + u[pid];
                    monthTotal += u[pid];
                });
            });
            activity.push(monthTotal);
            newProjects.push(DASH.newProjects[DASH.newProjects.length - n + idx] || 1);
        });
        return { labels: labels, activity: activity, newProjects: newProjects, byUniPhase: byUniPhase };
    }

    function computeByPhase() {
        var res = {};
        DASH.phases.forEach(function (p) { res[p.id] = 0; });
        Object.keys(state.aggregate.byUniPhase).forEach(function (uid) {
            if (state.uniId && parseInt(uid, 10) !== state.uniId) return;
            var u = state.aggregate.byUniPhase[uid];
            Object.keys(u).forEach(function (pid) { var key = parseInt(pid, 10); if (res[key] !== undefined) res[key] += u[pid]; });
        });
        return res;
    }

    function computeByUni() {
        var res = {};
        DASH.universities.forEach(function (u) { res[u.id] = 0; });
        Object.keys(state.aggregate.byUniPhase).forEach(function (uidStr) {
            var uid = parseInt(uidStr, 10);
            var u = state.aggregate.byUniPhase[uidStr];
            Object.keys(u).forEach(function (pidStr) {
                var pid = parseInt(pidStr, 10);
                if (state.phaseId && pid !== state.phaseId) return;
                if (res[uid] !== undefined) res[uid] += u[pidStr];
            });
        });
        return res;
    }

    function topPhaseForUni(uid) {
        var u = state.aggregate.byUniPhase[String(uid)];
        if (!u) return null;
        var maxCount = 0, maxPid = null;
        Object.keys(u).forEach(function (pidStr) { if (u[pidStr] > maxCount) { maxCount = u[pidStr]; maxPid = parseInt(pidStr, 10); } });
        return maxPid;
    }

    function phaseColorById(pid) { var p = phaseById(pid); return p ? p.color : '#9ca3af'; }
    function rebuildAggregate() { state.aggregate = aggregateMonths(state.months); }

    function renderActivity() {
        var canvas = document.getElementById('chart-activity');
        if (!canvas) return;
        if (charts.activity) { charts.activity.destroy(); charts.activity = null; }
        var labels = state.aggregate.labels;
        var actData = state.aggregate.activity;
        var newData = state.aggregate.newProjects;
        var gradient = canvas.getContext('2d').createLinearGradient(0, 0, 0, 260);
        gradient.addColorStop(0, 'rgba(139, 105, 20, 0.4)');
        gradient.addColorStop(1, 'rgba(139, 105, 20, 0.02)');
        var type = state.chartType === 'bar' ? 'bar' : 'line';
        var fill = state.chartType === 'area';
        var datasets = [
            { label: 'Активность', data: actData, borderColor: accent, backgroundColor: type === 'bar' ? accent : (fill ? gradient : 'rgba(139, 105, 20, 0.08)'), fill: fill || type === 'bar', tension: 0.35, pointBackgroundColor: accent, pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: type === 'bar' ? 0 : 4, pointHoverRadius: type === 'bar' ? 0 : 7, borderWidth: 2, borderRadius: type === 'bar' ? 3 : 0, barPercentage: 0.6 },
            { label: 'Новые проекты', data: newData, borderColor: accentDark, backgroundColor: 'transparent', borderDash: type === 'bar' ? [] : [4,4], tension: 0.35, pointBackgroundColor: accentDark, pointRadius: type === 'bar' ? 0 : 3, pointHoverRadius: 5, borderWidth: 1.5, type: 'line', hidden: type === 'bar' }
        ];
        charts.activity = new Chart(canvas, {
            type: type, data: { labels: labels, datasets: datasets },
            options: { responsive: true, maintainAspectRatio: false, animation: { duration: 500, easing: 'easeOutQuart' }, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14, font: { size: 11 } } }, tooltip: { backgroundColor: 'rgba(26, 26, 26, 0.92)', padding: 10, cornerRadius: 2 } },
                scales: { x: { grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor } }, y: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor, precision: 0 } } }
            }
        });
    }

    function renderPhases() {
        var canvas = document.getElementById('chart-phases');
        if (!canvas) return;
        if (charts.phases) { charts.phases.destroy(); charts.phases = null; }
        var byPhase = computeByPhase();
        var labels = DASH.phases.map(function (p) { return p.num + '. ' + p.name; });
        var colors = DASH.phases.map(function (p) { return p.color; });
        var data = DASH.phases.map(function (p) { return byPhase[p.id] || 0; });
        var alphas = DASH.phases.map(function (p) { if (state.phaseId && p.id !== state.phaseId) return 0.22; return 1; });
        charts.phases = new Chart(canvas, {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: data, backgroundColor: colors.map(function (c, i) { return hexWithAlpha(c, alphas[i]); }), borderColor: '#fff', borderWidth: 2, hoverOffset: 8 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '60%', animation: { duration: 500, easing: 'easeOutQuart' },
                onClick: function (evt, els) { if (!els || !els.length) return; var pid = DASH.phases[els[0].index].id; state.phaseId = (state.phaseId === pid) ? null : pid; refreshAll(); },
                plugins: { legend: { position: 'right', labels: { boxWidth: 10, boxHeight: 10, padding: 8, font: { size: 10 } } }, tooltip: { backgroundColor: 'rgba(26, 26, 26, 0.92)', padding: 10, cornerRadius: 2, callbacks: { label: function (ctx) { return ctx.label + ': ' + ctx.parsed + ' проектов'; } } } }
            }
        });
    }

    function renderUnis() {
        var canvas = document.getElementById('chart-universities');
        if (!canvas) return;
        if (charts.unis) { charts.unis.destroy(); charts.unis = null; }
        var byUni = computeByUni();
        var labels = DASH.universities.map(function (u) { return u.short; });
        var data = DASH.universities.map(function (u) { return byUni[u.id] || 0; });
        var bgs = DASH.universities.map(function (u) { if (state.uniId && u.id !== state.uniId) return hexWithAlpha(accent, 0.22); return accent; });
        var hoverBgs = DASH.universities.map(function (u) { if (state.uniId && u.id !== state.uniId) return hexWithAlpha(accentDark, 0.3); return accentDark; });
        var type = state.chartType === 'bar' ? 'bar' : 'line';
        var fill = state.chartType === 'area';
        var dataset, options;
        if (type === 'bar') {
            dataset = { label: 'Проектов', data: data, backgroundColor: bgs, hoverBackgroundColor: hoverBgs, borderRadius: 3, barThickness: 16 };
            options = { indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: { duration: 500, easing: 'easeOutQuart' },
                onClick: function (evt, els) { if (!els || !els.length) return; var uid = DASH.universities[els[0].index].id; state.uniId = (state.uniId === uid) ? null : uid; refreshAll(); },
                plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(26, 26, 26, 0.92)', padding: 10, cornerRadius: 2, callbacks: { label: function (ctx) { var v = ctx.parsed && ctx.parsed.x !== undefined ? ctx.parsed.x : ctx.parsed; return 'Проектов: ' + v; } } } },
                scales: { x: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor, precision: 0 } }, y: { grid: { display: false }, ticks: { color: textColor, font: { size: 11 } } } }
            };
        } else {
            dataset = { label: 'Проектов', data: data, borderColor: accent, backgroundColor: fill ? hexWithAlpha(accent, 0.35) : hexWithAlpha(accent, 0.1), fill: fill, tension: 0.35, pointBackgroundColor: accent, pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: 5, pointHoverRadius: 8, borderWidth: 2 };
            options = { responsive: true, maintainAspectRatio: false, animation: { duration: 500, easing: 'easeOutQuart' },
                onClick: function (evt, els) { if (!els || !els.length) return; var uid = DASH.universities[els[0].index].id; state.uniId = (state.uniId === uid) ? null : uid; refreshAll(); },
                plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(26, 26, 26, 0.92)', padding: 10, cornerRadius: 2, callbacks: { label: function (ctx) { var v = ctx.parsed && ctx.parsed.y !== undefined ? ctx.parsed.y : ctx.parsed; return 'Проектов: ' + v; } } } },
                scales: { x: { grid: { display: false }, ticks: { color: textColor, font: { size: 11 }, autoSkip: false, maxRotation: 45, minRotation: 0 } }, y: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor, precision: 0 } } }
            };
        }
        charts.unis = new Chart(canvas, { type: type, data: { labels: labels, datasets: [dataset] }, options: options });
    }

    function renderStacked() {
        var canvas = document.getElementById('chart-directions');
        if (!canvas) return;
        if (charts.stacked) { charts.stacked.destroy(); charts.stacked = null; }
        var byUniPhase = state.aggregate.byUniPhase;
        var uniLabels = DASH.universities.map(function (u) { return u.short; });
        var phaseIds = DASH.phases.map(function (p) { return p.id; });
        var phaseLabels = DASH.phases.map(function (p) { return p.num + '. ' + p.name; });
        var phaseColors = DASH.phases.map(function (p) { return p.color; });
        var type = state.chartType === 'bar' ? 'bar' : 'line';
        var fill = state.chartType === 'area';
        var datasets = [];
        for (var i = 0; i < phaseIds.length; i++) {
            var pid = phaseIds[i];
            var isDimmed = state.phaseId && state.phaseId !== pid;
            var data = DASH.universities.map(function (u) {
                if (state.uniId && u.id !== state.uniId) return 0;
                var uData = byUniPhase[String(u.id)];
                return uData ? (uData[String(pid)] || 0) : 0;
            });
            var alpha = isDimmed ? 0.22 : 1;
            var ds = { label: phaseLabels[i], data: data, borderColor: phaseColors[i], borderWidth: 2, tension: 0.35, pointBackgroundColor: phaseColors[i], pointBorderColor: '#fff', pointBorderWidth: 1.5, pointRadius: type === 'bar' ? 0 : 4, pointHoverRadius: 6 };
            if (type === 'bar') { ds.backgroundColor = hexWithAlpha(phaseColors[i], alpha * 0.9); ds.borderRadius = 2; ds.stack = 'phases'; }
            else { ds.backgroundColor = fill ? hexWithAlpha(phaseColors[i], alpha * 0.35) : 'transparent'; ds.fill = fill; }
            datasets.push(ds);
        }
        charts.stacked = new Chart(canvas, {
            type: type, data: { labels: uniLabels, datasets: datasets },
            options: { responsive: true, maintainAspectRatio: false, animation: { duration: 500, easing: 'easeOutQuart' },
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, padding: 10, font: { size: 10 } } }, tooltip: { backgroundColor: 'rgba(26, 26, 26, 0.92)', padding: 10, cornerRadius: 2 } },
                scales: { x: { stacked: type === 'bar', grid: { display: false }, ticks: { color: textColor } }, y: { stacked: type === 'bar', beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor, precision: 0 } } }
            }
        });
    }

    function renderMap() {
        var mapWrap = document.querySelector('.russia-map-wrap');
        if (!mapWrap) return;
        mapWrap.querySelectorAll('.uni-point').forEach(function (g) {
            var uid = parseInt(g.dataset.uniId, 10);
            g.classList.remove('is-dimmed', 'is-selected');
            var topPid = topPhaseForUni(uid);
            var color = topPid ? phaseColorById(topPid) : '#9ca3af';
            var dot = g.querySelector('.uni-point__dot');
            var halo = g.querySelector('.uni-point__halo');
            var pulse = g.querySelector('.uni-point__pulse');
            if (dot) dot.setAttribute('fill', color);
            if (halo) halo.setAttribute('fill', color);
            if (pulse) pulse.setAttribute('stroke', color);
            var hasData = state.aggregate.byUniPhase[String(uid)] !== undefined;
            if (state.uniId && uid === state.uniId) { g.classList.add('is-selected'); return; }
            if (state.phaseId) {
                var uData = state.aggregate.byUniPhase[String(uid)];
                var inPhase = uData && uData[String(state.phaseId)];
                if (!inPhase) g.classList.add('is-dimmed');
            } else if (state.uniId && uid !== state.uniId) { g.classList.add('is-dimmed'); }
            else if (!hasData) { g.classList.add('is-dimmed'); }
        });
        document.querySelectorAll('.map-legend__item').forEach(function (li) {
            li.classList.toggle('is-active', state.phaseId === parseInt(li.dataset.phaseId, 10));
        });
    }

    function updateChip() {
        var chip = document.getElementById('filter-chip');
        var label = document.getElementById('filter-chip-label');
        if (!chip || !label) return;
        var parts = [];
        if (state.uniId) { var u = uniById(state.uniId) || anyUniById(state.uniId); if (u) parts.push('Вуз: ' + u.short); }
        if (state.phaseId) { var p = phaseById(state.phaseId); if (p) parts.push('Фаза: ' + p.num + '. ' + p.name); }
        if (parts.length === 0) chip.style.display = 'none';
        else { chip.style.display = 'inline-flex'; label.textContent = parts.join('  ·  '); }
    }

    function updateHints() {
        var tl = state.chartType === 'line' ? 'линия' : (state.chartType === 'bar' ? 'столбцы' : 'область');
        var el;
        if ((el = document.getElementById('hint-activity'))) el.textContent = 'последние ' + state.months + ' мес · ' + tl;
        if ((el = document.getElementById('hint-phases'))) el.textContent = 'за ' + state.months + ' мес · клик — фильтр';
        if ((el = document.getElementById('hint-unis'))) el.textContent = 'за ' + state.months + ' мес · ' + tl + ' · клик — фильтр';
        if ((el = document.getElementById('hint-stacked'))) el.textContent = 'за ' + state.months + ' мес · ' + tl;
        if ((el = document.getElementById('hint-map'))) el.textContent = 'за ' + state.months + ' мес · цвет — преобладающая фаза';
    }

    function refreshAll() { rebuildAggregate(); renderPhases(); renderUnis(); renderStacked(); renderMap(); updateChip(); updateHints(); }

    rebuildAggregate();
    renderActivity(); renderPhases(); renderUnis(); renderStacked(); renderMap();
    updateChip(); updateHints();

    var monthBtns = document.querySelectorAll('[data-months]');
    monthBtns.forEach(function (btn) { btn.addEventListener('click', function () { monthBtns.forEach(function (b) { b.classList.remove('is-active'); }); btn.classList.add('is-active'); state.months = parseInt(btn.dataset.months, 10); refreshAll(); renderActivity(); }); });
    var typeBtns = document.querySelectorAll('[data-act-type]');
    typeBtns.forEach(function (btn) { btn.addEventListener('click', function () { typeBtns.forEach(function (b) { b.classList.remove('is-active'); }); btn.classList.add('is-active'); state.chartType = btn.dataset.actType; renderActivity(); renderUnis(); renderStacked(); updateHints(); }); });

    var chip = document.getElementById('filter-chip');
    if (chip) chip.addEventListener('click', function () { state.phaseId = null; state.uniId = null; refreshAll(); });

    document.querySelectorAll('.map-legend__item').forEach(function (li) { li.addEventListener('click', function () { var pid = parseInt(li.dataset.phaseId, 10); state.phaseId = (state.phaseId === pid) ? null : pid; refreshAll(); }); });

    var mapWrap = document.querySelector('.russia-map-wrap');
    var mapInfo = document.getElementById('map-info');
    if (mapWrap && mapInfo) {
        var infoPhase = document.getElementById('map-info-phase');
        var infoName = document.getElementById('map-info-name');
        var infoProjects = document.getElementById('map-info-projects');
        mapWrap.querySelectorAll('.uni-point').forEach(function (g) {
            var uid = parseInt(g.dataset.uniId, 10);
            g.addEventListener('mouseenter', function () {
                var topPid = topPhaseForUni(uid);
                var topPhase = topPid ? phaseById(topPid) : null;
                var phaseName = topPhase ? (topPhase.num + '. ' + topPhase.name) : 'Не начато';
                var color = topPhase ? topPhase.color : '#8b6914';
                var uData = state.aggregate.byUniPhase[String(uid)] || {};
                var total = 0;
                Object.keys(uData).forEach(function (k) { total += uData[k]; });
                infoPhase.textContent = phaseName;
                infoPhase.style.color = color;
                infoName.textContent = g.dataset.name || '';
                infoName.title = g.dataset.full || '';
                infoProjects.textContent = 'Проектов за период: ' + total + ' · клик — фильтр';
                mapInfo.classList.add('is-visible');
            });
            g.addEventListener('mouseleave', function () { mapInfo.classList.remove('is-visible'); });
            g.addEventListener('click', function () { state.uniId = (state.uniId === topUniId(uid)) ? null : uid; refreshAll(); });
        });
    }

    function topUniId(uid) { return state.uniId === uid ? uid : null; }
})();