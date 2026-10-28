<?php

namespace AnghelutaV\PdfTemplates\Renderers;

use AnghelutaV\PdfTemplates\Exceptions\RenderException;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use Illuminate\Support\Facades\Http;

// WYSIWYG mode: pdfme only runs in JS, so server-side generation goes through the Node renderer
class WysiwygRenderer
{
    public static function configured(): bool
    {
        return (bool) config('pdf-templates.pdfme.renderer_url');
    }

    // $data as a raw JSON string is passed through untouched, so {} stays an object (PHP would turn it into [])
    public function pdf(PdfTemplate $template, string|array|null $data): string
    {
        $url = config('pdf-templates.pdfme.renderer_url');
        if (! $url) {
            throw new RenderException('Server-side WYSIWYG rendering needs the pdfme renderer (PDFME_RENDERER_URL is not set, see php artisan pdf-templates:renderer).');
        }

        $inputs = is_string($data) && trim($data) !== '' ? $data : json_encode($data ?: new \stdClass, JSON_UNESCAPED_UNICODE);
        $body = '{"template":'.($template->schema ?: PdfTemplate::BLANK_SCHEMA).',"inputs":'.$inputs.'}';

        $response = Http::withHeaders(['X-Renderer-Token' => (string) config('pdf-templates.pdfme.renderer_token')])
            ->timeout((int) config('pdf-templates.pdfme.timeout', 60))
            ->withBody($body, 'application/json')
            ->post(rtrim($url, '/').'/render');

        if ($response->failed()) {
            throw new RenderException('pdfme renderer: '.($response->json('error') ?? 'HTTP '.$response->status()));
        }

        return $response->body();
    }
}
