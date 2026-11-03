<?php

namespace AnghelutaV\PdfTemplates\Ai;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;

// Sends the editor draft + the user's message to the agent and returns only what it changed
class Assistant
{
    const FIELDS = [
        PdfTemplate::MODE_HTML => ['name', 'html', 'css', 'sample_data', 'paper', 'orientation'],
        PdfTemplate::MODE_WYSIWYG => ['name', 'schema', 'sample_data'],
    ];

    public static function enabled(): bool
    {
        if (! config('pdf-templates.ai.enabled', true)) {
            return false;
        }

        $provider = (string) config('pdf-templates.ai.provider', 'anthropic');
        $settings = config("ai.providers.{$provider}");

        // Local providers (Ollama) have no key
        return is_array($settings) && (! empty($settings['key']) || ($settings['driver'] ?? $provider) === 'ollama');
    }

    /**
     * @param  array<string, mixed>  $draft  current editor content (unsaved)
     * @param  list<array{role: string, content: string}>  $history
     * @return array{reply: string, changes: array<string, string>}
     */
    public function ask(string $mode, array $draft, string $message, array $history = [], ?UploadedFile $file = null): array
    {
        $assets = new Assets;
        $fields = self::FIELDS[$mode] ?? throw new InvalidArgumentException("Unknown mode {$mode}");
        $draft = array_map(
            fn ($value) => $assets->stash(is_string($value) ? $value : (string) json_encode($value)),
            array_intersect_key($draft, array_flip($fields)),
        );

        // One section per field, JSON compacted: no JSON-in-JSON escaping, ~⅓ fewer tokens (small models have tight limits)
        $prompt = "Current draft:\n"
            .collect($draft)->map(function (string $value, string $field) {
                $json = in_array($field, ['schema', 'sample_data'], true) ? json_decode($value, true) : null;
                $value = is_array($json) ? (string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value;

                return "<pt:{$field}>\n{$value}\n</pt:{$field}>";
            })->implode("\n")
            ."\n\n"
            .($file ? "Attached: a reference document ({$file->getClientOriginalName()}). Reproduce its layout and content as a template.\n\n" : '')
            ."Request:\n".$message;

        $attachments = $file ? [str_starts_with((string) $file->getMimeType(), 'image/') ? Image::fromUpload($file) : Document::fromUpload($file)] : [];

        $limit = (int) config('pdf-templates.ai.history', 20);
        $history = $limit > 0 ? array_slice($history, -$limit) : [];
        $retries = max(0, (int) config('pdf-templates.ai.retries', 1));

        // Writing a whole template takes a while (free models: often over PHP's default 30 s)
        $seconds = (int) config('pdf-templates.ai.timeout', 120) * ($retries + 1) + 30;
        if ((int) ini_get('max_execution_time') > 0 && (int) ini_get('max_execution_time') < $seconds) {
            @set_time_limit($seconds);
        }

        // ai.format: "structured" = JSON output + check tools (strong models), "text" = <pt:…> sections (small models)
        $agent = config('pdf-templates.ai.format', 'structured') === 'text' ? TemplateAssistant::class : StructuredTemplateAssistant::class;

        // The answer is checked here as well, in both formats; the problems go back to the model, which answers again
        for ($attempt = 0; ; $attempt++) {
            $response = (new $agent($mode, $history))->prompt($prompt, $attempt === 0 ? $attachments : []);
            if ($response instanceof StructuredAgentResponse) {
                $output = $response->toArray();
                $text = (string) json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $text = (string) $response;
                $output = static::parse($text);
                // A section opened but never closed: the answer hit the model's output limit; a retry would too
                if (preg_match('#<pt:(\w+)>(?![\s\S]*</pt:\1>)#', $text, $cut)) {
                    throw new InvalidArgumentException(__('pdf-templates::ui.ai_cut_off', ['field' => $cut[1]]));
                }
            }
            [$changes, $problems] = $this->changes($mode, $output, $draft);

            if (! $problems) {
                return [
                    'reply' => trim((string) ($output['reply'] ?? '')),
                    'changes' => array_map(fn (string $value) => $assets->restore($value), $changes),
                ];
            }
            if ($attempt >= $retries) {
                throw new InvalidArgumentException(__('pdf-templates::ui.ai_invalid_output', ['error' => $problems[0]]));
            }

            $history[] = ['role' => 'user', 'content' => $prompt];
            $history[] = ['role' => 'assistant', 'content' => $text];
            $prompt = "Your answer has these problems:\n- ".implode("\n- ", $problems)
                ."\nAnswer again with the corrected full content, in the same format.";
        }
    }

    /**
     * <pt:field>content</pt:field> sections of the answer; text without any section is the reply
     *
     * @return array<string, string>
     */
    public static function parse(string $text): array
    {
        preg_match_all('#<pt:([a-z_]+)>(.*?)</pt:\1>#s', $text, $matches, PREG_SET_ORDER);

        $output = [];
        foreach ($matches as [, $field, $content]) {
            // Some models still wrap the content in a Markdown code fence
            $output[$field] = (string) preg_replace('#^\s*```[a-z]*\s*\n(.*?)\n\s*```\s*$#s', '$1', $content);
        }

        return $output ?: ['reply' => $text];
    }

    /**
     * The changed fields (normalized) and the problems found in them
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, string>  $draft
     * @return array{0: array<string, string>, 1: list<string>}
     */
    protected function changes(string $mode, array $output, array $draft): array
    {
        $changes = [];
        $problems = [];

        foreach (self::FIELDS[$mode] as $field) {
            $value = trim((string) ($output[$field] ?? ''));
            if ($value === '' || $value === trim($draft[$field] ?? '')) {
                continue;
            }

            if ($field === 'sample_data' || $field === 'schema') {
                $decoded = json_decode($value, true);
                if (! is_array($decoded)) {
                    $problems[] = "{$field} is not valid JSON: ".json_last_error_msg();

                    continue;
                }
                $value = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($field === 'schema') {
                array_push($problems, ...Checks::pdfme($value));
            }
            if ($field === 'paper' && ! in_array($value, PdfTemplate::PAPERS, true)
                || $field === 'orientation' && ! in_array($value, PdfTemplate::ORIENTATIONS, true)) {
                continue;
            }

            $changes[$field] = $value;
        }

        if ($mode === PdfTemplate::MODE_HTML && (isset($changes['html']) || isset($changes['sample_data']))) {
            array_push($problems, ...Checks::html($changes['html'] ?? $draft['html'] ?? '', $changes['sample_data'] ?? $draft['sample_data'] ?? null));
        }

        return [$changes, $problems];
    }
}
