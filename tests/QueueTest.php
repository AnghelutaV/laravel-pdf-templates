<?php

namespace AnghelutaV\PdfTemplates\Tests;

use AnghelutaV\PdfTemplates\Events\PdfGenerated;
use AnghelutaV\PdfTemplates\Events\PdfGenerating;
use AnghelutaV\PdfTemplates\Events\PdfGenerationFailed;
use AnghelutaV\PdfTemplates\Facades\PdfTemplates;
use AnghelutaV\PdfTemplates\Jobs\GeneratePdf;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class QueueTest extends TestCase
{
    public function test_queue_dispatches_the_job_on_the_pdf_queue(): void
    {
        Queue::fake();
        $template = $this->htmlTemplate();

        PdfTemplates::make($template, ['title' => 'Hi'])->watermark('DRAFT')->queue('invoices/1.pdf', 'local', ['invoice' => 1]);

        Queue::assertPushedOn('pdf', GeneratePdf::class, function (GeneratePdf $job) use ($template) {
            return $job->templateId === $template->id && $job->data === ['title' => 'Hi'] && $job->path === 'invoices/1.pdf'
                && $job->disk === 'local' && $job->context === ['invoice' => 1] && $job->options === ['watermark' => 'DRAFT']
                && $job->tries === 3 && $job->timeout === 120;
        });
    }

    public function test_queue_name_and_limits_come_from_the_config(): void
    {
        Queue::fake();
        config(['pdf-templates.queue' => ['name' => 'documents', 'tries' => 5, 'timeout' => 60]]);

        PdfTemplates::make($this->htmlTemplate(), [])->queue('a.pdf');

        Queue::assertPushedOn('documents', GeneratePdf::class, fn (GeneratePdf $job) => $job->tries === 5 && $job->timeout === 60);
    }

    public function test_the_job_stores_the_pdf_and_fires_the_events(): void
    {
        Storage::fake('local');
        Event::fake([PdfGenerating::class, PdfGenerated::class]);
        $template = $this->htmlTemplate();

        (new GeneratePdf($template->id, ['title' => 'Queued'], 'invoices/7.pdf', 'local', [], ['invoice' => 7]))
            ->handle(app(\AnghelutaV\PdfTemplates\PdfTemplates::class));

        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get('invoices/7.pdf'));
        Event::assertDispatched(PdfGenerating::class, fn (PdfGenerating $e) => $e->path === 'invoices/7.pdf' && $e->context === ['invoice' => 7]);
        Event::assertDispatched(PdfGenerated::class, fn (PdfGenerated $e) => $e->bytes > 0 && $e->disk === 'local' && $e->context === ['invoice' => 7]);
    }

    public function test_a_failed_job_fires_the_failed_event(): void
    {
        Event::fake([PdfGenerationFailed::class]);

        (new GeneratePdf(1, [], 'x.pdf', 'local', [], ['invoice' => 3]))->failed(new RuntimeException('Renderer exploded'));

        Event::assertDispatched(PdfGenerationFailed::class, fn (PdfGenerationFailed $e) => $e->error === 'Renderer exploded' && $e->context === ['invoice' => 3]);
    }

    public function test_the_same_file_is_not_rendered_twice_at_once(): void
    {
        $middleware = (new GeneratePdf(1, [], 'invoices/1.pdf', 'local'))->middleware();

        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('pdf:local:invoices/1.pdf', $middleware[0]->key);
        $this->assertInstanceOf(ThrottlesExceptions::class, $middleware[1]);
    }
}
