<?php

namespace AnghelutaV\PdfTemplates\Tests;

use AnghelutaV\PdfTemplates\Ai\Assets;
use AnghelutaV\PdfTemplates\Ai\Assistant;
use AnghelutaV\PdfTemplates\Ai\Checks;
use AnghelutaV\PdfTemplates\Ai\StructuredTemplateAssistant;
use AnghelutaV\PdfTemplates\Ai\TemplateAssistant;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;

class AssistantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // Structured answer of the agent: every key present, "" = unchanged
    protected function answer(array $fields): array
    {
        return $fields + ['reply' => 'Done', 'name' => '', 'html' => '', 'css' => '', 'sample_data' => '', 'paper' => '', 'orientation' => '', 'schema' => ''];
    }

    public function test_text_format_for_small_models(): void
    {
        config(['pdf-templates.ai.format' => 'text']);
        $template = $this->htmlTemplate();
        TemplateAssistant::fake([
            // Broken first (unclosed loop), fixed after the problems are sent back
            "<pt:reply>Listed.</pt:reply>\n<pt:html>{#each items}<p>{name}</p></pt:html>",
            "<pt:reply>Listed the items.</pt:reply>\n<pt:html>\n```html\n<ul>{#each items}<li>\"{name}\"</li>{/each}</ul>\n```\n</pt:html>\n<pt:paper>a5</pt:paper>",
        ]);

        $this->post("/pdf-templates/{$template->id}/assistant", ['message' => 'List the items'])
            ->assertOk()
            ->assertJsonPath('reply', 'Listed the items.')
            ->assertJsonPath('changes.html', '<ul>{#each items}<li>"{name}"</li>{/each}</ul>')
            ->assertJsonPath('changes.paper', 'a5')
            ->assertJsonMissingPath('changes.css');

        TemplateAssistant::assertPromptedTimes(2);
        TemplateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('{#each} without {/each}'));
        StructuredTemplateAssistant::assertNeverPrompted();
        $this->assertStringContainsString('<pt:html>', (new TemplateAssistant)->instructions());
        $this->assertStringContainsString('check_html_template', (new StructuredTemplateAssistant)->instructions());
    }

    public function test_a_cut_off_answer_is_a_clear_error(): void
    {
        config(['pdf-templates.ai.format' => 'text']);
        $template = $this->wysiwygTemplate();
        TemplateAssistant::fake(["<pt:reply>Added the header.</pt:reply>\n<pt:schema>\n{\"basePdf\":{\"width\":210"]);

        $this->post("/pdf-templates/{$template->id}/assistant", ['message' => 'Add a header', 'draft' => json_encode(['schema' => json_encode(json_decode($template->schema), JSON_PRETTY_PRINT)])])
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'cut off in "schema"'));

        // The draft goes as compact sections, not JSON in JSON
        TemplateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains("<pt:schema>\n{\"basePdf\":{\"width\":210,")
            && ! $prompt->contains('\\"'));
    }

    public function test_request_too_large_is_explained(): void
    {
        $template = $this->htmlTemplate();
        StructuredTemplateAssistant::fake(fn () => throw new RequestException(new Response(new Psr7Response(413, [], '{"error":{"message":"Request too large"}}'))));

        $this->post("/pdf-templates/{$template->id}/assistant", ['message' => 'Hi'])
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'too large for this model'));
    }

    public function test_text_answer_without_sections_is_the_reply(): void
    {
        $this->assertSame(['reply' => 'What paper size?'], Assistant::parse('What paper size?'));
        $this->assertSame(['reply' => 'Ok', 'css' => 'h1 { color: red; }'], Assistant::parse("<pt:reply>Ok</pt:reply>\n<pt:css>```css\nh1 { color: red; }\n```</pt:css>"));
    }

    public function test_edit_returns_only_the_changed_fields(): void
    {
        $template = $this->htmlTemplate();
        StructuredTemplateAssistant::fake([$this->answer([
            'reply' => 'Added a footer.',
            'html' => '<h1>{title}</h1><footer>{company}</footer>',
            'css' => $template->css, // same as the draft: not a change
            'sample_data' => '{"title":"T","company":"ACME"}',
            'paper' => 'a5',
        ])]);

        $this->post("/pdf-templates/{$template->id}/assistant", [
            'message' => 'Add a footer with the company',
            'draft' => json_encode(['html' => $template->html, 'css' => $template->css, 'sample_data' => $template->sample_data]),
            'history' => json_encode([['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => 'hello'], ['role' => 'system', 'content' => 'ignored']]),
        ])
            ->assertOk()
            ->assertJsonPath('reply', 'Added a footer.')
            ->assertJsonPath('changes.html', '<h1>{title}</h1><footer>{company}</footer>')
            ->assertJsonPath('changes.paper', 'a5')
            ->assertJsonMissingPath('changes.css')
            ->assertJsonMissingPath('changes.orientation');

        StructuredTemplateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Add a footer with the company')
            && $prompt->contains('{title}')
            && count($prompt->agent->history) === 2);

        // Nothing is saved: the editor applies the changes
        $this->assertSame('h1 { color: #123; }', $template->fresh()->css);
        $this->assertStringNotContainsString('footer', $template->fresh()->html);
    }

    public function test_embedded_files_are_not_sent_and_come_back(): void
    {
        $template = $this->wysiwygTemplate();
        $pdf = 'data:application/pdf;base64,'.str_repeat('QUJD', 500);
        $schema = json_encode(['basePdf' => $pdf, 'schemas' => [[]]]);

        StructuredTemplateAssistant::fake(fn (string $prompt) => $this->answer([
            'schema' => json_encode(['basePdf' => '[ASSET_1]', 'schemas' => [[
                ['name' => 'qr', 'type' => 'qrcode', 'position' => ['x' => 170, 'y' => 260], 'width' => 25, 'height' => 25],
            ]]]),
        ]));

        $response = $this->post("/pdf-templates/{$template->id}/assistant", [
            'message' => 'Add a QR code',
            'draft' => json_encode(['schema' => $schema]),
        ])->assertOk();

        StructuredTemplateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('[ASSET_1]') && ! $prompt->contains('QUJDQUJD'));
        $this->assertSame($pdf, json_decode($response->json('changes.schema'), true)['basePdf']);
    }

    public function test_invalid_output_is_an_error(): void
    {
        $template = $this->htmlTemplate();
        StructuredTemplateAssistant::fake([$this->answer(['sample_data' => '{not json']), $this->answer(['sample_data' => '{still not'])]);

        $this->post("/pdf-templates/{$template->id}/assistant", ['message' => 'Data please'])
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'sample_data is not valid JSON'));

        StructuredTemplateAssistant::assertPromptedTimes(2);
    }

    public function test_a_broken_answer_is_sent_back_once_to_be_fixed(): void
    {
        $template = $this->htmlTemplate();
        StructuredTemplateAssistant::fake([
            $this->answer(['html' => '{#each items}<p>{name}</p>']),
            $this->answer(['reply' => 'Fixed', 'html' => '{#each items}<p>{name}</p>{/each}']),
        ]);

        $this->post("/pdf-templates/{$template->id}/assistant", ['message' => 'List the items'])
            ->assertOk()
            ->assertJsonPath('reply', 'Fixed')
            ->assertJsonPath('changes.html', '{#each items}<p>{name}</p>{/each}');

        StructuredTemplateAssistant::assertPromptedTimes(2);
        StructuredTemplateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('{#each} without {/each}')
            && count($prompt->agent->history) === 2);
    }

    public function test_invalid_pdfme_output_is_an_error(): void
    {
        $template = $this->wysiwygTemplate();
        $bad = $this->answer(['schema' => '{"basePdf":{"width":210,"height":297},"schemas":[[{"name":"a","type":"magic","position":{"x":1,"y":1},"width":5,"height":5}]]}']);
        StructuredTemplateAssistant::fake([$bad, $bad]);

        $this->post("/pdf-templates/{$template->id}/assistant", ['message' => 'Add a field'])
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'unknown type'));
    }

    public function test_provider_errors_are_shown(): void
    {
        $template = $this->htmlTemplate();
        StructuredTemplateAssistant::fake(fn () => throw new RuntimeException('Overloaded'));

        $this->post("/pdf-templates/{$template->id}/assistant", ['message' => 'Hi'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'AI assistant: Overloaded');
    }

    public function test_attachment_must_be_an_image_or_a_pdf(): void
    {
        $template = $this->htmlTemplate();
        StructuredTemplateAssistant::fake();

        $this->postJson("/pdf-templates/{$template->id}/assistant", [
            'message' => 'Copy this',
            'file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        StructuredTemplateAssistant::assertNeverPrompted();
    }

    public function test_attachment_is_sent_to_the_model(): void
    {
        $template = $this->htmlTemplate();
        StructuredTemplateAssistant::fake([$this->answer(['reply' => 'Copied'])]);

        $this->post("/pdf-templates/{$template->id}/assistant", [
            'message' => 'Copy this',
            'file' => UploadedFile::fake()->image('invoice.png'),
        ])->assertOk()->assertJsonPath('reply', 'Copied');

        StructuredTemplateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->count() === 1 && $prompt->contains('invoice.png'));
    }

    public function test_create_generates_and_saves_a_template(): void
    {
        StructuredTemplateAssistant::fake([$this->answer([
            'reply' => 'Invoice ready.',
            'name' => 'Something else',
            'html' => '<h1>Invoice {number}</h1>',
            'sample_data' => '{"number":"F-1"}',
        ])]);

        $response = $this->post('/pdf-templates/assistant', [
            'name' => 'Invoice',
            'mode' => PdfTemplate::MODE_HTML,
            'message' => 'An invoice with a number',
        ]);

        $template = PdfTemplate::sole();
        $response->assertRedirect("/pdf-templates/{$template->id}/edit")
            ->assertSessionHas('pdf-templates.status', 'Invoice ready.')
            // The editor chat starts with the dialog's description and the answer
            ->assertSessionHas('pdf-templates.ai_history', [
                ['role' => 'user', 'content' => 'An invoice with a number'],
                ['role' => 'assistant', 'content' => 'Invoice ready.'],
            ]);
        $this->followRedirects($response)->assertSee('An invoice with a number', false);
        $this->assertSame('Invoice', $template->name);
        $this->assertSame('<h1>Invoice {number}</h1>', $template->html);
        $this->assertSame(PdfTemplate::STARTER_CSS, $template->css);
        $this->assertSame(['number' => 'F-1'], json_decode($template->sample_data, true));
    }

    public function test_create_failure_keeps_the_dialog_input(): void
    {
        StructuredTemplateAssistant::fake(fn () => throw new RuntimeException('No credits'));

        $this->from('/pdf-templates')->post('/pdf-templates/assistant', [
            'name' => 'Invoice',
            'mode' => PdfTemplate::MODE_HTML,
            'message' => 'An invoice',
        ])->assertRedirect('/pdf-templates')->assertSessionHasErrors(['message'], null, 'ai')->assertSessionHasInput('name', 'Invoice');

        $this->assertSame(0, PdfTemplate::count());
    }

    public function test_editors_and_list_show_the_assistant(): void
    {
        $html = $this->htmlTemplate();
        $designer = $this->wysiwygTemplate();

        $this->get("/pdf-templates/{$html->id}/edit")->assertOk()->assertSee('id="pt-ai-toggle"', false)->assertSee('id="pt-ai"', false);
        $this->get("/pdf-templates/{$designer->id}/edit")->assertOk()->assertSee('id="pt-ai"', false);
        $this->get('/pdf-templates')->assertOk()->assertSee('id="pt-ai-generate"', false);
    }

    public function test_disabled_without_a_key(): void
    {
        config(['ai.providers.anthropic.key' => null]);
        $template = $this->htmlTemplate();

        $this->get("/pdf-templates/{$template->id}/edit")->assertOk()->assertDontSee('id="pt-ai"', false);
        $this->get('/pdf-templates')->assertOk()->assertDontSee('id="pt-ai-generate"', false);
        $this->post("/pdf-templates/{$template->id}/assistant", ['message' => 'Hi'])->assertNotFound();
        $this->post('/pdf-templates/assistant', ['name' => 'X', 'mode' => 'html', 'message' => 'Hi'])->assertNotFound();
    }

    public function test_disabled_by_config(): void
    {
        config(['pdf-templates.ai.enabled' => false]);
        $template = $this->htmlTemplate();

        $this->post("/pdf-templates/{$template->id}/assistant", ['message' => 'Hi'])->assertNotFound();
    }

    public function test_html_check(): void
    {
        $this->assertSame(['html: {#each} without {/each}'], Checks::html('{#each items}<p>{name}</p>', '{}'));
        $this->assertSame([], Checks::html('<h1>{title}</h1>', 'not json'));
    }

    public function test_pdfme_check(): void
    {
        $this->assertSame([], Checks::pdfme((string) $this->wysiwygTemplate()->schema));

        $problems = Checks::pdfme(json_encode(['basePdf' => ['width' => 210, 'height' => 297], 'schemas' => [[
            ['name' => 'a', 'type' => 'text', 'position' => ['x' => 200, 'y' => 10], 'width' => 50, 'height' => 10],
            ['name' => 'a', 'type' => 'text', 'position' => ['x' => 10, 'y' => 10], 'width' => 50, 'height' => 10],
        ]]]));
        $this->assertSame(['Page 1 field a: outside the page', 'Page 1 field a: duplicate name'], $problems);
    }

    public function test_assets_stash_and_restore(): void
    {
        $assets = new Assets;
        $image = 'data:image/png;base64,'.str_repeat('iVBO', 100);
        $text = "<img src=\"{$image}\"><img src=\"{$image}\"><img src=\"data:image/png;base64,AAAA\">";

        $stashed = $assets->stash($text);

        $this->assertSame('<img src="[ASSET_1]"><img src="[ASSET_1]"><img src="data:image/png;base64,AAAA">', $stashed);
        $this->assertSame($text, $assets->restore($stashed));
    }
}
