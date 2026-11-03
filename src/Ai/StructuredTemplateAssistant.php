<?php

namespace AnghelutaV\PdfTemplates\Ai;

use AnghelutaV\PdfTemplates\Ai\Tools\CheckHtmlTemplate;
use AnghelutaV\PdfTemplates\Ai\Tools\CheckPdfmeTemplate;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;

// ai.format "structured" (Claude, GPT, Gemini…): structured output + check tools the model calls before it answers
#[MaxSteps(8)]
class StructuredTemplateAssistant extends TemplateAssistant implements HasStructuredOutput, HasTools
{
    const FORMAT = 'structured';

    public function tools(): iterable
    {
        return $this->mode === PdfTemplate::MODE_HTML ? [new CheckHtmlTemplate] : [new CheckPdfmeTemplate];
    }

    // Every key is required (strict structured output on every provider); "" = unchanged
    public function schema(JsonSchema $schema): array
    {
        $unchanged = ' Empty string = unchanged.';
        $fields = [
            'reply' => $schema->string()->description('Short answer for the user: what changed, or a question.')->required(),
            'name' => $schema->string()->description('New template name, only for a new template or when asked.'.$unchanged)->required(),
            'sample_data' => $schema->string()->description('Full sample data, a JSON object as a string.'.$unchanged)->required(),
        ];

        return $this->mode === PdfTemplate::MODE_HTML ? $fields + [
            'html' => $schema->string()->description('Full HTML template.'.$unchanged)->required(),
            'css' => $schema->string()->description('Full CSS.'.$unchanged)->required(),
            'paper' => $schema->string()->enum(['', ...PdfTemplate::PAPERS])->description('Paper size.'.$unchanged)->required(),
            'orientation' => $schema->string()->enum(['', ...PdfTemplate::ORIENTATIONS])->description('Orientation.'.$unchanged)->required(),
        ] : $fields + [
            'schema' => $schema->string()->description('Full pdfme template, a JSON string.'.$unchanged)->required(),
        ];
    }
}
