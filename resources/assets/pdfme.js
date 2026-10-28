// laravel-pdf-templates: pdfme (Designer + browser generation).
// Bare imports are resolved by the import map in resources/views/partials/head.blade.php
import { Designer } from '@pdfme/ui';
import { generate } from '@pdfme/generator';
import {
    text, multiVariableText, image, svg, table, line, rectangle, ellipse, barcodes, date, checkbox, list,
} from '@pdfme/schemas';
import { toInputs } from 'pdf-templates';

export { Designer };

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
    Code128: barcodes.code128,
    EAN13: barcodes.ean13,
    Date: date,
    Checkbox: checkbox,
};

export async function generatePdf(template, data) {
    const pdf = await generate({ template, inputs: toInputs(data), plugins });

    return new Blob([pdf], { type: 'application/pdf' });
}
