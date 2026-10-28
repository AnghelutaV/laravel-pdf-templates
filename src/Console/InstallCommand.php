<?php

namespace AnghelutaV\PdfTemplates\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'pdf-templates:install {--examples : Also add the example templates}';

    protected $description = 'Publish the config, run the migration and optionally add example templates';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'pdf-templates-config']);
        $this->call('migrate');

        if ($this->option('examples') || ($this->input->isInteractive() && $this->confirm('Add the example templates?', true))) {
            $this->call('pdf-templates:examples');
        }

        $this->newLine();
        $this->components->info('Done. Admin UI: '.url(config('pdf-templates.routes.prefix', 'pdf-templates')));
        $this->line('  API:  set PDF_TEMPLATES_API_KEY in .env, then POST '.url(config('pdf-templates.api.prefix', 'api/pdf-templates')).'/{slug}');
        $this->line('  WYSIWYG on the server / API: php artisan pdf-templates:renderer and PDFME_RENDERER_URL=http://127.0.0.1:3311');

        return self::SUCCESS;
    }
}
