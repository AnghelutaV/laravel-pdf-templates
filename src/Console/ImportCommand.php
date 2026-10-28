<?php

namespace AnghelutaV\PdfTemplates\Console;

use AnghelutaV\PdfTemplates\TemplateFiles;
use Illuminate\Console\Command;

class ImportCommand extends Command
{
    const STATUS = [
        'added' => '<fg=green>added</>',
        'updated' => '<fg=green>updated</>',
        'skipped' => '<fg=yellow>exists, skipped</>',
    ];

    protected $signature = 'pdf-templates:import
        {path=database/pdf-templates : Folder written by pdf-templates:export}
        {--force : Overwrite templates that already exist}';

    protected $description = 'Import PDF templates from files (e.g. on deploy)';

    public function handle(TemplateFiles $files): int
    {
        $dir = TemplateFiles::path($this->argument('path'));
        $templates = $files->read($dir);

        if (! $templates) {
            $this->components->warn("No templates found in {$dir}");

            return self::FAILURE;
        }

        foreach ($files->import($templates, (bool) $this->option('force')) as $slug => $status) {
            $this->components->twoColumnDetail($slug, self::STATUS[$status]);
        }

        return self::SUCCESS;
    }
}
