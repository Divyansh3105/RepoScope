/*
 * RepoScope charts: bar, ranked bar, line and pie charts drawn on <canvas> with plain JavaScript.
 *
 * How a chart gets onto the page:
 *   1. PHP prints a <canvas data-chart="bar" data-source="chart-2"> and next to it
 *      <script type="application/json" id="chart-2">{"title": …, "labels": […], "values": […]}</script>
 *      (the title is also printed by PHP as the chart's heading).
 *   2. This file is loaded with `defer`, so it runs once the HTML is parsed. It finds every
 *      canvas[data-chart], reads its JSON with JSON.parse and calls drawBar, drawHBar, drawLine or drawPie.
 *
 * drawBar, drawHBar, drawLine and drawPie are reusable with any canvas and any { labels, values, title }
 * object. Each returns what it drew, where each mark sits (anchor) and hitTest(x, y), which the
 * tooltip uses to find the bar, slice or station under the pointer.
 *
 * The look is RepoScope's transit theme: categories are "lines" with coloured bullets and a
 * line chart is a route with stations. Motion: transitions.dev timings, GSAP for the draw-in.
 */
'use strict';

const numberFormat = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
const smallFormat = new Intl.NumberFormat('en-US', { maximumSignificantDigits: 3 });
/** Two decimals, but three significant digits below 1 (like format_cell() in layout.php), so 0.0035 isn't "0". */
const formatNumber = (value) => (value !== 0 && Math.abs(value) < 1 ? smallFormat : numberFormat).format(value);
const percentFormat = new Intl.NumberFormat('en-US', { style: 'percent', maximumFractionDigits: 1 });

/* ---------- Shared helpers ---------- */

/** Reads the chart colours and font from the CSS custom properties in css/style.css. */
function chartTheme() {
    const css = getComputedStyle(document.documentElement);
    const get = (name) => css.getPropertyValue(name).trim();
    return {
        series: [1, 2, 3, 4, 5, 6, 7, 8].map((n) => get('--chart-' + n)),
        other: get('--chart-other'),
        grid: get('--chart-grid'),
        axis: get('--chart-axis'),
        label: get('--chart-text'),
        lift: get('--chart-lift'),
        ink: get('--ink'),
        surface: get('--panel'),
        plate: get('--plate'),
        font: get('--font'),
    };
}

/** CSS class for line colour slot i (0-7); 8 and up is the grey "Other" line. */
function lineClass(slot) {
    return slot < 8 ? 'line-' + (slot + 1) : 'line-other';
}

/**
 * Two-letter code for a line bullet: "JavaScript" → "JS", "Jupyter Notebook" → "JN",
 * "HTML" → "HT", "Python" → "Py". Same rule as line_code() in includes/layout.php.
 */
function lineCode(label) {
    const text = String(label).trim();
    const words = text.split(/[\s_-]+/).filter(Boolean);
    if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
    if (/^[A-Z0-9#+]+$/.test(text)) return text.slice(0, 2);
    const capitals = text.replace(/[^A-Z]/g, '');
    if (capitals.length >= 2) return capitals.slice(0, 2);
    return text.charAt(0).toUpperCase() + text.charAt(1).toLowerCase();
}

/**
 * Sizes the canvas so drawings stay sharp. A canvas has a CSS size (how big it looks) and
 * a pixel buffer (what we draw into). High-DPI screens have a devicePixelRatio of 2 or more,
 * so the buffer gets that many times more pixels and the drawing is scaled up to match.
 * After this, all drawing code can simply work in CSS pixels.
 */
function prepareCanvas(canvas) {
    const ratio = window.devicePixelRatio || 1;
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    canvas.width = Math.round(width * ratio); // resizing the buffer also clears it
    canvas.height = Math.round(height * ratio);
    const ctx = canvas.getContext('2d');
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    return { ctx, width, height };
}

/** Shortens text with "…" until it fits in maxWidth pixels. */
function fitText(ctx, text, maxWidth) {
    if (ctx.measureText(text).width <= maxWidth) return text;
    while (text.length > 1 && ctx.measureText(text + '…').width > maxWidth) {
        text = text.slice(0, -1);
    }
    return text + '…';
}

/**
 * Picks a "nice" y-axis: the range is rounded out to steps of 1, 2 or 5 × 10ⁿ,
 * so the gridlines read 0, 50, 100, 150 instead of 0, 47.3, 94.6…
 */
function niceScale(min, max, wholeNumbers) {
    min = Math.min(0, min); // bars and lines are measured from zero
    max = Math.max(0, max);
    if (min === max) max = 1; // all zeros: still draw a sensible axis
    const rough = (max - min) / 4; // aim for about four gaps between gridlines
    const magnitude = Math.pow(10, Math.floor(Math.log10(rough)));
    const fraction = rough / magnitude;
    let step = (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) * magnitude;
    if (wholeNumbers) step = Math.max(1, step); // counts never get a "2.5" gridline
    return { min: Math.floor(min / step) * step, max: Math.ceil(max / step) * step, step };
}

/**
 * Draws what bar and line charts share: gridlines, y-axis numbers and x-axis labels.
 * Returns the plot area, the width of one category ("band") and y(value) → pixel position.
 */
function drawAxes(ctx, width, height, data, theme) {
    ctx.font = '12px ' + theme.font;
    const scale = niceScale(Math.min(...data.values), Math.max(...data.values), data.values.every(Number.isInteger));
    const ticks = [];
    for (let i = 0; i <= Math.round((scale.max - scale.min) / scale.step); i++) {
        const value = scale.min + i * scale.step;
        ticks.push(Math.abs(value) < 1e-9 ? 0 : value); // tidy floating-point dust like 1e-17
    }

    // X labels lie flat when they all fit under their bar. Otherwise they are turned 45°
    // and shortened, and when the chart is crowded only every n-th label is drawn.
    const top = 12;
    const right = 16;
    let left = Math.max(...ticks.map((v) => ctx.measureText(formatNumber(v)).width)) + 14;
    let band = (width - left - right) / data.labels.length;
    const flat = data.labels.every((label) => ctx.measureText(String(label)).width <= band - 8);
    const labels = data.labels.map((label) => (flat ? String(label) : fitText(ctx, String(label), 110)));
    if (!flat) {
        // A turned label reaches left of its bar: make room so the first one isn't cut off.
        left = Math.max(left, ctx.measureText(labels[0]).width * Math.SQRT1_2 - band / 2 + 4);
        band = (width - left - right) / data.labels.length;
    }
    const longest = Math.max(...labels.map((label) => ctx.measureText(label).width));
    const bottom = flat ? 28 : longest * Math.SQRT1_2 + 20;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const y = (value) => top + plotHeight - ((value - scale.min) / (scale.max - scale.min)) * plotHeight;

    // Gridlines and y-axis numbers. The zero line is the baseline, so it is a little stronger.
    ctx.lineWidth = 1;
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';
    for (const value of ticks) {
        const lineY = Math.round(y(value)) + 0.5; // +0.5 lands a 1px line exactly on a pixel row
        ctx.strokeStyle = value === 0 ? theme.axis : theme.grid;
        ctx.beginPath();
        ctx.moveTo(left, lineY);
        ctx.lineTo(width - right, lineY);
        ctx.stroke();
        ctx.fillStyle = theme.label;
        ctx.fillText(formatNumber(value), left - 8, lineY);
    }

    // X-axis labels
    const every = flat ? 1 : Math.ceil(18 / band);
    labels.forEach((label, i) => {
        if (i % every !== 0) return;
        ctx.save();
        ctx.translate(left + band * (i + 0.5), top + plotHeight + 10);
        if (flat) {
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';
        } else {
            ctx.rotate(-Math.PI / 4);
            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
        }
        ctx.fillText(label, 0, 0);
        ctx.restore();
    });

    return { left, top, plotWidth, plotHeight, band, y };
}

/** Index of the category column under (x, y), or -1. The whole column counts, not just the mark. */
function bandAt(layout, count, x, y) {
    const inside = x >= layout.left && x <= layout.left + layout.plotWidth
        && y >= layout.top && y <= layout.top + layout.plotHeight;
    return inside ? Math.min(count - 1, Math.floor((x - layout.left) / layout.band)) : -1;
}

/** Writes "No data to show" and returns a result that never matches the pointer. */
function drawEmpty(ctx, width, height, theme) {
    ctx.font = '14px ' + theme.font;
    ctx.fillStyle = theme.label;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('No data to show', width / 2, height / 2);
    return { labels: [], values: [], slots: [], anchor: () => ({ x: 0, y: 0 }), hitTest: () => -1 };
}

/* ---------- The three charts ---------- */

/**
 * Bar chart: one bar per label, measured from zero.
 * hover: index of the highlighted bar, or -1. progress: 0-1 for every bar, or an array with
 * one value per bar (the draw-in grows each bar from the baseline).
 */
function drawBar(canvas, data, hover = -1, progress = 1) {
    const { ctx, width, height } = prepareCanvas(canvas);
    const theme = chartTheme();
    if (data.labels.length === 0) return drawEmpty(ctx, width, height, theme);

    const layout = drawAxes(ctx, width, height, data, theme);
    // Thin bars (24px at most) with air between them, and always at least a 2px gap.
    const barWidth = Math.max(1, Math.min(24, layout.band * 0.7, layout.band - 2));
    const zero = layout.y(0);

    data.values.forEach((value, i) => {
        const grown = value * (Array.isArray(progress) ? progress[i] : progress);
        const x = layout.left + layout.band * i + (layout.band - barWidth) / 2;
        const end = layout.y(grown);
        const length = Math.abs(zero - end);
        if (length < 0.5) return;
        const radius = Math.min(4, barWidth / 2, length);
        // Only the data end is rounded: the top of a positive bar, the bottom of a negative one.
        ctx.beginPath();
        ctx.roundRect(x, Math.min(zero, end), barWidth, length, value >= 0 ? [radius, radius, 0, 0] : [0, 0, radius, radius]);
        ctx.fillStyle = theme.series[0];
        ctx.fill();
        if (i === hover) { // the hovered bar lightens, so the reader sees it respond
            ctx.fillStyle = theme.lift;
            ctx.fill();
        }
    });

    return {
        labels: data.labels.map(String),
        values: data.values,
        slots: data.values.map(() => 0),
        anchor: (i) => ({ x: layout.left + layout.band * (i + 0.5), y: Math.min(zero, layout.y(data.values[i])) }),
        hitTest: (x, y) => bandAt(layout, data.labels.length, x, y),
    };
}

/**
 * Ranked bar chart: one horizontal bar per label, longest first, with the name on the left and
 * the exact value at the end of the bar. Long names (repositories) stay readable instead of
 * being turned 45° under the bars. Values are counts, so the bars start at zero.
 * progress: 0-1, or one value per bar (the draw-in grows each bar from the left).
 */
function drawHBar(canvas, data, hover = -1, progress = 1) {
    const { ctx, width, height } = prepareCanvas(canvas);
    const theme = chartTheme();
    if (data.labels.length === 0) return drawEmpty(ctx, width, height, theme);

    ctx.font = '13px ' + theme.font;
    const max = Math.max(...data.values, 0) || 1; // all zeros: still a sensible scale
    const names = data.labels.map(String);
    const labelWidth = Math.min(width * 0.4, Math.max(...names.map((name) => ctx.measureText(name).width)));
    const left = labelWidth + 12;
    const plotWidth = Math.max(10, width - left - ctx.measureText(formatNumber(max)).width - 10);
    const band = height / names.length;                     // one row per bar
    const barHeight = Math.max(2, Math.min(16, band * 0.6));
    const length = (value) => (Math.max(0, value) / max) * plotWidth;

    // The baseline every bar grows from
    ctx.strokeStyle = theme.axis;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(Math.round(left) + 0.5, 0);
    ctx.lineTo(Math.round(left) + 0.5, height);
    ctx.stroke();

    ctx.textBaseline = 'middle';
    data.values.forEach((value, i) => {
        const share = Array.isArray(progress) ? progress[i] : progress;
        const middle = band * (i + 0.5);
        const bar = length(value * share);

        ctx.textAlign = 'right';
        ctx.fillStyle = i === hover ? theme.ink : theme.label;
        ctx.fillText(fitText(ctx, names[i], labelWidth), labelWidth, middle);

        if (bar >= 0.5) {
            const radius = Math.min(4, barHeight / 2, bar);
            ctx.beginPath();
            ctx.roundRect(left, middle - barHeight / 2, bar, barHeight, [0, radius, radius, 0]); // only the data end is rounded
            ctx.fillStyle = theme.series[0];
            ctx.fill();
            if (i === hover) {
                ctx.fillStyle = theme.lift;
                ctx.fill();
            }
        }
        // The exact value rides on the end of the bar and fades in as the bar grows.
        ctx.globalAlpha = share;
        ctx.textAlign = 'left';
        ctx.fillStyle = i === hover ? theme.ink : theme.label;
        ctx.fillText(formatNumber(value), left + bar + 6, middle);
        ctx.globalAlpha = 1;
    });

    return {
        labels: names,
        values: data.values,
        slots: data.values.map(() => 0),
        anchor: (i) => ({ x: left + length(data.values[i]), y: band * (i + 0.5) - barHeight / 2 }),
        hitTest: (x, y) => (x >= 0 && x <= width && y >= 0 && y < height ? Math.min(names.length - 1, Math.floor(y / band)) : -1),
    };
}

/**
 * Line chart, drawn as a transit route: a 3px line with a hollow station on every point.
 * The hovered station becomes a white interchange. progress (0-1) draws the route from the
 * left, and each station appears as the line reaches it.
 */
function drawLine(canvas, data, hover = -1, progress = 1) {
    const { ctx, width, height } = prepareCanvas(canvas);
    const theme = chartTheme();
    if (data.labels.length === 0) return drawEmpty(ctx, width, height, theme);

    const layout = drawAxes(ctx, width, height, data, theme);
    const points = data.values.map((value, i) => ({ x: layout.left + layout.band * (i + 0.5), y: layout.y(value) }));

    if (hover >= 0) { // crosshair: a vertical hairline through the hovered station
        const x = Math.round(points[hover].x) + 0.5;
        ctx.strokeStyle = theme.axis;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, layout.top);
        ctx.lineTo(x, layout.top + layout.plotHeight);
        ctx.stroke();
    }

    // How far along the route the pen has travelled (all of it, unless the draw-in is running).
    const distance = [0];
    for (let i = 1; i < points.length; i++) {
        distance.push(distance[i - 1] + Math.hypot(points[i].x - points[i - 1].x, points[i].y - points[i - 1].y));
    }
    const drawn = distance[distance.length - 1] * progress;

    ctx.strokeStyle = theme.series[0];
    ctx.lineWidth = 3;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.beginPath();
    points.forEach((p, i) => {
        if (i === 0) {
            ctx.moveTo(p.x, p.y);
        } else if (distance[i] <= drawn) {
            ctx.lineTo(p.x, p.y);
        } else if (distance[i - 1] < drawn) { // the segment the pen is drawing right now
            const t = (drawn - distance[i - 1]) / (distance[i] - distance[i - 1]);
            ctx.lineTo(points[i - 1].x + (p.x - points[i - 1].x) * t, points[i - 1].y + (p.y - points[i - 1].y) * t);
        }
    });
    ctx.stroke();

    // Stations: hollow rings on the route, or only the hovered one when the route is crowded.
    points.forEach((p, i) => {
        if (distance[i] > drawn + 0.5) return;             // the line hasn't reached it yet
        if (layout.band < 14 && i !== hover) return;
        const interchange = i === hover;
        ctx.beginPath();
        ctx.arc(p.x, p.y, interchange ? 7 : 5, 0, Math.PI * 2);
        ctx.fillStyle = interchange ? theme.plate : theme.surface;
        ctx.fill();
        ctx.lineWidth = interchange ? 3 : 2.5;
        ctx.strokeStyle = theme.series[0];
        ctx.stroke();
    });

    return {
        labels: data.labels.map(String),
        values: data.values,
        slots: data.values.map(() => 0),
        anchor: (i) => points[i],
        hitTest: (x, y) => bandAt(layout, data.labels.length, x, y), // anywhere in the column snaps to its station
    };
}

/**
 * Pie chart, drawn as a donut with the total in the middle. Slices run clockwise from
 * 12 o'clock, largest first. With more than 8 categories, the 8 largest keep a line colour and
 * the rest are combined into one grey "Other" slice (more colours would be hard to tell apart).
 * progress (0-1) sweeps the donut round from 12 o'clock.
 */
function drawPie(canvas, data, hover = -1, progress = 1) {
    const { ctx, width, height } = prepareCanvas(canvas);
    const theme = chartTheme();

    let slices = data.labels
        .map((label, i) => ({ label: String(label), value: data.values[i] }))
        .filter((slice) => slice.value > 0) // a pie can't show zero or negative parts
        .sort((a, b) => b.value - a.value);
    if (slices.length > 8) {
        const rest = slices.slice(8);
        slices = slices.slice(0, 8);
        slices.push({ label: 'Other', value: rest.reduce((sum, slice) => sum + slice.value, 0) });
    }
    const total = slices.reduce((sum, slice) => sum + slice.value, 0);
    if (total === 0) return drawEmpty(ctx, width, height, theme);
    const slots = slices.map((slice, i) => i); // slot 8 is the "Other" slice

    const cx = width / 2;
    const cy = height / 2;
    const outer = Math.max(20, Math.min(width, height) / 2 - 10); // leaves room for the hover "pop"
    const inner = outer * 0.6;
    let angle = -Math.PI / 2; // 12 o'clock
    const arcs = slices.map((slice) => {
        const start = angle;
        angle += (slice.value / total) * Math.PI * 2;
        return { start, end: angle };
    });
    const sweepEnd = -Math.PI / 2 + Math.PI * 2 * progress;

    arcs.forEach((arc, i) => {
        const end = Math.min(arc.end, sweepEnd);
        if (end <= arc.start) return;
        const radius = i === hover ? outer + 6 : outer; // the hovered slice grows a little
        ctx.beginPath();
        ctx.arc(cx, cy, radius, arc.start, end);
        ctx.arc(cx, cy, inner, end, arc.start, true);
        ctx.closePath();
        ctx.fillStyle = i < 8 ? theme.series[i] : theme.other;
        ctx.fill();
        ctx.lineWidth = 2; // a 2px line in the panel colour leaves a gap between slices
        ctx.strokeStyle = theme.surface;
        ctx.stroke();
    });

    // The total, in the hole of the donut
    ctx.textAlign = 'center';
    ctx.fillStyle = theme.ink;
    ctx.font = '600 22px ' + theme.font;
    ctx.textBaseline = 'alphabetic';
    ctx.fillText(formatNumber(total), cx, cy + 4);
    ctx.fillStyle = theme.label;
    ctx.font = '12px ' + theme.font;
    ctx.textBaseline = 'top';
    ctx.fillText('total', cx, cy + 10);

    return {
        labels: slices.map((slice) => slice.label),
        values: slices.map((slice) => slice.value),
        slots,
        total,
        anchor(i) { // the middle of the slice's ring
            const mid = (arcs[i].start + arcs[i].end) / 2;
            const r = (inner + outer) / 2;
            return { x: cx + Math.cos(mid) * r, y: cy + Math.sin(mid) * r };
        },
        hitTest(x, y) {
            const distance = Math.hypot(x - cx, y - cy);
            if (distance < inner || distance > outer + 6) return -1;
            let pointer = Math.atan2(y - cy, x - cx); // -π … π, measured from 3 o'clock
            if (pointer < -Math.PI / 2) pointer += Math.PI * 2; // line it up with our 12 o'clock start
            return arcs.findIndex((arc) => pointer >= arc.start && pointer < arc.end);
        },
    };
}

/* ---------- Wiring: find each chart, draw it, add a legend, tooltips and the draw-in ---------- */

const drawFunctions = { bar: drawBar, hbar: drawHBar, line: drawLine, pie: drawPie };
const perBar = (type) => type === 'bar' || type === 'hbar'; // these animate one progress value per bar

/** A line bullet element (the same look as line_bullet() in includes/layout.php). */
function makeBullet(label, slot) {
    const bullet = document.createElement('span');
    bullet.className = 'bullet ' + lineClass(slot);
    bullet.setAttribute('aria-hidden', 'true');
    bullet.textContent = slot < 8 ? lineCode(label) : '+'; // "Other" gets a plus
    return bullet;
}

/** The pie's key: a line bullet, the name and its share for every slice. */
function buildLegend(result) {
    const list = document.createElement('ul');
    list.className = 'chart-legend';
    result.labels.forEach((label, i) => {
        const name = document.createElement('span');
        name.className = 'legend-label';
        name.textContent = label; // SECURITY: textContent, never innerHTML, because labels come from users' data
        const share = document.createElement('span');
        share.className = 'legend-value';
        share.textContent = percentFormat.format(result.values[i] / result.total);
        const item = document.createElement('li');
        item.append(makeBullet(label, result.slots[i]), name, share);
        list.append(item);
    });
    return list;
}

/**
 * The one authored moment, run with GSAP: when fresh data arrives from GitHub (PHP marks the
 * section with data-animate), the bars rise, the donut sweeps round and the route draws itself
 * station by station. Cached views and reduced-motion users get the finished chart at once.
 */
function drawIn(type, reveal, redraw) {
    const mm = gsap.matchMedia();
    mm.add({ motion: '(prefers-reduced-motion: no-preference)', reduce: '(prefers-reduced-motion: reduce)' }, (context) => {
        const targets = Array.isArray(reveal) ? reveal : [reveal];
        if (context.conditions.reduce) {
            targets.forEach((target) => { target.p = 1; });
            redraw();
            return;
        }
        // power4.out is the same curve family as transitions.dev's --ease-smooth-out.
        const timeline = gsap.timeline({ defaults: { ease: 'power4.out' }, onUpdate: redraw });
        if (perBar(type)) {
            // 40ms between bars (--duration-stagger), but the whole stagger stays under 300ms.
            const each = Math.min(0.04, 0.3 / Math.max(1, targets.length - 1));
            timeline.to(targets, { p: 1, duration: 0.5, stagger: each });
        } else if (type === 'line') {
            timeline.to(targets, { p: 1, duration: 0.7, ease: 'power2.inOut' }); // a pen draws at an even pace
        } else {
            timeline.to(targets, { p: 1, duration: 0.6 });
        }
    });
}

function setUpChart(canvas) {
    const type = canvas.dataset.chart;
    const draw = drawFunctions[type];
    const source = document.getElementById(canvas.dataset.source);
    if (!draw || !source) return;
    const data = JSON.parse(source.textContent); // the JSON block PHP printed next to the canvas

    // Draw-in state: one progress value per bar, or one for the whole line or pie.
    const animate = Boolean(canvas.closest('[data-animate]')) && typeof gsap !== 'undefined';
    const start = animate ? 0 : 1;
    const reveal = perBar(type) ? data.values.map(() => ({ p: start })) : { p: start };

    // A ranked bar chart is as tall as its rows (32px each), so a short list leaves no empty band.
    if (type === 'hbar') canvas.style.height = Math.max(3, data.labels.length) * 32 + 'px';
    const progress = () => (Array.isArray(reveal) ? reveal.map((r) => r.p) : reveal.p);

    // One tooltip per chart: the value leads, then a colour key and the label.
    const box = canvas.parentElement; // .chart-canvas, which the tooltip is positioned inside
    const tooltip = document.createElement('div');
    tooltip.className = 'chart-tooltip';
    tooltip.setAttribute('aria-hidden', 'true'); // the data table below is the accessible version
    const tipValue = document.createElement('strong');
    tipValue.className = 'tip-value';
    const tipLabel = document.createElement('span');
    tipLabel.className = 'tip-label';
    const tipKey = document.createElement('span');
    const tipText = document.createElement('span');
    tipLabel.append(tipKey, tipText);
    tooltip.append(tipValue, tipLabel);
    box.append(tooltip);

    let hover = -1;
    let result;
    const redraw = () => { result = draw(canvas, data, hover, progress()); };
    redraw();
    if (type === 'pie' && result.labels.length > 0) {
        box.after(buildLegend(drawPie(canvas, data, -1, 1))); // the legend always describes the finished pie
        redraw();
    }

    // Redraw whenever the canvas changes size: window resizes, phone rotation, a scrollbar appearing…
    new ResizeObserver(redraw).observe(canvas);
    // …and once the web font has loaded: canvas text doesn't update by itself when a font swaps in.
    document.fonts.ready.then(redraw);
    if (animate) drawIn(type, reveal, redraw);

    function hideTooltip() {
        tooltip.dataset.show = 'false';
    }

    // transitions.dev tooltip: when hidden, the plate jumps to the new mark and only the appear
    // plays; when already showing, it travels from mark to mark.
    function showTooltip(index) {
        const value = result.values[index];
        tipValue.textContent = formatNumber(value)
            + (result.total ? ' (' + percentFormat.format(value / result.total) + ')' : '');
        tipText.textContent = result.labels[index]; // SECURITY: textContent again
        tipKey.className = 'tip-key ' + lineClass(result.slots[index]);

        const mark = result.anchor(index);
        const width = tooltip.offsetWidth;
        const height = tooltip.offsetHeight;
        const x = Math.min(Math.max(0, mark.x - width / 2), canvas.clientWidth - width);
        const y = mark.y - height - 12 >= 0 ? mark.y - height - 12 : mark.y + 12; // above the mark, or below near the top
        const showing = tooltip.dataset.show === 'true';
        if (!showing) tooltip.style.transition = 'none';
        tooltip.style.setProperty('--tt-x', x + 'px');
        tooltip.style.setProperty('--tt-y', y + 'px');
        if (!showing) {
            void tooltip.offsetWidth; // apply the jump before the appear transition is restored
            tooltip.style.transition = '';
        }
        tooltip.dataset.show = 'true';
    }

    function onPointer(event) {
        const rect = canvas.getBoundingClientRect();
        const index = result.hitTest(event.clientX - rect.left, event.clientY - rect.top);
        if (index === hover) return; // same mark: nothing to redraw
        hover = index;
        redraw();
        if (index < 0) hideTooltip();
        else showTooltip(index);
    }

    canvas.addEventListener('pointermove', onPointer);
    canvas.addEventListener('pointerdown', onPointer); // a tap on touch screens
    canvas.addEventListener('pointerleave', (event) => {
        if (event.pointerType === 'touch') return; // on touch, keep the tooltip until the next tap
        hover = -1;
        hideTooltip();
        redraw();
    });
}

document.querySelectorAll('canvas[data-chart]').forEach(setUpChart);
