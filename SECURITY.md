# Security policy

## Supported versions

Security fixes go into the latest release. While the package is in 0.x, update to the newest 0.x version to get them.

## Reporting a vulnerability

Please do not open a public issue. Report it privately through GitHub: **Security → Report a vulnerability** on [the repository](https://github.com/AnghelutaV/laravel-pdf-templates/security/advisories/new).

Include what is affected (admin UI, API, renderer, template engine), how to reproduce it and the impact you see. You will get an answer within a few days; once a fix is released, the advisory is published with credit to you unless you prefer otherwise.

## Scope notes

- The admin UI is protected by the `viewPdfTemplates` gate; while the gate is not defined it is only open in the `local` environment.
- The HTTP API requires `X-API-KEY`; keep `PDF_TEMPLATES_API_KEY` secret and long.
- The Node renderer should listen on `127.0.0.1`, or require `PDFME_RENDERER_TOKEN` when reachable over a network.
- dompdf runs with remote resources and PHP disabled, restricted to `public/` and the `html.chroot` directories.
- `{value|raw}` prints unescaped HTML from the data: only for HTML you produce yourself.
