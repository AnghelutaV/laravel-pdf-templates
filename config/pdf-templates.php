<?php

return [

    // Table used by the PdfTemplate model (the migration is skipped when it already exists)
    'table' => 'pdf_templates',

    // Admin UI (designer, HTML editor, test page)
    'routes' => [
        'enabled' => true,
        'prefix' => 'pdf-templates',
        'name' => 'pdf-templates.',
        'middleware' => ['web'],
    ],

    // Who may open the admin UI (checked after the route middleware). Define it in a service provider:
    //   Gate::define('viewPdfTemplates', fn ($user = null) => $user?->isAdmin());
    // While the gate is not defined, the UI is only available in the "local" environment. null = no check.
    'gate' => 'viewPdfTemplates',

    // Public API: POST {api.prefix}/{slug}, header X-API-KEY, body = JSON data -> application/pdf
    'api' => [
        'enabled' => true,
        'prefix' => 'api/pdf-templates',
        'middleware' => ['api', 'throttle:60,1'],
        'key' => env('PDF_TEMPLATES_API_KEY'),
    ],

    // Layout the admin pages extend. null = the package's own standalone layout.
    // A host layout must yield `section` and have @stack('styles') in <head> and @stack('scripts') before </body>.
    'layout' => null,
    'section' => 'content',

    'ui' => [
        // Optional back link shown in the package header (e.g. your admin dashboard)
        'back_url' => null,
        'back_label' => 'Back',
    ],

    // WYSIWYG mode runs pdfme in the browser; server-side generation (API) goes through the Node renderer
    // shipped in vendor/anghelutav/laravel-pdf-templates/renderer (php artisan pdf-templates:renderer)
    'pdfme' => [
        'version' => '6.2.8',
        'cdn' => 'https://esm.sh',
        'renderer_url' => env('PDFME_RENDERER_URL'),
        'renderer_token' => env('PDFME_RENDERER_TOKEN'),
        'timeout' => 60,
    ],

    // HTML mode (dompdf)
    'html' => [
        'default_font' => 'DejaVu Sans',
        // Extra fonts: family => [[file, weight, style], ...] (absolute .ttf paths)
        'fonts' => [],
        // {value|number:2} separators
        'decimal_separator' => ',',
        'thousands_separator' => ' ',
        // Directories dompdf may read files from (images, fonts). public_path() is always allowed.
        'chroot' => [],
    ],

];
