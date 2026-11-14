<?php

namespace AnghelutaV\PdfTemplates\Http\Controllers;

use AnghelutaV\PdfTemplates\ApiRenders;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\Models\PdfTemplateApiKey;
use AnghelutaV\PdfTemplates\Models\PdfTemplateRender;
use AnghelutaV\PdfTemplates\PdfTemplates;
use AnghelutaV\PdfTemplates\PostmanCollection;
use AnghelutaV\PdfTemplates\RendererProcess;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;

// Admin UI → API: keys per client, the Node renderer (start / stop), the API documentation, the render log, Postman
class ApiAdminController extends Controller
{
    public function index(Request $request, PdfTemplates $pdf, RendererProcess $renderer)
    {
        $renders = PdfTemplateRender::with('apiKey:id,name')
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('key'), fn ($query, $key) => $query->where('api_key_id', $key))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        $since = now()->subDay();
        $stats = [
            'day' => PdfTemplateRender::where('created_at', '>=', $since)->count(),
            'failed' => PdfTemplateRender::where('created_at', '>=', $since)->where('status', PdfTemplateRender::FAILED)->count(),
            'avg_ms' => (int) PdfTemplateRender::where('created_at', '>=', $since)->where('status', PdfTemplateRender::DONE)->avg('duration_ms'),
        ];

        return view('pdf-templates::api', [
            // Usage per key: documents this month (the monthly limit), renders and failures in the last 30 days
            'keys' => PdfTemplateApiKey::withCount([
                'renders',
                'renders as renders_30d' => fn ($query) => $query->where('created_at', '>=', now()->subDays(30)),
                'renders as failed_30d' => fn ($query) => $query->where('created_at', '>=', now()->subDays(30))->where('status', PdfTemplateRender::FAILED),
            ])->withSum(['renders as month_documents' => fn ($query) => $query->where('created_at', '>=', now()->startOfMonth())
                ->where('status', '!=', PdfTemplateRender::FAILED)], 'documents')
                ->orderByRaw('revoked_at is not null')->orderBy('name')->get(),
            'renders' => $renders,
            'stats' => $stats,
            'masterKey' => (string) config('pdf-templates.api.key') !== '',
            'apiUrl' => url(config('pdf-templates.api.prefix', 'api/pdf-templates')),
            'example' => PdfTemplate::active()->orderBy('name')->first(),
            'newKey' => $request->session()->get('pdf-templates.api_key'),
            'renderer' => $renderer->status(),
            // Documentation: every active template with the keys it reads and its test data as the example body
            'docs' => PdfTemplate::active()->orderBy('name')->get()->map(fn (PdfTemplate $template) => [
                'slug' => $template->slug,
                'name' => $template->name,
                'mode' => $template->mode,
                'fields' => $pdf->fields($template),
                'example' => json_decode((string) $template->sample_data, true) ?? new \stdClass,
            ])->values(),
            // Log filters / pages open the log tab
            'tab' => in_array($request->query('tab'), ['server', 'docs', 'log'], true)
                ? $request->query('tab')
                : ($request->query('status') || $request->query('key') || $request->query('page') ? 'log' : 'server'),
        ]);
    }

    public function storeKey(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        [$key, $plain] = PdfTemplateApiKey::generate($data['name']);

        // Shown once, on the next page
        return redirect()->route($this->route('api.index'))
            ->with('pdf-templates.api_key', ['name' => $key->name, 'key' => $plain])
            ->with('pdf-templates.status', __('pdf-templates::ui.api_key_created', ['name' => $key->name]));
    }

    // Limits of a client key: requests per minute, documents per month (empty = no limit)
    public function updateKey(Request $request, PdfTemplateApiKey $key)
    {
        $data = $request->validate([
            'per_minute' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_month' => ['nullable', 'integer', 'min:1', 'max:100000000'],
        ]);
        $key->update(['per_minute' => $data['per_minute'] ?? null, 'per_month' => $data['per_month'] ?? null]);

        return redirect()->route($this->route('api.index'))
            ->with('pdf-templates.status', __('pdf-templates::ui.api_key_updated', ['name' => $key->name]));
    }

    public function revokeKey(PdfTemplateApiKey $key)
    {
        $key->update(['revoked_at' => now()]);

        return redirect()->route($this->route('api.index'))
            ->with('pdf-templates.status', __('pdf-templates::ui.api_key_revoked', ['name' => $key->name]));
    }

    // The log's "Render again": the same template, data and format, shown in the browser (not logged as an API render)
    public function renderAgain(string $render, PdfTemplates $pdf)
    {
        $model = PdfTemplateRender::where('uuid', $render)->firstOrFail();
        abort_if($model->data === null, 404, __('pdf-templates::ui.api_no_data'));
        $template = $model->format === 'zip' ? null : (PdfTemplate::find($model->template_id) ?? abort(404));

        try {
            $content = $model->format === 'zip'
                ? $pdf->zip(app(ApiRenders::class)->batchItems($model->data), $model->renderOptions())
                : ($model->format === 'pdf'
                ? $pdf->render($template, $model->data, $model->renderOptions())
                : $pdf->image($template, $model->data, $model->format, (int) ($model->options['page'] ?? 1), $model->renderOptions()));
        } catch (\Throwable $e) {
            return response($e->getMessage(), 422, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response($content, 200, [
            'Content-Type' => $model->mime(),
            'Content-Disposition' => 'inline; filename="'.($model->filename ?? 'document.'.$model->format).'"',
        ]);
    }

    public function rendererStatus(RendererProcess $renderer)
    {
        return response()->json($renderer->status());
    }

    public function rendererStart(RendererProcess $renderer)
    {
        try {
            $pid = $renderer->start();
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()] + $renderer->status(), 422);
        }

        return response()->json(['message' => __('pdf-templates::ui.renderer_started', ['pid' => $pid])] + $renderer->status());
    }

    public function rendererStop(RendererProcess $renderer)
    {
        $stopped = $renderer->stop();

        return response()->json(['message' => __($stopped ? 'pdf-templates::ui.renderer_stopped' : 'pdf-templates::ui.renderer_not_ours')] + $renderer->status());
    }

    // The collection for this app: its API URL and every active template with its test data
    public function postman(Request $request)
    {
        $collection = PostmanCollection::make(
            url(config('pdf-templates.api.prefix', 'api/pdf-templates')),
            (string) $request->query('key', ''),
            PdfTemplate::active()->orderBy('name')->get(),
        );

        return response()->json($collection, 200, [
            'Content-Disposition' => 'attachment; filename="pdf-templates.postman_collection.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function route(string $name): string
    {
        return config('pdf-templates.routes.name', 'pdf-templates.').$name;
    }
}
