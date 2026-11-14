<?php

namespace AnghelutaV\PdfTemplates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * One API render (log row). url / async renders keep their file on `disk` / `path` until it expires.
 *
 * @property int $id
 * @property string $uuid
 * @property ?int $template_id
 * @property string $template_slug
 * @property ?int $api_key_id
 * @property string $status queued | processing | done | failed
 * @property string $output pdf | base64 | url
 * @property string $format pdf | png | jpg | zip (batch)
 * @property int $documents
 * @property ?int $bytes
 * @property ?int $duration_ms
 * @property ?string $error
 * @property ?string $disk
 * @property ?string $path
 * @property ?string $filename
 * @property ?string $webhook
 * @property ?string $webhook_status
 * @property ?string $ip
 * @property ?string $data the JSON sent (config api.log_data)
 * @property ?array $options query options of the request
 * @property ?string $user_agent
 * @property ?Carbon $finished_at
 * @property ?Carbon $created_at
 */
class PdfTemplateRender extends Model
{
    const QUEUED = 'queued';

    const PROCESSING = 'processing';

    const DONE = 'done';

    const FAILED = 'failed';

    protected $guarded = ['id'];

    // Not in toArray() / JSON by accident: it can hold customer data
    protected $hidden = ['data'];

    // The data sent, pretty-printed for the log details; null when it was not kept
    public function prettyData(): ?string
    {
        if ($this->data === null) {
            return null;
        }
        $decoded = json_decode($this->data);

        return json_last_error() === JSON_ERROR_NONE
            ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $this->data;
    }

    /**
     * The pretty data as HTML: one block per line (so the indentation survives any whitespace handling of the page)
     * with keys, strings, numbers and true/false/null coloured. Escaped; null when the data was not kept.
     */
    public function highlightedData(): ?string
    {
        $pretty = $this->prettyData();
        if ($pretty === null) {
            return null;
        }
        $json = json_decode($this->data) !== null || trim($this->data) === 'null';
        $token = '/("(?:[^"\\\\]|\\\\.)*+")(\s*+:)?|(-?\d++(?:\.\d++)?(?:[eE][+-]?\d++)?)|\b(true|false|null)\b/';

        $lines = array_map(function (string $line) use ($token, $json): string {
            $text = ltrim($line, ' ');
            $indent = (strlen($line) - strlen($text)) / 2;
            // Not JSON (kept as sent): plain escaped text. JSON: only {}[],: and spaces sit between the tokens
            $html = ! $json ? e($text) : (string) preg_replace_callback($token, function (array $m): string {
                if (($m[1] ?? '') !== '') {
                    $class = ($m[2] ?? '') !== '' ? 'pt-json-key' : 'pt-json-string';

                    return '<span class="'.$class.'">'.e($m[1]).'</span>'.($m[2] ?? '');
                }
                if (($m[3] ?? '') !== '') {
                    return '<span class="pt-json-number">'.$m[3].'</span>';
                }

                return '<span class="pt-json-literal">'.$m[4].'</span>';
            }, $text);

            return '<span class="pt-json-line" style="padding-left:'.$indent.'ch">'.($html === '' ? '&#8203;' : $html).'</span>';
        }, explode("\n", $pretty));

        return implode('', $lines);
    }

    protected $casts = [
        'finished_at' => 'datetime',
        'documents' => 'integer',
        'options' => 'array',
        'bytes' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function getTable()
    {
        return config('pdf-templates.api.renders_table', 'pdf_template_renders');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<PdfTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PdfTemplate::class, 'template_id');
    }

    /** @return BelongsTo<PdfTemplateApiKey, $this> */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(PdfTemplateApiKey::class, 'api_key_id');
    }

    public function expiresAt(): ?Carbon
    {
        return $this->path && $this->finished_at
            ? $this->finished_at->copy()->addMinutes((int) config('pdf-templates.api.files_ttl', 1440))
            : null;
    }

    public function hasFile(): bool
    {
        $expires = $this->expiresAt();

        return $this->status === self::DONE && $expires && $expires->isFuture()
            && Storage::disk($this->disk)->exists((string) $this->path);
    }

    // Signed download link, valid until the file expires; no API key needed
    public function fileUrl(): ?string
    {
        if (! $this->path || ! $this->expiresAt()) {
            return null;
        }

        return URL::temporarySignedRoute(config('pdf-templates.routes.name', 'pdf-templates.').'api.file', $this->expiresAt(), ['render' => $this->uuid]);
    }

    // Options for PdfTemplates::render() from the request's query (watermark, watermark_color, watermark_opacity)
    public function renderOptions(): array
    {
        $query = $this->options ?? [];
        if (empty($query['watermark'])) {
            return [];
        }

        return ['watermark' => array_filter([
            'text' => (string) $query['watermark'],
            'color' => $query['watermark_color'] ?? null,
            'opacity' => $query['watermark_opacity'] ?? null,
        ], fn ($value) => $value !== null && $value !== '')];
    }

    public function mime(): string
    {
        return match ($this->format) {
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'zip' => 'application/zip',
            default => 'application/pdf',
        };
    }

    // What the API answers about a render (status endpoint, async 202, webhook body, output=url)
    public function toApi(): array
    {
        return array_filter([
            'id' => $this->uuid,
            'status' => $this->status,
            'template' => $this->template_slug,
            'format' => $this->format,
            'documents' => $this->documents,
            'url' => $this->status === self::DONE ? $this->fileUrl() : null,
            'expires_at' => $this->status === self::DONE ? $this->expiresAt()?->toIso8601String() : null,
            'filename' => $this->filename,
            'bytes' => $this->bytes,
            'duration_ms' => $this->duration_ms,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }
}
