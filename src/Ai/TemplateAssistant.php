<?php

namespace AnghelutaV\PdfTemplates\Ai;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

// ai.format "text" (small / free models): plain text answer with <pt:field> sections (Assistant::parse()),
// no structured output and no tools, so whole HTML documents need no escaping.
// StructuredTemplateAssistant is the "structured" format for the strong models.
class TemplateAssistant implements Agent, Conversational
{
    use Promptable;

    const FORMAT = 'text';

    /** @param list<array{role: string, content: string}> $history */
    public function __construct(public string $mode = PdfTemplate::MODE_HTML, public array $history = []) {}

    public function instructions(): string
    {
        $html = $this->mode === PdfTemplate::MODE_HTML;
        $dir = __DIR__.'/../../resources/prompts/';
        $prompt = strtr((string) file_get_contents($dir.$this->mode.'.md'), [
            '{{answer}}' => rtrim((string) file_get_contents($dir.'answer/'.$this->mode.'-'.static::FORMAT.'.md')),
            '{{papers}}' => implode(', ', PdfTemplate::PAPERS),
        ]);

        if (! $html) {
            return $prompt;
        }

        $syntax = collect((array) trans('pdf-templates::ui.syntax', [], 'en'))
            ->map(fn (array $row) => "- `{$row[0]}`: {$row[1]}")
            ->implode("\n");
        $fonts = collect([config('pdf-templates.html.default_font', 'DejaVu Sans'), ...array_keys(config('pdf-templates.html.fonts', []))])
            ->implode(', ');

        return strtr($prompt, [
            '{{syntax}}' => $syntax,
            '{{fonts}}' => $fonts,
            '{{starter_html}}' => PdfTemplate::STARTER_HTML,
            '{{starter_css}}' => PdfTemplate::STARTER_CSS,
            '{{starter_sample}}' => PdfTemplate::STARTER_SAMPLE,
        ]);
    }

    public function messages(): iterable
    {
        return array_map(fn (array $message) => new Message($message['role'], $message['content']), $this->history);
    }

    public function provider(): string
    {
        return (string) config('pdf-templates.ai.provider', 'anthropic');
    }

    public function model(): ?string
    {
        return config('pdf-templates.ai.model') ?: null;
    }

    public function timeout(): int
    {
        return (int) config('pdf-templates.ai.timeout', 120);
    }
}
