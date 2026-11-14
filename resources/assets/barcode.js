// laravel-pdf-templates: one "Barcode" tool for all of pdfme's barcode types, picked in the Edit Field panel
// (field type "barcode" + barcodeType), instead of a sidebar icon per type. QR Code stays a tool of its own.
// No imports: the browser loads it through the import map, the Node renderer from its source (see server.mjs).

export const BARCODE_TYPES = {
    code128: 'Code 128',
    ean13: 'EAN-13',
    ean8: 'EAN-8',
    code39: 'Code 39',
    itf14: 'ITF-14',
    upca: 'UPC-A',
    upce: 'UPC-E',
    nw7: 'Codabar (NW-7)',
    gs1datamatrix: 'GS1 DataMatrix',
    pdf417: 'PDF417',
    japanpost: 'Japan Post',
};

// A sample each type accepts, put in when the type changes so the field does not show an error
const SAMPLES = {
    code128: 'ABC-12345',
    ean13: '5901234123457',
    ean8: '96385074',
    code39: 'ABC123',
    itf14: '15400141288763',
    upca: '416000336108',
    upce: '00123457',
    nw7: 'A12345B',
    gs1datamatrix: '(01)09521234543213(3103)000123',
    pdf417: 'PDF417 sample',
    japanpost: '6540123789-A-K-Z',
};

const typeOf = (schema) => (BARCODE_TYPES[schema.barcodeType] ? schema.barcodeType : 'code128');

/** @param barcodes pdfme's barcodes export (@pdfme/schemas) */
export function createBarcodePlugin(barcodes) {
    const base = barcodes.code128;
    // pdfme's own plugin of the chosen type does the work, with the field's type set to it
    const as = (schema) => ({ ...schema, type: typeOf(schema) });
    const baseSchema = base.propPanel.schema;

    return {
        pdf: (arg) => barcodes[typeOf(arg.schema)].pdf({ ...arg, schema: as(arg.schema) }),
        ui: (arg) => barcodes[typeOf(arg.schema)].ui({ ...arg, schema: as(arg.schema) }),
        propPanel: {
            ...base.propPanel,
            schema: (props) => ({
                barcodeType: {
                    title: 'Barcode type',
                    type: 'string',
                    widget: 'select',
                    props: { options: Object.entries(BARCODE_TYPES).map(([value, label]) => ({ label, value })) },
                    span: 24,
                },
                ...(typeof baseSchema === 'function' ? baseSchema(props) : baseSchema ?? {}),
            }),
            defaultSchema: { ...base.propPanel.defaultSchema, type: 'barcode', barcodeType: 'code128', content: SAMPLES.code128 },
        },
        icon: base.icon,
    };
}

/**
 * Templates made before: code128 / ean13 / … fields become "barcode" fields of that type, so the designer shows them
 * under the one tool. Returns a new template; the Node renderer reads both forms.
 */
export function useBarcodeTool(template) {
    const convert = (field) => (field && BARCODE_TYPES[field.type] ? { ...field, type: 'barcode', barcodeType: field.type } : field);

    return {
        ...template,
        schemas: (template.schemas ?? []).map((page) => page.map(convert)),
        ...(template.basePdf && typeof template.basePdf === 'object' && Array.isArray(template.basePdf.staticSchema)
            ? { basePdf: { ...template.basePdf, staticSchema: template.basePdf.staticSchema.map(convert) } }
            : {}),
    };
}

