<?php

namespace AnghelutaV\PdfTemplates\Console;

use AnghelutaV\PdfTemplates\PdfTemplates;
use AnghelutaV\PdfTemplates\TemplateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class RenderCommand extends Command
{
    protected $signature = 'pdf-templates:render
        {template : Template slug}
        {output? : PDF file to write (default: <slug>.pdf in the current directory)}
        {--data= : JSON file with the data (default: the template test data)}
        {--fields : Only list the data keys the template uses}';

    protected $description = 'Render a PDF template from the console';

    public function handle(PdfTemplates $pdf): int
    {
        try {
            $template = $pdf->find($this->argument('template'));

            if ($this->option('fields')) {
                $this->table(['Key', 'Type'], collect($pdf->fields($template))->map(fn ($type, $key) => [$key, $type])->values());

                return self::SUCCESS;
            }

            $data = $this->option('data') ? File::get(TemplateFiles::path($this->option('data'))) : $template->sample_data;
            $output = $this->argument('output') ?? $template->slug.'.pdf';
            File::put($output, $pdf->render($template, $data));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Written {$output} (".round(filesize($output) / 1024, 1).' KB)');

        return self::SUCCESS;
    }
}
