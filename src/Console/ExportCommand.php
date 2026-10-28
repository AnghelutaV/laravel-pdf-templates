<?php

namespace AnghelutaV\PdfTemplates\Console;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\TemplateFiles;
use Illuminate\Console\Command;

class ExportCommand extends Command
{
    protected $signature = 'pdf-templates:export
        {path=database/pdf-templates : Folder to write to (one sub-folder per template)}
        {--slug=* : Only these templates}';

    protected $description = 'Export PDF templates to files, to commit them and import them elsewhere';

    public function handle(TemplateFiles $files): int
    {
        $dir = TemplateFiles::path($this->argument('path'));
        $templates = PdfTemplate::query()
            ->when($this->option('slug'), fn ($q, $slugs) => $q->whereIn('slug', $slugs))
            ->orderBy('slug')
            ->get();

        foreach ($templates as $template) {
            $this->components->twoColumnDetail($template->slug, $files->export($template, $dir));
        }

        $this->components->info($templates->count().' template(s) exported. Import with: php artisan pdf-templates:import');

        return self::SUCCESS;
    }
}
