// laravel-pdf-templates: data binding for WYSIWYG (pdfme) templates.
//
//   Texts          "Invoice {{ number }} for {{ client.name|upper }}"   {{ qty * price|number:2 }}   {{ note|default:— }}
//   Formulas       {{ sum(items, "total")|number:2 }}   {{ count(items) }}   {{ paid ? "Paid" : "Due" }}
//   Visibility     field.showIf = 'status == "paid" and total > 100'    the field is drawn only when it is true
//   Tables         field.dataColumns = ['name', 'qty', 'qty * price|number:2']   rows of the data are objects
//
// Pure module without imports: the browser loads it through the import map (pdf-templates/placeholders) and the
// Node renderer (renderer/server.mjs) reads this same file, so both render alike. Nothing is eval'ed: expressions
// are parsed here and can only read the data.
//
// pdfme takes the value of a field from the data by the field's name. A text with placeholders is therefore
// rendered here, per document, and passed to pdfme as the value of its own field. In the header / footer
// (basePdf.staticSchema) pdfme's own {name} syntax is used, next to {currentPage} and {totalPages}.

const PLACEHOLDER = /\{\{\s*([^{}]*?)\s*\}\}/g;
const TOKEN = /\s*(?:("(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*')|(\d+(?:\.\d+)?)|(==|!=|>=|<=|&&|\|\||[-+*\/%<>!()?:,])|([A-Za-z_@][\w@]*(?:\.[\w@]+)*))/y;
const KEYWORDS = { and: '&&', or: '||', not: '!' };
const LITERALS = { true: true, false: false, null: null, none: null };

export const hasPlaceholders = (value) => typeof value === 'string' && value.includes('{{') && /\{\{[^{}]+\}\}/.test(value);

const stringify = (value) => {
    if (value === null || value === undefined || value === false) return '';
    // 0.1 + 0.2 → 0.3
    if (typeof value === 'number') return Number.isFinite(value) ? String(Math.round(value * 1e10) / 1e10) : '';
    if (typeof value === 'object') return JSON.stringify(value);

    return String(value);
};

const truthy = (value) => !(value === null || value === undefined || value === false || value === '' || value === 0 || value === '0'
    || value === 'false' || (Array.isArray(value) && !value.length));

const isNumeric = (value) => typeof value === 'number' || (typeof value === 'string' && value.trim() !== '' && !Number.isNaN(Number(value)));
const toNumber = (value) => (isNumeric(value) ? Number(value) : 0);

// "client.name", "items.0.name" in the scopes (innermost first: a table row, then the document)
function lookup(scopes, path) {
    const keys = path.split('.');
    for (const scope of scopes) {
        let value = scope;
        let found = true;
        for (const key of keys) {
            if (value === null || typeof value !== 'object' || !(key in value)) {
                found = false;
                break;
            }
            value = value[key];
        }
        if (found) return value;
    }

    return undefined;
}

function tokenize(source) {
    const tokens = [];
    TOKEN.lastIndex = 0;
    while (TOKEN.lastIndex < source.length) {
        if (/^\s*$/.test(source.slice(TOKEN.lastIndex))) break;
        const match = TOKEN.exec(source);
        if (!match) throw new Error(`Invalid expression: ${source}`);
        if (match[1] !== undefined) tokens.push({ type: 'value', value: match[1].slice(1, -1).replace(/\\(.)/g, '$1') });
        else if (match[2] !== undefined) tokens.push({ type: 'value', value: Number(match[2]) });
        else if (match[3] !== undefined) tokens.push({ type: 'op', value: match[3] });
        else if (match[4].toLowerCase() in KEYWORDS) tokens.push({ type: 'op', value: KEYWORDS[match[4].toLowerCase()] });
        else if (match[4].toLowerCase() in LITERALS) tokens.push({ type: 'value', value: LITERALS[match[4].toLowerCase()] });
        else tokens.push({ type: 'path', value: match[4] });
    }

    return tokens;
}

// A list given as an array or as the JSON string pdfme tables use
const toList = (value) => {
    if (Array.isArray(value)) return value;
    if (typeof value === 'string' && value.trim().startsWith('[')) {
        try {
            const parsed = JSON.parse(value);
            if (Array.isArray(parsed)) return parsed;
        } catch { /* not a list */ }
    }

    return [];
};

// sum(items, "total") → the "total" of every row; sum(items, 3) → the 4th cell; sum(items, "qty * price") → a formula
function column(list, key, scopes) {
    const rows = toList(list);
    if (key === undefined) return rows;

    return rows.map((row) => {
        if (row !== null && typeof row === 'object' && key in row) return row[key];
        if (typeof key !== 'string' || /^\d+$/.test(key)) return undefined;
        try {
            return evaluate(key, [row, ...scopes]);
        } catch {
            return undefined;
        }
    });
}

const FUNCTIONS = {
    sum: (scopes, list, key) => column(list, key, scopes).reduce((total, value) => total + toNumber(value), 0),
    avg: (scopes, list, key) => {
        const values = column(list, key, scopes);

        return values.length ? values.reduce((total, value) => total + toNumber(value), 0) / values.length : 0;
    },
    min: (scopes, list, key) => (toList(list).length ? Math.min(...column(list, key, scopes).map(toNumber)) : 0),
    max: (scopes, list, key) => (toList(list).length ? Math.max(...column(list, key, scopes).map(toNumber)) : 0),
    count: (scopes, list) => (typeof list === 'string' && !list.trim().startsWith('[') ? list.length : toList(list).length),
    length: (scopes, list) => FUNCTIONS.count(scopes, list),
    round: (scopes, value, digits = 0) => Number(toNumber(value).toFixed(Math.max(0, toNumber(digits)))),
    abs: (scopes, value) => Math.abs(toNumber(value)),
    floor: (scopes, value) => Math.floor(toNumber(value)),
    ceil: (scopes, value) => Math.ceil(toNumber(value)),
    upper: (scopes, value) => stringify(value).toUpperCase(),
    lower: (scopes, value) => stringify(value).toLowerCase(),
    trim: (scopes, value) => stringify(value).trim(),
    concat: (scopes, ...values) => values.map(stringify).join(''),
    default: (scopes, value, fallback = '') => (stringify(value) === '' ? fallback : value),
    if: (scopes, condition, yes, no = '') => (truthy(condition) ? yes : no),
};

function compare(left, operator, right) {
    if (typeof left === 'boolean' || typeof right === 'boolean') [left, right] = [truthy(left), truthy(right)];
    else if (isNumeric(left) && isNumeric(right)) [left, right] = [Number(left), Number(right)];
    else [left, right] = [stringify(left), stringify(right)];

    switch (operator) {
        case '==': return left === right;
        case '!=': return left !== right;
        case '>': return left > right;
        case '>=': return left >= right;
        case '<': return left < right;
        default: return left <= right;
    }
}

// Recursive descent over the tokens: ternary → or → and → not → comparison → + - → * / % → unary → value
function parse(tokens, scopes) {
    let at = 0;
    const peek = (...ops) => tokens[at]?.type === 'op' && ops.includes(tokens[at].value);
    const take = (...ops) => (peek(...ops) ? tokens[at++].value : null);
    const expect = (op) => {
        if (!take(op)) throw new Error(`Expected "${op}"`);
    };

    const ternary = () => {
        const condition = or();
        if (!take('?')) return condition;
        const yes = ternary();
        expect(':');
        const no = ternary();

        return truthy(condition) ? yes : no;
    };
    const or = () => {
        let left = and();
        while (take('||')) {
            const right = and();
            left = truthy(left) ? left : right;
        }

        return left;
    };
    const and = () => {
        let left = not();
        while (take('&&')) {
            const right = not();
            left = truthy(left) && truthy(right);
        }

        return left;
    };
    const not = () => (take('!') ? !truthy(not()) : comparison());
    const comparison = () => {
        let left = sum();
        let operator;
        while ((operator = take('==', '!=', '>=', '<=', '>', '<'))) left = compare(left, operator, sum());

        return left;
    };
    const sum = () => {
        let left = product();
        let operator;
        while ((operator = take('+', '-'))) {
            const right = product();
            if (operator === '-') left = toNumber(left) - toNumber(right);
            // + adds numbers and joins texts
            else left = isNumeric(left) && isNumeric(right) ? Number(left) + Number(right) : stringify(left) + stringify(right);
        }

        return left;
    };
    const product = () => {
        let left = unary();
        let operator;
        while ((operator = take('*', '/', '%'))) {
            const right = toNumber(unary());
            if (operator === '*') left = toNumber(left) * right;
            else if (right === 0) left = 0;
            else left = operator === '/' ? toNumber(left) / right : toNumber(left) % right;
        }

        return left;
    };
    const unary = () => (take('-') ? -toNumber(unary()) : value());
    const value = () => {
        const token = tokens[at++];
        if (!token) throw new Error('Incomplete expression');
        if (token.type === 'value') return token.value;
        if (token.type === 'path') {
            if (!peek('(')) return lookup(scopes, token.value);
            at++;
            const fn = FUNCTIONS[token.value.toLowerCase()];
            if (!fn) throw new Error(`Unknown function: ${token.value}`);
            const args = [];
            if (!take(')')) {
                do args.push(ternary()); while (take(','));
                expect(')');
            }

            return fn(scopes, ...args);
        }
        if (token.value === '(') {
            const inner = ternary();
            expect(')');

            return inner;
        }
        throw new Error(`Unexpected "${token.value}"`);
    };

    const result = ternary();
    if (at < tokens.length) throw new Error('Unexpected input after the expression');

    return result;
}

const tokenCache = new Map();

// Value of an expression; throws on a syntax error
export function evaluate(expression, scopes) {
    if (!tokenCache.has(expression)) tokenCache.set(expression, tokenize(expression));

    return parse(tokenCache.get(expression), Array.isArray(scopes) ? scopes : [scopes]);
}

// "qty * price | number:2 | default:—" → ["qty * price", "number:2", "default:—"]: split on a single | that is not
// inside quotes or parentheses (|| is "or")
function splitFilters(source) {
    const parts = [];
    let quote = null;
    let depth = 0;
    let start = 0;
    for (let i = 0; i < source.length; i++) {
        const char = source[i];
        if (quote) {
            if (char === '\\') i++;
            else if (char === quote) quote = null;
        } else if (char === '"' || char === "'") quote = char;
        else if (char === '(') depth++;
        else if (char === ')') depth--;
        else if (char === '|' && depth === 0) {
            if (source[i + 1] === '|') {
                i++;
                continue;
            }
            parts.push(source.slice(start, i));
            start = i + 1;
            // The rest are filters: their arguments are plain text ("default:—"), not expressions
            return [...parts, ...source.slice(start).split('|')];
        }
    }
    parts.push(source.slice(start));

    return parts;
}

// "default('—')", "default:—", "round(2)", "number:2" → [name, argument]
function parseFilter(filter) {
    const match = /^(\w+)\s*(?:\((.*)\)|:(.*))?$/s.exec(filter.trim());
    if (!match) return [filter.trim(), null];
    const arg = match[2] ?? match[3];

    return [match[1], arg === undefined ? null : arg.trim().replace(/^(["'])(.*)\1$/s, '$2')];
}

function applyFilter(value, name, arg, format) {
    switch (name) {
        case 'default':
            return stringify(value) === '' ? (arg ?? '') : value;
        case 'upper':
            return stringify(value).toUpperCase();
        case 'lower':
            return stringify(value).toLowerCase();
        case 'trim':
            return stringify(value).trim();
        case 'number':
        case 'round': {
            if (!isNumeric(value)) return value;
            const [whole, fraction] = Number(value).toFixed(Math.max(0, Number(arg) || 0)).split('.');
            const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, format.thousands);

            return fraction ? grouped + format.decimal + fraction : grouped;
        }
        default:
            return value;
    }
}

const separators = (format = {}) => ({ decimal: format?.decimal ?? ',', thousands: format?.thousands ?? ' ' });

// One expression with its filters, as text; throws on a syntax error
export function renderExpression(source, scopes, format) {
    const [expression, ...filters] = splitFilters(source);
    const value = filters.reduce((current, filter) => applyFilter(current, ...parseFilter(filter), separators(format)), evaluate(expression.trim(), scopes));

    return stringify(value);
}

// The text with every {{ expression|filter }} replaced; unknown keys become empty, a broken expression stays as typed
export function renderText(text, data, format = {}) {
    const scopes = Array.isArray(data) ? data : [data];

    return text.replace(PLACEHOLDER, (whole, source) => {
        try {
            return renderExpression(source, scopes, format);
        } catch {
            return whole;
        }
    });
}

// field.showIf: true when there is none, when it holds, or when it cannot be parsed (a typo must not hide a field)
export function isVisible(field, data) {
    const condition = typeof field?.showIf === 'string' ? field.showIf.trim() : '';
    if (!condition) return true;
    try {
        return truthy(evaluate(condition, [data]));
    } catch {
        return true;
    }
}

// Data paths an expression reads ("client.name", "items"); function names and keywords are left out
export function expressionPaths(source) {
    let tokens;
    try {
        tokens = tokenize(splitFilters(source)[0]);
    } catch {
        return [];
    }

    return tokens.filter((token, i) => token.type === 'path' && !(tokens[i + 1]?.type === 'op' && tokens[i + 1].value === '(')).map((token) => token.value);
}

const allFields = (template) => [...(template?.schemas ?? []).flat(), ...(template?.basePdf?.staticSchema ?? [])].filter(Boolean);
const isBoundTable = (field) => field.type === 'table' && Array.isArray(field.dataColumns) && field.dataColumns.some((column) => String(column ?? '').trim() !== '');

// Data paths the placeholders and conditions of a template read
export function placeholderPaths(template) {
    const paths = new Set();
    for (const field of allFields(template)) {
        if (field.type === 'text' && hasPlaceholders(field.content)) {
            for (const [, source] of field.content.matchAll(PLACEHOLDER)) expressionPaths(source).forEach((path) => paths.add(path));
        }
        if (typeof field.showIf === 'string') expressionPaths(field.showIf).forEach((path) => paths.add(path));
    }

    return [...paths];
}

// Top level keys of the same ("client" for {{ client.name }})
export const placeholderKeys = (template) => [...new Set(placeholderPaths(template).map((path) => path.split('.')[0]))];

// Rows of objects → the rows of text cells pdfme draws. Columns come from field.dataColumns (one expression per
// column), or from the header labels ("Price €" → price), or from the order of the keys.
export function tableRows(field, rows, data, format = {}) {
    if (!Array.isArray(rows) || !rows.some((row) => row !== null && typeof row === 'object' && !Array.isArray(row))) return rows;

    const first = rows.find((row) => row !== null && typeof row === 'object' && !Array.isArray(row));
    const keys = Object.keys(first);
    const slug = (text) => String(text).toLowerCase().replace(/[^a-z0-9]+/g, '');
    const head = Array.isArray(field.head) ? field.head : [];
    const width = Math.max(head.length, isBoundTable(field) ? field.dataColumns.length : 0) || keys.length;
    const columns = Array.from({ length: width }, (unused, i) => {
        const typed = String(field.dataColumns?.[i] ?? '').trim();
        if (typed) return { source: typed };
        const label = slug(head[i] ?? '');
        const key = keys.find((candidate) => slug(candidate) === label) ?? keys.find((candidate) => label && label.startsWith(slug(candidate))) ?? keys[i];

        return { key };
    });

    return rows.map((row, index) => {
        if (Array.isArray(row)) return row.map(stringify);
        const scopes = [row, { rowNumber: index + 1, rowIndex: index }, data];

        return columns.map((column) => {
            if (!column.source) return stringify(column.key === undefined ? '' : row?.[column.key]);
            try {
                return renderExpression(column.source, scopes, format);
            } catch {
                return '';
            }
        });
    });
}

/**
 * What to hand to pdfme's generate(): a list of jobs [{ template, data: [documents] }].
 * One job in the usual case; several when fields with a showIf condition are shown for some documents and hidden for
 * others (pdfme takes one template per call), to be generated one by one and merged in order.
 */
// pdfme field types whose value is one string to encode: "https://pay.example.com/{{ number }}" works like in a text
export const CODE_TYPES = ['qrcode', 'barcode', 'japanpost', 'ean13', 'ean8', 'code39', 'code128', 'nw7', 'itf14', 'upca', 'upce', 'gs1datamatrix', 'pdf417'];

export function prepare(template, data, format = {}) {
    const docs = (Array.isArray(data) ? data : [data]).map((doc) => (doc && typeof doc === 'object' ? doc : {}));
    const pages = template?.schemas ?? [];
    const fixed = template?.basePdf?.staticSchema ?? [];
    const conditional = allFields(template).filter((field) => typeof field.showIf === 'string' && field.showIf.trim() !== '');
    const texts = pages.flat().filter((field) => (field?.type === 'text' || CODE_TYPES.includes(field?.type)) && field.name && hasPlaceholders(field.content));
    const tables = pages.flat().filter((field) => field?.type === 'table' && field.name);
    const charts = pages.flat().filter((field) => field?.type === 'chart' && field.name && field.content);
    const fixedTexts = fixed.some((field) => field?.type === 'text' && hasPlaceholders(field.content));

    if (!conditional.length && !texts.length && !fixedTexts && !tables.length && !charts.length) return [{ template, data: docs }];

    // Header / footer: pdfme fills them itself (it knows {currentPage} / {totalPages}), with the variables of each
    // document. Each {{ expression|filter }} becomes a hidden variable {__pt_0}, computed here per document, so
    // filters and formulas work there too.
    const hiddenVars = [];
    const fixedContent = new Map(fixed.filter((field) => field?.type === 'text' && hasPlaceholders(field.content)).map((field) => [
        field,
        field.content.replace(PLACEHOLDER, (whole, source) => {
            const key = `__pt_${hiddenVars.length}`;
            hiddenVars.push([key, source]);

            return `{${key}}`;
        }),
    ]));

    const templateFor = (hidden) => {
        const keep = (field) => !hidden.has(field);
        const next = {
            ...template,
            // The text comes from the data now (see fill), so the field must not be read-only
            schemas: pages.map((page) => page.filter(keep).map((field) => (texts.includes(field)
                // Code fields: the template text must not reach pdfme (it checks the content of a barcode), the value comes from fill()
                ? { ...field, readOnly: false, ...(CODE_TYPES.includes(field.type) ? { content: '' } : {}) }
                : field))),
        };
        if (fixed.length) {
            next.basePdf = {
                ...template.basePdf,
                staticSchema: fixed.filter(keep).map((field) => (fixedContent.has(field) ? { ...field, content: fixedContent.get(field) } : field)),
            };
        }

        return next;
    };

    const fill = (doc) => {
        const filled = { ...doc };
        // Always from the text of the field, also when the data has a key named like the field ("Acme {{ name }}" in
        // a field called "name"): placeholders read the original data
        for (const field of texts) filled[field.name] = renderText(field.content, doc, format);
        for (const field of tables) {
            if (Array.isArray(doc[field.name])) filled[field.name] = tableRows(field, doc[field.name], doc, format);
        }
        // Charts: the rows come from the data key named like the field, or from the field's own content
        for (const field of charts) {
            if (doc[field.name] === undefined || doc[field.name] === '') filled[field.name] = field.content;
        }
        for (const [key, source] of hiddenVars) {
            let value;
            try {
                value = renderExpression(source, [doc], format);
            } catch {
                value = `{{ ${source} }}`;
            }
            // pdfme would evaluate braces in a variable again: a value is printed as it is
            filled[key] = value.replace(/[{}]/g, '');
        }

        return filled;
    };

    const jobs = [];
    let last = null;
    for (const doc of docs) {
        const hidden = new Set(conditional.filter((field) => !isVisible(field, doc)));
        const signature = conditional.map((field) => (hidden.has(field) ? '0' : '1')).join('');
        if (!last || last.signature !== signature) {
            last = { signature, template: templateFor(hidden), data: [] };
            jobs.push(last);
        }
        last.data.push(fill(doc));
    }

    return jobs.map(({ template: jobTemplate, data: jobData }) => ({ template: jobTemplate, data: jobData }));
}

// Kept for callers that render one template: the first job of prepare()
export function applyPlaceholders(template, data, format = {}) {
    const [job] = prepare(template, data, format);

    return { template: job.template, data: Array.isArray(data) ? job.data : job.data[0] };
}

/**
 * A diagonal see-through text over every page (PdfTemplates watermark option), as a read-only pdfme text field:
 * on the fixed layer (basePdf.staticSchema) when the template has one, so pages added by long tables get it too,
 * otherwise on each page. wm = {text, opacity, color, angle (degrees, counter-clockwise), size (pt) | null}.
 */
export function withWatermark(template, wm) {
    if (!wm || !wm.text) return template;
    const base = template?.basePdf && typeof template.basePdf === 'object' ? template.basePdf : null;
    const width = Number(base?.width) || 210;
    const height = Number(base?.height) || 297;
    const PT = 0.3528; // mm per pt
    const angle = (Number(wm.angle) || 0) * Math.PI / 180;
    const chars = [...String(wm.text)].length;
    // Same fit as Watermark::fitSize() in PHP: most of the longest line through the centre at this angle
    const span = Math.min(
        Math.abs(Math.cos(angle)) > 1e-6 ? width / Math.abs(Math.cos(angle)) : Infinity,
        Math.abs(Math.sin(angle)) > 1e-6 ? height / Math.abs(Math.sin(angle)) : Infinity,
    ) / PT;
    const size = Number(wm.size) > 0 ? Number(wm.size) : Math.max(12, Math.min(160, span * 0.7 / (chars * 0.62)));
    const boxWidth = Math.min(chars * 0.7 * size * PT + 10, span * PT);
    const boxHeight = size * 1.4 * PT;
    const field = {
        name: '__pt_watermark',
        type: 'text',
        readOnly: true,
        content: String(wm.text),
        position: { x: (width - boxWidth) / 2, y: (height - boxHeight) / 2 },
        width: boxWidth,
        height: boxHeight,
        rotate: (360 - (Number(wm.angle) || 0) % 360) % 360,
        opacity: Number(wm.opacity) || 0.15,
        fontSize: size,
        fontColor: wm.color || '#808080',
        alignment: 'center',
        verticalAlignment: 'middle',
        lineHeight: 1,
        characterSpacing: 0,
    };

    if (base) return { ...template, basePdf: { ...base, staticSchema: [...(base.staticSchema ?? []), field] } };

    return { ...template, schemas: (template.schemas ?? []).map((page) => [...page, field]) };
}
