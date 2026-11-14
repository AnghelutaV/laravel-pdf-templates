<?php

namespace AnghelutaV\PdfTemplates\Renderers;

use AnghelutaV\PdfTemplates\Exceptions\RendererUnavailableException;
use AnghelutaV\PdfTemplates\Exceptions\RenderException;
use AnghelutaV\PdfTemplates\ImageLibrary;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// WYSIWYG mode: pdfme only runs in JS, so server-side generation goes through the Node renderer
class WysiwygRenderer
{
    public static function configured(): bool
    {
        return (bool) config('pdf-templates.pdfme.renderer_url');
    }

    // GET /health with a short timeout: lets the test page say "not running" before anyone clicks
    public static function reachable(?string $url = null): bool
    {
        try {
            return Http::connectTimeout(1)->timeout(2)
                ->get(rtrim((string) ($url ?? config('pdf-templates.pdfme.renderer_url')), '/').'/health')
                ->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    // $data as a raw JSON string is passed through untouched, so {} stays an object (PHP would turn it into [])
    /** @param  ?array  $watermark  normalized, see Watermark; the renderer adds it as a text field on every page */
    public function pdf(PdfTemplate $template, string|array|null $data, ?array $watermark = null): string
    {
        $url = config('pdf-templates.pdfme.renderer_url');
        if (! $url) {
            throw new RenderException('Server-side WYSIWYG rendering needs the pdfme renderer (PDFME_RENDERER_URL is not set, see php artisan pdf-templates:renderer).');
        }

        $inputs = is_string($data) && trim($data) !== '' ? $data : json_encode($data ?: new \stdClass, JSON_UNESCAPED_UNICODE);
        // pdf-asset:… (uploaded images) in the template and the data → data URLs (pdfme only takes those)
        $body = app(ImageLibrary::class)->resolve('{"template":'.($template->schema ?: PdfTemplate::BLANK_SCHEMA).',"inputs":'.$inputs.($watermark ? ',"watermark":'.json_encode($watermark, JSON_UNESCAPED_UNICODE) : '').'}');

        try {
            $response = Http::withHeaders(['X-Renderer-Token' => (string) config('pdf-templates.pdfme.renderer_token')])
                ->timeout((int) config('pdf-templates.pdfme.timeout', 60))
                ->withBody($body, 'application/json')
                ->post(rtrim($url, '/').'/render');
        } catch (ConnectionException) {
            // Nothing listening (renderer not started) or a timeout: say what to do instead of the raw cURL error
            throw new RendererUnavailableException(__('pdf-templates::ui.renderer_down', ['url' => $url]));
        }

        if ($response->failed()) {
            throw new RenderException('pdfme renderer: '.($response->json('error') ?? 'HTTP '.$response->status()));
        }

        return $response->body();
    }

    // One page of any PDF (HTML or WYSIWYG) as PNG, made by the Node renderer (POST /image)
    public function png(string $pdf, int $page = 1, float $scale = 2): string
    {
        $url = config('pdf-templates.pdfme.renderer_url');
        if (! $url) {
            throw new RenderException('Images (png / jpg) are made by the Node renderer: PDFME_RENDERER_URL is not set (php artisan pdf-templates:renderer).');
        }

        try {
            $response = Http::withHeaders(['X-Renderer-Token' => (string) config('pdf-templates.pdfme.renderer_token')])
                ->timeout((int) config('pdf-templates.pdfme.timeout', 60))
                ->withBody($pdf, 'application/pdf')
                ->post(rtrim($url, '/').'/image?'.http_build_query(['page' => $page, 'scale' => $scale]));
        } catch (ConnectionException) {
            throw new RendererUnavailableException(__('pdf-templates::ui.renderer_down', ['url' => $url]));
        }

        if ($response->failed()) {
            throw new RenderException('pdfme renderer: '.($response->json('error') ?? 'HTTP '.$response->status()));
        }

        return $response->body();
    }
}
