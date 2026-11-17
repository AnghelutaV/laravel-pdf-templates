// Renders WYSIWYG (pdfme) templates server-side.
//   POST /render  {"template": {...pdfme template}, "inputs": {...} | [{...}, ...], "watermark"?: {text, opacity, color, angle, size}}  -> application/pdf
//   POST /image?page=1&scale=2  body = a PDF (application/pdf)  -> image/png of that page (any PDF: HTML or WYSIWYG)
//   POST /html  {"html": "<!doctype html>…", "paper": "a4", "landscape": false, "margins": [t, r, b, l] mm | null,
//                "header": "<html>" | null, "footer": "<html>" | null, "chrome": "path" | null}  -> application/pdf (Chrome engine)
//   GET  /health
// Env: CHROME_PATH (Chrome / Edge / Chromium for /html; found by itself when unset), PORT (default 3311), HOST (default 127.0.0.1), PDFME_RENDERER_TOKEN (optional, checked against X-Renderer-Token),
//      RENDERER_CONCURRENCY (renders at once, default 2), RENDERER_QUEUE_MAX (waiting before 503, default 50),
//      RENDERER_BROWSER_RECYCLE (PDFs before Chrome is restarted, default 200),
//      PDFME_DECIMAL_SEPARATOR / PDFME_THOUSANDS_SEPARATOR for {{ total|number:2 }} (default "," and " ")
import http from 'node:http';
import { readFileSync } from 'node:fs';
import { generate } from '@pdfme/generator';
import {
    text, multiVariableText, image, svg, table, line, rectangle, ellipse, barcodes, date, checkbox, list,
} from '@pdfme/schemas';

// Keep in sync with resources/assets/pdfme.js
const plugins = {
    Text: text,
    'Multi-Variable Text': multiVariableText,
    List: list,
    Table: table,
    Image: image,
    SVG: svg,
    Line: line,
    Rectangle: rectangle,
    Ellipse: ellipse,
    'QR Code': barcodes.qrcode,
    // Old fields keep their own type; new ones are "barcode" + barcodeType (resources/assets/barcode.js, added below)
    Code128: barcodes.code128,
    EAN13: barcodes.ean13,
    EAN8: barcodes.ean8,
    Code39: barcodes.code39,
    ITF14: barcodes.itf14,
    'UPC-A': barcodes.upca,
    'UPC-E': barcodes.upce,
    Codabar: barcodes.nw7,
    'GS1 DataMatrix': barcodes.gs1datamatrix,
    PDF417: barcodes.pdf417,
    Date: date,
    Checkbox: checkbox,
};

// pdfme inputs are string maps; arrays/objects (tables, lists, multi-variable text) become JSON strings
function toInputs(data) {
    const docs = Array.isArray(data) ? data : [data ?? {}];

    return docs.map((doc) => Object.fromEntries(Object.entries(doc ?? {}).map(([key, value]) => [
        key,
        value === null || value === undefined ? '' : (typeof value === 'string' ? value : JSON.stringify(value)),
    ])));
}

// {{ placeholders }} in texts: the same module the browser uses (resources/assets/placeholders.js, no imports).
// Loaded from its source so it works whatever Node makes of a .js file outside this package.
const placeholders = await import('data:text/javascript;base64,' + readFileSync(new URL('../resources/assets/placeholders.js', import.meta.url)).toString('base64'));
const FORMAT = { decimal: process.env.PDFME_DECIMAL_SEPARATOR ?? ',', thousands: process.env.PDFME_THOUSANDS_SEPARATOR ?? ' ' };
// The one Barcode tool of the designer (resources/assets/barcode.js)
const barcode = await import('data:text/javascript;base64,' + readFileSync(new URL('../resources/assets/barcode.js', import.meta.url)).toString('base64'));
plugins.Barcode = barcode.createBarcodePlugin(barcodes);
// Chart fields (resources/assets/charts.js), loaded the same way; numbers with the separators above
const charts = await import('data:text/javascript;base64,' + readFileSync(new URL('../resources/assets/charts.js', import.meta.url)).toString('base64'));
plugins.Chart = charts.createChartPlugin({ text, svg }, (value, decimals) => {
    const [int, frac] = Math.abs(value).toFixed(decimals).split('.');

    return (value < 0 ? '-' : '') + int.replace(/\B(?=(\d{3})+(?!\d))/g, FORMAT.thousands) + (frac ? FORMAT.decimal + frac : '');
});

async function render(template, data, watermark = null) {
    template = placeholders.withWatermark(template, watermark);
    // {{ placeholders }}, formulas, showIf and bound tables → plain pdfme input. Usually one job; documents that
    // show different fields are generated one by one and merged.
    const jobs = placeholders.prepare(template, data, FORMAT);
    const parts = [];
    for (const job of jobs) parts.push(await generate({ template: job.template, inputs: toInputs(job.data), plugins }));
    if (parts.length === 1) return parts[0];

    const { PDFDocument } = await import('@pdfme/pdf-lib');
    const merged = await PDFDocument.create();
    for (const part of parts) {
        const source = await PDFDocument.load(part);
        (await merged.copyPages(source, source.getPageIndices())).forEach((page) => merged.addPage(page));
    }

    return merged.save();
}

// One page of a PDF as PNG (PDFium in WebAssembly via @pdfme/converter, loaded on first use)
let converter = null;
async function toPng(pdf, page, scale) {
    converter ??= await import('@pdfme/converter');
    const bytes = new Uint8Array(pdf.buffer, pdf.byteOffset, pdf.byteLength);
    const [png] = await converter.pdf2img(bytes, { scale, range: { start: page - 1, end: page - 1 } });
    if (!png) throw new Error(`The PDF has no page ${page}.`);

    return Buffer.from(png);
}

// HTML templates with the Chrome engine: a headless Chrome / Edge / Chromium of this machine (puppeteer-core, loaded on
// first use), one browser kept open, a new page per render. The page may not load anything: PHP has already put the
// images and fonts in as data: URLs, so nothing the data points to is fetched from here.
const BROWSERS = {
    win32: [
        `${process.env['PROGRAMFILES'] ?? 'C:\\Program Files'}\\Google\\Chrome\\Application\\chrome.exe`,
        `${process.env['PROGRAMFILES(X86)'] ?? 'C:\\Program Files (x86)'}\\Google\\Chrome\\Application\\chrome.exe`,
        `${process.env['LOCALAPPDATA'] ?? ''}\\Google\\Chrome\\Application\\chrome.exe`,
        `${process.env['PROGRAMFILES(X86)'] ?? 'C:\\Program Files (x86)'}\\Microsoft\\Edge\\Application\\msedge.exe`,
        `${process.env['PROGRAMFILES'] ?? 'C:\\Program Files'}\\Microsoft\\Edge\\Application\\msedge.exe`,
    ],
    darwin: ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Chromium.app/Contents/MacOS/Chromium', '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge'],
    linux: ['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/snap/bin/chromium', '/usr/bin/microsoft-edge'],
};
let browser = null;
let browserPath = null;
// One Chrome is kept open; it is closed (and opened again on the next PDF) after RENDERER_BROWSER_RECYCLE PDFs,
// so a long-running renderer does not keep growing (Chrome leaks memory over thousands of pages)
const RECYCLE = Math.max(1, Number(process.env.RENDERER_BROWSER_RECYCLE) || 200);
let printed = 0;
let openPages = 0;
let launching = null;

async function chrome(path) {
    const { existsSync } = await import('node:fs');
    const executable = path || process.env.CHROME_PATH || (BROWSERS[process.platform] ?? BROWSERS.linux).find((candidate) => existsSync(candidate));
    if (!executable) throw new Error('No Chrome, Edge or Chromium found for the Chrome engine: install one or set pdf-templates.chrome.path (PDF_TEMPLATES_CHROME_PATH).');
    if (browser?.connected && browserPath === executable) return browser;
    // Renders starting together share one launch instead of opening a Chrome each
    if (launching && browserPath === executable) return launching;
    browserPath = executable;
    launching = (async () => {
        await browser?.close().catch(() => {});
        const puppeteer = (await import('puppeteer-core')).default;
        const launched = await puppeteer.launch({
            executablePath: executable,
            headless: true,
            // --disable-dev-shm-usage: Docker gives /dev/shm 64 MB, too little for Chrome
            args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--font-render-hinting=none'],
        });
        launched.on('disconnected', () => { if (browser === launched) browser = null; });
        browser = launched;
        printed = 0;

        return launched;
    })();
    try {
        return await launching;
    } finally {
        launching = null;
    }
}

const FORMATS = { a3: 'A3', a4: 'A4', a5: 'A5', a6: 'A6', letter: 'Letter', legal: 'Legal', tabloid: 'Tabloid' };

async function htmlToPdf({ html, paper = 'a4', landscape = false, margins = null, header = null, footer = null, chrome: path = null }) {
    // Counted before Chrome is asked for a page, so a recycle never closes it under a render that is starting
    openPages++;
    let page = null;
    try {
        page = await (await chrome(path)).newPage();
        page.setDefaultTimeout(60000);
        await page.setRequestInterception(true);
        page.on('request', (request) => (/^(data|about|blob):/i.test(request.url()) ? request.continue() : request.abort()));
        await page.setContent(String(html ?? ''), { waitUntil: 'load', timeout: 30000 });
        await page.evaluate(() => document.fonts?.ready);
        const options = { printBackground: true, preferCSSPageSize: true, landscape: !!landscape, format: FORMATS[String(paper).toLowerCase()] ?? 'A4' };
        if (Array.isArray(margins)) {
            const [top, right, bottom, left] = margins.map((m) => `${Number(m) || 0}mm`);
            options.margin = { top, right, bottom, left };
        }
        if (header || footer) {
            options.displayHeaderFooter = true;
            options.headerTemplate = header || '<span></span>';
            options.footerTemplate = footer || '<span></span>';
        }

        return Buffer.from(await page.pdf(options));
    } finally {
        await page?.close().catch(() => {});
        openPages--;
        printed++;
        if (printed >= RECYCLE && openPages === 0 && browser) {
            const old = browser;
            browser = null;
            await old.close().catch(() => {});
        }
    }
}

// At most RENDERER_CONCURRENCY renders at once (each one is CPU + memory, a Chrome page for the Chrome engine);
// the next ones wait their turn, and past RENDERER_QUEUE_MAX waiting the request gets 503 (PHP retries later).
const CONCURRENCY = Math.max(1, Number(process.env.RENDERER_CONCURRENCY) || 2);
const QUEUE_MAX = Math.max(0, Number(process.env.RENDERER_QUEUE_MAX ?? 50));
let running = 0;
const waiting = [];

class Busy extends Error {}

async function limited(work) {
    if (running >= CONCURRENCY) {
        if (waiting.length >= QUEUE_MAX) throw new Busy(`Renderer busy: ${running} running, ${waiting.length} waiting`);
        await new Promise((resolve) => waiting.push(resolve));
    } else {
        running++;
    }
    try {
        return await work();
    } finally {
        const next = waiting.shift();
        if (next) next(); // the slot goes straight to the next one
        else running--;
    }
}

const PORT = Number(process.env.PORT || 3311);
const HOST = process.env.HOST || '127.0.0.1';
const TOKEN = process.env.PDFME_RENDERER_TOKEN || '';
const MAX_BODY = 30 * 1024 * 1024;

function send(res, status, body, type = 'application/json') {
    res.writeHead(status, { 'Content-Type': type });
    res.end(type === 'application/json' ? JSON.stringify(body) : body);
}

const server = http.createServer((req, res) => {
    if (req.method === 'GET' && req.url === '/health') {
        return send(res, 200, { ok: true, running, waiting: waiting.length, concurrency: CONCURRENCY, chrome: !!browser });
    }
    const url = new URL(req.url, 'http://renderer');
    if (req.method !== 'POST' || !['/render', '/image', '/html'].includes(url.pathname)) {
        return send(res, 404, { error: 'Not found' });
    }
    if (TOKEN && req.headers['x-renderer-token'] !== TOKEN) {
        return send(res, 401, { error: 'Unauthorized' });
    }

    const chunks = [];
    let size = 0;
    req.on('data', (chunk) => {
        size += chunk.length;
        if (size > MAX_BODY) {
            send(res, 413, { error: 'Payload too large' });
            req.destroy();
        } else {
            chunks.push(chunk);
        }
    });
    req.on('end', async () => {
        if (res.writableEnded) return;
        try {
            if (url.pathname === '/image') {
                const page = Math.max(1, Number(url.searchParams.get('page')) || 1);
                const scale = Math.min(4, Math.max(0.5, Number(url.searchParams.get('scale')) || 2));
                send(res, 200, await limited(() => toPng(Buffer.concat(chunks), page, scale)), 'image/png');
                return;
            }
            if (url.pathname === '/html') {
                const body = JSON.parse(Buffer.concat(chunks).toString('utf8'));
                send(res, 200, await limited(() => htmlToPdf(body)), 'application/pdf');
                return;
            }
            const { template, inputs, watermark } = JSON.parse(Buffer.concat(chunks).toString('utf8'));
            const pdf = await limited(() => render(template, inputs, watermark));
            send(res, 200, Buffer.from(pdf), 'application/pdf');
        } catch (e) {
            if (e instanceof Busy) {
                res.setHeader('Retry-After', '10');
                send(res, 503, { error: e.message });
                return;
            }
            send(res, 422, { error: e.message });
        }
    });
});

server.listen(PORT, HOST, () => console.log(`pdfme renderer on http://${HOST}:${PORT}`));
