# Changelog

All notable changes to `anghelutav/laravel-pdf-templates`. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/) (0.x: a minor version may contain breaking changes).

## [Unreleased]

### Added

- **Chrome engine** for HTML templates, optional (`PDF_TEMPLATES_CHROME=true`, `PDF_TEMPLATES_CHROME_PATH`): per template in the page setup (PDF engine: dompdf / Chrome). Printed by the server's Chrome / Edge / Chromium through the Node renderer (`POST /html`, `puppeteer-core`), so flexbox, grid, `position: absolute` and modern CSS work as in a browser (templates from APITemplate.io / CraftMyPDF). Margins, header / footer with page numbers, watermark, images and fonts supported; the page loads nothing from the network.
- Remote `https://` images in HTML templates and their data (`<img src="{{ logo_url }}">`) are fetched when rendering (public hosts only, cached; `html.remote_images`, `html.remote_images_ttl`).
- Visual editor looks like the PDF: dompdf's defaults instead of CKEditor's table styles, the template's `body` CSS and default font, the test data's images for `<img src="{{ key }}">`; a warning for flexbox / grid / `position: absolute` on dompdf.
- WYSIWYG Edit Field panel: a **Value** row for every field with content (text, list, date, checkbox, QR / barcodes), with the data keys of the test data as `{{ key }}` buttons; **Chart data** for charts (used when the data has no key for the field).

### Fixed

- HTML (dompdf): `height: 100%` inside a table cell no longer pushes the table to the next page; sizes without a unit are px; `<th>` follows the text alignment of its table / row; CKEditor block images are centred and resized ones keep their width.

- **Charts**: `{{ chart("bar", sales, "month", "total", "title='…'") }}` in HTML templates and a **Chart** tool in the WYSIWYG designer (type, label key, value keys, options). Bar, horizontal bar, stacked, line, area, pie, doughnut; title, colours, legend, values, prefix / suffix, decimals, min / max. Vector shapes, texts in the template's font.
- **QR codes and barcodes in HTML templates**: `{{ url|qrcode(140) }}`, `{{ sku|barcode("ean13", 50) }}` (code128, code39, code93, ean13, ean8, upca, upce, itf14, i25, codabar). New dependencies `chillerlan/php-qrcode` and `picqer/php-barcode-generator`.
- WYSIWYG: one **Barcode** tool for every pdfme barcode type (Code 128, EAN-13, EAN-8, Code 39, ITF-14, UPC-A, UPC-E, Codabar, GS1 DataMatrix, PDF417, Japan Post), picked in the Edit Field panel; older code128 / ean13 fields are converted when the editor opens and still render as they are. QR / barcode values can use placeholders (`https://pay.example.com/{{ number }}`) and are edited from the Edit Field panel (Value).
- **Watermark**: `->watermark('DRAFT')`, `render(…, ['watermark' => […]])`, API `?watermark=DRAFT&watermark_color=…&watermark_opacity=…`; text, colour, opacity, angle, size. Both modes.
- **Batch API** `POST /api/pdf-templates/batch`: a ZIP with one PDF per item, one template for all or one per item, file names from the data (`invoice-{{ number }}`); with output / async / webhook / watermark. `PdfTemplates::zip()` in code. Config `api.batch_max` (100).
- **Limits per API key**: requests per minute and documents per month, set on the API page, `429` when over; the keys table shows the usage of this month and of the last 30 days. New migration `add_limits_to_pdf_template_api_keys`.

- WYSIWYG header & footer (editor menu → Margins, header & footer): a left, center and right text for each, with a font size, repeated on every page, also on the pages a long table adds. Texts can use `{currentPage}`, `{totalPages}`, `{date}`, `{dateTime}` and data keys. "Custom header & footer…" switches the designer to a layer for logos, lines and boxes. Stored as pdfme's `basePdf.staticSchema`; blank pages only, not with a background PDF.
- WYSIWYG page margins (top, right, bottom, left, in mm) and an "Add page" button in the same menu.
- WYSIWYG editor: "Test data and the template as JSON" dialog (edit by hand, apply, preview), a delete button on the rows of the Field List, and a dashed outline around every field in the designer.
- HTML editor: **Visual** mode next to the code (CKEditor 5, loaded on first use): fonts, sizes, colours, alignment, lists, links, tables, images, page breaks, and a right-click menu (link, image, cell / row / column, delete table, a Properties dialog for the style and class of a cell, table, image or paragraph). Loops and conditions survive it, also between table rows; classes and inline styles are kept; scripts, event handlers and `javascript:` URLs are dropped. Config `html.editor` (`enabled`, `url`, `css`), env `PDF_TEMPLATES_VISUAL_EDITOR`. CKEditor 5 is GPL 2+: a closed-source product needs a commercial licence or `enabled => false`.
- HTML mode: `counter(pages)` in CSS is the total number of pages, so `content: "Page " counter(page) " of " counter(pages)` works. Templates that use it are rendered twice (the first pass counts the pages).
- New example template `example-report` (`pdf-templates:examples`): a three-page price list with a bordered header and footer, "Page 1 of 3" and a table that continues across pages.
- The AI assistant knows about headers / footers, page numbers and how pages break in WYSIWYG mode; its answers are checked for `staticSchema` fields too.
- Template list: created / updated dates, a sort menu (name A–Z, last updated, newest) and a category filter.
- Optional **category** per template (free text, set in the editor settings; the ones in use are suggested). Shown under the slug in the list, kept by export / import (`meta.json`). `PdfTemplate::categories()` lists the ones in use.
- HTML mode page setup (editor menu → Margins, header & footer): margins, a left / center / right header and footer with a font size, a custom HTML header / footer, word break. Page variables `{currentPage}` / `{pageNumber}`, `{totalPages}`, `{date}`, `{dateTime}`. Stored in the new `settings` column, kept by export / import.
- Jinja-style syntax in HTML templates (`{{ name }}`, `{% for item in items %}`, `{% if %}` / `{% elif %}` / `{% if not %}`, `loop.index`, `|safe`, `|default("x")`, `|round(2)`), so templates from APITemplate.io or Twig can be pasted in.
- Conditions with expressions, in both syntaxes: `{#if status == "paid" and total > 100}`, `==` `!=` `>` `>=` `<` `<=`, `and` / `or` / `not`, parentheses, `in`, `is defined` / `is none` / `is empty`, `|length`.
- HTML editor: **Visual** is a tab next to HTML and CSS.
- WYSIWYG editor: the "Edit Field" panel of an image shows its source (`pdf-asset:logo.png`, or "embedded", with type and size) and a "Replace…" button that puts another library image into the same field.
- WYSIWYG: `{{ placeholders }}` inside texts (`Invoice {{ number }} for {{ client.name|upper }}`, `{{ total|number:2 }}`, `{{ note|default:— }}`), in the browser preview, the Node renderer and the API; also in the header / footer. `PdfTemplates::fields()` and the generated test data list their keys. Restart the Node renderer after updating.
- Font library (editor menu → Fonts): add a Google Fonts family by name or upload `.ttf` files; config `html.font_dir`, `html.font_max_kb`.
- "Import external images" (editor menu → Images, HTML mode): downloads the template's `https://…` images into the image library and replaces the URLs. Public hosts only.
- Template history (editor menu → History, both modes): the state before each save that changes a template is kept and can be restored. Config `history.keep` (50), `history.table`.
- Visual editor: "Show only if…" on a paragraph, table row or table (a key, `not key` or any condition), image source and link in Image properties, line height, a Show blocks button.
- WYSIWYG formulas in `{{ }}`: arithmetic, `sum(items, "qty * price")`, `count`, `avg`, `min`, `max`, `round`, `concat`, `a ? b : c`, comparisons and `and` / `or` / `not`. Parsed, never eval'ed.
- WYSIWYG right-click menu on a field: duplicate, order (front / back), copy name, delete, replace image, **Show only if…** (field `showIf`: drawn only for documents where the condition holds) and, for tables, **Columns from data** (field `dataColumns`: rows as objects, a key / formula / filters per column, `rowNumber`). The Edit Field panel shows and edits both.
- **API**: keys per client (admin UI → API, or `pdf-templates:api-key`; hashed, revocable; `Authorization: Bearer` works too), `?output=base64|url` (signed, expiring download link), `?format=png|jpg&page=n`, `?async=1&webhook=…` (queued, webhook with optional HMAC signature), `GET /templates`, `GET /templates/{slug}` (with example body), `GET /renders/{id}`. Every API render is logged (no data) and shown with 24 h stats on the API page. `pdf-templates:prune` deletes expired files and old rows. Config `api.*` (disk, path, files_ttl, log_days, queue, webhook_secret, webhook_private, image_scale, jpg_quality).
- Postman collection: downloaded from the API page (with this app's URL, its templates and their test data) or `docs/postman/`.
- Images: `PdfTemplates::image($template, $data, 'png'|'jpg', $page)` and `PendingPdf::image()`, made by the Node renderer (new `POST /image`, `@pdfme/converter`) for both modes.
- Starter gallery in the "New template" dialog: receipt, shipping label, quote / offer, service contract and the examples; your own folders via `gallery.paths`.
- HTML mode expressions: `{{ qty * price|number:2 }}` / `{= … }`, `+ - * / %`, `?:`, `sum` / `avg` / `min` / `max` / `count` / `round` / `concat` / `if`…, also in conditions; filters `length`, `trim`, `abs`, `round`.
- WYSIWYG header / footer: `{{ … }}` with filters and formulas (computed per document, next to `{currentPage}` / `{totalPages}`).
- Admin API page with tabs: **Server & keys** (start / restart / stop the Node renderer from the browser, status, output; config `pdfme.manage`, `pdfme.node`), **Documentation** (generated from your templates: body, keys, cURL / PHP / JavaScript / Python) and **Log** (a row opens its details: the JSON sent, options, IP, client, full error, **Render again**). Config `api.log_data` (env `PDF_TEMPLATES_LOG_DATA`), `api.log_data_kb`.

### Changed

- Editor menu: wider (520 px), its sections are blocks that fold on a click on their title (remembered in the browser), in the order Settings, Preview & test, Page, Margins / header & footer, Fonts, Images, Template file, History.
- The page size menu also scales the header / footer fields.
- The designer's zoom bar is see-through until hovered, so it no longer hides the bottom of the page (the footer).

### Upgrade notes

- Run `php artisan migrate`: five new migrations add the `category` and `settings` columns, the `pdf_template_versions`, `pdf_template_api_keys` and `pdf_template_renders` tables and the per-key limits. The list, the editors and the API fail without them.
- The API controller is no longer invokable-only: routes call `ApiController@render` (the `pdf-templates.api` route name is unchanged). The API now answers `403` only when there is neither a master key nor a client key.
- Restart the Node renderer and run `npm install` in its folder once (new dependency `@pdfme/converter`, for images).
- Schedule `pdf-templates:prune` daily if you use `output=url` or async renders.
- The API log now keeps the JSON each render was sent (new migration adds `data`, `options`, `user_agent`). Set `PDF_TEMPLATES_LOG_DATA=false` if that data must not be stored.
- If you published the views, re-publish them or compare both editors, `index.blade.php`, `components/editor.blade.php`, `partials/settings.blade.php`, `partials/images.blade.php` and `partials/head.blade.php` (import map entries `@pdfme/pdf-lib`, `pdf-templates/placeholders`, `pdf-templates/designer-tools`, `pdf-templates/visual`, `pdf-templates/visual-menu`, `pdf-templates/html-tools`); `partials/history.blade.php`, `partials/page-setup.blade.php` and `api.blade.php` are new.
- A published config needs no change: the new keys (`html.editor`, `html.font_dir`, `html.date_format`, `history`, `api.*`, `gallery`) have defaults in code.

## [0.4.0] - 2026-11-03

### Added

- Image library (editor menu → Images): upload PNG / JPG / SVG once (drag & drop), click to insert into a template. Templates and data use `pdf-asset:name.png`, replaced by the image at render time (server, API, browser), so templates stay small. Stored on any Laravel disk: `images.disk` (env `PDF_TEMPLATES_IMAGES_DISK`, default `local`), `images.path`, `images.max_kb`.
- The WYSIWYG editor saves library images as references (the designer still shows them).
- The AI assistant gets the list of uploaded images and can place them ("put our logo top left").
- `PdfTemplates::auth(fn ($request) => ...)`: admin UI access with a callback, like `LogViewer::auth()`; it decides before the gate.

## [0.3.0] - 2026-11-03

### Added

- AI assistant ([laravel/ai](https://github.com/laravel/ai)): a Copilot-like chat panel in both editors (Ctrl+I) that writes and edits the HTML, CSS, test data or the pdfme fields. Answers go into the editor as unsaved changes and can be undone; an image or PDF can be attached as a reference.
- "Generate with AI" in the new template dialog: a description (and an optional image / PDF) becomes the first version of the template; the editor opens with that exchange in the chat.
- Two answer formats (`ai.format`): `structured` (default; JSON output + check tools, for Claude / GPT / Gemini) and `text` (plain `<pt:…>` sections, no tools, for small and free models such as Groq or OpenRouter `:free`).
- Every answer is checked on the server (template syntax, JSON, pdfme field types, names, page bounds); problems are sent back to the model once (`ai.retries`). Cut-off answers and "request too large" / rate-limit errors get a clear message.
- Embedded files (background PDF, data-URL images) are replaced by placeholders before the request and restored in the answer; the draft is sent as compact sections.
- `ai` config block (`enabled`, `provider`, `model`, `format`, `timeout`, `throttle`, `retries`, `history`, `max_upload_kb`); env `PDF_TEMPLATES_AI`, `PDF_TEMPLATES_AI_PROVIDER`, `PDF_TEMPLATES_AI_MODEL`, `PDF_TEMPLATES_AI_FORMAT`. The assistant is shown only when the provider has a key. AI requests raise PHP's time limit to fit `ai.timeout`.
- The WYSIWYG editor saves the test data with the template.

### Changed

- **Breaking:** requires PHP 8.3+ and Laravel 12 or 13 (`laravel/ai`). Stay on 0.2.x for Laravel 11.

### Upgrade notes

- If you published the views, re-publish them or compare: `components/editor.blade.php`, both editors, `index.blade.php` and `partials/head.blade.php` (import map entry `pdf-templates/ai`) changed, `components/ai-panel.blade.php` is new.

## [0.2.2] - 2026-11-02

### Added

- GitHub (with the repository's star count) and documentation icon links in the list header; `ui.repository` sets the URL, `null` hides them. The star count is read from the GitHub API in the browser and cached for 6 hours.
- Installed version (from Composer) in the list page footer, linked to its release and to the changelog; `PdfTemplates::version()`.

## [0.2.1] - 2026-11-02

Same code as 0.2.0 (tagged on the same commit).

## [0.2.0] - 2026-11-02

### Added

- New admin UI design: zinc/indigo light theme, matte green dark theme with grain, inline Lucide icons, tooltips (`data-pt-tip`), dismissible alerts, theme toggle with icons.
- Editors are "editor first": a slim top bar (back to the list, menu, name, unsaved-changes dot, Save, full screen) and an off-canvas menu with the page / file / test actions and the settings.
- Full screen for the whole editor, for the HTML preview and for the test page result.
- "Leave without saving?" dialog (save & leave / leave / stay) and a "Leave PDF templates?" confirmation for links that exit the package.
- Template IDs in the list and in the editors; template count in the list header; empty and "no results" states; mode cards in the "new template" dialog.
- HTML editor saves over JSON (Ctrl+S, no page reload); the form still works without JavaScript.
- `pdf-templates:benchmark` command: min / avg / p95 / max time, peak memory, size and PDF/s per template, `--docs` for multi-document PDFs, `--cache` for the cache hit time.
- Optional cache of generated PDFs (`cache.ttl`, `cache.store`, env `PDF_TEMPLATES_CACHE_TTL` / `PDF_TEMPLATES_CACHE_STORE`), keyed by template content + data.
- Rate limit on admin renders (`routes.render_throttle`, default `120,1`).
- Clear "renderer not running" message (`RendererUnavailableException`) instead of the raw cURL error, with "Don't show again" on the test page; the test page checks the renderer's `/health` and disables "Generate (server)" when it is down.
- README "Production" section (Supervisor / pm2, cache, queues, limits, `|raw`).
- Pint, Larastan (level 5), `composer check`, CI on PHP 8.5 and a code quality job.

### Changed

- The list query loads only the list columns (no schema / html / css / sample data).
- The HTML live preview skips a render when nothing changed since the last one.
- `ui.back_url` accepts a path (`admin`, `/admin`) and resolves it from the site root.
- The editors' back links go to the template list; `ui.back_url` (leave the package) is used by the list and by the editor menu's exit link.
- `composer.json`: no `version` field (the git tag is the version), all used `illuminate/*` components and `guzzlehttp/guzzle` are declared.

### Fixed

- The back link did not show: config was read with `::` instead of dot notation.
- Missing translation keys in the header (`back_label`, `back_title`).
- Unclosed wrapper `<div>` on the list page.

### Upgrade notes

- If you published the views (`--tag=pdf-templates-views`), re-publish them or compare: the editors now use the `<x-pdf-templates::editor>` component and new element ids.
- New config keys have defaults; add `routes.render_throttle` and the `cache` block to a published `config/pdf-templates.php` only to change them.

## [0.1.4] - 2026-10-28

### Changed

- Compact buttons in the editor toolbars.

## [0.1.2] - 2026-10-28

### Added

- Configurable label for the back link (`ui.back_label`).

## [0.1.1] - 2026-10-28

### Changed

- Compact editors: collapsible settings bar, the workspace fills the window.

## [0.1.0] - 2026-10-28

- Initial release: WYSIWYG (pdfme) and HTML/CSS templates filled with JSON, admin UI, test page, HTTP API, Node renderer, export / import commands.

[Unreleased]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.1.4...v0.2.0
[0.1.4]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.1.2...v0.1.4
[0.1.2]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/AnghelutaV/laravel-pdf-templates/releases/tag/v0.1.0
