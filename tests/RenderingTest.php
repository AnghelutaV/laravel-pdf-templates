<?php

namespace AnghelutaV\PdfTemplates\Tests;

use AnghelutaV\PdfTemplates\Exceptions\RenderException;
use AnghelutaV\PdfTemplates\Facades\PdfTemplates;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\PendingPdf;
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
        $html = app(\AnghelutaV\PdfTemplates\Renderers\HtmlRenderer::class)->document($template, [
            ['title' => 'First', 'items' => [['name' => 'a']]],
            ['title' => 'Second', 'items' => []],
        ]);

        $this->assertStringContainsString('<h1>First</h1><p>1. a</p>', $html);
        $this->assertStringContainsString('<h1>Second</h1>', $html);
        $this->assertSame(1, substr_count($html, 'page-break-after'));
        $this->assertStringContainsString('h1 { color: #123; }', $html);
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
}
