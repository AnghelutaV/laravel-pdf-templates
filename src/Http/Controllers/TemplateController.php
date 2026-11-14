<?php

namespace AnghelutaV\PdfTemplates\Http\Controllers;

use AnghelutaV\PdfTemplates\Exceptions\RendererUnavailableException;
use AnghelutaV\PdfTemplates\Exceptions\RenderException;
use AnghelutaV\PdfTemplates\Gallery;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\Models\PdfTemplateVersion;
use AnghelutaV\PdfTemplates\PageSetup;
use AnghelutaV\PdfTemplates\PdfTemplates;
use AnghelutaV\PdfTemplates\Renderers\WysiwygRenderer;
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
            'category' => 'nullable|string|max:100',
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
        ]);

        [$column, $direction] = self::SORTS[$request->input('sort') ?: 'name'];

        // Only the list columns: schema can hold a base64 background PDF (MBs), html/css/sample_data are big too
        $templates = PdfTemplate::query()
            ->select(['id', 'name', 'slug', 'mode', 'description', 'category', 'active', 'created_at', 'updated_at'])
            ->search($request->input('q'))
            ->when($request->input('mode'), fn ($q, $mode) => $q->where('mode', $mode))
            ->when($request->input('category'), fn ($q, $category) => $q->where('category', $category))
            ->orderBy($column, $direction)
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('pdf-templates::index', [
            'templates' => $templates,
            'categories' => PdfTemplate::categories(),
            'sorts' => array_keys(self::SORTS),
            'version' => $this->version(),
            // "New template" dialog: start blank or from a starter (name, mode, category, description only)
            'gallery' => array_map(
                fn (array $starter) => array_intersect_key($starter, array_flip(['name', 'mode', 'category', 'description'])),
                app(Gallery::class)->all(),
            ),
        ]);
    }

    // List order (?sort=): name A–Z by default, or the most recently updated / created first
    const SORTS = [
        'name' => ['name', 'asc'],
        'updated' => ['updated_at', 'desc'],
        'created' => ['created_at', 'desc'],
    ];

    // Footer: installed version, linked to its release (tag) or to the changelog (dev checkout)
    protected function version(): ?array
    {
        $version = PdfTemplates::version();
        if (! $version) {
            return null;
        }

        $repo = config('pdf-templates.ui.repository', 'https://github.com/AnghelutaV/laravel-pdf-templates');
        $released = (bool) preg_match('/^v?\d+\.\d+\.\d+$/', $version);
        $label = $released ? 'v'.ltrim($version, 'v') : $version;

        return [
            'label' => $label,
            'url' => $repo ? ($released ? "{$repo}/releases/tag/{$label}" : "{$repo}/blob/main/CHANGELOG.md") : null,
            'changelog' => $repo ? "{$repo}/blob/main/CHANGELOG.md" : null,
        ];
    }

    public function store(Request $request)
    {
        $gallery = app(Gallery::class);
        $inputs = $request->validate([
            // From the gallery: the starter gives the mode, and its name when none is typed
            'starter' => ['nullable', 'string', Rule::in(array_keys($gallery->all()))],
            'name' => 'required_without:starter|nullable|string|max:255',
            'mode' => ['required_without:starter', 'nullable', Rule::in(PdfTemplate::MODES)],
            'description' => 'nullable|string|max:255',
        ]);

        $template = ! empty($inputs['starter'])
            ? $gallery->make($inputs['starter'], $inputs['name'] ?? null, $inputs['description'] ?? null)
            : PdfTemplate::makeStarter($inputs['name'], $inputs['mode'], $inputs['description'] ?? null);
        $template?->save();

        return redirect()->route($this->route('edit'), $template)->with('pdf-templates.status', __('pdf-templates::ui.created'));
    }

    // Languages the pdfme designer ships with
    const DESIGNER_LANGS = ['ar', 'de', 'en', 'es', 'fr', 'it', 'ja', 'ko', 'pl', 'th', 'tr', 'zh'];

    public function edit(PdfTemplate $template)
    {
        // History list: the small columns only
        $versions = $template->versions()->select(['id', 'template_id', 'author', 'created_at'])
            ->limit((int) config('pdf-templates.history.keep', 50))->get();

        if ($template->isHtml()) {
            return view('pdf-templates::edit-html', compact('template', 'versions'));
        }

        $locale = substr(app()->getLocale(), 0, 2);
        $designerLang = in_array($locale, self::DESIGNER_LANGS, true) ? $locale : 'en';

        return view('pdf-templates::edit-wysiwyg', compact('template', 'designerLang', 'versions'));
    }

    // Puts an earlier version back; the state before it is kept as a version too
    public function restore(PdfTemplate $template, PdfTemplateVersion $version)
    {
        abort_unless($version->template_id === $template->id, 404);
        $template->restore($version);

        return redirect()->route($this->route('edit'), $template)
            ->with('pdf-templates.status', __('pdf-templates::ui.version_restored', ['date' => $version->created_at?->format('d.m.Y H:i')]));
    }

    // Form submit (HTML mode) or JSON save (designer, test data)
    public function update(Request $request, PdfTemplate $template)
    {
        $inputs = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => ['sometimes', 'required', 'alpha_dash', 'max:255', Rule::unique($template->getTable())->ignore($template)],
            'description' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:100',
            'active' => 'sometimes|boolean',
            'schema' => 'sometimes|nullable|json',
            'html' => 'sometimes|nullable|string',
            'css' => 'sometimes|nullable|string',
            'paper' => ['sometimes', Rule::in(PdfTemplate::PAPERS)],
            'orientation' => ['sometimes', Rule::in(PdfTemplate::ORIENTATIONS)],
            'sample_data' => 'sometimes|nullable|json',
        ] + PageSetup::rules());

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
        $configured = $pdf->wysiwygOnServer();

        return view('pdf-templates::test', [
            'template' => $template,
            'rendererConfigured' => $configured,
            // Only pinged when it matters (WYSIWYG + URL set)
            'rendererUp' => $configured && ! $template->isHtml() ? WysiwygRenderer::reachable() : null,
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
            ? json_encode($template->only(['name', 'mode', 'html', 'css', 'paper', 'orientation', 'settings']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
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
            // Unsaved page setup, as JSON (the preview posts form data)
            'settings' => 'nullable|json',
        ]);

        // Preview of unsaved edits: render a copy, never persist
        $preview = $template->replicate()->fill(array_filter(
            $request->only(['html', 'css', 'paper', 'orientation']),
            fn ($value) => $value !== null,
        ));
        if ($request->filled('settings')) {
            $settings = ['settings' => json_decode((string) $request->input('settings'), true)];
            validator($settings, PageSetup::rules())->validate();
            $preview->settings = $settings['settings'];
        }
        $preview->slug = $template->slug;

        try {
            return $pdf->inline($preview, $request->input('data'));
        } catch (Throwable $e) {
            // Template/JSON errors are expected while editing; anything else is worth a log entry
            if (! $e instanceof InvalidArgumentException && ! $e instanceof RenderException) {
                report($e);
            }

            return response()->json(array_filter([
                'error' => $e->getMessage(),
                'code' => $e instanceof RendererUnavailableException ? 'renderer_down' : null,
            ]), 422);
        }
    }

    protected function route(string $name): string
    {
        return config('pdf-templates.routes.name', 'pdf-templates.').$name;
    }
}
