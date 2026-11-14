<?php

namespace AnghelutaV\PdfTemplates;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\Models\PdfTemplateApiKey;
use AnghelutaV\PdfTemplates\Models\PdfTemplateRender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * API renders with a log row each (PdfTemplateRender): render now or in a queued job, keep the file for output=url /
 * async (config api.disk / api.path, expiring after api.files_ttl minutes) and call the client's webhook.
 */
class ApiRenders
{
    const OUTPUTS = ['pdf', 'base64', 'url'];

    const FORMATS = ['pdf', 'png', 'jpg'];

    public function __construct(protected PdfTemplates $pdf) {}

    /**
     * The log row of a new render. $data (the JSON sent) is kept for the log details and "render again" when
     * api.log_data is on and it fits in api.log_data_kb; $request = query options, user agent.
     */
    public function start(?PdfTemplate $template, ?PdfTemplateApiKey $key, array $options, int $documents, ?string $ip = null, ?string $data = null, array $request = []): PdfTemplateRender
    {
        $format = $options['format'] ?? 'pdf';
        $keep = config('pdf-templates.api.log_data', true) && $data !== null
            && strlen($data) <= (int) config('pdf-templates.api.log_data_kb', 512) * 1024;

        return PdfTemplateRender::create([
            'data' => $keep ? $data : null,
            'options' => array_filter($request['options'] ?? [], fn ($value) => $value !== null && $value !== '') ?: null,
            'user_agent' => isset($request['user_agent']) ? Str::limit((string) $request['user_agent'], 250, '') : null,
            'uuid' => (string) Str::uuid(),
            'template_id' => $template?->id,
            'template_slug' => $template->slug ?? 'batch',
            'api_key_id' => $key?->id,
            'status' => PdfTemplateRender::QUEUED,
            'output' => $options['output'] ?? 'pdf',
            'format' => $format,
            'documents' => $documents,
            'filename' => $this->filename($options['filename'] ?? null, $template->slug ?? 'batch', $format),
            'webhook' => $options['webhook'] ?? null,
            'ip' => $ip,
        ]);
    }

    /**
     * Renders, keeps the file when the render needs one (url / async) and fills in the log row.
     * Returns the file contents; a failed render is logged and rethrown.
     */
    public function run(PdfTemplateRender $render, string|array|null $data, bool $store = false, int $page = 1): string
    {
        $started = hrtime(true);
        $render->update(['status' => PdfTemplateRender::PROCESSING]);

        try {
            $options = $render->renderOptions();
            if ($render->format === 'zip') {
                $content = $this->pdf->zip($this->batchItems($data), $options);
            } else {
                $template = PdfTemplate::findOrFail($render->template_id);
                $content = $render->format === 'pdf'
                    ? $this->pdf->render($template, $data, $options)
                    : $this->pdf->image($template, $data, $render->format, $page, $options);
            }

            $attributes = [
                'status' => PdfTemplateRender::DONE,
                'bytes' => strlen($content),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
                'finished_at' => now(),
            ];
            if ($store) {
                $disk = config('pdf-templates.api.disk') ?: config('filesystems.default');
                $path = trim((string) config('pdf-templates.api.path', 'pdf-templates/renders'), '/')."/{$render->uuid}.{$render->format}";
                Storage::disk($disk)->put($path, $content);
                $attributes += ['disk' => $disk, 'path' => $path];
            }
            $render->update($attributes);

            return $content;
        } catch (Throwable $e) {
            $render->update([
                'status' => PdfTemplateRender::FAILED,
                'error' => Str::limit($e->getMessage(), 2000),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * The items of a batch body, for PdfTemplates::zip():
     *   {"template": "invoice", "items": [{…data}, …], "filename": "invoice-{{ number }}"}   one template
     *   {"items": [{"template": "invoice", "data": {…}, "filename": "…"}, …]}               one per item
     *
     * @return list<array{template: string, data: mixed, filename: ?string}>
     *
     * @throws \InvalidArgumentException
     */
    public function batchItems(string|array|null $body): array
    {
        $body = is_array($body) ? $body : json_decode((string) $body, true);
        if (! is_array($body) || ! isset($body['items']) || ! is_array($body['items']) || ! array_is_list($body['items'])) {
            throw new \InvalidArgumentException('The body must be {"template": "slug", "items": [{…}, …]} or {"items": [{"template": "slug", "data": {…}}, …]}.');
        }
        if (! $body['items']) {
            throw new \InvalidArgumentException('items is empty.');
        }
        $max = (int) config('pdf-templates.api.batch_max', 100);
        if (count($body['items']) > $max) {
            throw new \InvalidArgumentException("At most {$max} items per batch.");
        }
        $template = isset($body['template']) ? (string) $body['template'] : null;

        return array_map(function ($item, int $i) use ($template, $body) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException('Item '.($i + 1).' must be an object.');
            }
            if ($template !== null) {
                return ['template' => $template, 'data' => $item, 'filename' => $body['filename'] ?? null];
            }
            if (empty($item['template'])) {
                throw new \InvalidArgumentException('Item '.($i + 1).': template is missing (or give one "template" for all items).');
            }

            return ['template' => (string) $item['template'], 'data' => $item['data'] ?? [], 'filename' => $item['filename'] ?? ($body['filename'] ?? null)];
        }, $body['items'], array_keys($body['items']));
    }

    // POSTs the render (status, url…) to its webhook; the answer's status is logged. Never throws.
    public function notify(PdfTemplateRender $render): void
    {
        if (! $render->webhook) {
            return;
        }

        try {
            if (! config('pdf-templates.api.webhook_private', false)) {
                PublicUrl::assert($render->webhook, 'webhook');
            }
            $body = json_encode($render->fresh()?->toApi() ?? $render->toApi(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $request = Http::timeout(15)->withOptions(['allow_redirects' => false])->withHeaders(['User-Agent' => 'laravel-pdf-templates']);
            // Lets the client check that the call comes from here: HMAC-SHA256 of the body with the shared secret
            if ($secret = (string) config('pdf-templates.api.webhook_secret')) {
                $request = $request->withHeaders(['X-Signature' => hash_hmac('sha256', (string) $body, $secret)]);
            }
            $response = $request->retry(3, 1000, throw: false)->withBody((string) $body, 'application/json')->post($render->webhook);
            $render->update(['webhook_status' => (string) $response->status()]);
        } catch (Throwable $e) {
            $render->update(['webhook_status' => Str::limit('error: '.$e->getMessage(), 32, '')]);
        }
    }

    public function filename(?string $requested, string $slug, string $format): string
    {
        $base = Str::slug(Str::beforeLast((string) ($requested ?: $slug), '.'.$format)) ?: 'document';

        return "{$base}.{$format}";
    }

    // Removes the files of expired renders and the log rows older than api.log_days; returns [files, rows]
    public function prune(): array
    {
        $files = 0;
        $ttl = (int) config('pdf-templates.api.files_ttl', 1440);
        PdfTemplateRender::whereNotNull('path')->where('finished_at', '<', now()->subMinutes($ttl))
            ->each(function (PdfTemplateRender $render) use (&$files) {
                Storage::disk($render->disk)->delete((string) $render->path);
                $render->update(['path' => null]);
                $files++;
            });

        $rows = 0;
        if (($days = (int) config('pdf-templates.api.log_days', 30)) > 0) {
            PdfTemplateRender::where('created_at', '<', now()->subDays($days))
                ->each(function (PdfTemplateRender $render) use (&$rows) {
                    if ($render->path) {
                        Storage::disk($render->disk)->delete($render->path);
                    }
                    $render->delete();
                    $rows++;
                });
        }

        return [$files, $rows];
    }
}
