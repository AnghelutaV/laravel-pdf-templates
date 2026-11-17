// laravel-pdf-templates: charts for WYSIWYG templates, the same as src/Chart.php draws for HTML ones.
// No imports: the browser loads it through the import map, the Node renderer from its source (see server.mjs).
//
// chartModel(type, data, label, values, options) → {width, height, shapes (SVG elements, no text), labels}
// createChartPlugin({ text, svg }) → a pdfme plugin "Chart": a field whose data is a list of rows (or {label: value})
// and whose properties pick the type, the label key, the value keys and the options.

export const CHART_TYPES = ['bar', 'hbar', 'stacked', 'line', 'area', 'pie', 'doughnut'];
export const CHART_COLORS = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#475569'];

const num = (value) => (value !== null && value !== '' && typeof value !== 'boolean' && Number.isFinite(Number(value)) ? Number(value) : 0);
const flag = (value) => !['0', 'false', 'no', 'off', ''].includes(String(value).toLowerCase());
const n2 = (value) => String(Math.round(value * 100) / 100);
const chars = (text) => [...String(text)].length;

// "width=520 title='Sales 2026' colors=#2563eb,#16a34a" → {width: '520', title: 'Sales 2026', …}
export function chartOptions(source) {
    const options = {};
    for (const m of String(source ?? '').matchAll(/(\w+)\s*=\s*(?:"([^"]*)"|'([^']*)'|(\S+))/gu)) {
        options[m[1].toLowerCase()] = m[2] ?? m[3] ?? m[4] ?? '';
    }

    return options;
}

function defaultFormat(value, decimals) {
    const [int, frac] = Math.abs(value).toFixed(decimals).split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

    return (value < 0 ? '-' : '') + grouped + (frac ? ',' + frac : '');
}

function getPath(row, path) {
    return String(path).split('.').reduce((value, key) => (value && typeof value === 'object' ? value[key] : undefined), row);
}

function series(data, label, values) {
    if (typeof data === 'string') {
        try { data = JSON.parse(data); } catch { return [[], []]; }
    }
    if (!data || typeof data !== 'object') return [[], []];
    if (!Array.isArray(data)) {
        return [Object.keys(data), [{ name: values || '', values: Object.values(data).map(num) }]];
    }
    const rows = data.filter((row) => row && typeof row === 'object' && !Array.isArray(row));
    if (!rows.length) return [data.map((_, i) => String(i + 1)), [{ name: '', values: data.map(num) }]];

    // Keys set but not in the rows (a typo, or values typed in place of key names): the numeric keys of the rows;
    // a label key not in the rows: their first text key
    const numeric = (key) => Number.isFinite(Number(rows[0][key])) && typeof rows[0][key] !== 'boolean' && rows[0][key] !== '';
    if (label && !rows.some((row) => row[label] !== undefined)) label = Object.keys(rows[0]).find((key) => !numeric(key)) ?? '';
    let keys = values && String(values).trim() !== ''
        ? String(values).split(',').map((key) => key.trim()).filter((key) => key && rows.some((row) => getPath(row, key) !== undefined))
        : [];
    if (!keys.length) keys = Object.keys(rows[0]).filter((key) => key !== label && numeric(key));
    const categories = rows.map((row, i) => (label && row[label] !== undefined ? String(row[label]) : String(i + 1)));

    return [categories, keys.map((key) => ({ name: key, values: rows.map((row) => num(getPath(row, key))) }))];
}

function scale(min, max) {
    if (max <= min) max = min + 1;
    const raw = (max - min) / 5;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const r = raw / magnitude;
    const step = magnitude * (r <= 1 ? 1 : r <= 2 ? 2 : r <= 2.5 ? 2.5 : r <= 5 ? 5 : 10);

    return [Math.floor(min / step) * step, Math.ceil(max / step) * step, step];
}

function canvas() {
    const c = { shapes: '', labels: [] };
    c.label = (x, y, w, align, text, size, color, bold = false) => c.labels.push({ x, y, w: Math.max(1, w), align, text: String(text), size, color, bold });
    c.line = (x1, y1, x2, y2, color, width = 1) => { c.shapes += `<line x1="${n2(x1)}" y1="${n2(y1)}" x2="${n2(x2)}" y2="${n2(y2)}" stroke="${color}" stroke-width="${width}"/>`; };
    c.rect = (x, y, w, h, color, radius = 0) => {
        if (w > 0 && h > 0) c.shapes += `<rect x="${n2(x)}" y="${n2(y)}" width="${n2(w)}" height="${n2(h)}"${radius ? ` rx="${radius}"` : ''} fill="${color}"/>`;
    };
    c.circle = (x, y, r, color) => { c.shapes += `<circle cx="${n2(x)}" cy="${n2(y)}" r="${r}" fill="${color}" stroke="#ffffff" stroke-width="1"/>`; };
    const pts = (points) => points.map(([x, y]) => `${n2(x)},${n2(y)}`).join(' ');
    // A path, not <polyline>: pdf-lib's SVG drawing (pdfme) skips polylines
    c.polyline = (points, color, width) => { c.shapes += `<path d="M${points.map(([x, y]) => `${n2(x)} ${n2(y)}`).join(' L')}" fill="none" stroke="${color}" stroke-width="${width}" stroke-linejoin="round"/>`; };
    c.polygon = (points, color, opacity = 1) => { c.shapes += `<polygon points="${pts(points)}" fill="${color}" fill-opacity="${opacity}"/>`; };
    c.slice = (cx, cy, r, inner, from, to, color) => {
        if (to - from >= 2 * Math.PI - 1e-6) {
            c.slice(cx, cy, r, inner, from, from + Math.PI, color);
            c.slice(cx, cy, r, inner, from + Math.PI, to, color);

            return;
        }
        const large = to - from > Math.PI ? 1 : 0;
        const p = (radius, angle) => `${n2(cx + Math.cos(angle) * radius)} ${n2(cy + Math.sin(angle) * radius)}`;
        let d = `M${p(r, from)} A${n2(r)} ${n2(r)} 0 ${large} 1 ${p(r, to)}`;
        d += inner > 0 ? ` L${p(inner, to)} A${n2(inner)} ${n2(inner)} 0 ${large} 0 ${p(inner, from)} Z` : ` L${n2(cx)} ${n2(cy)} Z`;
        c.shapes += `<path d="${d}" fill="${color}" stroke="#ffffff" stroke-width="1.5"/>`;
    };

    return c;
}

/**
 * The chart as shapes and labels (x / y = anchor of the text's middle line, w = box width, align left|center|right),
 * in px of a width × height box. Null when there is nothing to draw. Mirrors Chart::model() in PHP.
 */
export function chartModel(type, data, label, values, options = {}, format = defaultFormat) {
    type = CHART_TYPES.includes(String(type).toLowerCase()) ? String(type).toLowerCase() : 'bar';
    const [categories, list] = series(data, label, values);
    if (!categories.length || !list.length) return null;

    const colors = String(options.colors ?? '').split(',').map((color) => color.trim()).filter((color) => /^#[0-9a-f]{3,8}$/i.test(color));
    const o = {
        width: Math.max(80, Math.min(2000, Math.round(num(options.width) || 480))),
        height: Math.max(60, Math.min(2000, Math.round(num(options.height) || 240))),
        title: String(options.title ?? ''),
        colors: colors.length ? colors : CHART_COLORS,
        decimals: Math.max(0, Math.min(6, Math.round(num(options.decimals)))),
        prefix: String(options.prefix ?? ''),
        suffix: String(options.suffix ?? ''),
        min: options.min !== undefined && options.min !== '' && Number.isFinite(Number(options.min)) ? Number(options.min) : null,
        max: options.max !== undefined && options.max !== '' && Number.isFinite(Number(options.max)) ? Number(options.max) : null,
    };
    o.legend = options.legend !== undefined && options.legend !== '' ? flag(options.legend) : (list.length > 1 || type === 'pie' || type === 'doughnut');
    o.values = options.values !== undefined && options.values !== '' ? flag(options.values) : (list.length === 1 && categories.length <= 12 && type !== 'line' && type !== 'area');
    const color = (i) => o.colors[i % o.colors.length];
    const money = (v) => o.prefix + format(v, o.decimals) + o.suffix;

    const c = canvas();
    let top = 6;
    if (o.title) {
        c.label(o.width / 2, 11, o.width - 8, 'center', o.title, 13, '#111827', true);
        top = 26;
    }

    if (type === 'pie' || type === 'doughnut') {
        const vals = list[0].values.map((v) => Math.max(0, v));
        const total = vals.reduce((a, b) => a + b, 0);
        if (total > 0) {
            const legendWidth = o.legend ? Math.min(o.width * 0.45, Math.max(...categories.map(chars)) * 6.5 + 70) : 0;
            const size = Math.min(o.width - legendWidth - 12, o.height - top - 6);
            const r = size / 2;
            const cx = 6 + r + Math.max(0, (o.width - legendWidth - 12 - size) / 2);
            const cy = top + (o.height - top) / 2;
            const inner = type === 'doughnut' ? r * 0.55 : 0;
            let angle = -Math.PI / 2;
            vals.forEach((v, i) => {
                if (v <= 0) return;
                const sweep = v / total * 2 * Math.PI;
                c.slice(cx, cy, r, inner, angle, angle + sweep, color(i));
                if (o.values && sweep > 0.3) {
                    const mid = angle + sweep / 2;
                    const at = inner ? (r + inner) / 2 : r * 0.62;
                    c.label(cx + Math.cos(mid) * at, cy + Math.sin(mid) * at, 50, 'center', Math.round(v / total * 100) + '%', 10, '#ffffff', true);
                }
                angle += sweep;
            });
            if (o.legend) {
                const x = o.width - legendWidth + 4;
                const rowHeight = Math.min(18, (o.height - top) / Math.max(1, categories.length));
                let y = cy - rowHeight * categories.length / 2 + rowHeight / 2;
                categories.forEach((category, i) => {
                    c.rect(x, y - 5, 10, 10, color(i), 2);
                    c.label(x + 15, y, legendWidth - 22, 'left', `${category}  ${money(list[0].values[i])}`, 10, '#374151');
                    y += rowHeight;
                });
            }
        }

        return { width: o.width, height: o.height, shapes: c.shapes, labels: c.labels };
    }

    const bottomEdge = o.height - (o.legend ? 18 : 0);
    const stacked = type === 'stacked';
    const idx = categories.map((_, i) => i);
    const all = stacked ? idx.map((i) => list.reduce((s, x) => s + Math.max(0, x.values[i]), 0)) : list.flatMap((x) => x.values);
    const negatives = stacked ? idx.map((i) => list.reduce((s, x) => s + Math.min(0, x.values[i]), 0)) : all;
    const [min, max, step] = scale(o.min ?? Math.min(0, ...negatives), o.max ?? Math.max(0, ...all));
    const ticks = [];
    for (let v = min; v <= max + step / 1000; v += step) ticks.push(Math.round(v * 1e10) / 1e10);
    const tickText = (v) => o.prefix + format(v, step < 1 ? Math.max(o.decimals, 1) : o.decimals) + o.suffix;
    const count = categories.length;

    if (type === 'hbar') {
        const labelWidth = Math.min(o.width * 0.35, Math.max(30, Math.max(...categories.map(chars)) * 6 + 8));
        const [left, right, plotTop, plotBottom] = [labelWidth, o.width - 12, top + 2, bottomEdge - 18];
        const x = (v) => left + (v - min) / (max - min) * (right - left);
        ticks.forEach((t) => {
            c.line(x(t), plotTop, x(t), plotBottom, '#e5e7eb');
            c.label(x(t), plotBottom + 9, 60, 'center', tickText(t), 9, '#6b7280');
        });
        c.line(x(0), plotTop, x(0), plotBottom, '#9ca3af');
        const band = (plotBottom - plotTop) / count;
        const barHeight = band * 0.7 / list.length;
        categories.forEach((category, i) => {
            c.label(left - 6, plotTop + band * (i + 0.5), labelWidth - 8, 'right', category, 10, '#374151');
            list.forEach((serie, s) => {
                const v = serie.values[i];
                const y = plotTop + band * i + band * 0.15 + barHeight * s;
                c.rect(Math.min(x(0), x(v)), y, Math.abs(x(v) - x(0)), barHeight, color(s));
                if (o.values) c.label(x(v) + (v < 0 ? -4 : 4), y + barHeight / 2, 70, v < 0 ? 'right' : 'left', money(v), 9, '#374151');
            });
        });
    } else {
        const axisWidth = Math.max(...ticks.map((t) => chars(tickText(t)))) * 6 + 10;
        const [left, right, plotTop, plotBottom] = [axisWidth, o.width - 8, top + (o.values ? 12 : 4), bottomEdge - 20];
        const y = (v) => plotBottom - (v - min) / (max - min) * (plotBottom - plotTop);
        ticks.forEach((t) => {
            c.line(left, y(t), right, y(t), '#e5e7eb');
            c.label(left - 5, y(t), axisWidth - 6, 'right', tickText(t), 9, '#6b7280');
        });
        c.line(left, y(0), right, y(0), '#9ca3af');
        const band = (right - left) / count;
        const longest = Math.max(...categories.map(chars)) * 6 + 6;
        const every = Math.max(1, Math.ceil(longest / band));
        categories.forEach((category, i) => {
            if (i % every === 0) c.label(left + band * (i + 0.5), plotBottom + 10, band * every, 'center', category, 10, '#374151');
        });

        if (type === 'line' || type === 'area') {
            const px = (i) => left + band * (i + 0.5);
            list.forEach((serie, s) => {
                const points = serie.values.map((v, i) => [px(i), y(v)]);
                if (type === 'area') {
                    const base = y(Math.max(min, 0));
                    c.polygon([[points[0][0], base], ...points, [points[points.length - 1][0], base]], color(s), 0.18);
                }
                c.polyline(points, color(s), 2);
                points.forEach(([px1, py1]) => c.circle(px1, py1, 2.6, color(s)));
                if (o.values) points.forEach(([px1, py1], i) => c.label(px1, py1 - 10, 60, 'center', money(serie.values[i]), 9, '#374151'));
            });
        } else {
            const groupWidth = band * 0.72;
            const barWidth = stacked ? groupWidth : groupWidth / list.length;
            categories.forEach((category, i) => {
                let up = 0;
                let down = 0;
                list.forEach((serie, s) => {
                    const v = serie.values[i];
                    const x = left + band * i + (band - groupWidth) / 2 + (stacked ? 0 : barWidth * s);
                    let from = 0;
                    let to = v;
                    if (stacked) {
                        from = v >= 0 ? up : down;
                        to = from + v;
                        if (v >= 0) up = to; else down = to;
                    }
                    c.rect(x, Math.min(y(from), y(to)), barWidth, Math.abs(y(to) - y(from)), color(s));
                    if (o.values && !stacked) c.label(x + barWidth / 2, v < 0 ? y(to) + 8 : y(to) - 7, Math.max(barWidth, 40), 'center', money(v), 9, '#374151');
                });
                if (o.values && stacked) c.label(left + band * (i + 0.5), y(up) - 7, band, 'center', money(up), 9, '#374151');
            });
        }
    }

    if (o.legend) {
        const names = list.map((serie) => serie.name);
        const widths = names.map((name) => chars(name) * 6.2 + 26);
        let x = Math.max(4, (o.width - widths.reduce((a, b) => a + b, 0)) / 2);
        const y = o.height - 9;
        names.forEach((name, i) => {
            c.rect(x, y - 5, 10, 10, color(i), 2);
            c.label(x + 14, y, widths[i] - 14, 'left', name, 10, '#374151');
            x += widths[i];
        });
    }

    return { width: o.width, height: o.height, shapes: c.shapes, labels: c.labels };
}

const escapeXml = (text) => String(text).replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));

// The whole chart as one SVG, texts included: for the designer and previews (the browser has the fonts)
export function chartSvg(model, fontFamily = 'Helvetica, Arial, sans-serif') {
    const texts = model.labels.map((l) => {
        const anchor = l.align === 'center' ? 'middle' : l.align === 'right' ? 'end' : 'start';

        return `<text x="${n2(l.x)}" y="${n2(l.y)}" font-size="${l.size}" fill="${l.color}" text-anchor="${anchor}" dominant-baseline="central"${l.bold ? ' font-weight="bold"' : ''}>${escapeXml(l.text)}</text>`;
    }).join('');

    return `<svg xmlns="http://www.w3.org/2000/svg" width="${model.width}" height="${model.height}" viewBox="0 0 ${model.width} ${model.height}" font-family="${fontFamily}">${model.shapes}${texts}</svg>`;
}

// Shown in the designer while the field has no data yet
const SAMPLE = [{ label: 'Jan', value: 12 }, { label: 'Feb', value: 19 }, { label: 'Mar', value: 9 }, { label: 'Apr', value: 24 }, { label: 'May', value: 17 }];

const PX_PER_MM = 96 / 25.4;
const PT_PER_PX = 0.75;

// The field's own properties as chartModel() options; the chart is laid out at the field's size
function fieldModel(schema, value, format) {
    const options = { ...chartOptions(schema.chartOptions), width: Math.round(schema.width * PX_PER_MM), height: Math.round(schema.height * PX_PER_MM) };
    if (schema.chartTitle) options.title = schema.chartTitle;
    let model = chartModel(schema.chartType, value, schema.labelKey || null, schema.valueKeys || null, options, format);
    let sample = false;
    if (!model) {
        model = chartModel(schema.chartType, SAMPLE, 'label', 'value', options, format);
        sample = true;
    }

    return { model, sample };
}

/**
 * A pdfme plugin "Chart". In the PDF the shapes go through pdfme's SVG schema and each text through its text schema,
 * so the template's fonts (and their diacritics) are used. format(value, decimals) formats the numbers.
 */
export function createChartPlugin({ text, svg }, format = defaultFormat) {
    return {
        async pdf(arg) {
            const { schema, value } = arg;
            const { model, sample } = fieldModel(schema, value || schema.content, format);
            if (sample) return;
            const mm = (px) => px / PX_PER_MM;
            const base = { rotate: 0, opacity: schema.opacity ?? 1 };

            await svg.pdf({
                ...arg,
                value: `<svg xmlns="http://www.w3.org/2000/svg" width="${model.width}" height="${model.height}" viewBox="0 0 ${model.width} ${model.height}">${model.shapes}</svg>`,
                schema: { ...schema, ...base, type: 'svg' },
            });
            for (const l of model.labels) {
                const height = mm(l.size * 1.3);
                const left = l.align === 'center' ? l.x - l.w / 2 : l.align === 'right' ? l.x - l.w : l.x;
                await text.pdf({
                    ...arg,
                    value: l.text,
                    schema: {
                        ...base,
                        name: `${schema.name}__label`,
                        type: 'text',
                        position: { x: schema.position.x + mm(left), y: schema.position.y + mm(l.y) - height / 2 },
                        width: mm(l.w),
                        height,
                        fontSize: l.size * PT_PER_PX,
                        fontColor: l.color,
                        fontName: schema.fontName,
                        alignment: l.align,
                        verticalAlignment: 'middle',
                        lineHeight: 1,
                        characterSpacing: 0,
                    },
                });
            }
        },
        ui(arg) {
            const { schema, value, rootElement } = arg;
            const { model, sample } = fieldModel(schema, value || schema.content, format);
            rootElement.innerHTML = '';
            const box = document.createElement('div');
            box.style.cssText = `width:100%;height:100%;${sample ? 'opacity:.55;' : ''}`;
            box.innerHTML = chartSvg(model).replace('<svg ', '<svg preserveAspectRatio="none" style="width:100%;height:100%;display:block" ');
            rootElement.appendChild(box);
        },
        propPanel: {
            schema: {
                chartType: {
                    title: 'Type',
                    type: 'string',
                    widget: 'select',
                    props: { options: CHART_TYPES.map((type) => ({ label: type, value: type })) },
                    span: 12,
                },
                chartTitle: { title: 'Title', type: 'string', widget: 'input', span: 12 },
                labelKey: { title: 'Label key (in each row)', type: 'string', widget: 'input', span: 12 },
                valueKeys: { title: 'Value key(s) (in each row: total or a,b)', type: 'string', widget: 'input', span: 12 },
                chartOptions: { title: "Options (colors=#hex,#hex suffix=' lei' legend=1 values=1 decimals=2)", type: 'string', widget: 'input', span: 24 },
            },
            defaultSchema: {
                name: '',
                type: 'chart',
                // The data rows: from the data key named like the field, or these when it has none
                content: JSON.stringify(SAMPLE),
                position: { x: 0, y: 0 },
                width: 120,
                height: 60,
                rotate: 0,
                opacity: 1,
                chartType: 'bar',
                chartTitle: '',
                labelKey: 'label',
                valueKeys: 'value',
                chartOptions: '',
            },
        },
        icon: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    };
}
