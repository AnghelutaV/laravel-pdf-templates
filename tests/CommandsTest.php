<?php

namespace AnghelutaV\PdfTemplates\Tests;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use Illuminate\Support\Facades\File;

class CommandsTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pdf-templates-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_examples_are_added_once(): void
    {
        $this->artisan('pdf-templates:examples')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['example-invoice', 'example-certificate', 'example-html'], PdfTemplate::pluck('slug')->all());

        PdfTemplate::where('slug', 'example-html')->update(['name' => 'Edited']);
        $this->artisan('pdf-templates:examples')->assertSuccessful();
        $this->assertSame('Edited', PdfTemplate::where('slug', 'example-html')->value('name'));

        $this->artisan('pdf-templates:examples', ['--force' => true])->assertSuccessful();
        $this->assertSame('Example: Simple document (HTML)', PdfTemplate::where('slug', 'example-html')->value('name'));
    }

    public function test_export_then_import_round_trip(): void
    {
        $html = $this->htmlTemplate(['description' => 'Desc', 'active' => false]);
        $designer = $this->wysiwygTemplate();

        $this->artisan('pdf-templates:export', ['path' => $this->dir])->assertSuccessful();
        $this->assertFileExists("{$this->dir}/invoice/template.html");
        $this->assertFileExists("{$this->dir}/invoice/style.css");
        $this->assertFileExists("{$this->dir}/designer/template.json");

        PdfTemplate::query()->delete();
        $this->artisan('pdf-templates:import', ['path' => $this->dir])->assertSuccessful();

        $imported = PdfTemplate::where('slug', 'invoice')->firstOrFail();
        $this->assertSame($html->html, $imported->html);
        $this->assertSame('Desc', $imported->description);
        $this->assertFalse($imported->active);
        $this->assertEquals(json_decode($designer->schema, true), json_decode(PdfTemplate::where('slug', 'designer')->value('schema'), true));
    }

    public function test_import_skips_existing_unless_forced(): void
    {
        $this->htmlTemplate();
        $this->artisan('pdf-templates:export', ['path' => $this->dir])->assertSuccessful();
        PdfTemplate::where('slug', 'invoice')->update(['html' => 'local edit']);

        $this->artisan('pdf-templates:import', ['path' => $this->dir])->assertSuccessful();
        $this->assertSame('local edit', PdfTemplate::where('slug', 'invoice')->value('html'));

        $this->artisan('pdf-templates:import', ['path' => $this->dir, '--force' => true])->assertSuccessful();
        $this->assertStringStartsWith('<h1>', PdfTemplate::where('slug', 'invoice')->value('html'));
    }

    public function test_import_from_empty_folder_fails(): void
    {
        $this->artisan('pdf-templates:import', ['path' => $this->dir])->assertFailed();
    }

    public function test_render_command(): void
    {
        $this->htmlTemplate();
        File::ensureDirectoryExists($this->dir);
        File::put("{$this->dir}/data.json", '{"title":"CLI"}');

        $this->artisan('pdf-templates:render', ['template' => 'invoice', 'output' => "{$this->dir}/out.pdf", '--data' => "{$this->dir}/data.json"])
            ->assertSuccessful();
        $this->assertIsPdf(File::get("{$this->dir}/out.pdf"));

        $this->artisan('pdf-templates:render', ['template' => 'invoice', '--fields' => true])
            ->expectsTable(['Key', 'Type'], [['title', 'value'], ['items', 'list'], ['items.*.name', 'value']])
            ->assertSuccessful();

        $this->artisan('pdf-templates:render', ['template' => 'missing'])->assertFailed();
    }
}
