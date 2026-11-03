<?php

namespace AnghelutaV\PdfTemplates\Ai\Tools;

use AnghelutaV\PdfTemplates\Ai\Checks;
use AnghelutaV\PdfTemplates\TemplateEngine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

// Lets the model test its HTML template (syntax + data keys) before it answers ("structured" format)
class CheckHtmlTemplate implements Tool
{
    public function description(): string
    {
        return 'Check an HTML template: parses the {placeholder} syntax, fills it with the sample data and lists the data keys it uses. Returns "ok" with the keys, or the errors.';
    }

    public function handle(Request $request): string
    {
        $html = (string) $request['html'];
        $sample = (string) ($request['sample_data'] ?? '');

        if ($errors = Checks::html($html, $sample)) {
            return 'Errors: '.implode('; ', $errors);
        }

        $data = json_decode($sample ?: '{}', true);
        if (! is_array($data)) {
            return 'Error: sample_data is not a JSON object ('.json_last_error_msg().').';
        }

        $fields = app(TemplateEngine::class)->fields($html);
        $missing = array_keys(array_filter($fields, fn ($type, $path) => ! str_contains($path, '*') && data_get($data, $path) === null, ARRAY_FILTER_USE_BOTH));

        return 'ok. Keys: '.(json_encode($fields) ?: '{}')
            .($missing ? '. Missing in sample_data: '.implode(', ', $missing) : '');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'html' => $schema->string()->description('The full HTML template')->required(),
            'sample_data' => $schema->string()->description('Sample data, a JSON object as a string')->required(),
        ];
    }
}
