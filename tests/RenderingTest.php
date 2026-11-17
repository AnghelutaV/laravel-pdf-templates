<?php

namespace AnghelutaV\PdfTemplates\Tests;

use AnghelutaV\PdfTemplates\Exceptions\RenderException;
use AnghelutaV\PdfTemplates\Facades\PdfTemplates;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\PageSetup;
use AnghelutaV\PdfTemplates\PendingPdf;
use AnghelutaV\PdfTemplates\Renderers\HtmlRenderer;
use AnghelutaV\PdfTemplates\Renderers\WysiwygRenderer;
use AnghelutaV\PdfTemplates\TemplateEngine;
use AnghelutaV\PdfTemplates\Watermark;
use Barryvdh\DomPDF\PDF;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class RenderingTest extends TestCase
{
    public function test_html_template_renders_a_pdf_from_array_or_json(): void
    {
        $template = $this->htmlTemplate();

        $this->assertIsPdf(PdfTemplates::render('invoice', ['title' => 'X']));
        $this->assertIsPdf(PdfTemplates::render($template, '{"title":"X"}'));
    }

    public function test_html_document_is_filled_and_lists_become_several_documents(): void
    {
        $template = $this->htmlTemplate();
        $html = app(HtmlRenderer::class)->document($template, [
            ['title' => 'First', 'items' => [['name' => 'a']]],
            ['title' => 'Second', 'items' => []],
        ]);

        $this->assertStringContainsString('<h1>First</h1><p>1. a</p>', $html);
        $this->assertStringContainsString('<h1>Second</h1>', $html);
        $this->assertSame(1, substr_count($html, 'page-break-after'));
        $this->assertStringContainsString('h1 { color: #123; }', $html);
    }

    public function test_html_total_page_count(): void
    {
        $template = $this->htmlTemplate([
            'html' => '<div class="footer"><span class="pagenum"></span></div>{#each items}<p class="page">{name}</p>{/each}',
            'css' => '.footer { position: fixed; bottom: 0; } .page { page-break-after: always; }'
                .' .pagenum:before { content: "Page " counter(page) " of " counter( pages ); }',
        ]);
        $renderer = new class(app(TemplateEngine::class)) extends HtmlRenderer
        {
            public array $passes = [];

            protected function dompdf(PdfTemplate $template, string $html): PDF
            {
                $this->passes[] = $html;

                return parent::dompdf($template, $html)->setOption('isFontSubsettingEnabled', false);
            }
        };

        $pdf = $renderer->pdf($template, ['items' => [['name' => 'a'], ['name' => 'b']]]);

        // Two items, a page break after each: 3 pages. First pass counts them, the second one prints the total.
        $this->assertIsPdf($pdf);
        $this->assertCount(2, $renderer->passes);
        $this->assertStringContainsString('counter(page) " of " "1"', $renderer->passes[0]);
        $this->assertStringContainsString('counter(page) " of " "3"', $renderer->passes[1]);
        $this->assertSame(3, preg_match_all('#/Type /Page\b#', $pdf));

        // Without counter(pages): a single pass
        $renderer->passes = [];
        $renderer->pdf($this->htmlTemplate(), ['title' => 'X']);
        $this->assertCount(1, $renderer->passes);
    }

    public function test_invalid_json_and_unknown_template_throw(): void
    {
        $this->htmlTemplate();

        $this->assertThrows(fn () => PdfTemplates::render('invoice', '{bad'), InvalidArgumentException::class);
        $this->assertThrows(fn () => PdfTemplates::render('missing'), RenderException::class);
    }

    public function test_pending_pdf(): void
    {
        Storage::fake('local');
        $this->htmlTemplate();

        $pending = PdfTemplates::make('invoice', ['title' => 'X'])->filename('Factură 1.pdf');

        $this->assertInstanceOf(PendingPdf::class, $pending);
        $this->assertSame('invoices/1.pdf', $pending->save('invoices/1.pdf', 'local'));
        Storage::disk('local')->assertExists('invoices/1.pdf');
        $this->assertIsPdf(base64_decode($pending->base64()));
        $this->assertSame('attachment; filename="factura-1.pdf"', $pending->download()->headers->get('Content-Disposition'));
        $this->assertSame('inline; filename="factura-1.pdf"', $pending->toResponse(request())->headers->get('Content-Disposition'));
        $this->assertInstanceOf(Attachment::class, $pending->attachment());
    }

    public function test_options_lists_active_templates_by_name(): void
    {
        $this->htmlTemplate(['name' => 'B html']);
        $this->wysiwygTemplate(['name' => 'A designer']);
        $this->htmlTemplate(['name' => 'C inactive', 'active' => false]);

        $this->assertSame(['a-designer' => 'A designer', 'b-html' => 'B html'], PdfTemplates::options());
        $this->assertSame(['b-html' => 'B html'], PdfTemplates::options(PdfTemplate::MODE_HTML));
    }

    public function test_fields_for_both_modes(): void
    {
        $this->htmlTemplate();
        $this->wysiwygTemplate();

        $this->assertSame(['title' => 'value', 'items' => 'list', 'items.*.name' => 'value'], PdfTemplates::fields('invoice'));
        $this->assertSame(['client' => 'text', 'items' => 'table'], PdfTemplates::fields('designer'));

        // {{ placeholders }} in texts are data keys; the text that carries them is not
        PdfTemplate::create(['name' => 'Placeholders', 'slug' => 'placeholders', 'mode' => PdfTemplate::MODE_WYSIWYG, 'schema' => json_encode([
            'basePdf' => ['width' => 210, 'height' => 297, 'padding' => [10, 10, 10, 10], 'staticSchema' => [
                ['name' => 'foot', 'type' => 'text', 'readOnly' => true, 'content' => '{{ company }} {currentPage}'],
            ]],
            'schemas' => [[
                ['name' => 'heading', 'type' => 'text', 'readOnly' => true, 'content' => 'Invoice {{ number }} for {{ client.name|upper }}'],
                ['name' => 'intro', 'type' => 'text', 'content' => 'Total {{ total|number:2 }}'],
                ['name' => 'qty', 'type' => 'text', 'content' => '5'],
                ['name' => 'sum', 'type' => 'text', 'readOnly' => true, 'content' => '{{ sum(items, "qty * price")|number:2 }} {{ paid ? "Paid" : "Due" }}'],
                ['name' => 'stamp', 'type' => 'rectangle', 'readOnly' => true, 'showIf' => 'status == "paid" and not archived'],
            ]],
        ])]);
        $this->assertSame(
            ['qty' => 'text', 'number' => 'text', 'client.name' => 'text', 'total' => 'text', 'items' => 'text', 'paid' => 'text', 'status' => 'condition', 'archived' => 'condition', 'company' => 'text'],
            PdfTemplates::fields('placeholders'),
        );
    }

    public function test_slugs_are_unique(): void
    {
        $this->assertSame('invoice', $this->htmlTemplate()->slug);
        $this->assertSame('invoice-2', $this->htmlTemplate()->slug);
    }

    public function test_wysiwyg_without_renderer_fails_clearly(): void
    {
        $this->wysiwygTemplate();

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('PDFME_RENDERER_URL');
        PdfTemplates::render('designer', []);
    }

    public function test_wysiwyg_goes_through_the_renderer_with_raw_json(): void
    {
        config(['pdf-templates.pdfme.renderer_url' => 'http://renderer.test', 'pdf-templates.pdfme.renderer_token' => 'secret']);
        Http::fake(['renderer.test/render' => Http::response('%PDF-fake', 200, ['Content-Type' => 'application/pdf'])]);
        $this->wysiwygTemplate();

        $this->assertSame('%PDF-fake', PdfTemplates::render('designer', '{"client":"Ana","meta":{}}'));

        Http::assertSent(function (Request $request) {
            // {} must reach pdfme as an object, so the JSON is passed through, not decoded and re-encoded
            return $request->url() === 'http://renderer.test/render'
                && $request->hasHeader('X-Renderer-Token', 'secret')
                && str_contains($request->body(), '"inputs":{"client":"Ana","meta":{}}')
                && $request['template']['basePdf']['width'] === 210;
        });
    }

    public function test_renderer_errors_are_reported(): void
    {
        config(['pdf-templates.pdfme.renderer_url' => 'http://renderer.test']);
        Http::fake(['renderer.test/render' => Http::response(['error' => 'bad template'], 422)]);
        $this->wysiwygTemplate();

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('bad template');
        PdfTemplates::render('designer', []);
    }

    public function test_cache_reuses_the_pdf_for_the_same_content_and_data(): void
    {
        config([
            'pdf-templates.pdfme.renderer_url' => 'http://renderer.test',
            'pdf-templates.cache.ttl' => 60,
            'pdf-templates.cache.store' => 'array',
        ]);
        Http::fake(['renderer.test/render' => Http::response('%PDF-fake', 200)]);
        $template = $this->wysiwygTemplate();

        PdfTemplates::render('designer', '{"client":"Ana"}');
        PdfTemplates::render('designer', '{"client":"Ana"}');
        Http::assertSentCount(1);

        PdfTemplates::render('designer', '{"client":"Ion"}');
        Http::assertSentCount(2);

        // Edited template = new content = new PDF
        $template->update(['schema' => str_replace('210', '211', $template->schema)]);
        PdfTemplates::render('designer', '{"client":"Ana"}');
        Http::assertSentCount(3);
    }

    public function test_renderer_health_check(): void
    {
        config(['pdf-templates.pdfme.renderer_url' => 'http://renderer.test']);
        Http::fake(['renderer.test/health' => Http::response(['ok' => true])]);
        $this->assertTrue(WysiwygRenderer::reachable());

        Http::fake(fn () => throw new ConnectionException('refused'));
        $this->assertFalse(WysiwygRenderer::reachable());
    }

    public function test_renderer_not_running_says_how_to_start_it(): void
    {
        config(['pdf-templates.pdfme.renderer_url' => 'http://127.0.0.1:3311']);
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));
        $this->wysiwygTemplate();

        try {
            PdfTemplates::render('designer', []);
            $this->fail('RenderException expected');
        } catch (RenderException $e) {
            $this->assertStringContainsString('http://127.0.0.1:3311', $e->getMessage());
            $this->assertStringContainsString('php artisan pdf-templates:renderer', $e->getMessage());
            $this->assertStringNotContainsString('cURL', $e->getMessage());
        }
    }

    public function test_qr_codes_and_barcodes_in_html(): void
    {
        $engine = app(TemplateEngine::class);
        $html = $engine->render('{{ url|qrcode(80) }}|{sku|barcode}|{{ ean|barcode("ean13", 50) }}|{{ sku|barcode("ean13") }}|{{ missing|qrcode }}', [
            'url' => 'https://example.com/i/42', 'sku' => 'ABC-1', 'ean' => '5901234123457',
        ]);
        [$qr, $code128, $ean, $bad, $empty] = explode('|', $html);

        $this->assertStringStartsWith('<img class="pt-qrcode" src="data:image/svg+xml;base64,', $qr);
        $this->assertStringContainsString('width="80"', $qr);
        $this->assertStringStartsWith('<img class="pt-barcode"', $code128);
        $this->assertStringContainsString('height="50"', $ean);
        $this->assertSame(['', ''], [$bad, $empty]);
        $this->assertIsPdf(PdfTemplates::render($this->htmlTemplate(['html' => '<p>{{ url|qrcode }} {{ sku|barcode }}</p>']), ['url' => 'x', 'sku' => 'y']));
    }

    public function test_charts_in_html(): void
    {
        $engine = app(TemplateEngine::class);
        $sales = [['month' => 'Ian', 'total' => 1200.5], ['month' => 'Feb', 'total' => 900]];
        $html = $engine->render('{{ chart("bar", sales, "month", "total", "title=\'Vânzări <2026>\' width=300 height=150 suffix=\' lei\'") }}', ['sales' => $sales]);

        $this->assertStringStartsWith('<div class="pt-chart" style="position: relative; width: 300px; height: 150px;', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('Vânzări &lt;2026&gt;', $html);
        $this->assertStringContainsString('1 201 lei', $html);
        $this->assertStringContainsString('>Ian</div>', $html);

        foreach (['line', 'area', 'hbar', 'stacked', 'pie', 'doughnut'] as $type) {
            $this->assertStringContainsString('pt-chart', $engine->render("{{ chart(\"{$type}\", sales, \"month\") }}", ['sales' => $sales]), $type);
        }
        // An object is label → value; no data, no chart
        $this->assertStringContainsString('Gresie', $engine->render('{{ chart("pie", shares) }}', ['shares' => ['Gresie' => 40, 'Vopsea' => 60]]));
        $this->assertSame('', $engine->render('{{ chart("bar", nothing) }}', []));
        $this->assertArrayHasKey('sales', $engine->fields('{{ chart("bar", sales, "month", "total") }}'));
        $this->assertIsPdf(PdfTemplates::render($this->htmlTemplate(['html' => '{{ chart("line", sales, "month") }}']), ['sales' => $sales]));
    }

    public function test_watermark(): void
    {
        $template = $this->htmlTemplate();
        $plain = PdfTemplates::render($template, ['title' => 'A']);
        $marked = PdfTemplates::make($template, ['title' => 'A'])->watermark('CIORNĂ')->content();

        $this->assertIsPdf($marked);
        $this->assertNotSame(strlen($plain), strlen($marked));
        $this->assertNull(Watermark::normalize(' '));
        $this->assertSame(
            ['text' => 'COPY', 'opacity' => 1, 'color' => '#808080', 'angle' => 0.0, 'size' => 40.0],
            Watermark::normalize(['text' => 'COPY', 'opacity' => 5, 'color' => 'red', 'angle' => 360, 'size' => 40]),
        );

        // WYSIWYG: sent to the renderer with the template
        config(['pdf-templates.pdfme.renderer_url' => 'http://renderer.test']);
        Http::fake(['renderer.test/*' => Http::response('%PDF-1.7 fake', 200, ['Content-Type' => 'application/pdf'])]);
        PdfTemplates::make($this->wysiwygTemplate(), ['client' => 'Jane'])->watermark(['text' => 'DRAFT', 'color' => '#dc2626'])->content();
        Http::assertSent(fn (Request $request) => $request['watermark']['text'] === 'DRAFT' && $request['watermark']['color'] === '#dc2626');
    }

    public function test_zip_of_several_templates(): void
    {
        $this->htmlTemplate();
        $zip = PdfTemplates::zip([
            ['template' => 'invoice', 'data' => ['title' => 'A'], 'filename' => 'a'],
            ['template' => 'invoice', 'data' => '{"title":"B"}'],
        ]);
        $this->assertStringStartsWith("PK\x03\x04", $zip);

        $this->expectException(InvalidArgumentException::class);
        PdfTemplates::zip([]);
    }

    public function test_remote_images_are_fetched_for_dompdf(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake(['93.184.215.14/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $template = $this->htmlTemplate(['html' => '<img src="{{ logo }}"><img src="http://127.0.0.1/private.png">']);

        $html = app(HtmlRenderer::class)->document($template, ['logo' => 'https://93.184.215.14/logo.png?a=1&b=2']);
        $this->assertStringContainsString('src="data:image/png;base64,'.base64_encode($png).'"', $html);
        // Private addresses are never fetched
        $this->assertStringContainsString('src="http://127.0.0.1/private.png"', $html);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://93.184.215.14/logo.png?a=1&b=2');

        config(['pdf-templates.html.remote_images' => false]);
        $this->assertStringContainsString('src="https://93.184.215.14/x.png"', app(HtmlRenderer::class)->document($template, ['logo' => 'https://93.184.215.14/x.png']));
    }

    public function test_css_the_pdf_engine_would_misread_is_fixed(): void
    {
        $renderer = app(HtmlRenderer::class);

        // A size without a unit is px; 0 stays
        $this->assertSame('<img style="height:120px; margin:0">', $renderer->pxSizes('<img style="height:120; margin:0">'));
        // height: 100% on a div in a cell would be a whole page in dompdf
        $this->assertSame('<div class="x" style="position:relative;">', $renderer->percentHeights('<div class="x" style="height:100%;position:relative;">'));
        $this->assertSame('<table style="height:100%">', $renderer->percentHeights('<table style="height:100%">'));

        $html = $renderer->document($this->htmlTemplate(['html' => '<table style="text-align:left"><tr><th>a</th></tr></table><div style="height: 100%">b</div>']), []);
        $this->assertStringContainsString('table[style*="text-align"] th', $html);
        $this->assertStringContainsString('figure.image img', $html);
        $this->assertStringContainsString('<div style="">b</div>', $html);
    }

    public function test_chrome_engine(): void
    {
        config(['pdf-templates.pdfme.renderer_url' => 'http://renderer.test']);
        Http::fake(['renderer.test/*' => Http::response('%PDF-1.7 chrome', 200, ['Content-Type' => 'application/pdf'])]);
        $template = $this->htmlTemplate(['html' => '<div style="display:flex">{title}</div>', 'paper' => 'a5', 'orientation' => 'landscape', 'settings' => [
            'engine' => 'chrome',
            'margins' => [20, 10, 15, 10],
            'footer' => ['center' => 'Page {currentPage} of {totalPages} · {title}'],
        ]]);

        // Off in the config: the template's choice is ignored, dompdf renders it
        $this->assertStringStartsWith('%PDF-1.', PdfTemplates::render($template, ['title' => 'A']));
        Http::assertNothingSent();

        config(['pdf-templates.chrome.enabled' => true, 'pdf-templates.chrome.path' => 'C:/chrome.exe']);
        $this->assertSame('%PDF-1.7 chrome', PdfTemplates::make($template, ['title' => 'Hi'])->watermark('DRAFT')->content());
        Http::assertSent(function (Request $request) {
            return $request->url() === 'http://renderer.test/html'
                && str_contains($request['html'], 'display:flex') && str_contains($request['html'], '>Hi<')
                && str_contains($request['html'], 'position: fixed') && str_contains($request['html'], 'DRAFT')
                && ! str_contains($request['html'], 'pt-page-footer">')
                && $request['paper'] === 'a5' && $request['landscape'] === true && $request['margins'] === [20.0, 10.0, 15.0, 10.0]
                && str_contains($request['footer'], '<span class="pageNumber"></span> of <span class="totalPages"></span> · Hi')
                && $request['header'] === null && $request['chrome'] === 'C:/chrome.exe';
        });

        // Chrome on: the default for a template that did not pick an engine; dompdf when picked or chrome.default off
        $this->assertSame('chrome', PageSetup::from(null)->engine());
        $this->assertSame('dompdf', PageSetup::from(['engine' => 'dompdf'])->engine());
        config(['pdf-templates.chrome.default' => false]);
        $this->assertSame('dompdf', PageSetup::from([])->engine());
        $this->assertSame('chrome', PageSetup::from(['engine' => 'chrome'])->engine());
    }
}
