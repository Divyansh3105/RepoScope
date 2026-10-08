// Canvas charts (bar, hbar, line, pie). PHP prints <canvas data-chart="bar" data-source="chart-2">
// plus a <script type="application/json" id="chart-2"> with {title, labels, values}.
// Each draw function returns {labels, values, slots, anchor(i), hitTest(x, y)} for the tooltip.
"use strict";

const numberFormat = new Intl.NumberFormat("en-US", {
  maximumFractionDigits: 2,
});
const smallFormat = new Intl.NumberFormat("en-US", {
  maximumSignificantDigits: 3,
});
// same rule as format_cell() in layout.php: 3 significant digits below 1
const formatNumber = (value) =>
  (value !== 0 && Math.abs(value) < 1 ? smallFormat : numberFormat).format(
    value,
  );
const percentFormat = new Intl.NumberFormat("en-US", {
  style: "percent",
  maximumFractionDigits: 1,
});


// colours and font come from the CSS variables in style.css
function chartTheme() {
  const css = getComputedStyle(document.documentElement);
  const get = (name) => css.getPropertyValue(name).trim();
  return {
    series: [1, 2, 3, 4, 5, 6, 7, 8].map((n) => get("--chart-" + n)),
    other: get("--chart-other"),
    grid: get("--chart-grid"),
    axis: get("--chart-axis"),
    label: get("--chart-text"),
    lift: get("--chart-lift"),
    ink: get("--ink"),
    surface: get("--panel"),
    plate: get("--plate"),
    font: get("--font"),
  };
}

function lineClass(slot) {
  return slot < 8 ? "line-" + (slot + 1) : "line-other";
}

// keep in sync with line_code() in layout.php
function lineCode(label) {
  const text = String(label).trim();
  const words = text.split(/[\s_-]+/).filter(Boolean);
  if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
  if (/^[A-Z0-9#+]+$/.test(text)) return text.slice(0, 2);
  const capitals = text.replace(/[^A-Z]/g, "");
  if (capitals.length >= 2) return capitals.slice(0, 2);
  return text.charAt(0).toUpperCase() + text.charAt(1).toLowerCase();
}

// scale the backing buffer by devicePixelRatio so charts aren't blurry on hi-dpi screens,
// then draw in CSS pixels
function prepareCanvas(canvas) {
  const ratio = window.devicePixelRatio || 1;
  const width = canvas.clientWidth;
  const height = canvas.clientHeight;
  canvas.width = Math.round(width * ratio);
  canvas.height = Math.round(height * ratio);
  const ctx = canvas.getContext("2d");
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
  return { ctx, width, height };
}

function fitText(ctx, text, maxWidth) {
  if (ctx.measureText(text).width <= maxWidth) return text;
  while (text.length > 1 && ctx.measureText(text + "…").width > maxWidth) {
    text = text.slice(0, -1);
  }
  return text + "…";
}

// round the axis out to steps of 1, 2 or 5 * 10^n (0, 50, 100 rather than 0, 47.3, 94.6)
function niceScale(min, max, wholeNumbers) {
  min = Math.min(0, min);
  max = Math.max(0, max);
  if (min === max) max = 1; // all zeros
  const rough = (max - min) / 4; // ~4 gridlines
  const magnitude = Math.pow(10, Math.floor(Math.log10(rough)));
  const fraction = rough / magnitude;
  let step =
    (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) *
    magnitude;
  if (wholeNumbers) step = Math.max(1, step);
  return {
    min: Math.floor(min / step) * step,
    max: Math.ceil(max / step) * step,
    step,
  };
}

// gridlines + axis labels for bar and line charts. Returns the plot area, band width and y(value).
function drawAxes(ctx, width, height, data, theme) {
  ctx.font = "12px " + theme.font;
  const scale = niceScale(
    Math.min(...data.values),
    Math.max(...data.values),
    data.values.every(Number.isInteger),
  );
  const ticks = [];
  for (let i = 0; i <= Math.round((scale.max - scale.min) / scale.step); i++) {
    const value = scale.min + i * scale.step;
    ticks.push(Math.abs(value) < 1e-9 ? 0 : value); // float noise
  }

  // labels go diagonal (and get shortened) when they don't fit flat;
  // on crowded charts only every nth one is drawn
  const top = 12;
  const right = 16;
  let left =
    Math.max(...ticks.map((v) => ctx.measureText(formatNumber(v)).width)) + 14;
  let band = (width - left - right) / data.labels.length;
  const flat = data.labels.every(
    (label) => ctx.measureText(String(label)).width <= band - 8,
  );
  const labels = data.labels.map((label) =>
    flat ? String(label) : fitText(ctx, String(label), 110),
  );
  if (!flat) {
    // diagonal labels stick out to the left, make room for the first one
    left = Math.max(
      left,
      ctx.measureText(labels[0]).width * Math.SQRT1_2 - band / 2 + 4,
    );
    band = (width - left - right) / data.labels.length;
  }
  const longest = Math.max(
    ...labels.map((label) => ctx.measureText(label).width),
  );
  const bottom = flat ? 28 : longest * Math.SQRT1_2 + 20;
  const plotWidth = width - left - right;
  const plotHeight = height - top - bottom;
  const y = (value) =>
    top +
    plotHeight -
    ((value - scale.min) / (scale.max - scale.min)) * plotHeight;

  ctx.lineWidth = 1;
  ctx.textAlign = "right";
  ctx.textBaseline = "middle";
  for (const value of ticks) {
    const lineY = Math.round(y(value)) + 0.5; // crisp 1px line
    ctx.strokeStyle = value === 0 ? theme.axis : theme.grid;
    ctx.beginPath();
    ctx.moveTo(left, lineY);
    ctx.lineTo(width - right, lineY);
    ctx.stroke();
    ctx.fillStyle = theme.label;
    ctx.fillText(formatNumber(value), left - 8, lineY);
  }

  const every = flat ? 1 : Math.ceil(18 / band);
  labels.forEach((label, i) => {
    if (i % every !== 0) return;
    ctx.save();
    ctx.translate(left + band * (i + 0.5), top + plotHeight + 10);
    if (flat) {
      ctx.textAlign = "center";
      ctx.textBaseline = "top";
    } else {
      ctx.rotate(-Math.PI / 4);
      ctx.textAlign = "right";
      ctx.textBaseline = "middle";
    }
    ctx.fillText(label, 0, 0);
    ctx.restore();
  });

  return { left, top, plotWidth, plotHeight, band, y };
}

// index of the column under the pointer, or -1
function bandAt(layout, count, x, y) {
  const inside =
    x >= layout.left &&
    x <= layout.left + layout.plotWidth &&
    y >= layout.top &&
    y <= layout.top + layout.plotHeight;
  return inside
    ? Math.min(count - 1, Math.floor((x - layout.left) / layout.band))
    : -1;
}

function drawEmpty(ctx, width, height, theme) {
  ctx.font = "14px " + theme.font;
  ctx.fillStyle = theme.label;
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  ctx.fillText("No data to show", width / 2, height / 2);
  return {
    labels: [],
    values: [],
    slots: [],
    anchor: () => ({ x: 0, y: 0 }),
    hitTest: () => -1,
  };
}


// progress is 0-1, or an array with one value per bar (for the staggered draw-in)
function drawBar(canvas, data, hover = -1, progress = 1) {
  const { ctx, width, height } = prepareCanvas(canvas);
  const theme = chartTheme();
  if (data.labels.length === 0) return drawEmpty(ctx, width, height, theme);

  const layout = drawAxes(ctx, width, height, data, theme);
  // max 24px wide, at least a 2px gap
  const barWidth = Math.max(
    1,
    Math.min(24, layout.band * 0.7, layout.band - 2),
  );
  const zero = layout.y(0);

  data.values.forEach((value, i) => {
    const grown = value * (Array.isArray(progress) ? progress[i] : progress);
    const x = layout.left + layout.band * i + (layout.band - barWidth) / 2;
    const end = layout.y(grown);
    const length = Math.abs(zero - end);
    if (length < 0.5) return;
    const radius = Math.min(4, barWidth / 2, length);
    // round only the end away from zero
    ctx.beginPath();
    ctx.roundRect(
      x,
      Math.min(zero, end),
      barWidth,
      length,
      value >= 0 ? [radius, radius, 0, 0] : [0, 0, radius, radius],
    );
    ctx.fillStyle = theme.series[0];
    ctx.fill();
    if (i === hover) {
      ctx.fillStyle = theme.lift;
      ctx.fill();
    }
  });

  return {
    labels: data.labels.map(String),
    values: data.values,
    slots: data.values.map(() => 0),
    anchor: (i) => ({
      x: layout.left + layout.band * (i + 0.5),
      y: Math.min(zero, layout.y(data.values[i])),
    }),
    hitTest: (x, y) => bandAt(layout, data.labels.length, x, y),
  };
}

// horizontal bars with the label on the left and the value at the end, for long names
function drawHBar(canvas, data, hover = -1, progress = 1) {
  const { ctx, width, height } = prepareCanvas(canvas);
  const theme = chartTheme();
  if (data.labels.length === 0) return drawEmpty(ctx, width, height, theme);

  ctx.font = "13px " + theme.font;
  const max = Math.max(...data.values, 0) || 1;
  const names = data.labels.map(String);
  const labelWidth = Math.min(
    width * 0.4,
    Math.max(...names.map((name) => ctx.measureText(name).width)),
  );
  const left = labelWidth + 12;
  const plotWidth = Math.max(
    10,
    width - left - ctx.measureText(formatNumber(max)).width - 10,
  );
  const band = height / names.length;
  const barHeight = Math.max(2, Math.min(16, band * 0.6));
  const length = (value) => (Math.max(0, value) / max) * plotWidth;

  ctx.strokeStyle = theme.axis;
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(Math.round(left) + 0.5, 0);
  ctx.lineTo(Math.round(left) + 0.5, height);
  ctx.stroke();

  ctx.textBaseline = "middle";
  data.values.forEach((value, i) => {
    const share = Array.isArray(progress) ? progress[i] : progress;
    const middle = band * (i + 0.5);
    const bar = length(value * share);

    ctx.textAlign = "right";
    ctx.fillStyle = i === hover ? theme.ink : theme.label;
    ctx.fillText(fitText(ctx, names[i], labelWidth), labelWidth, middle);

    if (bar >= 0.5) {
      const radius = Math.min(4, barHeight / 2, bar);
      ctx.beginPath();
      ctx.roundRect(left, middle - barHeight / 2, bar, barHeight, [
        0,
        radius,
        radius,
        0,
      ]);
      ctx.fillStyle = theme.series[0];
      ctx.fill();
      if (i === hover) {
        ctx.fillStyle = theme.lift;
        ctx.fill();
      }
    }
    // value label fades in with the bar
    ctx.globalAlpha = share;
    ctx.textAlign = "left";
    ctx.fillStyle = i === hover ? theme.ink : theme.label;
    ctx.fillText(formatNumber(value), left + bar + 6, middle);
    ctx.globalAlpha = 1;
  });

  return {
    labels: names,
    values: data.values,
    slots: data.values.map(() => 0),
    anchor: (i) => ({
      x: left + length(data.values[i]),
      y: band * (i + 0.5) - barHeight / 2,
    }),
    hitTest: (x, y) =>
      x >= 0 && x <= width && y >= 0 && y < height
        ? Math.min(names.length - 1, Math.floor(y / band))
        : -1,
  };
}

// line with a hollow marker on each point; progress draws it from the left
function drawLine(canvas, data, hover = -1, progress = 1) {
  const { ctx, width, height } = prepareCanvas(canvas);
  const theme = chartTheme();
  if (data.labels.length === 0) return drawEmpty(ctx, width, height, theme);

  const layout = drawAxes(ctx, width, height, data, theme);
  const points = data.values.map((value, i) => ({
    x: layout.left + layout.band * (i + 0.5),
    y: layout.y(value),
  }));

  if (hover >= 0) {
    // crosshair
    const x = Math.round(points[hover].x) + 0.5;
    ctx.strokeStyle = theme.axis;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(x, layout.top);
    ctx.lineTo(x, layout.top + layout.plotHeight);
    ctx.stroke();
  }

  // cumulative length along the line, so the draw-in can stop partway
  const distance = [0];
  for (let i = 1; i < points.length; i++) {
    distance.push(
      distance[i - 1] +
        Math.hypot(
          points[i].x - points[i - 1].x,
          points[i].y - points[i - 1].y,
        ),
    );
  }
  const drawn = distance[distance.length - 1] * progress;

  ctx.strokeStyle = theme.series[0];
  ctx.lineWidth = 3;
  ctx.lineJoin = "round";
  ctx.lineCap = "round";
  ctx.beginPath();
  points.forEach((p, i) => {
    if (i === 0) {
      ctx.moveTo(p.x, p.y);
    } else if (distance[i] <= drawn) {
      ctx.lineTo(p.x, p.y);
    } else if (distance[i - 1] < drawn) {
      // partial segment
      const t = (drawn - distance[i - 1]) / (distance[i] - distance[i - 1]);
      ctx.lineTo(
        points[i - 1].x + (p.x - points[i - 1].x) * t,
        points[i - 1].y + (p.y - points[i - 1].y) * t,
      );
    }
  });
  ctx.stroke();

  // point markers (only the hovered one when points are too close)
  points.forEach((p, i) => {
    if (distance[i] > drawn + 0.5) return;
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
    hitTest: (x, y) => bandAt(layout, data.labels.length, x, y),
  };
}

// donut, largest slice first from 12 o'clock. Past 8 slices the rest go into "Other".
function drawPie(canvas, data, hover = -1, progress = 1) {
  const { ctx, width, height } = prepareCanvas(canvas);
  const theme = chartTheme();

  let slices = data.labels
    .map((label, i) => ({ label: String(label), value: data.values[i] }))
    .filter((slice) => slice.value > 0)
    .sort((a, b) => b.value - a.value);
  if (slices.length > 8) {
    const rest = slices.slice(8);
    slices = slices.slice(0, 8);
    slices.push({
      label: "Other",
      value: rest.reduce((sum, slice) => sum + slice.value, 0),
    });
  }
  const total = slices.reduce((sum, slice) => sum + slice.value, 0);
  if (total === 0) return drawEmpty(ctx, width, height, theme);
  const slots = slices.map((slice, i) => i);

  const cx = width / 2;
  const cy = height / 2;
  const outer = Math.max(20, Math.min(width, height) / 2 - 10); // room for the hover offset
  const inner = outer * 0.6;
  let angle = -Math.PI / 2;
  const arcs = slices.map((slice) => {
    const start = angle;
    angle += (slice.value / total) * Math.PI * 2;
    return { start, end: angle };
  });
  const sweepEnd = -Math.PI / 2 + Math.PI * 2 * progress;

  arcs.forEach((arc, i) => {
    const end = Math.min(arc.end, sweepEnd);
    if (end <= arc.start) return;
    const radius = i === hover ? outer + 6 : outer;
    ctx.beginPath();
    ctx.arc(cx, cy, radius, arc.start, end);
    ctx.arc(cx, cy, inner, end, arc.start, true);
    ctx.closePath();
    ctx.fillStyle = i < 8 ? theme.series[i] : theme.other;
    ctx.fill();
    ctx.lineWidth = 2; // gap between slices
    ctx.strokeStyle = theme.surface;
    ctx.stroke();
  });

  // total in the middle
  ctx.textAlign = "center";
  ctx.fillStyle = theme.ink;
  ctx.font = "600 22px " + theme.font;
  ctx.textBaseline = "alphabetic";
  ctx.fillText(formatNumber(total), cx, cy + 4);
  ctx.fillStyle = theme.label;
  ctx.font = "12px " + theme.font;
  ctx.textBaseline = "top";
  ctx.fillText("total", cx, cy + 10);

  return {
    labels: slices.map((slice) => slice.label),
    values: slices.map((slice) => slice.value),
    slots,
    total,
    anchor(i) {
      const mid = (arcs[i].start + arcs[i].end) / 2;
      const r = (inner + outer) / 2;
      return { x: cx + Math.cos(mid) * r, y: cy + Math.sin(mid) * r };
    },
    hitTest(x, y) {
      const distance = Math.hypot(x - cx, y - cy);
      if (distance < inner || distance > outer + 6) return -1;
      let pointer = Math.atan2(y - cy, x - cx); // -PI..PI from 3 o'clock
      if (pointer < -Math.PI / 2) pointer += Math.PI * 2;
      return arcs.findIndex((arc) => pointer >= arc.start && pointer < arc.end);
    },
  };
}


const drawFunctions = {
  bar: drawBar,
  hbar: drawHBar,
  line: drawLine,
  pie: drawPie,
};
const perBar = (type) => type === "bar" || type === "hbar";

function makeBullet(label, slot) {
  const bullet = document.createElement("span");
  bullet.className = "bullet " + lineClass(slot);
  bullet.setAttribute("aria-hidden", "true");
  bullet.textContent = slot < 8 ? lineCode(label) : "+";
  return bullet;
}

function buildLegend(result) {
  const list = document.createElement("ul");
  list.className = "chart-legend";
  result.labels.forEach((label, i) => {
    const name = document.createElement("span");
    name.className = "legend-label";
    name.textContent = label; // labels are user data, never innerHTML
    const share = document.createElement("span");
    share.className = "legend-value";
    share.textContent = percentFormat.format(result.values[i] / result.total);
    const item = document.createElement("li");
    item.append(makeBullet(label, result.slots[i]), name, share);
    list.append(item);
  });
  return list;
}

// draw-in animation, only when PHP marks the section data-animate (fresh data) and
// never with prefers-reduced-motion
function drawIn(type, reveal, redraw) {
  const mm = gsap.matchMedia();
  mm.add(
    {
      motion: "(prefers-reduced-motion: no-preference)",
      reduce: "(prefers-reduced-motion: reduce)",
    },
    (context) => {
      const targets = Array.isArray(reveal) ? reveal : [reveal];
      if (context.conditions.reduce) {
        targets.forEach((target) => {
          target.p = 1;
        });
        redraw();
        return;
      }
      const timeline = gsap.timeline({
        defaults: { ease: "power4.out" },
        onUpdate: redraw,
      });
      if (perBar(type)) {
        // 40ms stagger, capped at 300ms total
        const each = Math.min(0.04, 0.3 / Math.max(1, targets.length - 1));
        timeline.to(targets, { p: 1, duration: 0.5, stagger: each });
      } else if (type === "line") {
        timeline.to(targets, { p: 1, duration: 0.7, ease: "power2.inOut" });
      } else {
        timeline.to(targets, { p: 1, duration: 0.6 });
      }
    },
  );
}

function setUpChart(canvas) {
  const type = canvas.dataset.chart;
  const draw = drawFunctions[type];
  const source = document.getElementById(canvas.dataset.source);
  if (!draw || !source) return;
  const data = JSON.parse(source.textContent);

  const animate =
    Boolean(canvas.closest("[data-animate]")) && typeof gsap !== "undefined";
  const start = animate ? 0 : 1;
  const reveal = perBar(type)
    ? data.values.map(() => ({ p: start }))
    : { p: start };

  // 32px per row
  if (type === "hbar")
    canvas.style.height = Math.max(3, data.labels.length) * 32 + "px";
  const progress = () =>
    Array.isArray(reveal) ? reveal.map((r) => r.p) : reveal.p;

  const box = canvas.parentElement; // .chart-canvas
  const tooltip = document.createElement("div");
  tooltip.className = "chart-tooltip";
  tooltip.setAttribute("aria-hidden", "true"); // the data table covers screen readers
  const tipValue = document.createElement("strong");
  tipValue.className = "tip-value";
  const tipLabel = document.createElement("span");
  tipLabel.className = "tip-label";
  const tipKey = document.createElement("span");
  const tipText = document.createElement("span");
  tipLabel.append(tipKey, tipText);
  tooltip.append(tipValue, tipLabel);
  box.append(tooltip);

  let hover = -1;
  let result;
  const redraw = () => {
    result = draw(canvas, data, hover, progress());
  };
  redraw();
  if (type === "pie" && result.labels.length > 0) {
    box.after(buildLegend(drawPie(canvas, data, -1, 1))); // build the legend from the finished pie
    redraw();
  }

  // redraw on resize, and once the web font is ready (canvas text won't update by itself)
  new ResizeObserver(redraw).observe(canvas);
  document.fonts.ready.then(redraw);
  if (animate) drawIn(type, reveal, redraw);

  function hideTooltip() {
    tooltip.dataset.show = "false";
  }

  // if hidden, jump straight to the new spot and fade in; if visible, slide over
  function showTooltip(index) {
    const value = result.values[index];
    tipValue.textContent =
      formatNumber(value) +
      (result.total
        ? " (" + percentFormat.format(value / result.total) + ")"
        : "");
    tipText.textContent = result.labels[index];
    tipKey.className = "tip-key " + lineClass(result.slots[index]);

    const mark = result.anchor(index);
    const width = tooltip.offsetWidth;
    const height = tooltip.offsetHeight;
    const x = Math.min(
      Math.max(0, mark.x - width / 2),
      canvas.clientWidth - width,
    );
    const y = mark.y - height - 12 >= 0 ? mark.y - height - 12 : mark.y + 12; // flip below near the top edge
    const showing = tooltip.dataset.show === "true";
    if (!showing) tooltip.style.transition = "none";
    tooltip.style.setProperty("--tt-x", x + "px");
    tooltip.style.setProperty("--tt-y", y + "px");
    if (!showing) {
      void tooltip.offsetWidth; // flush the jump before re-enabling the transition
      tooltip.style.transition = "";
    }
    tooltip.dataset.show = "true";
  }

  function onPointer(event) {
    const rect = canvas.getBoundingClientRect();
    const index = result.hitTest(
      event.clientX - rect.left,
      event.clientY - rect.top,
    );
    if (index === hover) return;
    hover = index;
    redraw();
    if (index < 0) hideTooltip();
    else showTooltip(index);
  }

  canvas.addEventListener("pointermove", onPointer);
  canvas.addEventListener("pointerdown", onPointer); // taps
  canvas.addEventListener("pointerleave", (event) => {
    if (event.pointerType === "touch") return; // keep it open on touch
    hover = -1;
    hideTooltip();
    redraw();
  });
}

document.querySelectorAll("canvas[data-chart]").forEach(setUpChart);
