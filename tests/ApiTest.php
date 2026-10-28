<?php

namespace AnghelutaV\PdfTemplates\Tests;

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
}
