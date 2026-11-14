// laravel-pdf-templates: pdfme (Designer + browser generation).
// Bare imports are resolved by the import map in resources/views/partials/head.blade.php
import { Designer } from '@pdfme/ui';
import { generate } from '@pdfme/generator';
import {
    text, multiVariableText, image, svg, table, line, rectangle, ellipse, barcodes, date, checkbox, list,
} from '@pdfme/schemas';
import { toInputs, resolveImages } from 'pdf-templates';
import { prepare } from 'pdf-templates/placeholders';
import { createChartPlugin } from 'pdf-templates/charts';
import { BARCODE_TYPES, createBarcodePlugin, useBarcodeTool } from 'pdf-templates/barcode';

export { Designer, useBarcodeTool };

// Chart numbers like the number filter: the separators from the config (window.ptNumberFormat, partials/head)
function chartNumber(value, decimals) {
    const { decimal = ',', thousands = ' ' } = window.ptNumberFormat ?? {};
    const [int, frac] = Math.abs(value).toFixed(decimals).split('.');

    return (value < 0 ? '-' : '') + int.replace(/\B(?=(\d{3})+(?!\d))/g, thousands) + (frac ? decimal + frac : '');
}

// Keep in sync with renderer/server.mjs
export const plugins = {
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
    // Every barcode type behind one tool (resources/assets/barcode.js)
    Barcode: createBarcodePlugin(barcodes),

    Date: date,
    Checkbox: checkbox,
    Chart: createChartPlugin({ text, svg }, chartNumber),
};

// Generating also reads fields saved before the Barcode tool (type code128, ean13…): pdfme finds a plugin by its type
const generatePlugins = { ...plugins, ...Object.fromEntries(Object.keys(BARCODE_TYPES).map((type) => [`barcode:${type}`, barcodes[type]])) };

// Several generate() results (documents that show different fields) as one PDF, in order
async function mergePdfs(parts) {
    const { PDFDocument } = await import('@pdfme/pdf-lib');
    const merged = await PDFDocument.create();
    for (const part of parts) {
        const source = await PDFDocument.load(part);
        (await merged.copyPages(source, source.getPageIndices())).forEach((page) => merged.addPage(page));
    }

    return merged.save();
}

export async function generatePdf(template, data) {
    // pdf-asset:… references (image library) → data URLs
    [template, data] = await Promise.all([resolveImages(template), resolveImages(data)]);
    // {{ placeholders }}, formulas, showIf and bound tables → plain pdfme input; same code as the Node renderer
    const jobs = prepare(template, data, window.ptNumberFormat);
    const parts = [];
    for (const job of jobs) parts.push(await generate({ template: job.template, inputs: toInputs(job.data), plugins: generatePlugins }));
    const pdf = parts.length === 1 ? parts[0] : await mergePdfs(parts);

    return new Blob([pdf], { type: 'application/pdf' });
}
