<?php

namespace AnghelutaV\PdfTemplates\Events;

use Illuminate\Foundation\Events\Dispatchable;

// A queued PDF failed for good (all tries used, or a timeout); $error is the last exception message
class PdfGenerationFailed
{
    use Dispatchable;

    public function __construct(
        public string $path,
        public ?string $disk,
        public string $error,
        public array $context = [],
    ) {}
}
