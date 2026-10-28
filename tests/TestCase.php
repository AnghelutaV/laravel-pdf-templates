<?php

namespace AnghelutaV\PdfTemplates\Tests;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\PdfTemplatesServiceProvider;
use Barryvdh\DomPDF\ServiceProvider as DomPdfServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [DomPdfServiceProvider::class, PdfTemplatesServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('pdf-templates.api.key', 'test-key');
        // Most tests are about behaviour, not access: the Authorize middleware has its own tests
        $app['config']->set('pdf-templates.gate', null);
    }

    protected function htmlTemplate(array $attributes = []): PdfTemplate
    {
        return PdfTemplate::create($attributes + [
            'name' => 'Invoice',
            'mode' => PdfTemplate::MODE_HTML,
            'html' => '<h1>{title}</h1>{#each items}<p>{@number}. {name}</p>{/each}',
            'css' => 'h1 { color: #123; }',
            'sample_data' => '{"title":"Sample","items":[{"name":"A"}]}',
        ]);
    }

    protected function wysiwygTemplate(array $attributes = []): PdfTemplate
    {
        return PdfTemplate::create($attributes + [
            'name' => 'Designer',
            'mode' => PdfTemplate::MODE_WYSIWYG,
            'schema' => json_encode([
                'basePdf' => ['width' => 210, 'height' => 297, 'padding' => [0, 0, 0, 0]],
                'schemas' => [[
                    ['name' => 'client', 'type' => 'text', 'position' => ['x' => 10, 'y' => 10], 'width' => 50, 'height' => 10],
                    ['name' => 'label', 'type' => 'text', 'readOnly' => true, 'content' => 'Fixed', 'position' => ['x' => 10, 'y' => 30], 'width' => 50, 'height' => 10],
                    ['name' => 'items', 'type' => 'table', 'position' => ['x' => 10, 'y' => 50], 'width' => 150, 'height' => 20],
                ]],
            ]),
        ]);
    }

    protected function assertIsPdf(string $content): void
    {
        $this->assertStringStartsWith('%PDF-', $content);
    }
}
