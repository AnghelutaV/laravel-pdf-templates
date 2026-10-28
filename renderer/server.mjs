// Renders WYSIWYG (pdfme) templates server-side.
//   POST /render  {"template": {...pdfme template}, "inputs": {...} | [{...}, ...]}  -> application/pdf
//   GET  /health
// Env: PORT (default 3311), HOST (default 127.0.0.1), PDFME_RENDERER_TOKEN (optional, checked against X-Renderer-Token)
import http from 'node:http';
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
    Code128: barcodes.code128,
    EAN13: barcodes.ean13,
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

async function render(template, data) {
    return generate({ template, inputs: toInputs(data), plugins });
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
        return send(res, 200, { ok: true });
    }
    if (req.method !== 'POST' || req.url !== '/render') {
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
            const { template, inputs } = JSON.parse(Buffer.concat(chunks).toString('utf8'));
            const pdf = await render(template, inputs);
            send(res, 200, Buffer.from(pdf), 'application/pdf');
        } catch (e) {
            send(res, 422, { error: e.message });
        }
    });
});

server.listen(PORT, HOST, () => console.log(`pdfme renderer on http://${HOST}:${PORT}`));
