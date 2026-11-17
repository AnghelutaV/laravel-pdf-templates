<?php

namespace AnghelutaV\PdfTemplates\Jobs;

use AnghelutaV\PdfTemplates\Events\PdfGenerated;
use AnghelutaV\PdfTemplates\Events\PdfGenerating;
use AnghelutaV\PdfTemplates\Events\PdfGenerationFailed;
use AnghelutaV\PdfTemplates\Exceptions\RendererUnavailableException;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\PdfTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A PDF made in a queue worker (PendingPdf::queue()): rendered, stored at $path on $disk, events fired so the app
 * can keep the status of its document (PdfGenerating → PdfGenerated / PdfGenerationFailed).
 *
 * On its own queue (config queue.name, "pdf") so a few workers bound how many PDFs (and Chrome pages) run at once.
 * The same file is never rendered twice at the same time; while the Node renderer is down the job backs off
 * instead of using up its tries.
 */
class GeneratePdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $templateId,
        public string|array|null $data,
        public string $path,
        public ?string $disk = null,
        public array $options = [],
        public array $context = [],
    ) {
        $config = (array) config('pdf-templates.queue', []);
        $this->tries = (int) ($config['tries'] ?? 3);
        $this->timeout = (int) ($config['timeout'] ?? 120);
        if (! empty($config['connection'])) {
            $this->onConnection($config['connection']);
        }
        $this->onQueue($config['name'] ?? 'pdf');
    }

    /** @return list<int> seconds before the 2nd, 3rd… try */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function middleware(): array
    {
        return [
            // A double click / a second dispatch for the same document waits for the first one
            (new WithoutOverlapping($this->lockKey()))->releaseAfter(30)->expireAfter($this->timeout + 60),
            // Renderer down: wait and retry later, without spending the tries
            (new ThrottlesExceptions(3, 60))->by('pdf-templates-renderer')->backoff(1)
                ->when(fn (Throwable $e) => $e instanceof RendererUnavailableException),
        ];
    }

    public function lockKey(): string
    {
        return 'pdf:'.($this->disk ?? 'default').':'.$this->path;
    }

    public function handle(PdfTemplates $pdf): void
    {
        PdfGenerating::dispatch($this->path, $this->disk, $this->context, $this->attempts());
        $started = hrtime(true);

        // The template as it is now (edited since the dispatch: the new version)
        $content = $pdf->render(PdfTemplate::findOrFail($this->templateId), $this->data, $this->options);
        Storage::disk($this->disk)->put($this->path, $content);

        PdfGenerated::dispatch($this->path, $this->disk, strlen($content), (int) ((hrtime(true) - $started) / 1e6), $this->context);
    }

    public function failed(?Throwable $e): void
    {
        PdfGenerationFailed::dispatch($this->path, $this->disk, $e?->getMessage() ?? 'Failed.', $this->context);
    }
}
