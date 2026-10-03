/*
 * RepoScope charts: bar, line and pie charts drawn on <canvas> with plain JavaScript.
 *
 * How a chart gets onto the page:
 *   1. PHP prints a <canvas data-chart="bar" data-source="chart-2"> and next to it
 *      <script type="application/json" id="chart-2">{"title": …, "labels": […], "values": […]}</script>
 *      (the title is also printed by PHP as the chart's heading).
 *   2. This file is loaded with `defer`, so it runs once the HTML is parsed. It finds every
 *      canvas[data-chart], reads its JSON with JSON.parse and calls drawBar, drawLine or drawPie.
 *
 * drawBar, drawLine and drawPie are reusable with any canvas and any { labels, values, title }
 * object. Each returns what it drew plus hitTest(x, y), which the tooltip code uses to find
 * the bar, slice or point under the pointer.
 */
'use strict';

const numberFormat = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
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
        ink: get('--text'),
        surface: get('--surface'),
        font: get('--font'),
    };
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
    let left = Math.max(...ticks.map((v) => ctx.measureText(numberFormat.format(v)).width)) + 14;
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
        ctx.fillText(numberFormat.format(value), left - 8, lineY);
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

/** Index of the category column under (x, y), or -1. The whole column counts, not just the bar. */
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
    return { labels: [], values: [], colors: [], hitTest: () => -1 };
}

/* ---------- The three charts ---------- */

/** Bar chart: one bar per label, measured from zero. hover = index of the highlighted bar, or -1. */
function drawBar(canvas, data, hover = -1) {
    const { ctx, width, height } = prepareCanvas(canvas);
    const theme = chartTheme();
    if (data.labels.length === 0) return drawEmpty(ctx, width, height, theme);

    const layout = drawAxes(ctx, width, height, data, theme);
    // Thin bars (24px at most) with air between them, and always at least a 2px gap.
    const barWidth = Math.max(1, Math.min(24, layout.band * 0.7, layout.band - 2));
    const zero = layout.y(0);

    data.values.forEach((value, i) => {
        const x = layout.left + layout.band * i + (layout.band - barWidth) / 2;
        const end = layout.y(value);
        const top = Math.min(zero, end);
        const length = Math.abs(zero - end);
        const radius = Math.min(4, barWidth / 2, length);
        // Only the data end is rounded: the top of a positive bar, the bottom of a negative one.
        const corners = value >= 0 ? [radius, radius, 0, 0] : [0, 0, radius, radius];
        ctx.beginPath();
        ctx.roundRect(x, top, barWidth, length, corners);
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
        colors: data.values.map(() => theme.series[0]),
        hitTest: (x, y) => bandAt(layout, data.labels.length, x, y),
    };
}

/** Line chart: one point per label, joined in order (for example, years). */
function drawLine(canvas, data, hover = -1) {
    const { ctx, width, height } = prepareCanvas(canvas);
    const theme = chartTheme();
    if (data.labels.length === 0) return drawEmpty(ctx, width, height, theme);

    const layout = drawAxes(ctx, width, height, data, theme);
    const points = data.values.map((value, i) => ({ x: layout.left + layout.band * (i + 0.5), y: layout.y(value) }));

    if (hover >= 0) { // crosshair: a vertical hairline through the hovered point
        const x = Math.round(points[hover].x) + 0.5;
        ctx.strokeStyle = theme.axis;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, layout.top);
        ctx.lineTo(x, layout.top + layout.plotHeight);
        ctx.stroke();
    }

    ctx.strokeStyle = theme.series[0];
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.beginPath();
    points.forEach((p, i) => (i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y)));
    ctx.stroke();

    // Dots on every point when there is room, otherwise only on the hovered one. Each dot
    // gets a 2px ring in the card colour so it stands clear of the line.
    points.forEach((p, i) => {
        if (layout.band < 14 && i !== hover) return;
        ctx.beginPath();
        ctx.arc(p.x, p.y, i === hover ? 6 : 4, 0, Math.PI * 2);
        ctx.fillStyle = theme.series[0];
        ctx.fill();
        ctx.lineWidth = 2;
        ctx.strokeStyle = theme.surface;
        ctx.stroke();
    });

    return {
        labels: data.labels.map(String),
        values: data.values,
        colors: data.values.map(() => theme.series[0]),
        hitTest: (x, y) => bandAt(layout, data.labels.length, x, y), // anywhere in the column snaps to its point
    };
}

/**
 * Pie chart, drawn as a donut with the total in the middle. Slices run clockwise from
 * 12 o'clock, largest first. With more than 8 categories, the 8 largest keep a colour and
 * the rest are combined into one grey "Other" slice (more colours would be hard to tell apart).
 */
function drawPie(canvas, data, hover = -1) {
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
    const colors = slices.map((slice, i) => (i < 8 ? theme.series[i] : theme.other));

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

    arcs.forEach((arc, i) => {
        const radius = i === hover ? outer + 6 : outer; // the hovered slice grows a little
        ctx.beginPath();
        ctx.arc(cx, cy, radius, arc.start, arc.end);
        ctx.arc(cx, cy, inner, arc.end, arc.start, true);
        ctx.closePath();
        ctx.fillStyle = colors[i];
        ctx.fill();
        ctx.lineWidth = 2; // a 2px line in the card colour leaves a gap between slices
        ctx.strokeStyle = theme.surface;
        ctx.stroke();
    });

    // The total, in the hole of the donut
    ctx.textAlign = 'center';
    ctx.fillStyle = theme.ink;
    ctx.font = '600 22px ' + theme.font;
    ctx.textBaseline = 'alphabetic';
    ctx.fillText(numberFormat.format(total), cx, cy + 4);
    ctx.fillStyle = theme.label;
    ctx.font = '12px ' + theme.font;
    ctx.textBaseline = 'top';
    ctx.fillText('total', cx, cy + 10);

    return {
        labels: slices.map((slice) => slice.label),
        values: slices.map((slice) => slice.value),
        colors,
        total,
        hitTest(x, y) {
            const distance = Math.hypot(x - cx, y - cy);
            if (distance < inner || distance > outer + 6) return -1;
            let pointer = Math.atan2(y - cy, x - cx); // -π … π, measured from 3 o'clock
            if (pointer < -Math.PI / 2) pointer += Math.PI * 2; // line it up with our 12 o'clock start
            return arcs.findIndex((arc) => pointer >= arc.start && pointer < arc.end);
        },
    };
}

/* ---------- Wiring: find each chart, draw it, add a legend and tooltips ---------- */

const drawFunctions = { bar: drawBar, line: drawLine, pie: drawPie };

/** The colour key shown beside a pie chart. */
function buildLegend(result) {
    const list = document.createElement('ul');
    list.className = 'chart-legend';
    result.labels.forEach((label, i) => {
        const swatch = document.createElement('span');
        swatch.className = 'swatch';
        swatch.style.background = result.colors[i]; // setting style from a script is allowed by our CSP
        const name = document.createElement('span');
        name.className = 'legend-label';
        name.textContent = label; // SECURITY: textContent, never innerHTML, because labels come from users' data
        const share = document.createElement('span');
        share.className = 'legend-value';
        share.textContent = percentFormat.format(result.values[i] / result.total);
        const item = document.createElement('li');
        item.append(swatch, name, share);
        list.append(item);
    });
    return list;
}

function setUpChart(canvas) {
    const draw = drawFunctions[canvas.dataset.chart];
    const source = document.getElementById(canvas.dataset.source);
    if (!draw || !source) return;
    const data = JSON.parse(source.textContent); // the JSON block PHP printed next to the canvas

    // One tooltip per chart: the value first (strong), then a colour key and the label.
    const box = canvas.parentElement; // .chart-canvas, which the tooltip is positioned inside
    const tooltip = document.createElement('div');
    tooltip.className = 'chart-tooltip';
    tooltip.hidden = true;
    const tipValue = document.createElement('strong');
    const tipLabel = document.createElement('span');
    tipLabel.className = 'tip-label';
    const tipKey = document.createElement('span');
    tipKey.className = 'tip-key';
    const tipText = document.createElement('span');
    tipLabel.append(tipKey, tipText);
    tooltip.append(tipValue, tipLabel);
    box.append(tooltip);

    let hover = -1;
    let result = draw(canvas, data, hover);
    if (canvas.dataset.chart === 'pie' && result.labels.length > 0) {
        box.after(buildLegend(result));
    }

    // Redraw whenever the canvas changes size: window resizes, phone rotation, a scrollbar appearing…
    new ResizeObserver(() => { result = draw(canvas, data, hover); }).observe(canvas);

    function showTooltip(event) {
        const rect = canvas.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;
        const index = result.hitTest(x, y);
        if (index !== hover) { // only redraw when the highlighted mark changes
            hover = index;
            result = draw(canvas, data, hover);
        }
        if (index < 0) {
            tooltip.hidden = true;
            return;
        }
        const value = result.values[index];
        tipValue.textContent = numberFormat.format(value)
            + (result.total ? ' (' + percentFormat.format(value / result.total) + ')' : '');
        tipText.textContent = result.labels[index]; // SECURITY: textContent again
        tipKey.style.background = result.colors[index];
        tooltip.hidden = false;
        // Sit beside the pointer, flipping to its left near the right edge of the chart.
        const left = x + 16 + tooltip.offsetWidth > rect.width ? x - 16 - tooltip.offsetWidth : x + 16;
        tooltip.style.left = Math.max(0, left) + 'px';
        tooltip.style.top = Math.max(0, y - tooltip.offsetHeight - 8) + 'px';
    }

    canvas.addEventListener('pointermove', showTooltip);
    canvas.addEventListener('pointerdown', showTooltip); // a tap on touch screens
    canvas.addEventListener('pointerleave', (event) => {
        if (event.pointerType === 'touch') return; // on touch, keep the tooltip until the next tap
        hover = -1;
        tooltip.hidden = true;
        result = draw(canvas, data, hover);
    });
}

document.querySelectorAll('canvas[data-chart]').forEach(setUpChart);
