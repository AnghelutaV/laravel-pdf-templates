<?php

namespace AnghelutaV\PdfTemplates\Events;

use Illuminate\Foundation\Events\Dispatchable;

// A queued PDF was rendered and stored at $path on $disk
class PdfGenerated
{
    use Dispatchable;

    public function __construct(
        public string $path,
        public ?string $disk,
        public int $bytes,
        public int $durationMs,
        public array $context = [],
    ) {}
}
