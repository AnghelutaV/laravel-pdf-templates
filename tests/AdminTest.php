<?php

namespace AnghelutaV\PdfTemplates\Tests;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Gate;

class AdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_pages_render(): void
    {
        $html = $this->htmlTemplate();
        $designer = $this->wysiwygTemplate();

        $this->get('/pdf-templates')->assertOk()->assertSee('Invoice')->assertSee('Designer');
        $this->get('/pdf-templates?mode=html')->assertOk()->assertSee('Invoice')->assertDontSee('/pdf-templates/'.$designer->id.'/edit');

        foreach ([$html, $designer] as $template) {
            $this->get("/pdf-templates/{$template->id}/edit")->assertOk()->assertSee('importmap', false);
            $this->get("/pdf-templates/{$template->id}/test")->assertOk();
            $this->get("/pdf-templates/{$template->id}/export")->assertOk()->assertHeader('Content-Type', 'application/json');
        }

        // pdfme version is built in PHP: Blade's "@{{" escape must not leak into the import map
        $this->get("/pdf-templates/{$designer->id}/edit")->assertSee('https://esm.sh/@pdfme/ui@6.2.8', false);
    }

    public function test_test_page_lists_fields_and_survives_broken_syntax(): void
    {
        $template = $this->htmlTemplate();
        $broken = $this->htmlTemplate(['name' => 'Broken', 'html' => '{#each items}']);

        $this->get("/pdf-templates/{$template->id}/test")->assertOk()->assertSee('items.*.name');
        $this->get("/pdf-templates/{$broken->id}/test")->assertOk()->assertSee('Fix the template syntax');
    }

    public function test_store_update_duplicate_destroy(): void
    {
        $this->post('/pdf-templates', ['name' => 'Offer', 'mode' => 'html'])->assertRedirect();
        $template = PdfTemplate::where('slug', 'offer')->firstOrFail();
        $this->assertStringContainsString('{#each items}', $template->html);

        $this->putJson("/pdf-templates/{$template->id}", ['name' => 'Offer 2', 'sample_data' => '{"a":1}'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('{"a":1}', $template->fresh()->sample_data);

        $this->putJson("/pdf-templates/{$template->id}", ['schema' => '{bad'])->assertStatus(422);

        $this->post("/pdf-templates/{$template->id}/duplicate")->assertRedirect();
        $this->assertDatabaseHas('pdf_templates', ['slug' => 'offer-2-copy']);

        $this->delete("/pdf-templates/{$template->id}")->assertRedirect('/pdf-templates');
        $this->assertDatabaseMissing('pdf_templates', ['id' => $template->id]);
    }

    public function test_slug_must_be_unique(): void
    {
        $this->htmlTemplate();
        $other = $this->htmlTemplate(['name' => 'Other']);

        $this->putJson("/pdf-templates/{$other->id}", ['slug' => 'invoice'])->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_preview_uses_unsaved_html_without_saving(): void
    {
        $template = $this->htmlTemplate();

        $response = $this->post("/pdf-templates/{$template->id}/render", ['html' => '<p>{x}</p>', 'data' => '{"x":1}']);

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('<h1>{title}</h1>{#each items}<p>{@number}. {name}</p>{/each}', $template->fresh()->html);

        $this->post("/pdf-templates/{$template->id}/render", ['html' => '{#each a}', 'data' => '{}'])
            ->assertStatus(422)->assertJson(['error' => '{#each} without {/each}']);
    }

    public function test_assets_are_served_and_traversal_is_blocked(): void
    {
        $this->get('/pdf-templates/assets/helpers.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->get('/pdf-templates/assets/pdf-templates.css')->assertOk();
        $this->get('/pdf-templates/assets/..%2Fcomposer.json')->assertNotFound();
        $this->get('/pdf-templates/assets/missing.js')->assertNotFound();
    }

    public function test_without_gate_only_local_is_allowed(): void
    {
        config(['pdf-templates.gate' => 'viewPdfTemplates']);

        $this->app['env'] = 'production';
        $this->get('/pdf-templates')->assertForbidden();

        $this->app['env'] = 'local';
        $this->get('/pdf-templates')->assertOk();
    }

    public function test_gate_decides_when_defined(): void
    {
        config(['pdf-templates.gate' => 'viewPdfTemplates']);
        $this->app['env'] = 'production';

        Gate::define('viewPdfTemplates', fn ($user = null) => false);
        $this->get('/pdf-templates')->assertForbidden();

        Gate::define('viewPdfTemplates', fn ($user = null) => true);
        $this->get('/pdf-templates')->assertOk();
    }

    public function test_routes_follow_config(): void
    {
        $this->assertSame(url('pdf-templates'), route('pdf-templates.index'));
        $this->assertSame(url('api/pdf-templates/x'), route('pdf-templates.api', 'x'));
    }
}
