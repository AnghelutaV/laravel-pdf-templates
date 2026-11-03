# Changelog

All notable changes to `anghelutav/laravel-pdf-templates`. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/) (0.x: a minor version may contain breaking changes).

## [Unreleased]

## [0.3.0] - 2026-11-02

### Added

- AI assistant ([laravel/ai](https://github.com/laravel/ai)): a Copilot-like chat panel in both editors (Ctrl+I) that writes and edits the HTML, CSS, test data or the pdfme fields. Answers go into the editor as unsaved changes and can be undone; an image or PDF can be attached as a reference.
- "Generate with AI" in the new template dialog: a description (and an optional image / PDF) becomes the first version of the template.
- `ai` config block (`enabled`, `provider`, `model`, `timeout`, `throttle`, `history`, `max_upload_kb`); env `PDF_TEMPLATES_AI`, `PDF_TEMPLATES_AI_PROVIDER`, `PDF_TEMPLATES_AI_MODEL`. The assistant is shown only when the provider has a key.
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

[Unreleased]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.1.4...v0.2.0
[0.1.4]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.1.2...v0.1.4
[0.1.2]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/AnghelutaV/laravel-pdf-templates/releases/tag/v0.1.0
