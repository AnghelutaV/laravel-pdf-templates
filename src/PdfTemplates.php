<?php

namespace AnghelutaV\PdfTemplates;

use AnghelutaV\PdfTemplates\Exceptions\RenderException;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\Renderers\HtmlRenderer;
use AnghelutaV\PdfTemplates\Renderers\WysiwygRenderer;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * PdfTemplates::make('invoice', $data)->save('invoices/1.pdf')   fluent, see PendingPdf
 * PdfTemplates::render('invoice', $data)                         PDF bytes
 * PdfTemplates::download('invoice', $data, 'invoice-1')          download response
 * PdfTemplates::options()                                        ['slug' => 'name'] for a <select>
 * PdfTemplates::fields('invoice')                                JSON keys the template uses
 *
 * $data: array, or a raw JSON string (an array of objects = several documents in one PDF)
 */
class PdfTemplates
{
    public function __construct(
        protected HtmlRenderer $html,
        protected WysiwygRenderer $wysiwyg,
        protected TemplateEngine $engine,
    ) {}

    public function find(PdfTemplate|string $template): PdfTemplate
    {
        if ($template instanceof PdfTemplate) {
            return $template;
        }

        return PdfTemplate::where('slug', $template)->first()
            ?? throw new RenderException("PDF template '{$template}' not found.");
    }

    public function make(PdfTemplate|string $template, string|array|null $data = []): PendingPdf
    {
        return new PendingPdf($this, $this->find($template), $data);
    }

    public function render(PdfTemplate|string $template, string|array|null $data = []): string
    {
        $template = $this->find($template);

        return $template->isHtml()
            ? $this->html->pdf($template, $this->decode($data))
            : $this->wysiwyg->pdf($template, $data);
    }

    public function download(PdfTemplate|string $template, string|array|null $data = [], ?string $filename = null): Response
    {
        return $this->pending($template, $data, $filename)->download();
    }

    public function inline(PdfTemplate|string $template, string|array|null $data = [], ?string $filename = null): Response
    {
        return $this->pending($template, $data, $filename)->inline();
    }

    // Active templates as ['slug' => 'name'], optionally only one mode
    public function options(?string $mode = null): array
    {
        return PdfTemplate::active()
            ->when($mode, fn ($q) => $q->where('mode', $mode))
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->all();
    }

    /**
     * The data keys a template uses, so callers know what to pass.
     * WYSIWYG: editable fields [name => pdfme type]; HTML: placeholder paths [path => 'value'|'list'|'condition'].
     */
    public function fields(PdfTemplate|string $template): array
    {
        $template = $this->find($template);

        if (! $template->isHtml()) {
            $schema = json_decode((string) $template->schema, true) ?: [];

            return collect($schema['schemas'] ?? [])
                ->flatten(1)
                ->reject(fn ($field) => ! empty($field['readOnly']) || empty($field['name']))
                ->mapWithKeys(fn ($field) => [$field['name'] => $field['type'] ?? 'text'])
                ->all();
        }

        return $this->engine->fields((string) $template->html);
    }

    public function wysiwygOnServer(): bool
    {
        return WysiwygRenderer::configured();
    }

    public function decode(string|array|null $data): array
    {
        if (is_array($data)) {
            return $data;
        }
        if ($data === null || trim($data) === '') {
            return [];
        }

        $decoded = json_decode($data, true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Invalid JSON: '.json_last_error_msg());
        }

        return $decoded;
    }

    protected function pending(PdfTemplate|string $template, string|array|null $data, ?string $filename): PendingPdf
    {
        $pending = $this->make($template, $data);

        return $filename ? $pending->filename($filename) : $pending;
    }
}
