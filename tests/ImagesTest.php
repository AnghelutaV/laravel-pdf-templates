<?php

namespace AnghelutaV\PdfTemplates\Tests;

use AnghelutaV\PdfTemplates\Ai\StructuredTemplateAssistant;
use AnghelutaV\PdfTemplates\ImageLibrary;
use AnghelutaV\PdfTemplates\PdfTemplates;
use AnghelutaV\PdfTemplates\Renderers\HtmlRenderer;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;

class ImagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['pdf-templates.images.disk' => 'images']);
        Storage::fake('images');
    }

    protected function upload(string $name = 'Company Logo.png'): string
    {
        return $this->post('/pdf-templates/images', ['images' => [UploadedFile::fake()->image($name, 20, 10)]])
            ->assertOk()
            ->json('stored.0');
    }

    public function test_upload_list_and_names(): void
    {
        $this->assertSame('company-logo.png', $this->upload());
        $this->assertSame('company-logo-2.png', $this->upload());
        Storage::disk('images')->assertExists('pdf-templates/images/company-logo.png');

        $this->getJson('/pdf-templates/images')
            ->assertOk()
            ->assertJsonCount(2, 'images')
            ->assertJsonPath('images.0.ref', fn ($ref) => str_starts_with($ref, 'pdf-asset:company-logo'))
            ->assertJsonPath('images.0.url', fn ($url) => str_contains($url, '/pdf-templates/images/company-logo'));
    }

    public function test_only_png_jpg_svg(): void
    {
        $this->postJson('/pdf-templates/images', ['images' => [UploadedFile::fake()->create('notes.txt', 1, 'text/plain')]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images.0');
        $this->postJson('/pdf-templates/images', ['images' => [UploadedFile::fake()->image('photo.webp')]])
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('images')->allFiles());
    }

    public function test_show_and_delete(): void
    {
        $name = $this->upload('logo.png');

        $this->get("/pdf-templates/images/{$name}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/pdf-templates/images/missing.png')->assertNotFound();
        // Never a file outside the images folder (the router normalizes the path away from the image route)
        $this->assertStringNotContainsString('image/', (string) $this->get('/pdf-templates/images/..%2F..%2F.env')->headers->get('Content-Type'));
        $this->assertNull(app(ImageLibrary::class)->contents('../../.env'));

        $this->deleteJson("/pdf-templates/images/{$name}")->assertOk()->assertJsonCount(0, 'images');
        Storage::disk('images')->assertMissing("pdf-templates/images/{$name}");
        $this->deleteJson("/pdf-templates/images/{$name}")->assertNotFound();
    }

    public function test_html_render_resolves_references(): void
    {
        $name = $this->upload('logo.png');
        $template = $this->htmlTemplate([
            'html' => '<img src="pdf-asset:'.$name.'"><img src="{signature}"><img src="pdf-asset:unknown.png">',
            'css' => '.x { background: url(pdf-asset:'.$name.'); }',
        ]);

        $document = app(HtmlRenderer::class)->document($template, ['signature' => 'pdf-asset:'.$name]);

        $this->assertSame(3, substr_count($document, 'data:image/png;base64,'));
        $this->assertStringContainsString('pdf-asset:unknown.png', $document);
        $this->assertIsPdf(app(PdfTemplates::class)->render($template, ['signature' => 'pdf-asset:'.$name]));
    }

    public function test_wysiwyg_render_resolves_references(): void
    {
        $name = $this->upload('stamp.png');
        config(['pdf-templates.pdfme.renderer_url' => 'http://renderer.test']);
        Http::fake(['renderer.test/render' => Http::response('%PDF-fake', 200)]);

        $template = $this->wysiwygTemplate(['schema' => json_encode(['basePdf' => ['width' => 210, 'height' => 297, 'padding' => [0, 0, 0, 0]], 'schemas' => [[
            ['name' => 'stamp', 'type' => 'image', 'readOnly' => true, 'content' => 'pdf-asset:'.$name, 'position' => ['x' => 10, 'y' => 10], 'width' => 30, 'height' => 15],
        ]]])]);
        app(PdfTemplates::class)->render($template, ['logo' => 'pdf-asset:'.$name]);

        Http::assertSent(fn (Request $request) => substr_count($request->body(), 'data:image/png;base64,') === 2
            && ! str_contains($request->body(), 'pdf-asset:'));
    }

    public function test_the_assistant_knows_the_images(): void
    {
        $this->upload('logo.png');
        $template = $this->htmlTemplate();
        StructuredTemplateAssistant::fake([['reply' => 'Ok', 'name' => '', 'html' => '', 'css' => '', 'sample_data' => '', 'paper' => '', 'orientation' => '']]);

        $this->post("/pdf-templates/{$template->id}/assistant", ['message' => 'Put our logo top left'])->assertOk();

        StructuredTemplateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('pdf-asset:logo.png'));
    }

    public function test_editors_show_the_library(): void
    {
        $this->get("/pdf-templates/{$this->htmlTemplate()->id}/edit")->assertOk()->assertSee('id="pt-images"', false);
        $this->get("/pdf-templates/{$this->wysiwygTemplate()->id}/edit")->assertOk()->assertSee('id="pt-images"', false);
        $this->assertTrue(ImageLibrary::valid('logo-2.png'));
        $this->assertFalse(ImageLibrary::valid('../logo.png'));
    }
}
