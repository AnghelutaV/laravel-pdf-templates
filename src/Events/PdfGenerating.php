<?php

namespace AnghelutaV\PdfTemplates\Events;

use Illuminate\Foundation\Events\Dispatchable;

// A queued PDF (PendingPdf::queue()) is being rendered now; $context is what the app passed (e.g. model + id)
class PdfGenerating
{
    use Dispatchable;

    public function __construct(
        public string $path,
        public ?string $disk,
        public array $context = [],
        public int $attempt = 1,
    ) {}
}
