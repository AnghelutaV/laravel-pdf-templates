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
use Illuminate\Support\Str;
use InvalidArgumentException;
use OutOfBoundsException;

/**
 * PdfTemplates::make('invoice', $data)->save('invoices/1.pdf')   fluent, see PendingPdf
 * PdfTemplates::render('invoice', $data)                         PDF bytes
 * PdfTemplates::render('invoice', $data, ['watermark' => 'DRAFT']) options: see PdfTemplates::OPTIONS
 * PdfTemplates::zip([['template' => 'invoice', 'data' => …], …]) several PDFs in one ZIP
 * PdfTemplates::download('invoice', $data, 'invoice-1')          download response
 * PdfTemplates::options()                                        ['slug' => 'name'] for a <select>
 * PdfTemplates::fields('invoice')                                JSON keys the template uses
 * PdfTemplates::auth(fn ($request) => ...)                       who may open the admin UI
 *
 * $data: array, or a raw JSON string (an array of objects = several documents in one PDF)
 */
class PdfTemplates
{
    // Render options: watermark (see Watermark)
    const OPTIONS = ['watermark'];

    // pdfme QR / barcode types: their value may hold {{ placeholders }} like a text (resources/assets/placeholders.js)
    const CODE_TYPES = ['qrcode', 'barcode', 'japanpost', 'ean13', 'ean8', 'code39', 'code128', 'nw7', 'itf14', 'upca', 'upce', 'gs1datamatrix', 'pdf417'];

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

    public function render(PdfTemplate|string $template, string|array|null $data = [], array $options = []): string
    {
        $template = $this->find($template);
        $options = $this->renderOptions($options);
        $ttl = (int) config('pdf-templates.cache.ttl', 0);

        if ($ttl <= 0) {
            return $this->generate($template, $data, $options);
        }

        return Cache::store(config('pdf-templates.cache.store'))
            ->remember($this->cacheKey($template, $data, $options), $ttl, fn () => $this->generate($template, $data, $options));
    }

    protected function generate(PdfTemplate $template, string|array|null $data, array $options = []): string
    {
        return $template->isHtml()
            ? $this->html->pdf($template, $this->decode($data), $options['watermark'] ?? null)
            : $this->wysiwyg->pdf($template, $data, $options['watermark'] ?? null);
    }

    // Known options only, normalized (so equal options share a cache entry)
    public function renderOptions(array $options): array
    {
        return array_filter(['watermark' => Watermark::normalize($options['watermark'] ?? null)]);
    }

    // Content, not id/updated_at: unsaved previews render a modified copy of the template
    protected function cacheKey(PdfTemplate $template, string|array|null $data, array $options = []): string
    {
        return 'pdf-templates:'.sha1(implode("\0", [
            $template->mode, $template->schema, $template->html, $template->css, $template->paper, $template->orientation,
            json_encode($template->settings),
            is_string($data) ? $data : json_encode($data),
            json_encode($options),
        ]));
    }

    /**
     * Several PDFs in one ZIP. Each item: ['template' => slug|model, 'data' => array|JSON, 'filename' => name]; the
     * file name may use the item's data ('invoice-{{ number }}', 'invoice-{number}') and defaults to slug-n.
     * Equal names get -2, -3…
     *
     * @param  iterable<array{template: PdfTemplate|string, data?: string|array|null, filename?: ?string}>  $items
     */
    public function zip(iterable $items, array $options = []): string
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new RenderException('ZIP output needs the PHP zip extension.');
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'pdf-templates-zip');
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new RenderException('Could not create the ZIP file.');
        }

        try {
            $used = [];
            $n = 0;
            foreach ($items as $item) {
                $n++;
                if (empty($item['template'])) {
                    throw new InvalidArgumentException("Item {$n}: template is missing.");
                }
                $template = $this->find($item['template']);
                $data = $item['data'] ?? [];
                $name = Str::slug(Str::beforeLast($this->itemFilename($item['filename'] ?? null, $data), '.pdf')) ?: "{$template->slug}-{$n}";
                for ($i = 2, $base = $name; isset($used[$name]); $i++) {
                    $name = "{$base}-{$i}";
                }
                $used[$name] = true;
                $zip->addFromString("{$name}.pdf", $this->render($template, $data, $options));
            }
            if (! $n) {
                throw new InvalidArgumentException('No items to render.');
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    // 'invoice-{{ number }}' / 'invoice-{number}' filled from the item's data (any template syntax)
    protected function itemFilename(?string $filename, string|array|null $data): string
    {
        if (! $filename || ! str_contains($filename, '{')) {
            return (string) $filename;
        }
        $data = is_array($data) ? $data : (json_decode((string) $data, true) ?: []);

        return html_entity_decode(strip_tags($this->engine->render($filename, array_is_list($data) ? ($data[0] ?? []) : $data)), ENT_QUOTES);
    }

    /**
     * One page of the PDF as an image: png or jpg (white background),  from 1. Made by the Node renderer,
     * for both modes; jpg needs PHP's GD extension.
     */
    public function image(PdfTemplate|string $template, string|array|null $data = [], string $format = 'png', int $page = 1, array $options = []): string
    {
        $scale = (float) config('pdf-templates.api.image_scale', 2);
        $png = $this->wysiwyg->png($this->render($template, $data, $options), $page, $scale);
        if (! in_array($format, ['jpg', 'jpeg'], true)) {
            return $png;
        }
        if (! function_exists('imagecreatefromstring')) {
            throw new RenderException('JPG output needs the PHP GD extension; use png.');
        }

        $source = imagecreatefromstring($png) ?: throw new RenderException('The renderer returned an unreadable image.');
        // PNG pages are transparent where nothing is drawn: flatten on white
        $canvas = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
        ob_start();
        imagejpeg($canvas, null, (int) config('pdf-templates.api.jpg_quality', 90));

        return (string) ob_get_clean();
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
     * WYSIWYG: editable fields [name => pdfme type] and the {{ paths }} used in texts; HTML: placeholder paths [path => 'value'|'list'|'condition'].
     */
    public function fields(PdfTemplate|string $template): array
    {
        $template = $this->find($template);

        if (! $template->isHtml()) {
            $schema = json_decode((string) $template->schema, true) ?: [];

            $all = collect($schema['schemas'] ?? [])->flatten(1);
            // A text with {{ placeholders }} is filled from them (resources/assets/placeholders.js), not by its name
            $templated = fn ($field) => in_array($field['type'] ?? '', ['text', ...self::CODE_TYPES], true) && preg_match('/\{\{[^{}]+\}\}/', (string) ($field['content'] ?? ''));

            $fields = $all
                ->reject(fn ($field) => ! empty($field['readOnly']) || empty($field['name']) || $templated($field))
                ->mapWithKeys(fn ($field) => [$field['name'] => $field['type'] ?? 'text'])
                ->all();

            foreach ($all->merge($schema['basePdf']['staticSchema'] ?? []) as $field) {
                if ($templated($field)) {
                    preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/', (string) $field['content'], $matches);
                    foreach ($matches[1] as $expression) {
                        foreach ($this->expressionPaths($expression) as $path) {
                            $fields[$path] ??= 'text';
                        }
                    }
                }
                // "Show only if" (field.showIf)
                foreach ($this->expressionPaths((string) ($field['showIf'] ?? '')) as $path) {
                    $fields[$path] ??= 'condition';
                }
            }

            return $fields;
        }

        return $this->engine->fields((string) $template->html);
    }

    /**
     * Data paths an expression of resources/assets/placeholders.js reads:
     * 'sum(items, "qty * price")|number:2' → ['items'], 'paid and total > 100' → ['paid', 'total'].
     *
     * @return list<string>
     */
    protected function expressionPaths(string $expression): array
    {
        // Filters start at a single | (|| is "or"); quoted texts are not paths
        $expression = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/', '""', $expression);
        $expression = preg_split('/(?<!\|)\|(?!\|)/', $expression)[0];
        preg_match_all('/(?<![\w.@])([A-Za-z_@][\w@]*+(?:\.[\w@]+)*+)(?!\s*\()/', $expression, $matches);

        return array_values(array_unique(array_filter(
            $matches[1],
            fn ($path) => ! in_array(strtolower($path), ['and', 'or', 'not', 'true', 'false', 'null', 'none'], true),
        )));
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
