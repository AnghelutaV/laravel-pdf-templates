# Changelog

All notable changes to `anghelutav/laravel-pdf-templates`. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/) (0.x: a minor version may contain breaking changes).

## [Unreleased]

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

[Unreleased]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.1.4...HEAD
[0.1.4]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.1.2...v0.1.4
[0.1.2]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/AnghelutaV/laravel-pdf-templates/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/AnghelutaV/laravel-pdf-templates/releases/tag/v0.1.0
