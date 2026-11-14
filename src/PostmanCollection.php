<?php

namespace AnghelutaV\PdfTemplates;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use Illuminate\Support\Collection;

/**
 * Postman collection (v2.1) of the HTTP API: every endpoint and option, and one request per template with its test
 * data as the body. Variables: api_url, api_key, template, render_id, webhook_url.
 * The admin UI (API page) serves it filled in for this app; docs/postman/ has a generic copy.
 */
class PostmanCollection
{
    const SCHEMA = 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json';

    /** @param Collection<int, PdfTemplate>|null $templates */
    public static function make(string $apiUrl, string $apiKey = '', ?Collection $templates = null): array
    {
        $templates ??= collect();
        $first = $templates->first();
        $example = $first ? self::example($first) : ['client' => ['name' => 'Jane Doe'], 'items' => [['name' => 'Tile', 'qty' => 2, 'price' => 10]], 'total' => 20];

        $render = fn (string $name, array $query, string $description, ?array $body = null, bool $saveId = false) => self::request(
            $name, 'POST', ['{{template}}'], $query, $description, $body ?? $example, $saveId,
        );

        return [
            'info' => [
                'name' => 'Laravel PDF Templates API',
                'schema' => self::SCHEMA,
                'description' => "Set the collection variables: api_url (…/api/pdf-templates), api_key (a key from the admin UI → API, or PDF_TEMPLATES_API_KEY) and template (a template slug).\n\nPDF / image responses: use \"Send and Download\" to save the file, or open the Preview tab.",
            ],
            'auth' => [
                'type' => 'apikey',
                'apikey' => [
                    ['key' => 'key', 'value' => 'X-API-KEY', 'type' => 'string'],
                    ['key' => 'value', 'value' => '{{api_key}}', 'type' => 'string'],
                    ['key' => 'in', 'value' => 'header', 'type' => 'string'],
                ],
            ],
            'variable' => [
                ['key' => 'api_url', 'value' => rtrim($apiUrl, '/')],
                ['key' => 'api_key', 'value' => $apiKey],
                ['key' => 'template', 'value' => $first instanceof PdfTemplate ? $first->slug : 'invoice'],
                ['key' => 'render_id', 'value' => ''],
                ['key' => 'webhook_url', 'value' => 'https://webhook.site/your-id'],
            ],
            'item' => [
                [
                    'name' => 'Templates',
                    'item' => [
                        self::request('List templates', 'GET', ['templates'], [], 'Active templates with the data keys (fields) each one uses.'),
                        self::request('Template details + example data', 'GET', ['templates', '{{template}}'], [], 'One template; `example` is its test data, a ready request body.'),
                    ],
                ],
                [
                    'name' => 'Render',
                    'item' => [
                        $render('PDF (inline)', [], 'The PDF itself. Body = the data. Use "Send and Download" to save it.'),
                        $render('PDF (download, file name)', ['download' => '1', 'filename' => 'invoice-42'], 'Content-Disposition: attachment; filename="invoice-42.pdf".'),
                        $render('PDF as base64 (JSON)', ['output' => 'base64'], '{ id, filename, mime, bytes, data } with the file base64-encoded.', null, true),
                        $render('PDF as a download link (JSON)', ['output' => 'url'], '{ id, status, url, expires_at… }. The url is signed, needs no key and expires after api.files_ttl minutes.', null, true),
                        $render('Several documents in one PDF', [], 'A JSON array: one document per item, all in one PDF.', [$example, $example]),
                        $render('PNG image (page 1)', ['format' => 'png', 'page' => '1'], 'One page as PNG. Needs the Node renderer (php artisan pdf-templates:renderer).'),
                        $render('JPG image', ['format' => 'jpg'], 'One page as JPG (white background). Needs the Node renderer and PHP GD.'),
                        $render('PDF with a watermark', ['watermark' => 'DRAFT', 'watermark_color' => '#dc2626', 'watermark_opacity' => '0.2'], 'A diagonal see-through text on every page.'),
                    ],
                ],
                [
                    'name' => 'Batch (ZIP)',
                    'item' => [
                        self::request('Several PDFs in one ZIP', 'POST', ['batch'], [], "One template for all items; each item is the data of one PDF. The file names may use the item's data.\nOr one template per item: {\"items\": [{\"template\": \"invoice\", \"data\": {…}, \"filename\": \"a\"}, …]}. At most api.batch_max items.", [
                            'template' => '{{template}}',
                            'items' => [$example, $example],
                        ]),
                    ],
                ],
                [
                    'name' => 'Async + webhook',
                    'item' => [
                        $render('Render in the background', ['async' => '1', 'webhook' => '{{webhook_url}}'], "202 at once with { id, status: queued, status_url }. A queue worker renders it (php artisan queue:work), keeps the file and POSTs the result (id, status, url…) to the webhook.\nWith api.webhook_secret set, X-Signature is the HMAC-SHA256 of the body.", null, true),
                        self::request('Render status', 'GET', ['renders', '{{render_id}}'], [], 'queued → processing → done (with url) or failed (with error). render_id is set by the requests above.'),
                    ],
                ],
                ...($templates->isEmpty() ? [] : [[
                    'name' => 'Your templates',
                    'item' => $templates->map(fn (PdfTemplate $template) => self::request(
                        $template->name, 'POST', [$template->slug], [], trim(($template->description ?? '')."\n\nBody: the template's test data."), self::example($template),
                    ))->values()->all(),
                ]]),
            ],
        ];
    }

    protected static function request(string $name, string $method, array $path, array $query, string $description, mixed $body = null, bool $saveId = false): array
    {
        $raw = '{{api_url}}/'.implode('/', $path).($query ? '?'.urldecode(http_build_query($query)) : '');
        $request = [
            'method' => $method,
            'header' => [['key' => 'Accept', 'value' => 'application/json, application/pdf, image/*']],
            'url' => [
                'raw' => $raw,
                'host' => ['{{api_url}}'],
                'path' => $path,
                'query' => array_map(fn ($key, $value) => ['key' => (string) $key, 'value' => (string) $value], array_keys($query), $query),
            ],
            'description' => $description,
        ];
        if ($method === 'POST') {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $request['body'] = [
                'mode' => 'raw',
                'raw' => json_encode($body ?? new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'options' => ['raw' => ['language' => 'json']],
            ];
        }

        $item = ['name' => $name, 'request' => $request];
        if ($saveId) {
            $item['event'] = [[
                'listen' => 'test',
                'script' => ['type' => 'text/javascript', 'exec' => [
                    'try { const r = pm.response.json(); if (r.id) pm.collectionVariables.set("render_id", r.id); } catch (e) {}',
                ]],
            ]];
        }

        return $item;
    }

    // The template's test data (an object or a list of documents), {} when there is none
    protected static function example(PdfTemplate $template): mixed
    {
        $data = json_decode((string) $template->sample_data, true);

        return is_array($data) ? $data : new \stdClass;
    }
}
