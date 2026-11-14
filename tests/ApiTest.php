<?php

namespace AnghelutaV\PdfTemplates\Tests;

use AnghelutaV\PdfTemplates\Jobs\RenderPdf;
use AnghelutaV\PdfTemplates\Models\PdfTemplateApiKey;
use AnghelutaV\PdfTemplates\Models\PdfTemplateRender;
use AnghelutaV\PdfTemplates\RendererProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class ApiTest extends TestCase
{
    protected function api(string $slug, string $body = '{}', ?string $key = 'test-key', string $query = '')
    {
        return $this->call('POST', "/api/pdf-templates/{$slug}{$query}", [], [], [], array_filter([
            'HTTP_X_API_KEY' => $key,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ]), $body);
    }

    public function test_renders_a_pdf(): void
    {
        $this->htmlTemplate();

        $response = $this->api('invoice', '{"title":"Hello"}');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertIsPdf($response->getContent());
        $this->assertSame('inline; filename="invoice.pdf"', $response->headers->get('Content-Disposition'));
    }

    public function test_download_and_filename(): void
    {
        $this->htmlTemplate();

        $this->api('invoice', '{}', 'test-key', '?download=1&filename=Factura 7')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="factura-7.pdf"');
    }

    public function test_key_is_required(): void
    {
        $this->htmlTemplate();

        $this->api('invoice', '{}', null)->assertStatus(401)->assertJson(['error' => 'Invalid API key.']);
        $this->api('invoice', '{}', 'wrong')->assertStatus(401);
    }

    public function test_disabled_without_configured_key(): void
    {
        config(['pdf-templates.api.key' => null]);
        $this->htmlTemplate();

        $this->api('invoice')->assertStatus(403);
    }

    public function test_unknown_or_inactive_template(): void
    {
        $this->htmlTemplate(['name' => 'Off', 'active' => false]);

        $this->api('missing')->assertNotFound();
        $this->api('off')->assertNotFound();
    }

    public function test_bad_json_and_bad_template_are_422(): void
    {
        $this->htmlTemplate();
        $this->htmlTemplate(['name' => 'Broken', 'html' => '{#if a}x']);

        $this->api('invoice', '{bad')->assertStatus(422)->assertJsonFragment(['error' => 'Invalid JSON: Syntax error']);
        $this->api('broken')->assertStatus(422)->assertJsonFragment(['error' => '{#if} without {/if}']);
    }

    public function test_needs_no_csrf_or_session(): void
    {
        $this->htmlTemplate();

        $response = $this->api('invoice');

        $response->assertOk();
        $this->assertEmpty($response->headers->getCookies());
    }

    protected function apiGet(string $uri, ?string $key = 'test-key')
    {
        return $this->call('GET', $uri, [], [], [], array_filter(['HTTP_X_API_KEY' => $key, 'HTTP_ACCEPT' => 'application/json']));
    }

    public function test_client_keys_work_and_are_logged(): void
    {
        $this->htmlTemplate();
        [$key, $plain] = PdfTemplateApiKey::generate('Shop');

        $this->assertStringStartsWith('pt_', $plain);
        $this->assertNotSame($plain, $key->key_hash);

        $response = $this->api('invoice', '{"title":"Hi"}', $plain);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $render = PdfTemplateRender::sole();
        $this->assertSame($render->uuid, $response->headers->get('X-Render-Id'));
        $this->assertSame([PdfTemplateRender::DONE, $key->id, 'invoice', 1], [$render->status, $render->api_key_id, $render->template_slug, $render->documents]);
        $this->assertGreaterThan(0, $render->bytes);
        $this->assertNotNull($key->fresh()->last_used_at);

        // Bearer works too; a revoked key does not
        $this->call('POST', '/api/pdf-templates/invoice', [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$plain}", 'CONTENT_TYPE' => 'application/json'], '{}')->assertOk();
        $key->update(['revoked_at' => now()]);
        $this->api('invoice', '{}', $plain)->assertStatus(401);
    }

    public function test_enabled_by_a_client_key_alone(): void
    {
        config(['pdf-templates.api.key' => null]);
        $this->htmlTemplate();
        [, $plain] = PdfTemplateApiKey::generate('Shop');

        $this->api('invoice', '{}', $plain)->assertOk();
        $this->api('invoice', '{}', 'wrong')->assertStatus(401);
    }

    public function test_base64_output_and_documents(): void
    {
        $this->htmlTemplate();

        $response = $this->api('invoice', '[{"title":"A"},{"title":"B"}]', 'test-key', '?output=base64&filename=Batch 1');

        $response->assertOk()->assertJsonPath('filename', 'batch-1.pdf')->assertJsonPath('mime', 'application/pdf');
        $this->assertIsPdf(base64_decode($response->json('data')));
        $this->assertSame(2, PdfTemplateRender::sole()->documents);
    }

    public function test_url_output_gives_a_signed_expiring_link(): void
    {
        Storage::fake('local');
        config(['pdf-templates.api.disk' => 'local']);
        $this->htmlTemplate();

        $response = $this->api('invoice', '{"title":"Link"}', 'test-key', '?output=url');

        $response->assertOk()->assertJsonPath('status', 'done')->assertJsonStructure(['id', 'url', 'expires_at']);
        $render = PdfTemplateRender::sole();
        Storage::disk('local')->assertExists($render->path);

        // The link needs no key; a changed signature is refused
        $file = $this->apiGet($response->json('url'), null);
        $file->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->apiGet($response->json('url').'x', null)->assertForbidden();

        // Expired: gone
        $this->travel(2)->days();
        $this->apiGet($render->fileUrl() ?? '', null)->assertStatus(403);
    }

    public function test_async_render_with_webhook(): void
    {
        Storage::fake('local');
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
        config(['pdf-templates.api.disk' => 'local', 'pdf-templates.api.webhook_secret' => 'shh', 'pdf-templates.api.webhook_private' => true]);
        $this->htmlTemplate();

        $response = $this->api('invoice', '{"title":"Later"}', 'test-key', '?async=1&webhook=https://hooks.example.com/pdf');

        // The sync queue in tests runs the job at once
        $response->assertStatus(202)->assertJsonStructure(['id', 'status', 'status_url']);
        $render = PdfTemplateRender::sole();
        $this->assertSame([PdfTemplateRender::DONE, '200'], [$render->status, $render->webhook_status]);
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://hooks.example.com/pdf' && $body['status'] === 'done' && isset($body['url'])
                && $request->header('X-Signature')[0] === hash_hmac('sha256', $request->body(), 'shh');
        });

        $this->apiGet($response->json('status_url'))->assertOk()->assertJsonPath('status', 'done');
    }

    public function test_async_is_queued(): void
    {
        Queue::fake();
        $this->htmlTemplate();

        $this->api('invoice', '{}', 'test-key', '?async=1')->assertStatus(202)->assertJsonPath('status', 'queued');
        Queue::assertPushed(RenderPdf::class);
    }

    public function test_failed_renders_are_logged(): void
    {
        $this->htmlTemplate(['name' => 'Broken', 'html' => '{#if a}x']);

        $this->api('broken')->assertStatus(422)->assertJsonStructure(['error', 'id']);
        $render = PdfTemplateRender::sole();
        $this->assertSame(PdfTemplateRender::FAILED, $render->status);
        $this->assertSame('{#if} without {/if}', $render->error);
    }

    public function test_private_webhooks_and_bad_options_are_refused(): void
    {
        $this->htmlTemplate();

        $this->api('invoice', '{}', 'test-key', '?async=1&webhook=http://127.0.0.1/hook')->assertStatus(422)->assertJsonFragment(['error' => '127.0.0.1 is a private address.']);
        $this->api('invoice', '{}', 'test-key', '?output=xml')->assertStatus(422);
        $this->api('invoice', '{}', 'test-key', '?format=gif')->assertStatus(422);
        $this->assertSame(0, PdfTemplateRender::count());
    }

    public function test_status_is_private_to_the_key(): void
    {
        $this->htmlTemplate();
        [, $shop] = PdfTemplateApiKey::generate('Shop');
        [, $other] = PdfTemplateApiKey::generate('Other');
        $id = $this->api('invoice', '{}', $shop, '?output=base64')->json('id');

        $this->apiGet("/api/pdf-templates/renders/{$id}", $shop)->assertOk();
        $this->apiGet("/api/pdf-templates/renders/{$id}", $other)->assertNotFound();
        $this->apiGet("/api/pdf-templates/renders/{$id}")->assertOk();
    }

    public function test_templates_endpoints(): void
    {
        $this->htmlTemplate(['category' => 'Sales']);
        $this->htmlTemplate(['name' => 'Off', 'active' => false]);

        $this->apiGet('/api/pdf-templates/templates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'invoice')
            ->assertJsonPath('data.0.fields.title', 'value');
        $this->apiGet('/api/pdf-templates/templates/invoice')->assertOk()->assertJsonPath('data.example.title', 'Sample');
        $this->apiGet('/api/pdf-templates/templates/off')->assertNotFound();
        $this->apiGet('/api/pdf-templates/templates', 'wrong')->assertStatus(401);
    }

    public function test_image_output_goes_through_the_renderer(): void
    {
        config(['pdf-templates.pdfme.renderer_url' => 'http://renderer.test']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake(['renderer.test/image*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $this->htmlTemplate();

        $this->api('invoice', '{}', 'test-key', '?format=png&page=2')->assertOk()->assertHeader('Content-Type', 'image/png');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/image?page=2') && str_starts_with($request->body(), '%PDF-'));

        $jpg = $this->api('invoice', '{}', 'test-key', '?format=jpg&download=1');
        $jpg->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Content-Disposition', 'attachment; filename="invoice.jpg"');
        $this->assertStringStartsWith("\xFF\xD8\xFF", $jpg->getContent());
    }

    public function test_prune_removes_expired_files_and_old_rows(): void
    {
        Storage::fake('local');
        config(['pdf-templates.api.disk' => 'local']);
        $this->htmlTemplate();
        $this->api('invoice', '{}', 'test-key', '?output=url')->assertOk();
        $path = PdfTemplateRender::sole()->path;

        $this->travel(2)->days();
        $this->artisan('pdf-templates:prune')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull(PdfTemplateRender::sole()->path);

        $this->travel(40)->days();
        $this->artisan('pdf-templates:prune')->assertSuccessful();
        $this->assertSame(0, PdfTemplateRender::count());
    }

    public function test_api_key_command(): void
    {
        $this->artisan('pdf-templates:api-key', ['name' => 'CLI'])->assertSuccessful();
        $key = PdfTemplateApiKey::sole();
        $this->assertSame('CLI', $key->name);

        $this->artisan('pdf-templates:api-key', ['--revoke' => $key->id])->assertSuccessful();
        $this->assertNotNull($key->fresh()->revoked_at);
    }

    public function test_admin_api_page_and_postman(): void
    {
        $this->htmlTemplate();

        $this->get('/pdf-templates/api')->assertOk()->assertSee('Postman');
        $this->post('/pdf-templates/api/keys', ['name' => 'Partner'])->assertRedirect()->assertSessionHas('pdf-templates.api_key');
        $key = PdfTemplateApiKey::sole();
        $this->get('/pdf-templates/api')->assertOk()->assertSee('Partner');

        $collection = $this->get('/pdf-templates/api/postman?key=abc')->assertOk()->json();
        $this->assertSame('https://schema.getpostman.com/json/collection/v2.1.0/collection.json', $collection['info']['schema']);
        $variables = collect($collection['variable'])->pluck('value', 'key');
        $this->assertSame([url('api/pdf-templates'), 'abc', 'invoice'], [$variables['api_url'], $variables['api_key'], $variables['template']]);
        $this->assertSame('Your templates', collect($collection['item'])->last()['name']);

        $this->delete("/pdf-templates/api/keys/{$key->id}")->assertRedirect();
        $this->assertNotNull($key->fresh()->revoked_at);
    }

    public function test_api_page_tabs_and_documentation(): void
    {
        $this->htmlTemplate();

        $this->get('/pdf-templates/api')
            ->assertOk()
            ->assertSee('id="pt-api-docs"', false)
            ->assertSee('Node renderer')
            ->assertSee('Invoice (invoice)')
            ->assertSee(url('api/pdf-templates'));
        $this->assertMatchesRegularExpression('/data-tab="pt-api-docs"\s+aria-selected="true"/', $this->get('/pdf-templates/api?tab=docs')->assertOk()->getContent());
    }

    public function test_renderer_status_and_remote_renderers_are_not_started(): void
    {
        config(['pdf-templates.pdfme.renderer_url' => 'http://renderer.internal:3311']);
        Http::fake(['renderer.internal:3311/health' => Http::response(['ok' => true])]);

        $this->getJson('/pdf-templates/api/renderer')->assertOk()->assertJson(['reachable' => true, 'local' => false, 'manageable' => false]);
        $this->postJson('/pdf-templates/api/renderer/start')->assertStatus(422)->assertJsonPath('error', __('pdf-templates::ui.renderer_not_manageable'));
        $this->postJson('/pdf-templates/api/renderer/stop')->assertOk()->assertJsonPath('message', __('pdf-templates::ui.renderer_not_ours'));
    }

    public function test_renderer_starts_and_stops_from_the_api_page(): void
    {
        $renderer = app(RendererProcess::class);
        if (! $renderer->nodeVersion() || ! is_dir($renderer->dir().'/node_modules/@pdfme/converter')) {
            $this->markTestSkipped('Node.js and the renderer dependencies are needed.');
        }
        config(['pdf-templates.pdfme.renderer_url' => 'http://127.0.0.1:3419']);

        try {
            $start = $this->postJson('/pdf-templates/api/renderer/start');
            $start->assertOk()->assertJson(['reachable' => true]);
            $this->assertIsInt($start->json('pid'));
            $this->postJson('/pdf-templates/api/renderer/start')->assertStatus(422);
        } finally {
            $this->postJson('/pdf-templates/api/renderer/stop')->assertOk()->assertJsonPath('message', __('pdf-templates::ui.renderer_stopped'));
        }
        $this->assertFalse($renderer->reachable());
        $this->assertStringContainsString('starting on 127.0.0.1:3419', $renderer->logTail());
    }

    public function test_log_keeps_the_data_and_renders_again(): void
    {
        $this->htmlTemplate();

        $this->api('invoice', '{"title":"Kept"}', 'test-key', '?output=base64&filename=a')->assertOk();
        $render = PdfTemplateRender::sole();
        $this->assertSame('{"title":"Kept"}', $render->data);
        $this->assertSame(['output' => 'base64', 'filename' => 'a'], $render->options);
        $this->assertArrayNotHasKey('data', $render->toArray());

        $this->get('/pdf-templates/api?tab=log')->assertOk()->assertSee('<span class="pt-json-key">&quot;title&quot;</span>: <span class="pt-json-string">&quot;Kept&quot;</span>', false)->assertSee('POST /invoice?output=base64&amp;filename=a', false);
        $again = $this->get("/pdf-templates/api/renders/{$render->uuid}/again");
        $again->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertIsPdf($again->getContent());
        $this->assertSame(1, PdfTemplateRender::count());

        // Off, or too big: not kept
        config(['pdf-templates.api.log_data' => false]);
        $this->api('invoice', '{"title":"Secret"}')->assertOk();
        config(['pdf-templates.api.log_data' => true, 'pdf-templates.api.log_data_kb' => 1]);
        $this->api('invoice', json_encode(['title' => str_repeat('x', 2000)]))->assertOk();
        $this->assertSame([null, null], PdfTemplateRender::latest('id')->take(2)->pluck('data')->all());
        $this->get('/pdf-templates/api/renders/'.PdfTemplateRender::latest('id')->first()->uuid.'/again')->assertNotFound();
    }

    protected function batch(string $body, ?string $key = 'test-key', string $query = '')
    {
        return $this->call('POST', "/api/pdf-templates/batch{$query}", [], [], [], array_filter([
            'HTTP_X_API_KEY' => $key,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ]), $body);
    }

    public function test_batch_returns_a_zip_with_one_pdf_per_item(): void
    {
        $this->htmlTemplate();
        $this->htmlTemplate(['name' => 'Receipt', 'slug' => 'receipt']);

        $response = $this->batch(json_encode(['template' => 'invoice', 'filename' => 'invoice-{{ number }}', 'items' => [
            ['title' => 'A', 'number' => 7], ['title' => 'B', 'number' => 8], ['title' => 'C', 'number' => 7],
        ]]));
        $response->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertSame('attachment; filename="invoice.zip"', $response->headers->get('Content-Disposition'));
        $this->assertSame(['invoice-7.pdf', 'invoice-8.pdf', 'invoice-7-2.pdf'], $this->zipNames($response->getContent()));

        $render = PdfTemplateRender::sole();
        $this->assertSame(['zip', 3, 'invoice'], [$render->format, $render->documents, $render->template_slug]);
        $this->assertNotNull($render->template_id);
        // Render again rebuilds the same ZIP
        $again = $this->get("/pdf-templates/api/renders/{$render->uuid}/again");
        $again->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertCount(3, $this->zipNames($again->getContent()));

        // One template per item, base64 output
        $json = $this->batch(json_encode(['items' => [
            ['template' => 'invoice', 'data' => ['title' => 'X']],
            ['template' => 'receipt', 'data' => ['title' => 'Y'], 'filename' => 'r'],
        ]]), 'test-key', '?output=base64')->assertOk()->json();
        $this->assertSame(['invoice-1.pdf', 'r.pdf'], $this->zipNames(base64_decode($json['data'])));
        $this->assertSame('batch', PdfTemplateRender::latest('id')->first()->template_slug);
    }

    public function test_batch_errors(): void
    {
        $this->htmlTemplate();

        $this->batch('{"items": []}')->assertStatus(422)->assertJsonPath('error', 'items is empty.');
        $this->batch('{"title": "x"}')->assertStatus(422);
        $this->batch('{"items": [{"data": {}}]}')->assertStatus(422);
        $this->batch('{"template": "missing", "items": [{}]}')->assertNotFound()->assertJsonPath('error', 'Template(s) not found or inactive: missing.');
        config(['pdf-templates.api.batch_max' => 1]);
        $this->batch('{"template": "invoice", "items": [{}, {}]}')->assertStatus(422)->assertJsonPath('error', 'At most 1 items per batch.');
        $this->batch('{"template": "invoice", "items": [{}]}', 'wrong')->assertUnauthorized();
        $this->assertSame(0, PdfTemplateRender::count());
    }

    public function test_key_limits_per_minute_and_per_month(): void
    {
        $this->htmlTemplate();
        [$key, $plain] = PdfTemplateApiKey::generate('Shop');
        $key->update(['per_minute' => 2]);

        $this->api('invoice', '{}', $plain)->assertOk();
        $this->api('invoice', '{}', $plain)->assertOk();
        $this->api('invoice', '{}', $plain)->assertStatus(429)->assertHeader('Retry-After')->assertHeader('X-RateLimit-Limit', 2);
        // The config key has no limits
        $this->api('invoice', '{}')->assertOk();

        // Documents per month: 2 used, a list of 2 more would go over 3; failed renders do not count
        $key->update(['per_minute' => null, 'per_month' => 3]);
        $this->api('invoice', '[{}, {}]', $plain)->assertStatus(429)->assertJsonPath('used', 2)->assertJsonPath('limit', 3);
        $this->api('invoice', '{}', $plain)->assertOk();
        $this->api('invoice', '{}', $plain)->assertStatus(429);
        $this->assertSame(3, $key->fresh()->documentsThisMonth());

        // Set from the admin UI
        $this->put("/pdf-templates/api/keys/{$key->id}", ['per_minute' => 30, 'per_month' => ''])->assertRedirect();
        $this->assertSame([30, null], [$key->fresh()->per_minute, $key->fresh()->per_month]);
        $this->get('/pdf-templates/api')->assertOk()->assertSee('documents this month')->assertSee('value="30"', false);
    }

    public function test_watermark_option_is_kept_with_the_render(): void
    {
        $this->htmlTemplate();

        $plain = $this->api('invoice', '{}')->assertOk()->getContent();
        $marked = $this->api('invoice', '{}', 'test-key', '?watermark=DRAFT&watermark_color=%23dc2626')->assertOk()->getContent();
        $this->assertIsPdf($marked);
        $this->assertNotSame(strlen($plain), strlen($marked));

        $render = PdfTemplateRender::latest('id')->first();
        $this->assertSame(['watermark' => ['text' => 'DRAFT', 'color' => '#dc2626']], $render->renderOptions());
    }

    protected function zipNames(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $content);
        $zip = new \ZipArchive;
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
            $this->assertIsPdf((string) $zip->getFromIndex($i));
        }
        $zip->close();
        unlink($path);

        return $names;
    }
}
