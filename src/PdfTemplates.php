<?php

namespace AnghelutaV\PdfTemplates;

use AnghelutaV\PdfTemplates\Exceptions\RenderException;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\Renderers\HtmlRenderer;
use AnghelutaV\PdfTemplates\Renderers\WysiwygRenderer;
use Closure;
use Composer\InstalledVersions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use OutOfBoundsException;

/**
 * PdfTemplates::make('invoice', $data)->save('invoices/1.pdf')   fluent, see PendingPdf
 * PdfTemplates::render('invoice', $data)                         PDF bytes
 * PdfTemplates::download('invoice', $data, 'invoice-1')          download response
 * PdfTemplates::options()                                        ['slug' => 'name'] for a <select>
 * PdfTemplates::fields('invoice')                                JSON keys the template uses
 * PdfTemplates::auth(fn ($request) => ...)                       who may open the admin UI
 *
 * $data: array, or a raw JSON string (an array of objects = several documents in one PDF)
 */
class PdfTemplates
{
    /** @var (Closure(Request): bool)|null */
    protected static ?Closure $authUsing = null;

    /**
     * Admin UI access, like LogViewer::auth() / Telescope::auth(). In a service provider's boot():
     *   PdfTemplates::auth(fn ($request) => auth('web')->check());
     * When set, it decides instead of the gate (config gate); null removes it.
     *
     * @param  (Closure(Request): bool)|null  $callback
     */
    public static function auth(?Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    // null = no auth() callback: the gate / environment rules apply (Authorize middleware)
    public static function check(Request $request): ?bool
    {
        return static::$authUsing ? (bool) (static::$authUsing)($request) : null;
    }

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
        $ttl = (int) config('pdf-templates.cache.ttl', 0);

        if ($ttl <= 0) {
            return $this->generate($template, $data);
        }

        return Cache::store(config('pdf-templates.cache.store'))
            ->remember($this->cacheKey($template, $data), $ttl, fn () => $this->generate($template, $data));
    }

    protected function generate(PdfTemplate $template, string|array|null $data): string
    {
        return $template->isHtml()
            ? $this->html->pdf($template, $this->decode($data))
            : $this->wysiwyg->pdf($template, $data);
    }

    // Content, not id/updated_at: unsaved previews render a modified copy of the template
    protected function cacheKey(PdfTemplate $template, string|array|null $data): string
    {
        return 'pdf-templates:'.sha1(implode("\0", [
            $template->mode, $template->schema, $template->html, $template->css, $template->paper, $template->orientation,
            is_string($data) ? $data : json_encode($data),
        ]));
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

    // Installed version as Composer sees it ("v0.2.0", "dev-main" for a path / git checkout), null when unknown
    public static function version(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion('anghelutav/laravel-pdf-templates');
        } catch (OutOfBoundsException) {
            return null;
        }
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
