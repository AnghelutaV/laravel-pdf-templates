<?php

namespace AnghelutaV\PdfTemplates\Renderers;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\TemplateEngine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

// HTML mode: placeholders filled by TemplateEngine, PDF made by dompdf (remote resources disabled)
class HtmlRenderer
{
    public function __construct(protected TemplateEngine $engine) {}

    public function pdf(PdfTemplate $template, array $data): string
    {
        File::ensureDirectoryExists(storage_path('fonts'));

        return Pdf::loadHTML($this->document($template, $data))
            ->setPaper($template->paper ?: 'a4', $template->orientation ?: 'portrait')
            ->setOption('defaultFont', config('pdf-templates.html.default_font', 'DejaVu Sans'))
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false)
            ->setOption('isFontSubsettingEnabled', true)
            ->setOption('chroot', $this->chroot())
            ->output();
    }

    // Full HTML document; a list of objects renders one document per item, each on new pages
    public function document(PdfTemplate $template, array $data): string
    {
        $docs = array_is_list($data) && $data && is_array($data[0]) ? $data : [$data];

        $body = collect($docs)
            ->map(fn ($doc) => $this->engine->render((string) $template->html, $doc))
            ->implode('<div style="page-break-after: always;"></div>');

        return view('pdf-templates::pdf.document', [
            'template' => $template,
            'body' => $this->localizeImages($body),
            'fonts' => config('pdf-templates.html.fonts', []),
            'defaultFont' => config('pdf-templates.html.default_font', 'DejaVu Sans'),
        ])->render();
    }

    // dompdf runs with remote access off: point same-site image URLs to local files in public/
    public function localizeImages(string $html): string
    {
        $hosts = array_filter([request()->getHost(), parse_url((string) config('app.url'), PHP_URL_HOST)]);

        return preg_replace_callback('#(<img[^>]+src=["\'])([^"\']+)(["\'])#i', function ($m) use ($hosts) {
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

            return $m[0];
        }, $html);
    }

    protected function chroot(): array
    {
        $fontDirs = collect(config('pdf-templates.html.fonts', []))
            ->flatten(1)
            ->map(fn ($font) => is_array($font) ? dirname($font[0]) : null);

        return collect([public_path(), ...config('pdf-templates.html.chroot', [])])
            ->merge($fontDirs)
            ->filter()
            ->map(fn ($dir) => realpath($dir))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
