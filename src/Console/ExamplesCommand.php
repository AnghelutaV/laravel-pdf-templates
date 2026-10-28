<?php

namespace AnghelutaV\PdfTemplates\Console;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\TemplateFiles;
use Illuminate\Console\Command;

class ExamplesCommand extends Command
{
    protected $signature = 'pdf-templates:examples {--force : Overwrite examples that already exist}';

    protected $description = 'Add the example PDF templates (invoice, certificate, HTML starter)';

    public function handle(TemplateFiles $files): int
    {
        $starter = PdfTemplate::makeStarter('Example: Simple document (HTML)', PdfTemplate::MODE_HTML, 'Basic syntax: values, loops, conditions, filters');
        $examples = $files->read(__DIR__.'/../../database/examples')
            + ['example-html' => $starter->only(['name', 'mode', 'description', 'html', 'css', 'sample_data'])];

        foreach ($files->import($examples, (bool) $this->option('force')) as $slug => $status) {
            $this->components->twoColumnDetail($slug, ImportCommand::STATUS[$status]);
        }

        return self::SUCCESS;
    }
}
