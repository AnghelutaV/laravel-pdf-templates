<?php

namespace AnghelutaV\PdfTemplates\Ai\Tools;

use AnghelutaV\PdfTemplates\Ai\Checks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

// Lets the model test its pdfme template structure before it answers ("structured" format)
class CheckPdfmeTemplate implements Tool
{
    public function description(): string
    {
        return 'Check a pdfme template (JSON string): structure, field types, unique names, fields inside the page. Returns "ok" or the list of problems.';
    }

    public function handle(Request $request): string
    {
        $problems = Checks::pdfme((string) $request['schema']);

        return $problems ? "Problems:\n- ".implode("\n- ", $problems) : 'ok';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'schema' => $schema->string()->description('The full pdfme template, a JSON string')->required(),
        ];
    }
}
