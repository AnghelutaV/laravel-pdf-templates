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
        // Live preview / test renders per user (or IP) per minute; each one is a full PDF generation
        'render_throttle' => '120,1',
    ],

    // Cache generated PDFs: same template content + same data → the stored bytes, no new generation.
    // ttl in seconds, 0 = off. Use a store that keeps binary data as is (file, redis), not "database".
    'cache' => [
        'ttl' => (int) env('PDF_TEMPLATES_CACHE_TTL', 0),
        'store' => env('PDF_TEMPLATES_CACHE_STORE'),
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
        // GitHub + documentation links (with the star count) in the list header. null = hidden
        'repository' => 'https://github.com/AnghelutaV/laravel-pdf-templates',
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

    // AI assistant (laravel/ai): chat panel in the editors and "Generate with AI" in the list.
    // The provider key lives in config/ai.php (e.g. ANTHROPIC_API_KEY). Template content and
    // attached files are sent to that provider.
    'ai' => [
        'enabled' => (bool) env('PDF_TEMPLATES_AI', true),
        'provider' => env('PDF_TEMPLATES_AI_PROVIDER', 'anthropic'),
        // null = the provider's default text model
        'model' => env('PDF_TEMPLATES_AI_MODEL', 'claude-sonnet-5-5'),
        // "structured" = JSON output + check tools (Claude, GPT, Gemini…)
        // "text"       = plain text sections, no tools: small / free models (Groq, Ollama…) that fail on structured output
        'format' => env('PDF_TEMPLATES_AI_FORMAT', 'structured'),
        'timeout' => 120,
        // Requests per user (or IP) per minute
        'throttle' => '20,1',
        // When the answer has errors (template syntax, invalid JSON / fields), ask the model again this many times
        'retries' => 1,
        // Previous chat messages sent with each request
        'history' => 20,
        // Attached image / PDF (a document to copy), in KB
        'max_upload_kb' => 10240,
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
