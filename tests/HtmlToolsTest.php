<?php

namespace AnghelutaV\PdfTemplates\Tests;

use AnghelutaV\PdfTemplates\Facades\PdfTemplates;
use AnghelutaV\PdfTemplates\FontLibrary;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\Models\PdfTemplateVersion;
use AnghelutaV\PdfTemplates\Renderers\HtmlRenderer;
use AnghelutaV\PdfTemplates\TemplateEngine;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// Jinja syntax, page setup (margins, header / footer), history, font library, import of external images
class HtmlToolsTest extends TestCase
{
    protected string $fontDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->fontDir = sys_get_temp_dir().'/pdf-templates-fonts-'.uniqid();
        config(['pdf-templates.html.font_dir' => $this->fontDir, 'pdf-templates.images.disk' => 'images']);
        Storage::fake('images');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fontDir);
        parent::tearDown();
    }

    protected function ttf(): string
    {
        return (string) file_get_contents(__DIR__.'/../vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
    }

    public function test_conditions_with_expressions(): void
    {
        $engine = app(TemplateEngine::class);
        $data = ['status' => 'Paid', 'total' => 150, 'qty' => '2', 'items' => [1, 2, 3], 'types' => ['a', 'b'], 'type' => 'b', 'ok' => true, 'zero' => 0, 'name' => "O'Neil"];
        $true = [
            'status == "Paid"', "status != 'draft'", 'total > 100', 'total >= 150', 'total < 200 and total <= 150', 'qty == 2',
            'status == "x" or total > 100', 'not (status == "x")', 'not zero', 'ok == true', 'zero == false', '(status == "x" or ok) and items',
            'items|length == 3', 'items | length > 2', 'status|lower == "paid"', 'type in types', '"ai" in status', 'type not in "xyz"',
            'missing is not defined', 'missing is none', 'status is defined', 'zero is empty', 'missing == none', 'total > -1',
            'ok && !zero', 'status == "x" || ok', 'name == "O\'Neil"', 'not zero and ok', 'total > 100 and status == "Paid" and qty < 3',
        ];
        foreach ($true as $condition) {
            $this->assertSame('Y', $engine->render('{#if '.$condition.'}Y{else}N{/if}', $data), $condition);
            $this->assertSame('Y', $engine->render('{% if '.$condition.' %}Y{% else %}N{% endif %}', $data), $condition);
        }
        foreach (['status == "paid"', 'total > 150', 'qty > 10', 'type in "xyz"', 'missing is defined', 'not ok or zero', 'missing > 1 and ok'] as $condition) {
            $this->assertSame('N', $engine->render('{% if '.$condition.' %}Y{% else %}N{% endif %}', $data), $condition);
        }

        $elif = '{% if n == 1 %}one{% elif n == 2 %}two{% elif not big %}small{% else %}many{% endif %}!{% if n %}{% if n > 1 %}+{% endif %}{% endif %}';
        $this->assertSame('one!', $engine->render($elif, ['n' => 1]));
        $this->assertSame('two!+', $engine->render($elif, ['n' => 2]));
        $this->assertSame('small!+', $engine->render($elif, ['n' => 3]));
        $this->assertSame('many!+', $engine->render($elif, ['n' => 3, 'big' => true]));

        $this->assertSame('[A*][B]', $engine->render('{% for item in items %}[{{ item.name }}{% if item.qty > 1 and loop.first %}*{% endif %}]{% endfor %}', [
            'items' => [['name' => 'A', 'qty' => 2], ['name' => 'B', 'qty' => 5]],
        ]));

        $this->assertEquals(
            ['status' => 'condition', 'total' => 'condition', 'items' => 'condition', 'note' => 'condition', 'x' => 'value'],
            $engine->fields('{#if status == "paid" and total > 100}{x}{/if}{% if items|length > 1 or note is not defined %}{% endif %}'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $engine->render('{#if total > }x{/if}', $data);
    }

    public function test_html_expressions_and_arithmetic(): void
    {
        $engine = app(TemplateEngine::class);
        $data = ['qty' => '3', 'price' => 2.5, 'vat' => 19, 'paid' => false, 'name' => 'Ana', 'items' => [['qty' => 2, 'price' => 10], ['qty' => 1, 'price' => 0.5]]];

        $this->assertSame('7.5 8,92 8 2.5 3 -3 0.3 0', $engine->render('{= qty * price} {= qty * price * (1 + vat / 100)|number:2} {= (qty + 1) * 2} {= 10 / 4} {= 7 % 4} {= -qty} {= 0.1 + 0.2} {= 5 / 0}', $data));
        $this->assertSame('20,50 2 Due Ana! 10 0,63 ANA', $engine->render('{= sum(items, "qty * price")|number:2} {= count(items)} {= paid ? "Paid" : "Due"} {= concat(name, "!")} {= max(items, "price")} {= round(price / 4, 2)|number:2} {= upper(name)}', $data));

        // Jinja: {{ expression }} → {= expression}; in a loop the item's keys are read
        $this->assertSame('[20][0.5] total 20,50 / 2 rows', $engine->render('{% for item in items %}[{{ item.qty * item.price }}]{% endfor %} total {{ sum(items, "qty * price")|round(2) }} / {{ items|length }} rows', $data));
        $this->assertSame('big', $engine->render('{% if qty * price > 5 %}big{% else %}small{% endif %}', $data));

        // A value from the data is printed, never read as a placeholder
        $this->assertSame('{name} 2', $engine->render('{= text} {= 1 + 1}', ['text' => '{name}', 'name' => 'x']));
        $this->assertSame('&lt;b&gt;', $engine->render('{= concat("<", "b>")}', []));

        $this->assertEquals(['qty' => 'value', 'price' => 'value', 'items' => 'value', 'vat' => 'value'], $engine->fields('{= qty * price} {{ sum(items, "total") }} {= round(vat, 2)}'));

        $this->expectException(\InvalidArgumentException::class);
        $engine->render('{= nope(1)}', []);
    }

    public function test_jinja_syntax_is_accepted(): void
    {
        $engine = app(TemplateEngine::class);
        $template = 'Nr: {{cct_id}} {{ client.name | upper }} {{ notes|default("—") }} {{ total|round(2) }} {{ html|safe }}'
            .'{% for item in items %}[{{ loop.index }}:{{ item.name }}{% for t in item.tags %}<{{ t }}>{% endfor %}]{% else %}none{% endfor %}'
            .'{% if paid %}PAID{% else %}DUE{% endif %}{% if not paid %} unpaid{% endif %} {x}';

        $this->assertSame(
            'Nr: C-1 ANA — 3,14 <b>ok</b>[1:A<x><y>][2:B]DUE unpaid X',
            $engine->render($template, [
                'cct_id' => 'C-1', 'client' => ['name' => 'ana'], 'total' => 3.14159, 'html' => '<b>ok</b>', 'paid' => false, 'x' => 'X',
                'items' => [['name' => 'A', 'tags' => ['x', 'y']], ['name' => 'B', 'tags' => []]],
            ]),
        );
        $this->assertSame('none', $engine->render('{% for i in items %}{{ i }}{% else %}none{% endfor %}', []));
        $this->assertSame(
            ['cct_id' => 'value', 'items' => 'list', 'items.*.name' => 'value', 'paid' => 'condition'],
            $engine->fields('{{ cct_id }}{% for item in items %}{{ item.name }}{% endfor %}{% if paid %}x{% endif %}'),
        );
        // Single-brace templates are untouched, also with CSS braces around
        $this->assertSame('<style>a { color: red; }</style> v', $engine->render('<style>a { color: red; }</style> {x}', ['x' => 'v']));
    }

    public function test_page_setup_adds_margins_header_and_footer(): void
    {
        $template = $this->htmlTemplate([
            'html' => '{#each items}<p style="page-break-after: always">{name}</p>{/each}',
            'settings' => [
                'margins' => [25, 12, 18, 12],
                'header' => ['left' => '{company}', 'right' => '{date}', 'font_size' => 8],
                'footer' => ['html' => '<b>Page {currentPage} of {{ totalPages }}</b>'],
                'word_break' => 'break-word',
            ],
        ]);
        $data = ['company' => 'Acme <Ltd>', 'items' => [['name' => 'a'], ['name' => 'b']]];
        $html = app(HtmlRenderer::class)->document($template, $data);

        $this->assertStringContainsString('@page { margin: 25mm 12mm 18mm 12mm; }', $html);
        $this->assertStringContainsString('.pt-page-header { top: -25mm; height: 25mm; }', $html);
        $this->assertStringContainsString('.pt-page-header { font-size: 8pt; }', $html);
        $this->assertStringContainsString('overflow-wrap: break-word', $html);
        $this->assertStringContainsString('<td style="text-align: left;">Acme &lt;Ltd&gt;</td>', $html);
        $this->assertStringContainsString('<td style="text-align: right;">'.now()->format('d.m.Y').'</td>', $html);
        $this->assertStringContainsString('<b>Page <span class="pt-page-number"></span> of <span class="pt-page-count"></span></b>', $html);
        // Header / footer come first in <body>, so dompdf repeats them on every page
        $this->assertLessThan(strpos($html, '<p style="page-break-after'), strpos($html, 'pt-page-footer">'));

        $pdf = PdfTemplates::render($template, $data);
        $this->assertIsPdf($pdf);
        $this->assertSame(3, preg_match_all('#/Type /Page\b#', $pdf));

        // No settings: nothing is added
        $plain = app(HtmlRenderer::class)->document($this->htmlTemplate(), ['title' => 'x']);
        $this->assertStringNotContainsString('pt-page-', $plain);
        // Only a footer: default margins make room for it
        $footerOnly = app(HtmlRenderer::class)->document($this->htmlTemplate(['settings' => ['footer' => ['center' => 'x']]]), []);
        $this->assertStringContainsString('@page { margin: 20mm 15mm 20mm 15mm; }', $footerOnly);
    }

    public function test_settings_are_saved_previewed_and_validated(): void
    {
        $template = $this->htmlTemplate();
        $settings = ['margins' => [20, null, 20, null], 'footer' => ['right' => 'Page {currentPage}', 'font_size' => 9]];

        $this->putJson("/pdf-templates/{$template->id}", ['settings' => $settings])->assertOk();
        $this->assertEquals($settings, $template->refresh()->settings);
        $this->get("/pdf-templates/{$template->id}/edit")->assertOk()
            ->assertSee('id="pt-page-setup"', false)->assertSee('id="pt-fonts"', false)->assertSee('id="pt-images-import"', false)
            ->assertSee('Page {currentPage}', false);
        $this->get("/pdf-templates/{$template->id}/export")->assertOk()->assertJsonPath('settings.footer.right', 'Page {currentPage}');

        // Unsaved settings in the preview; '{}' = none
        $this->post("/pdf-templates/{$template->id}/render", ['data' => '{}', 'settings' => json_encode(['header' => ['left' => 'H']])])
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post("/pdf-templates/{$template->id}/render", ['data' => '{}', 'settings' => '{}'])->assertOk();
        $this->postJson("/pdf-templates/{$template->id}/render", ['settings' => json_encode(['margins' => [1, 2, 3, 999]])])->assertStatus(422);

        $this->putJson("/pdf-templates/{$template->id}", ['settings' => ['word_break' => 'sideways']])->assertStatus(422);
        $this->putJson("/pdf-templates/{$template->id}", ['settings' => null])->assertOk();
        $this->assertNull($template->refresh()->settings);
    }

    public function test_history_keeps_and_restores_versions(): void
    {
        $template = $this->htmlTemplate(['html' => 'v1']);

        // Name / test data alone are not a new version; content is
        $template->update(['name' => 'Renamed', 'sample_data' => '{}']);
        $this->assertSame(0, $template->versions()->count());
        $template->update(['html' => 'v2']);
        $template->update(['html' => 'v3', 'settings' => ['margins' => [10, 10, 10, 10]]]);
        $this->assertSame(['v2', 'v1'], $template->versions()->pluck('html')->all());

        $first = $template->versions()->where('html', 'v1')->firstOrFail();
        $this->get("/pdf-templates/{$template->id}/edit")->assertOk()
            ->assertSee("/pdf-templates/{$template->id}/versions/{$first->id}/restore", false);
        $this->post("/pdf-templates/{$template->id}/versions/{$first->id}/restore")->assertRedirect();

        $template->refresh();
        $this->assertSame('v1', $template->html);
        $this->assertNull($template->settings);
        // The state before the restore is a version too
        $this->assertSame('v3', $template->versions()->first()->html);

        // A version of another template is not found
        $other = $this->htmlTemplate(['name' => 'Other']);
        $this->post("/pdf-templates/{$other->id}/versions/{$first->id}/restore")->assertNotFound();

        config(['pdf-templates.history.keep' => 2]);
        foreach (['a', 'b', 'c'] as $html) {
            $template->update(['html' => $html]);
        }
        $this->assertSame(2, $template->versions()->count());

        config(['pdf-templates.history.keep' => 0]);
        $template->update(['html' => 'no history']);
        $this->assertSame(2, $template->versions()->count());

        $template->delete();
        $this->assertSame(0, PdfTemplateVersion::where('template_id', $template->id)->count());
    }

    public function test_fonts_are_uploaded_listed_used_and_deleted(): void
    {
        $file = UploadedFile::fake()->createWithContent('MyFont.ttf', $this->ttf());

        $this->post('/pdf-templates/fonts', ['family' => 'My Font', 'weight' => 'bold', 'style' => 'normal', 'file' => $file])
            ->assertOk()
            ->assertJsonPath('fonts.0.family', 'My Font')
            ->assertJsonPath('fonts.0.weight', 'bold')
            ->assertJsonPath('families', ['DejaVu Sans', 'My Font']);
        $this->assertFileExists($this->fontDir.'/my-font-bold-normal.ttf');

        $fonts = app(FontLibrary::class)->fonts();
        $this->assertSame('bold', $fonts['My Font'][0][1]);
        $html = app(HtmlRenderer::class)->document($this->htmlTemplate(), ['title' => 'x']);
        $this->assertStringContainsString('font-family: "My Font"; font-weight: bold;', $html);
        $this->assertIsPdf(PdfTemplates::render($this->htmlTemplate(['css' => 'h1 { font-family: "My Font"; font-weight: bold; }']), ['title' => 'x']));

        // Not a font, bad family
        $this->postJson('/pdf-templates/fonts', ['family' => 'X', 'weight' => 'normal', 'style' => 'normal', 'file' => UploadedFile::fake()->create('x.ttf', 2)])
            ->assertStatus(422);
        $this->postJson('/pdf-templates/fonts', ['family' => '../evil', 'weight' => 'normal', 'style' => 'normal', 'file' => $file])
            ->assertStatus(422);

        $this->deleteJson('/pdf-templates/fonts', ['family' => 'My Font'])->assertOk()->assertJsonPath('fonts', []);
        $this->assertFileDoesNotExist($this->fontDir.'/my-font-bold-normal.ttf');
        $this->deleteJson('/pdf-templates/fonts', ['family' => 'My Font'])->assertNotFound();
    }

    public function test_fonts_from_google(): void
    {
        Http::fake([
            'fonts.googleapis.com/*' => Http::response(
                "@font-face { font-family: 'Open Sans'; font-style: italic; font-weight: 400; src: url(https://fonts.gstatic.com/s/opensans/i.ttf) format('truetype'); }\n"
                ."@font-face { font-family: 'Open Sans'; font-style: normal; font-weight: 400; src: url(https://fonts.gstatic.com/s/opensans/r.ttf) format('truetype'); }\n"
                ."@font-face { font-family: 'Open Sans'; font-style: normal; font-weight: 700; src: url(https://fonts.gstatic.com/s/opensans/b.ttf) format('truetype'); }\n"
            ),
            'fonts.gstatic.com/*' => Http::response($this->ttf()),
        ]);

        $this->postJson('/pdf-templates/fonts/google', ['family' => 'Open Sans'])
            ->assertOk()->assertJsonPath('added', 3)->assertJsonCount(3, 'fonts');
        $this->assertFileExists($this->fontDir.'/open-sans-normal-italic.ttf');

        Http::swap(new Factory);
        Http::fake(['fonts.googleapis.com/*' => Http::response('', 400)]);
        $this->postJson('/pdf-templates/fonts/google', ['family' => 'Nope Font'])
            ->assertStatus(422)->assertJsonPath('message', '"Nope Font" was not found on Google Fonts.');
    }

    public function test_external_images_are_imported_into_the_library(): void
    {
        // Kept in a variable: the fake's temporary file goes away with the object
        $image = UploadedFile::fake()->image('x.png', 4, 4);
        $png = (string) file_get_contents($image->getRealPath());
        Http::fake([
            '93.184.216.34/img/Company%20Logo.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/page.html' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->postJson('/pdf-templates/images/import', ['urls' => [
            'http://93.184.216.34/img/Company%20Logo.png',
            'http://93.184.216.34/page.html',
            'http://127.0.0.1/secret.png',
            'http://192.168.1.10/logo.png',
            'file:///etc/passwd',
        ]])->assertOk();

        $this->assertSame(['http://93.184.216.34/img/Company%20Logo.png' => 'pdf-asset:company20logo.png'], $response->json('imported'));
        $this->assertSame('Not a PNG, JPEG or SVG image.', $response->json('failed')['http://93.184.216.34/page.html']);
        $this->assertStringContainsString('private address', $response->json('failed')['http://127.0.0.1/secret.png']);
        $this->assertStringContainsString('private address', $response->json('failed')['http://192.168.1.10/logo.png']);
        $this->assertSame('Only http(s) URLs can be imported.', $response->json('failed')['file:///etc/passwd']);
        Storage::disk('images')->assertExists('pdf-templates/images/company20logo.png');
        // Only the public URL was requested
        Http::assertSentCount(2);
    }

    public function test_template_files_keep_the_settings(): void
    {
        $dir = sys_get_temp_dir().'/pdf-templates-test-'.uniqid();
        $settings = ['margins' => [20, 10, 20, 10], 'footer' => ['center' => '{currentPage}', 'font_size' => 9]];
        $this->htmlTemplate(['settings' => $settings]);

        $this->artisan('pdf-templates:export', ['path' => $dir])->assertSuccessful();
        PdfTemplate::query()->delete();
        $this->artisan('pdf-templates:import', ['path' => $dir])->assertSuccessful();

        $this->assertSame($settings, PdfTemplate::where('slug', 'invoice')->firstOrFail()->settings);
        File::deleteDirectory($dir);
    }
}
