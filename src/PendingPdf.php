<?php

namespace AnghelutaV\PdfTemplates;

use AnghelutaV\PdfTemplates\Jobs\GeneratePdf;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\Response;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PdfTemplates::make('invoice', $data)
 *     ->filename('invoice-42')
 *     ->watermark('DRAFT')               // or ['text' => 'COPY', 'opacity' => 0.1, 'color' => '#dc2626']
 *     ->save('invoices/42.pdf', 's3');   // or ->download(), ->inline(), ->content(), ->base64(), ->attachment()
 *
 * In a queue worker instead of the request: ->queue('invoices/42.pdf', 's3', ['invoice' => 42]) (see GeneratePdf).
 *
 * Returned from a controller it is shown inline. The PDF is generated once and reused.
 */
class PendingPdf implements Responsable
{
    protected ?string $filename = null;

    protected ?string $content = null;

    protected array $options = [];

    public function __construct(
        protected PdfTemplates $manager,
        protected PdfTemplate $template,
        protected string|array|null $data = [],
    ) {}

    public function with(string|array|null $data): static
    {
        $this->data = $data;
        $this->content = null;

        return $this;
    }

    public function filename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    // A diagonal text over every page; null removes it
    public function watermark(string|array|null $watermark): static
    {
        $this->options['watermark'] = $watermark;
        $this->content = null;

        return $this;
    }

    public function template(): PdfTemplate
    {
        return $this->template;
    }

    public function content(): string
    {
        return $this->content ??= $this->manager->render($this->template, $this->data, $this->options);
    }

    // One page as an image: ->image('png'), ->image('jpg', 2). Needs the Node renderer.
    public function image(string $format = 'png', int $page = 1): string
    {
        return $this->manager->image($this->template, $this->data, $format, $page, $this->options);
    }

    public function base64(): string
    {
        return base64_encode($this->content());
    }

    /**
     * Renders in a queue worker (queue config queue.name, "pdf") and stores the PDF at $path on $disk.
     * $context comes back in the PdfGenerating / PdfGenerated / PdfGenerationFailed events (e.g. ['invoice' => 42]).
     * Chain ->delay(), ->onQueue()… on the result like on any dispatch.
     */
    public function queue(string $path, ?string $disk = null, array $context = []): PendingDispatch
    {
        return GeneratePdf::dispatch($this->template->id, $this->data, $path, $disk, $this->options, $context);
    }

    // Stores the PDF on a filesystem disk and returns the path
    public function save(string $path, ?string $disk = null): string
    {
        Storage::disk($disk)->put($path, $this->content());

        return $path;
    }

    public function download(): Response
    {
        return $this->response('attachment');
    }

    public function inline(): Response
    {
        return $this->response('inline');
    }

    // For Mailables / notifications: public function attachments(): array { return [$pdf->attachment()]; }
    public function attachment(): Attachment
    {
        return Attachment::fromData(fn () => $this->content(), $this->resolvedFilename())->withMime('application/pdf');
    }

    public function toResponse($request): Response
    {
        return $this->inline();
    }

    protected function response(string $disposition): Response
    {
        return new Response($this->content(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$this->resolvedFilename().'"',
        ]);
    }

    protected function resolvedFilename(): string
    {
        $name = Str::slug(Str::beforeLast($this->filename ?? $this->template->slug, '.pdf')) ?: 'document';

        return $name.'.pdf';
    }
}
