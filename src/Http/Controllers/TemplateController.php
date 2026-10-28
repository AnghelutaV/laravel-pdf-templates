<?php

namespace AnghelutaV\PdfTemplates\Http\Controllers;

use AnghelutaV\PdfTemplates\Exceptions\RenderException;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\PdfTemplates;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'q' => 'nullable|string',
            'mode' => ['nullable', Rule::in(PdfTemplate::MODES)],
        ]);

        $templates = PdfTemplate::query()
            ->search($request->input('q'))
            ->when($request->input('mode'), fn ($q, $mode) => $q->where('mode', $mode))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('pdf-templates::index', compact('templates'));
    }

    public function store(Request $request)
    {
        $inputs = $request->validate([
            'name' => 'required|string|max:255',
            'mode' => ['required', Rule::in(PdfTemplate::MODES)],
            'description' => 'nullable|string|max:255',
        ]);

        $template = PdfTemplate::makeStarter($inputs['name'], $inputs['mode'], $inputs['description'] ?? null);
        $template->save();

        return redirect()->route($this->route('edit'), $template)->with('pdf-templates.status', __('pdf-templates::ui.created'));
    }

    // Languages the pdfme designer ships with
    const DESIGNER_LANGS = ['ar', 'de', 'en', 'es', 'fr', 'it', 'ja', 'ko', 'pl', 'th', 'tr', 'zh'];

    public function edit(PdfTemplate $template)
    {
        if ($template->isHtml()) {
            return view('pdf-templates::edit-html', compact('template'));
        }

        $locale = substr(app()->getLocale(), 0, 2);
        $designerLang = in_array($locale, self::DESIGNER_LANGS, true) ? $locale : 'en';

        return view('pdf-templates::edit-wysiwyg', compact('template', 'designerLang'));
    }

    // Form submit (HTML mode) or JSON save (designer, test data)
    public function update(Request $request, PdfTemplate $template)
    {
        $inputs = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => ['sometimes', 'required', 'alpha_dash', 'max:255', Rule::unique($template->getTable())->ignore($template)],
            'description' => 'nullable|string|max:255',
            'active' => 'sometimes|boolean',
            'schema' => 'sometimes|nullable|json',
            'html' => 'sometimes|nullable|string',
            'css' => 'sometimes|nullable|string',
            'paper' => ['sometimes', Rule::in(PdfTemplate::PAPERS)],
            'orientation' => ['sometimes', Rule::in(PdfTemplate::ORIENTATIONS)],
            'sample_data' => 'sometimes|nullable|json',
        ]);

        $template->update($inputs);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'updated_at' => $template->updated_at->format('H:i:s')]);
        }

        return back()->with('pdf-templates.status', __('pdf-templates::ui.saved'));
    }

    public function duplicate(PdfTemplate $template)
    {
        $copy = $template->replicate(['slug']);
        $copy->name = $template->name.' ('.__('pdf-templates::ui.copy').')';
        $copy->save();

        return redirect()->route($this->route('edit'), $copy)->with('pdf-templates.status', __('pdf-templates::ui.duplicated'));
    }

    public function destroy(PdfTemplate $template)
    {
        $template->delete();

        return redirect()->route($this->route('index'))->with('pdf-templates.status', __('pdf-templates::ui.deleted', ['name' => $template->name]));
    }

    public function test(PdfTemplate $template, PdfTemplates $pdf)
    {
        return view('pdf-templates::test', [
            'template' => $template,
            'rendererConfigured' => $pdf->wysiwygOnServer(),
            'apiEnabled' => config('pdf-templates.api.enabled', true) && config('pdf-templates.api.key'),
            'fields' => $this->safeFields($pdf, $template),
        ]);
    }

    // A template being edited may have broken syntax; the test page must still open
    protected function safeFields(PdfTemplates $pdf, PdfTemplate $template): ?array
    {
        try {
            return $pdf->fields($template);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    // Template definition, e.g. to reuse it in another app or in the pdfme playground
    public function export(PdfTemplate $template)
    {
        $content = $template->isHtml()
            ? json_encode($template->only(['name', 'mode', 'html', 'css', 'paper', 'orientation']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : $template->schema;

        return response($content, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$template->slug.'.json"',
        ]);
    }

    // Server-side render for the admin: test page, live HTML preview with unsaved html/css
    public function render(Request $request, PdfTemplate $template, PdfTemplates $pdf)
    {
        $request->validate([
            'data' => 'nullable|string',
            'html' => 'nullable|string',
            'css' => 'nullable|string',
            'paper' => ['nullable', Rule::in(PdfTemplate::PAPERS)],
            'orientation' => ['nullable', Rule::in(PdfTemplate::ORIENTATIONS)],
        ]);

        // Preview of unsaved edits: render a copy, never persist
        $preview = $template->replicate()->fill(array_filter(
            $request->only(['html', 'css', 'paper', 'orientation']),
            fn ($value) => $value !== null,
        ));
        $preview->slug = $template->slug;

        try {
            return $pdf->inline($preview, $request->input('data'));
        } catch (Throwable $e) {
            // Template/JSON errors are expected while editing; anything else is worth a log entry
            if (! $e instanceof InvalidArgumentException && ! $e instanceof RenderException) {
                report($e);
            }

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    protected function route(string $name): string
    {
        return config('pdf-templates.routes.name', 'pdf-templates.').$name;
    }
}
