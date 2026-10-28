# Laravel PDF Templates

[![tests](https://github.com/AnghelutaV/laravel-pdf-templates/actions/workflows/tests.yml/badge.svg)](https://github.com/AnghelutaV/laravel-pdf-templates/actions/workflows/tests.yml)

PDF templates for Laravel, stored in the database, filled with JSON and rendered from your code or over HTTP. You can use it instead of a hosted service such as apitemplate.io.

There are two template modes:

- **WYSIWYG**: a visual designer ([pdfme](https://pdfme.com)) for fields on the page, tables, images, QR/barcodes and a background PDF.
- **HTML + CSS**: code with `{placeholders}`, loops, conditions and filters, rendered by dompdf.

It comes with a standalone admin UI (like log-viewer or Telescope) in light and dark themes. The UI has a template list, the designer, an HTML editor with live preview, and a JSON test page that shows the fields each template expects.

## Requirements

- PHP 8.2+ and Laravel 11, 12 or 13. Laravel 13 needs PHP 8.3+.
- Node.js 18+, only to render **WYSIWYG** templates on the server (in code or through the API). The browser designer and the HTML mode don't need Node.

## Installation

```bash
composer require anghelutav/laravel-pdf-templates
php artisan pdf-templates:install --examples
```

This publishes `config/pdf-templates.php`, creates the `pdf_templates` table and adds three example templates.

Open **`/pdf-templates`**. In the `local` environment it's open to everyone. Everywhere else, define who may use it, for example in `AppServiceProvider::boot()`:

```php
Gate::define('viewPdfTemplates', fn ($user = null) => $user?->is_admin);
```

## Usage in code

```php
use AnghelutaV\PdfTemplates\Facades\PdfTemplates;

// From a controller: shown inline in the browser
return PdfTemplates::make('invoice', $data);

// Download, store, base64, mail attachment
return PdfTemplates::make('invoice', $data)->filename("invoice-{$order->id}")->download();
PdfTemplates::make('invoice', $data)->save("invoices/{$order->id}.pdf", 's3');
$base64 = PdfTemplates::make('invoice', $data)->base64();

// Raw bytes
$bytes = PdfTemplates::render('invoice', $data);
```

`$data` is an array or a raw JSON string. A list of objects (`[{…}, {…}]`) renders several documents into one PDF. `make()` renders once, so you can call `save()` and then `download()` on the same object.

### Mail attachments

```php
public function attachments(): array
{
    return [PdfTemplates::make('invoice', $this->order->toPdfData())->filename('invoice')->attachment()];
}
```

### Let users pick a template

```blade
<x-pdf-templates::select name="pdf_template" :value="old('pdf_template', $order->pdf_template)" placeholder="—" class="form-select" />
```

```php
PdfTemplates::options();        // ['invoice' => 'Invoice', ...]  active templates
PdfTemplates::options('html');  // only one mode
```

### What data does a template expect?

```php
PdfTemplates::fields('invoice');
// HTML:    ['title' => 'value', 'items' => 'list', 'items.*.name' => 'value', 'paid' => 'condition']
// WYSIWYG: ['client_name' => 'text', 'items' => 'table', 'payment_link' => 'qrcode']
```

The same list is shown on each template's test page, and by `php artisan pdf-templates:render invoice --fields`.

## HTTP API

Set a key in `.env`. You can generate one with `php -r "echo bin2hex(random_bytes(32));"`.

```bash
PDF_TEMPLATES_API_KEY=your-long-random-key
```

```bash
curl -X POST https://your-app.test/api/pdf-templates/invoice \
  -H "X-API-KEY: $PDF_TEMPLATES_API_KEY" \
  -H "Content-Type: application/json" \
  --data @data.json -o invoice.pdf
```

- **Request body:** the JSON data.
- **Response:** `application/pdf`. Optional query parameters: `?download=1` and `?filename=name`.
- **Errors** come back as JSON:

| Status | Meaning |
|---|---|
| `401` | wrong or missing key |
| `403` | API disabled (no key set) |
| `404` | unknown or inactive template |
| `422` | invalid JSON or template syntax |

The API is stateless: no session and no CSRF.

## Template syntax (HTML mode)

| Syntax | Meaning |
|---|---|
| `{name}` `{client.address}` `{items.0.name}` | escaped value, dot paths |
| `{#each items} … {else} empty {/each}` | loop; inside: `{name}`, `{this}`, `{@number}`, `{@index}`, `{@first}`, `{@last}`, `{@root.x}` |
| `{#if paid} … {else} … {/if}`, `{#unless paid} … {/unless}` | conditions; these count as false: `null`, `""`, `0`, `"false"`, `[]` |
| `{total\|number:2}` `{date\|date:d.m.Y}` `{name\|upper}` `{name\|lower}` | filters, which can be chained |
| `{phone\|default:—}` `{notes\|nl2br}` `{html\|raw}` | fallback value, line breaks, unescaped HTML |

Templates can't run PHP. dompdf runs with remote resources disabled, so images must be `data:` URIs or files under `public/`; same-host URLs are mapped to local files. Fonts: `DejaVu Sans` by default, and more TTF fonts can be added in `html.fonts`.

## WYSIWYG data

Each designer field has a **name**, which is its key in the JSON. Values are strings, except:

- table: `[["a","b"],["c","d"]]`
- list: `["x","y"]`
- multi-variable text: `{"var":"value"}`
- image: `data:image/png;base64,…`

The **Page…** menu in the designer resizes the page and scales every field to the new size.

### Rendering WYSIWYG on the server

pdfme only runs in JavaScript, so server-side generation goes through the bundled Node renderer:

```bash
php artisan pdf-templates:renderer      # installs npm deps on first run, listens on 127.0.0.1:3311
```

```bash
PDFME_RENDERER_URL=http://127.0.0.1:3311
PDFME_RENDERER_TOKEN=optional-shared-secret
```

In production, run it under supervisor or systemd. You can also copy `vendor/anghelutav/laravel-pdf-templates/renderer` anywhere and run `npm install && npm start` (env: `HOST`, `PORT`, `PDFME_RENDERER_TOKEN`).

## Templates in git: export and import

Templates live in the database. To keep them in version control and ship them with deploys:

```bash
php artisan pdf-templates:export                  # database/pdf-templates/<slug>/…
php artisan pdf-templates:import                  # on deploy, keeps templates that already exist
php artisan pdf-templates:import --force          # overwrite
```

## Commands

| Command | Does |
|---|---|
| `pdf-templates:install [--examples]` | publish config, migrate, add examples |
| `pdf-templates:examples [--force]` | add the example templates |
| `pdf-templates:render {slug} {output?} [--data=file.json] [--fields]` | render from the console, or list the fields |
| `pdf-templates:export {path?} [--slug=*]` | templates → files |
| `pdf-templates:import {path?} [--force]` | files → templates |
| `pdf-templates:renderer [--host] [--port] [--install]` | run the Node renderer |

## Configuration

`config/pdf-templates.php`:

| Key | Default | |
|---|---|---|
| `routes.prefix` / `routes.middleware` | `pdf-templates` / `['web']` | admin UI URL and middleware |
| `gate` | `viewPdfTemplates` | `null` = no gate check |
| `layout` / `section` | `null` / `content` | render inside your own admin layout (it must have `@stack('styles')` and `@stack('scripts')`) |
| `back_url` | `null` | back link in the UI header |
| `api.prefix` / `api.middleware` / `api.key` | `api/pdf-templates` / `['api', 'throttle:60,1']` / env | HTTP API |
| `table` | `pdf_templates` | database table |
| `pdfme.*` | | pdfme version, CDN, renderer URL and token |
| `html.*` | | default font, extra fonts, number separators, extra dompdf `chroot` dirs |

To publish the views or translations (`en`, `ro`): `php artisan vendor:publish --tag=pdf-templates-views` or `--tag=pdf-templates-lang`.

## Testing

```bash
composer test
```

## License

MIT
