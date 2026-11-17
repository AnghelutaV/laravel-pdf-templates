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
        // Master key (sees every render). Keys per client are created in the admin UI → API, or with
        // php artisan pdf-templates:api-key "Client name"
        'key' => env('PDF_TEMPLATES_API_KEY'),
        // output=url and async renders: where the file is kept, and for how long (minutes) its signed link works
        'disk' => env('PDF_TEMPLATES_API_DISK'),
        'path' => 'pdf-templates/renders',
        'files_ttl' => 1440,
        // POST {prefix}/batch: most items (PDFs) in one ZIP
        'batch_max' => 100,
        // Log rows (no data, only who / what / how long) are deleted after this many days; 0 = kept.
        // Schedule php artisan pdf-templates:prune daily to delete them and the expired files.
        'log_days' => 30,
        // Keep the JSON each API render was sent, for the log details and "Render again" (up to log_data_kb).
        // It can hold customer data: false keeps only who / what / how long.
        'log_data' => (bool) env('PDF_TEMPLATES_LOG_DATA', true),
        'log_data_kb' => 512,
        // async=1: the queue connection / queue of the render jobs (null = the app's default)
        'queue_connection' => env('PDF_TEMPLATES_QUEUE_CONNECTION'),
        'queue' => env('PDF_TEMPLATES_QUEUE'),
        // Webhooks get the render as JSON; with a secret, X-Signature = HMAC-SHA256 of the body.
        // Private / local addresses are refused unless webhook_private is true.
        'webhook_secret' => env('PDF_TEMPLATES_WEBHOOK_SECRET'),
        'webhook_private' => false,
        // format=png / jpg: resolution (2 = 144 dpi) and JPG quality
        'image_scale' => 2,
        'jpg_quality' => 90,
        'keys_table' => 'pdf_template_api_keys',
        'renders_table' => 'pdf_template_renders',
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
    // Chrome engine for HTML templates (optional): printed by a headless Chrome / Edge / Chromium through the Node
    // renderer, so flexbox, grid, position: absolute and web fonts work as in a browser. Off: every template uses
    // dompdf. On: each template picks its engine (editor menu → page setup → Engine). path = the browser binary;
    // empty = Chrome / Edge / Chromium found in the usual places.
    // PendingPdf::queue(): PDFs made by queue workers. A queue of their own, so the number of workers on it bounds
    // how many PDFs (and Chrome pages) run at once: php artisan queue:work --queue=pdf --max-jobs=500 --max-time=3600
    'queue' => [
        'connection' => env('PDF_TEMPLATES_QUEUE_CONNECTION'),
        'name' => env('PDF_TEMPLATES_QUEUE_NAME', 'pdf'),
        // seconds; keep it below the connection's retry_after
        'timeout' => 120,
        'tries' => 3,
    ],

    'chrome' => [
        'enabled' => (bool) env('PDF_TEMPLATES_CHROME', false),
        'path' => env('PDF_TEMPLATES_CHROME_PATH'),
        // With Chrome on, the engine of a template that did not pick one (dompdf when false)
        'default' => (bool) env('PDF_TEMPLATES_CHROME_DEFAULT', true),
    ],

    'pdfme' => [
        'version' => '6.2.8',
        'cdn' => 'https://esm.sh',
        'renderer_url' => env('PDFME_RENDERER_URL'),
        'renderer_token' => env('PDFME_RENDERER_TOKEN'),
        'timeout' => 60,
        // Start / stop buttons on the admin API page, for a renderer on this machine (127.0.0.1 / localhost).
        // In production prefer Supervisor / systemd / pm2 (they restart it after a crash or a reboot).
        'manage' => (bool) env('PDFME_RENDERER_MANAGE', true),
        // Node binary used by those buttons, when "node" is not on the web server's PATH
        'node' => env('PDF_TEMPLATES_NODE', 'node'),
    ],

    // Image library (editor menu → Images): logos, stamps, signatures uploaded once and used in any template
    // as "pdf-asset:logo.png" (also in the JSON data / API). Any Laravel disk: local (private), public, s3…
    'images' => [
        'disk' => env('PDF_TEMPLATES_IMAGES_DISK', 'local'),
        'path' => 'pdf-templates/images',
        // Upload size limit per image, in KB
        'max_kb' => 4096,
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
        // Remote https:// images (in the template or from the data): fetched when rendering, public hosts only, and
        // cached for remote_images_ttl minutes (0 = no cache). false = only library images, data: URIs and public/ files.
        'remote_images' => (bool) env('PDF_TEMPLATES_REMOTE_IMAGES', true),
        'remote_images_ttl' => 60,
        // Fonts added in the editor menu (uploaded .ttf files, Google Fonts): a local directory, since dompdf
        // reads font files from disk. null = storage/app/pdf-templates/fonts. Upload limit in KB.
        'font_dir' => null,
        'font_max_kb' => 4096,
        // {date} / {dateTime} in the header and footer set in the editor menu
        'date_format' => 'd.m.Y',
        // Visual (rich text) editing in the HTML editor: CKEditor 5, loaded when "Visual" is opened.
        // CKEditor 5 is GPL 2+ (used here with the "GPL" licence key); a closed-source product needs a commercial
        // licence from CKSource, or set enabled to false. The GPL key only works with the npm build (jsDelivr
        // below, or your own copy of ckeditor5/dist/browser); CKSource's own CDN (cdn.ckeditor.com) refuses it.
        'editor' => [
            'enabled' => (bool) env('PDF_TEMPLATES_VISUAL_EDITOR', true),
            'url' => 'https://cdn.jsdelivr.net/npm/ckeditor5@49.1.0/dist/browser/ckeditor5.js',
            'css' => 'https://cdn.jsdelivr.net/npm/ckeditor5@49.1.0/dist/browser/ckeditor5.css',
        ],
    ],

    // Template history: the state before each save that changes a template is kept and can be restored
    // from the editor menu. keep = versions kept per template (0 = no history).
    'history' => [
        'keep' => 50,
        'table' => 'pdf_template_versions',
    ],

    // "New template" dialog: starters offered next to "Blank". Your own folders come first (same layout as
    // pdf-templates:export, e.g. resource_path('pdf-templates/gallery')), then the package's gallery and examples.
    'gallery' => [
        'paths' => [],
    ],

];
