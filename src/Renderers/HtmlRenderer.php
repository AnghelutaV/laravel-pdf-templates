<?php

namespace AnghelutaV\PdfTemplates\Renderers;

use AnghelutaV\PdfTemplates\FontLibrary;
use AnghelutaV\PdfTemplates\ImageLibrary;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\PageSetup;
use AnghelutaV\PdfTemplates\TemplateEngine;
use AnghelutaV\PdfTemplates\Watermark;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Adapter\CPDF;
use Illuminate\Support\Facades\File;

// HTML mode: placeholders filled by TemplateEngine, PDF made by dompdf (remote resources disabled)
class HtmlRenderer
{
    // CSS counter(pages): the total number of pages, which dompdf does not know while it lays out the document
    const TOTAL_PAGES = '/counter\(\s*pages\s*\)/i';

    // Visual editor (CKEditor 5) defaults. A published config made before html.editor existed has an `html` block
    // without it, and Laravel merges package config one level deep only, so the keys are filled in here.
    const EDITOR = [
        'enabled' => true,
        'url' => 'https://cdn.jsdelivr.net/npm/ckeditor5@49.1.0/dist/browser/ckeditor5.js',
        'css' => 'https://cdn.jsdelivr.net/npm/ckeditor5@49.1.0/dist/browser/ckeditor5.css',
    ];

    /** @return array{enabled: bool, url: string, css: string} */
    public static function editor(): array
    {
        $config = array_filter((array) config('pdf-templates.html.editor'), fn ($value) => $value !== null && $value !== '');

        return ['enabled' => (bool) ($config['enabled'] ?? true)] + $config + self::EDITOR;
    }

    public function __construct(protected TemplateEngine $engine) {}

    /** @param  ?array  $watermark  normalized, see Watermark */
    public function pdf(PdfTemplate $template, array $data, ?array $watermark = null): string
    {
        if (PageSetup::from($template->settings)->engine() === 'chrome') {
            return $this->chrome($template, $data, $watermark);
        }
        File::ensureDirectoryExists(storage_path('fonts'));
        $html = $this->document($template, $data);

        if (! preg_match(self::TOTAL_PAGES, $html)) {
            $pdf = $this->dompdf($template, $html);
            $pdf->render();

            return $this->watermark($pdf, $watermark)->output();
        }

        // "Page 2 of 5": render, count the pages, render again with the total written in. A third pass covers
        // the rare case where the longer number moved a line and changed the count.
        $total = 1;
        for ($pass = 0; $pass < 3; $pass++) {
            $pdf = $this->dompdf($template, (string) preg_replace(self::TOTAL_PAGES, '"'.$total.'"', $html));
            $pdf->render();
            $pages = (int) $pdf->getDomPDF()->getCanvas()->get_page_count();
            if ($pages === $total) {
                break;
            }
            $total = $pages;
        }

        return $this->watermark($pdf, $watermark)->output();
    }

    /**
     * The Chrome engine: the same document, with the header / footer as Chrome's own templates, the watermark as a
     * fixed element (Chrome repeats those on every page) and every image / font as a data: URL (the browser page
     * loads nothing itself).
     */
    protected function chrome(PdfTemplate $template, array $data, ?array $watermark): string
    {
        $docs = array_is_list($data) && $data && is_array($data[0]) ? $data : [$data];
        $setup = PageSetup::from($template->settings);
        $font = (string) config('pdf-templates.html.default_font', 'DejaVu Sans');

        $html = $this->document($template, $data, chrome: true);
        if ($watermark) {
            [$width, $height] = $this->pageSize($template);
            $size = Watermark::fitSize($watermark, $width, $height);
            $html = str_replace('</body>', '<div style="position: fixed; left: 50%; top: 50%; z-index: 9999; pointer-events: none; white-space: nowrap;'
                .' transform: translate(-50%, -50%) rotate('.(-$watermark['angle']).'deg); font: bold '.round($size, 1).'pt \''.e($font).'\', sans-serif;'
                .' color: '.$watermark['color'].'; opacity: '.$watermark['opacity'].';">'.e($watermark['text']).'</div></body>', $html);
        }

        return app(ChromeRenderer::class)->pdf($this->inlineFiles($html), [
            'paper' => $template->paper ?: 'a4',
            'landscape' => $template->orientation === 'landscape',
            'margins' => $setup->margins(),
            'header' => ($band = $setup->chromeBand('header', $this->engine, $docs[0] ?? [], $font)) ? $this->inlineFiles($band) : null,
            'footer' => ($band = $setup->chromeBand('footer', $this->engine, $docs[0] ?? [], $font)) ? $this->inlineFiles($band) : null,
        ]);
    }

    // Local image and font files (public/ images, the font library) → data: URLs, for the Chrome engine
    protected function inlineFiles(string $html): string
    {
        $mime = fn (string $path) => match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
            'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2', default => null,
        };
        $inline = function (array $m) use ($mime) {
            $path = html_entity_decode($m[2], ENT_QUOTES);
            if (str_starts_with($path, 'data:') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) || ! ($type = $mime($path)) || ! is_file($path)) {
                return $m[0];
            }

            return $m[1].'data:'.$type.';base64,'.base64_encode((string) file_get_contents($path)).$m[3];
        };
        $html = (string) preg_replace_callback('#(<img[^>]+src=["\'])([^"\']+)(["\'])#i', $inline, $html);

        return (string) preg_replace_callback('#(url\(\s*["\']?)([^"\')]+)(["\']?\s*\))#i', $inline, $html);
    }

    /** @return array{0: float, 1: float} page width / height in pt */
    protected function pageSize(PdfTemplate $template): array
    {
        $size = CPDF::$PAPER_SIZES[strtolower($template->paper ?: 'a4')] ?? CPDF::$PAPER_SIZES['a4'];
        [$width, $height] = [(float) $size[2], (float) $size[3]];

        return $template->orientation === 'landscape' ? [$height, $width] : [$width, $height];
    }

    // Drawn on every page after the layout, centred and rotated, see-through
    protected function watermark(\Barryvdh\DomPDF\PDF $pdf, ?array $watermark): \Barryvdh\DomPDF\PDF
    {
        if (! $watermark) {
            return $pdf;
        }
        // After render(): page scripts run when the PDF is written out
        $dompdf = $pdf->getDomPDF();
        [$r, $g, $b] = sscanf($watermark['color'], '#%02x%02x%02x');
        $dompdf->getCanvas()->page_script(function ($page, $pages, $canvas, $fonts) use ($watermark, $r, $g, $b) {
            $font = $fonts->getFont(config('pdf-templates.html.default_font', 'DejaVu Sans'), 'bold') ?? $fonts->getFont('DejaVu Sans', 'bold');
            $width = $canvas->get_width();
            $height = $canvas->get_height();
            $size = Watermark::fitSize($watermark, $width, $height, $fonts->getTextWidth($watermark['text'], $font, 1));
            $textWidth = $fonts->getTextWidth($watermark['text'], $font, $size);
            // Start of the baseline so that the middle of the text sits on the page centre (the baseline goes 0.35 em
            // below it, along the text's own "down"); dompdf adds the font height to y and turns clockwise
            $angle = deg2rad($watermark['angle']);
            $x = $width / 2 - cos($angle) * $textWidth / 2 + sin($angle) * $size * 0.35;
            $y = $height / 2 + sin($angle) * $textWidth / 2 + cos($angle) * $size * 0.35 - $fonts->getFontHeight($font, $size);
            $canvas->set_opacity($watermark['opacity']);
            $canvas->text($x, $y, $watermark['text'], $font, $size, [$r / 255, $g / 255, $b / 255], 0, 0, -$watermark['angle']);
            $canvas->set_opacity(1);
        });

        return $pdf;
    }

    protected function dompdf(PdfTemplate $template, string $html): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadHTML($html)
            ->setPaper($template->paper ?: 'a4', $template->orientation ?: 'portrait')
            ->setOption('defaultFont', config('pdf-templates.html.default_font', 'DejaVu Sans'))
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false)
            ->setOption('isFontSubsettingEnabled', true)
            ->setOption('chroot', $this->chroot());
    }

    // Full HTML document; a list of objects renders one document per item, each on new pages
    // $chrome: for the Chrome engine (its header / footer are separate templates, not fixed elements)
    public function document(PdfTemplate $template, array $data, bool $chrome = false): string
    {
        $docs = array_is_list($data) && $data && is_array($data[0]) ? $data : [$data];

        $body = collect($docs)
            ->map(fn ($doc) => $this->engine->render((string) $template->html, $doc))
            ->implode('<div style="page-break-after: always;"></div>');

        // Margins, header / footer from the editor menu; a header shows the first document's data
        $setup = PageSetup::from($template->settings);

        // pdf-asset:… (uploaded images) in the HTML, the data and the CSS → data URLs
        $images = app(ImageLibrary::class);

        return $images->resolve($this->percentHeights($this->pxSizes(view('pdf-templates::pdf.document', [
            'template' => $template,
            'body' => $this->localizeImages($images->resolve($body)),
            'setupCss' => $setup->css(),
            'setupHtml' => $chrome ? '' : $this->localizeImages($images->resolve($setup->html($this->engine, $docs[0] ?? []))),
            'fonts' => app(FontLibrary::class)->fonts(),
            'defaultFont' => config('pdf-templates.html.default_font', 'DejaVu Sans'),
        ])->render())));
    }

    /**
     * height: 100% on a div / p / span…: a browser ignores it when the parent has no fixed height (a table cell, the
     * page), dompdf takes it as the whole page and pushes the table to the next one. Dropped, like a browser does.
     */
    public function percentHeights(string $html): string
    {
        return (string) preg_replace_callback(
            '/(<(?:div|p|span|section|article|header|footer|main|aside|figure|a|strong|em|b|i)\b[^>]*\sstyle\s*=\s*)(["\'])(.*?)\2/is',
            fn (array $m) => $m[1].$m[2].ltrim((string) preg_replace('/(^|;)\s*(?:min-|max-)?height\s*:\s*[\d.]+%\s*(?=;|$)/i', '$1', $m[3]), '; ').$m[2],
            $html,
        );
    }

    // style="height:120" → height:120px: a size without a unit is invalid CSS and ignored (the visual editor does the same)
    public function pxSizes(string $html): string
    {
        $properties = 'width|height|min-width|max-width|min-height|max-height|font-size|margin(?:-top|-right|-bottom|-left)?|padding(?:-top|-right|-bottom|-left)?|top|left|right|bottom|border-width|border-radius';

        return (string) preg_replace_callback('/(\sstyle\s*=\s*)(["\'])(.*?)\2/is', fn (array $m) => $m[1].$m[2].preg_replace_callback(
            '/(^|;)(\s*(?:'.$properties.')\s*:\s*)(\d+(?:\.\d+)?)(?=\s*(?:;|$))/i',
            fn (array $s) => (float) $s[3] === 0.0 ? $s[0] : $s[1].$s[2].$s[3].'px',
            $m[3],
        ).$m[2], $html);
    }

    // dompdf runs with remote access off: same-site image URLs → local files in public/, other https://… images
    // (in the template or from the data, e.g. {{ logo_url }}) → fetched here, public hosts only (html.remote_images)
    public function localizeImages(string $html): string
    {
        $remote = (bool) config('pdf-templates.html.remote_images', true);
        $hosts = array_filter([request()->getHost(), parse_url((string) config('app.url'), PHP_URL_HOST)]);

        return preg_replace_callback('#(<img[^>]+src=["\'])([^"\']+)(["\'])#i', function ($m) use ($hosts, $remote) {
            $src = $m[2];
            if (str_starts_with($src, 'data:')) {
                return $m[0];
            }

            $path = parse_url($src, PHP_URL_PATH);
            $host = parse_url($src, PHP_URL_HOST);
            if ($path && (! $host || in_array($host, $hosts, true))) {
                $local = public_path(ltrim(rawurldecode($path), '/'));
                if (is_file($local)) {
                    return $m[1].(realpath($local) ?: $local).$m[3];
                }
            }
            if ($remote && $host && preg_match('#^https?://#i', $src)) {
                $data = app(ImageLibrary::class)->remoteDataUrl(html_entity_decode($src, ENT_QUOTES));
                if ($data) {
                    return $m[1].$data.$m[3];
                }
            }

            return $m[0];
        }, $html);
    }

    protected function chroot(): array
    {
        $fontDirs = collect(app(FontLibrary::class)->fonts())
            ->flatten(1)
            ->map(fn ($font) => is_array($font) ? dirname($font[0]) : null);

        return collect([public_path(), ...(array) config('pdf-templates.html.chroot', [])])
            ->merge($fontDirs)
            ->filter()
            ->map(fn ($dir) => realpath($dir))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
