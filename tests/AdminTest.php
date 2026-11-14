<?php

namespace AnghelutaV\PdfTemplates\Tests;

use AnghelutaV\PdfTemplates\Facades\PdfTemplates as PdfTemplatesFacade;
use AnghelutaV\PdfTemplates\Gallery;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\PdfTemplates;
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

    public function test_html_editor_offers_the_visual_editor_unless_disabled(): void
    {
        $html = $this->htmlTemplate();
        $designer = $this->wysiwygTemplate();

        $this->get("/pdf-templates/{$html->id}/edit")->assertOk()
            ->assertSee('id="pt-mode-visual"', false)
            ->assertSee('ckeditor5@', false)
            ->assertSee('pdf-templates/visual', false);
        $this->get('/pdf-templates/assets/visual.js')->assertOk();
        $this->get('/pdf-templates/assets/visual-menu.js')->assertOk();
        // The designer has its own editor
        $this->get("/pdf-templates/{$designer->id}/edit")->assertOk()->assertDontSee('id="pt-mode-visual"', false);

        // A config published before html.editor existed: the `html` block has no editor key, defaults apply
        config(['pdf-templates.html' => ['default_font' => 'DejaVu Sans']]);
        $this->get("/pdf-templates/{$html->id}/edit")->assertOk()
            ->assertSee('id="pt-mode-visual"', false)
            ->assertSee('ckeditor5@49.1.0', false);

        config(['pdf-templates.html.editor.enabled' => false]);
        $this->get("/pdf-templates/{$html->id}/edit")->assertOk()->assertDontSee('id="pt-mode-visual"', false);
    }

    public function test_list_sorts_and_filters_by_category(): void
    {
        $old = $this->htmlTemplate(['name' => 'Beta', 'category' => 'Contracts']);
        $new = $this->htmlTemplate(['name' => 'Gamma', 'category' => 'Invoices']);
        $plain = $this->htmlTemplate(['name' => 'Alpha']);
        // Timestamps set apart: created in the order above, "Beta" edited last
        PdfTemplate::whereKey($old->id)->update(['created_at' => now()->subDays(3), 'updated_at' => now()]);
        PdfTemplate::whereKey($new->id)->update(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        PdfTemplate::whereKey($plain->id)->update(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

        $this->assertSame(['Contracts', 'Invoices'], PdfTemplate::categories());

        $this->get('/pdf-templates')->assertOk()->assertSeeInOrder(['Alpha', 'Beta', 'Gamma']);
        $this->get('/pdf-templates?sort=updated')->assertOk()->assertSeeInOrder(['Beta', 'Alpha', 'Gamma']);
        $this->get('/pdf-templates?sort=created')->assertOk()->assertSeeInOrder(['Alpha', 'Gamma', 'Beta']);
        $this->get('/pdf-templates?sort=slug')->assertSessionHasErrors('sort');

        $this->get('/pdf-templates?category=Invoices')->assertOk()
            ->assertSee("/pdf-templates/{$new->id}/edit")
            ->assertDontSee("/pdf-templates/{$old->id}/edit")
            ->assertDontSee("/pdf-templates/{$plain->id}/edit");

        // Optional: saved from the editor, cleared with an empty value
        $this->putJson("/pdf-templates/{$plain->id}", ['category' => 'Offers'])->assertOk();
        $this->assertSame('Offers', $plain->refresh()->category);
        $this->putJson("/pdf-templates/{$plain->id}", ['category' => ''])->assertOk();
        $this->assertNull($plain->refresh()->category);
        $this->get("/pdf-templates/{$new->id}/edit")->assertOk()->assertSee('value="Invoices"', false);
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

    public function test_auth_callback_decides_before_the_gate(): void
    {
        config(['pdf-templates.gate' => 'viewPdfTemplates']);
        $this->app['env'] = 'production';
        Gate::define('viewPdfTemplates', fn ($user = null) => true);

        try {
            PdfTemplatesFacade::auth(fn ($request) => $request->hasHeader('X-Admin'));
            $this->get('/pdf-templates')->assertForbidden();
            $this->get('/pdf-templates', ['X-Admin' => '1'])->assertOk();

            // Applies to every admin route, the AI and image routes too
            $this->getJson('/pdf-templates/images')->assertForbidden();
        } finally {
            PdfTemplatesFacade::auth(null);
        }

        $this->get('/pdf-templates')->assertOk();
    }

    public function test_routes_follow_config(): void
    {
        $this->assertSame(url('pdf-templates'), route('pdf-templates.index'));
        $this->assertSame(url('api/pdf-templates/x'), route('pdf-templates.api', 'x'));
    }

    public function test_new_template_from_the_gallery(): void
    {
        $this->get('/pdf-templates')->assertOk()->assertSee('Shipping label')->assertSee('Quote / offer')->assertSee('Warranty certificate');

        // Name from the starter when none is typed; mode, settings and test data come with it
        $this->post('/pdf-templates', ['starter' => 'quote'])->assertRedirect();
        $quote = PdfTemplate::where('name', 'Quote / offer')->sole();
        $this->assertSame(['html', 'Sales', 16.0], [$quote->mode, $quote->category, (float) $quote->settings['margins'][0]]);
        $this->assertStringContainsString('{% for item in items %}', $quote->html);
        $this->assertStringContainsString('Northwind', $quote->sample_data);

        $this->post('/pdf-templates', ['starter' => 'receipt', 'name' => 'Café receipt'])->assertRedirect();
        $receipt = PdfTemplate::where('name', 'Café receipt')->sole();
        $this->assertSame('wysiwyg', $receipt->mode);
        $this->assertStringContainsString('dataColumns', $receipt->schema);

        $this->post('/pdf-templates', ['starter' => 'nope'])->assertSessionHasErrors('starter');
        $this->post('/pdf-templates', ['name' => 'No mode'])->assertSessionHasErrors('mode');
    }

    public function test_gallery_starters_render(): void
    {
        foreach (['quote', 'contract'] as $key) {
            $template = app(Gallery::class)->make($key);
            $template->save();
            $this->assertIsPdf(app(PdfTemplates::class)->render($template, $template->sample_data));
        }
    }

    public function test_gallery_takes_folders_from_the_config(): void
    {
        $dir = sys_get_temp_dir().'/pt-gallery-'.uniqid();
        mkdir("{$dir}/house-letter", 0777, true);
        file_put_contents("{$dir}/house-letter/meta.json", '{"name": "House letter", "mode": "html", "category": "Ours"}');
        file_put_contents("{$dir}/house-letter/template.html", '<p>{name}</p>');
        config(['pdf-templates.gallery.paths' => [$dir]]);

        $this->get('/pdf-templates')->assertSee('House letter');
        $this->post('/pdf-templates', ['starter' => 'house-letter'])->assertRedirect();
        $this->assertSame('<p>{name}</p>', PdfTemplate::where('name', 'House letter')->sole()->html);

        array_map('unlink', glob("{$dir}/house-letter/*"));
        rmdir("{$dir}/house-letter");
        rmdir($dir);
    }
}
