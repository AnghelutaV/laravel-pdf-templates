<?php

namespace AnghelutaV\PdfTemplates\Http\Controllers;

use AnghelutaV\PdfTemplates\Ai\Assistant;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class AssistantController extends Controller
{
    // Chat in an editor: the answer goes into the editor as unsaved changes
    public function edit(Request $request, PdfTemplate $template, Assistant $assistant)
    {
        // Checked here, not in the constructor: route:list builds the controllers
        abort_unless(Assistant::enabled(), 404);

        $inputs = $request->validate([
            'message' => 'required|string|max:10000',
            'draft' => 'nullable|json',
            'history' => 'nullable|json',
            'file' => $this->fileRules(),
        ]);

        try {
            return response()->json($assistant->ask(
                $template->mode,
                (array) json_decode($inputs['draft'] ?? '{}', true),
                $inputs['message'],
                $this->history($inputs['history'] ?? null),
                $request->file('file'),
            ));
        } catch (Throwable $e) {
            return response()->json(['error' => $this->message($e)], 422);
        }
    }

    // "Generate with AI" in the new template dialog: the template is created with the answer
    public function create(Request $request, Assistant $assistant)
    {
        abort_unless(Assistant::enabled(), 404);

        $inputs = $request->validate([
            'name' => 'required|string|max:255',
            'mode' => ['required', Rule::in(PdfTemplate::MODES)],
            'description' => 'nullable|string|max:255',
            'message' => 'required|string|max:10000',
            'file' => $this->fileRules(),
        ]);

        $template = PdfTemplate::makeStarter($inputs['name'], $inputs['mode'], $inputs['description'] ?? null);

        try {
            $answer = $assistant->ask(
                $template->mode,
                $template->only(Assistant::FIELDS[$template->mode]),
                "Create a new template: {$inputs['message']}",
                [],
                $request->file('file'),
            );
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['message' => $this->message($e)], 'ai');
        }

        // The name typed in the dialog wins over the one the AI may suggest
        $template->fill(['name' => $inputs['name']] + $answer['changes'])->save();

        $reply = $answer['reply'] ?: __('pdf-templates::ui.ai_generated');

        // The editor's chat starts with this exchange, so the next requests keep its context
        return redirect()->route(config('pdf-templates.routes.name', 'pdf-templates.').'edit', $template)
            ->with('pdf-templates.status', $reply)
            ->with('pdf-templates.ai_history', [
                ['role' => 'user', 'content' => $inputs['message'].($request->file('file') ? "\n📎 ".$request->file('file')->getClientOriginalName() : '')],
                ['role' => 'assistant', 'content' => $reply],
            ]);
    }

    protected function fileRules(): array
    {
        return ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,gif,pdf', 'max:'.(int) config('pdf-templates.ai.max_upload_kb', 10240)];
    }

    /** @return list<array{role: string, content: string}> */
    protected function history(?string $json): array
    {
        return collect((array) json_decode($json ?? '[]', true))
            ->filter(fn ($m) => is_array($m) && in_array($m['role'] ?? null, ['user', 'assistant'], true) && is_string($m['content'] ?? null))
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->values()
            ->all();
    }

    // Expected errors (bad output, provider down / rate limited) are shown as is; the rest is logged
    protected function message(Throwable $e): string
    {
        // Request too large (413) / tokens per minute (429): typical of small and free plans
        if ($e instanceof RateLimitedException || $e instanceof RequestException && in_array($e->response->status(), [413, 429], true)) {
            return __('pdf-templates::ui.ai_error', ['error' => __('pdf-templates::ui.ai_too_large')]);
        }
        if (! $e instanceof InvalidArgumentException && ! str_starts_with($e::class, 'Laravel\\Ai\\')) {
            report($e);
        }

        return __('pdf-templates::ui.ai_error', ['error' => $e->getMessage()]);
    }
}
