<?php

namespace AnghelutaV\PdfTemplates\Jobs;

use AnghelutaV\PdfTemplates\ApiRenders;
use AnghelutaV\PdfTemplates\Models\PdfTemplateRender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

// An async API render (?async=1): renders, keeps the file and calls the webhook. The data travels in the job payload.
class RenderPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $renderId, public string $data, public int $page = 1)
    {
        if ($connection = config('pdf-templates.api.queue_connection')) {
            $this->onConnection($connection);
        }
        if ($queue = config('pdf-templates.api.queue')) {
            $this->onQueue($queue);
        }
    }

    public function handle(ApiRenders $renders): void
    {
        $render = PdfTemplateRender::find($this->renderId);
        if (! $render) {
            return;
        }

        try {
            $renders->run($render, $this->data, store: true, page: $this->page);
        } catch (Throwable) {
            // Logged on the render (status failed + error); the webhook tells the client
        }
        $renders->notify($render);
    }
}
