<?php

namespace AnghelutaV\PdfTemplates\Http\Controllers;

use AnghelutaV\PdfTemplates\ApiRenders;
use AnghelutaV\PdfTemplates\Exceptions\RenderException;
use AnghelutaV\PdfTemplates\Jobs\RenderPdf;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\Models\PdfTemplateApiKey;
use AnghelutaV\PdfTemplates\Models\PdfTemplateRender;
use AnghelutaV\PdfTemplates\PdfTemplates;
use AnghelutaV\PdfTemplates\PublicUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HTTP API, key in X-API-KEY (or Authorization: Bearer). Stateless: no session, no CSRF.
 *
 *   POST {prefix}/{slug}                body = JSON data (an array = several documents in one file)
 *        ?output=pdf|base64|url         the file (default), JSON with the file as base64, JSON with a download link
 *        ?format=pdf|png|jpg            images: one per page, the first page unless &page=n
 *        ?async=1&webhook=https://…     202 + id at once; the render runs in the queue and is POSTed to the webhook
 *        ?filename=name  ?download=1
 *        ?watermark=DRAFT&watermark_color=%23dc2626&watermark_opacity=0.2   a diagonal text over every page
 *   POST {prefix}/batch                 several PDFs in one ZIP, see batch()
 *   GET  {prefix}/templates             active templates with the data keys they use
 *   GET  {prefix}/templates/{slug}      one template, with its test data as an example body
 *   GET  {prefix}/renders/{id}          status of a render (async), with the download link when done
 *   GET  {prefix}/files/{id}            signed link from `url`, no key needed, valid until the file expires
 */
class ApiController extends Controller
{
    // Query options kept on the log row (and read back by ApiRenders::run / render again)
    const QUERY = ['output', 'format', 'page', 'filename', 'download', 'async', 'webhook', 'watermark', 'watermark_color', 'watermark_opacity'];

    public function __construct(protected PdfTemplates $pdf, protected ApiRenders $renders) {}

    public function render(Request $request, string $slug)
    {
        [$key, $error] = $this->authenticate($request);
        if ($error) {
            return $error;
        }

        $template = PdfTemplate::active()->where('slug', $slug)->first();
        if (! $template) {
            return $this->error("Template '{$slug}' not found or inactive.", 404);
        }
        [$options, $error] = $this->options($request, strtolower((string) $request->query('format', 'pdf')));
        if ($error) {
            return $error;
        }

        $data = $request->getContent();
        try {
            $decoded = $this->pdf->decode($data);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        $documents = array_is_list($decoded) && $decoded !== [] ? count($decoded) : 1;

        return $this->limit($key, $documents) ?? $this->process($request, $template, $key, $options, $documents, $data);
    }

    /**
     * POST {prefix}/batch: several PDFs in one ZIP, same query options as a render (output, async, webhook, watermark…).
     *   {"template": "invoice", "items": [{…data}, …], "filename": "invoice-{{ number }}"}
     *   {"items": [{"template": "invoice", "data": {…}, "filename": "…"}, {"template": "receipt", "data": {…}}]}
     */
    public function batch(Request $request)
    {
        [$key, $error] = $this->authenticate($request);
        if ($error) {
            return $error;
        }
        [$options, $error] = $this->options($request, 'zip');
        if ($error) {
            return $error;
        }

        $data = $request->getContent();
        try {
            $items = $this->renders->batchItems($data);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        $slugs = array_unique(array_column($items, 'template'));
        $found = PdfTemplate::active()->whereIn('slug', $slugs)->pluck('slug')->all();
        if ($missing = array_values(array_diff($slugs, $found))) {
            return $this->error('Template(s) not found or inactive: '.implode(', ', $missing).'.', 404);
        }
        // One template for all: the log row links to it
        $template = count($found) === 1 ? PdfTemplate::active()->where('slug', $found[0])->first() : null;

        return $this->limit($key, count($items)) ?? $this->process($request, $template, $key, $options, count($items), $data);
    }

    /**
     * The query options shared by render and batch: [options, error response or null].
     *
     * @return array{0: array, 1: ?JsonResponse}
     */
    protected function options(Request $request, string $format): array
    {
        $options = [
            'output' => strtolower((string) $request->query('output', 'pdf')),
            'format' => $format === 'jpeg' ? 'jpg' : $format,
            'filename' => $request->query('filename'),
            'webhook' => $request->query('webhook') ?: null,
        ];

        if (! in_array($options['output'], ApiRenders::OUTPUTS, true)) {
            return [$options, $this->error('output must be one of: '.implode(', ', ApiRenders::OUTPUTS).'.', 422)];
        }
        if ($format !== 'zip' && ! in_array($options['format'], ApiRenders::FORMATS, true)) {
            return [$options, $this->error('format must be one of: '.implode(', ', ApiRenders::FORMATS).'.', 422)];
        }
        if ($options['webhook'] && ! config('pdf-templates.api.webhook_private', false)) {
            try {
                PublicUrl::assert((string) $options['webhook'], 'webhook');
            } catch (InvalidArgumentException $e) {
                return [$options, $this->error($e->getMessage(), 422)];
            }
        }

        return [$options, null];
    }

    /**
     * The key's limits (set in the admin UI → API): requests per minute and documents per calendar month. The config
     * key has none. Null when the request may go on, otherwise the 429 response.
     */
    protected function limit(?PdfTemplateApiKey $key, int $documents): ?JsonResponse
    {
        if (! $key) {
            return null;
        }
        if ($key->per_minute) {
            $bucket = "pdf-templates-api:{$key->id}";
            if (RateLimiter::tooManyAttempts($bucket, $key->per_minute)) {
                $retry = RateLimiter::availableIn($bucket);

                return $this->error("Rate limit: {$key->per_minute} requests per minute for this key. Retry in {$retry} s.", 429)
                    ->withHeaders(['Retry-After' => $retry, 'X-RateLimit-Limit' => $key->per_minute, 'X-RateLimit-Remaining' => 0]);
            }
            RateLimiter::hit($bucket, 60);
        }
        if ($key->per_month) {
            $used = $key->documentsThisMonth();
            if ($used + $documents > $key->per_month) {
                return $this->error("Monthly limit: {$key->per_month} documents for this key ({$used} used this month).", 429, [
                    'limit' => $key->per_month,
                    'used' => $used,
                    'resets_at' => now()->startOfMonth()->addMonth()->toIso8601String(),
                ]);
            }
        }

        return null;
    }

    // Logs, then renders now (or queues it with ?async=1) and answers in the requested output
    protected function process(Request $request, ?PdfTemplate $template, ?PdfTemplateApiKey $key, array $options, int $documents, string $data)
    {
        // png / jpg: one page of the result, the first unless ?page=n
        $page = max(1, (int) $request->query('page', 1));

        $render = $this->renders->start($template, $key, $options, $documents, $request->ip(), $data, [
            'options' => $request->only(self::QUERY),
            'user_agent' => $request->userAgent(),
        ]);

        if ($request->boolean('async')) {
            RenderPdf::dispatch($render->id, $data, $page);

            return response()->json($render->fresh()->toApi() + [
                'status_url' => route($this->route('api.status'), $render->uuid),
            ], 202);
        }

        try {
            $content = $this->renders->run($render, $data, store: $options['output'] === 'url', page: $page);
        } catch (Throwable $e) {
            // Bad template syntax / data is the caller's problem; anything else is worth a log entry
            if (! $e instanceof InvalidArgumentException && ! $e instanceof RenderException) {
                report($e);
            }

            return $this->error($e->getMessage(), 422, ['id' => $render->uuid]);
        }
        $this->renders->notify($render);

        return match ($options['output']) {
            'url' => response()->json($render->fresh()->toApi()),
            'base64' => response()->json([
                'id' => $render->uuid,
                'filename' => $render->filename,
                'mime' => $render->mime(),
                'bytes' => strlen($content),
                'data' => base64_encode($content),
            ]),
            default => response($content, 200, [
                'Content-Type' => $render->mime(),
                'Content-Disposition' => ($request->boolean('download') || $render->format === 'zip' ? 'attachment' : 'inline').'; filename="'.$render->filename.'"',
                'X-Render-Id' => $render->uuid,
            ]),
        };
    }

    public function templates(Request $request)
    {
        [, $error] = $this->authenticate($request);
        if ($error) {
            return $error;
        }

        return response()->json(['data' => PdfTemplate::active()->orderBy('name')->get()->map(fn (PdfTemplate $template) => $this->describe($template))]);
    }

    public function template(Request $request, string $slug)
    {
        [, $error] = $this->authenticate($request);
        if ($error) {
            return $error;
        }
        $template = PdfTemplate::active()->where('slug', $slug)->first();
        if (! $template) {
            return $this->error("Template '{$slug}' not found or inactive.", 404);
        }

        return response()->json(['data' => $this->describe($template) + [
            'example' => json_decode((string) $template->sample_data, true) ?? new \stdClass,
        ]]);
    }

    public function status(Request $request, string $render)
    {
        [$key, $error] = $this->authenticate($request);
        if ($error) {
            return $error;
        }
        // A client key sees its own renders; the key from the config sees all
        $model = PdfTemplateRender::where('uuid', $render)
            ->when($key, fn ($query) => $query->where('api_key_id', $key->id))
            ->first();

        return $model ? response()->json($model->toApi()) : $this->error('Render not found.', 404);
    }

    // Signed URL (output=url, async): the file, until it expires
    public function file(Request $request, string $render)
    {
        $model = PdfTemplateRender::where('uuid', $render)->first();
        if (! $model || ! $model->hasFile()) {
            return $this->error('The file does not exist or has expired.', 404);
        }

        return Storage::disk($model->disk)->response((string) $model->path, $model->filename, [
            'Content-Type' => $model->mime(),
        ], $request->boolean('download') ? 'attachment' : 'inline');
    }

    /**
     * The key from X-API-KEY / Bearer: [client key model or null for the config key, error response or null].
     *
     * @return array{0: ?PdfTemplateApiKey, 1: ?JsonResponse}
     */
    protected function authenticate(Request $request): array
    {
        $given = (string) ($request->header('X-API-KEY') ?: $request->bearerToken());
        $master = (string) config('pdf-templates.api.key');

        if ($master !== '' && $given !== '' && hash_equals($master, $given)) {
            return [null, null];
        }
        if ($key = PdfTemplateApiKey::findByKey($given)) {
            // Not on every request: once a minute is enough for "last used"
            if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
                $key->forceFill(['last_used_at' => now()])->saveQuietly();
            }

            return [$key, null];
        }
        if ($master === '' && ! PdfTemplateApiKey::active()->exists()) {
            return [null, $this->error('PDF API is disabled (set PDF_TEMPLATES_API_KEY or create a key in the admin UI → API).', 403)];
        }

        return [null, $this->error('Invalid API key.', 401)];
    }

    protected function describe(PdfTemplate $template): array
    {
        return [
            'slug' => $template->slug,
            'name' => $template->name,
            'mode' => $template->mode,
            'category' => $template->category,
            'description' => $template->description,
            'fields' => (object) $this->pdf->fields($template),
            'url' => route($this->route('api'), $template->slug),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    protected function route(string $name): string
    {
        return config('pdf-templates.routes.name', 'pdf-templates.').$name;
    }

    protected function error(string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['error' => $message] + $extra, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // Kept for code that called the controller as invokable (route name pdf-templates.api)
    public function __invoke(Request $request, string $slug): Response
    {
        return $this->render($request, $slug);
    }
}
