<?php

namespace AnghelutaV\PdfTemplates\Renderers;

use AnghelutaV\PdfTemplates\Exceptions\RendererUnavailableException;
use AnghelutaV\PdfTemplates\Exceptions\RenderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTML templates with the Chrome engine (template settings → engine "chrome", when config chrome.enabled is on):
 * the document goes to the Node renderer (POST /html), which prints it with a headless Chrome / Edge / Chromium.
 * Flexbox, grid, position: absolute, web fonts… come out as in a browser, like APITemplate.io / CraftMyPDF.
 */
class ChromeRenderer
{
    // On in the config (PDF_TEMPLATES_CHROME): only then can a template choose it
    public static function enabled(): bool
    {
        return (bool) config('pdf-templates.chrome.enabled', false);
    }

    /**
     * @param  array{paper?: string, landscape?: bool, margins?: ?array, header?: ?string, footer?: ?string}  $options
     */
    public function pdf(string $html, array $options = []): string
    {
        $url = config('pdf-templates.pdfme.renderer_url');
        if (! $url) {
            throw new RenderException('The Chrome engine runs in the Node renderer: PDFME_RENDERER_URL is not set (php artisan pdf-templates:renderer).');
        }

        try {
            $response = Http::withHeaders(['X-Renderer-Token' => (string) config('pdf-templates.pdfme.renderer_token')])
                ->timeout((int) config('pdf-templates.pdfme.timeout', 60))
                ->post(rtrim((string) $url, '/').'/html', $options + [
                    'html' => $html,
                    'chrome' => config('pdf-templates.chrome.path') ?: null,
                ]);
        } catch (ConnectionException) {
            throw new RendererUnavailableException(__('pdf-templates::ui.renderer_down', ['url' => $url]));
        }

        if ($response->failed()) {
            throw new RenderException('Chrome engine: '.($response->json('error') ?? 'HTTP '.$response->status()));
        }

        return $response->body();
    }
}
