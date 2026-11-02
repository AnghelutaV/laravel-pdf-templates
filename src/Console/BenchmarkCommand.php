<?php

namespace AnghelutaV\PdfTemplates\Console;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\PdfTemplates;
use AnghelutaV\PdfTemplates\TemplateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

// Times PDF generation per template: min / avg / p95 / max, peak memory, size, and what the cache saves
class BenchmarkCommand extends Command
{
    protected $signature = 'pdf-templates:benchmark
        {template?* : Template slugs (default: all active templates)}
        {--runs=10 : Timed renders per template}
        {--warmup=1 : Untimed renders first (fonts, opcache, renderer JIT)}
        {--docs=1 : Documents per PDF (the data is repeated as a list)}
        {--data= : JSON file with the data (default: each template\'s test data)}
        {--cache : Also time a cache hit (array store, nothing is written to your cache)}';

    protected $description = 'Benchmark PDF generation (time, memory, size) for one or more templates';

    public function handle(PdfTemplates $pdf): int
    {
        $runs = max(1, (int) $this->option('runs'));
        $docs = max(1, (int) $this->option('docs'));
        $templates = $this->templates();

        if ($templates->isEmpty()) {
            $this->components->error('No templates to benchmark.');

            return self::FAILURE;
        }

        // Real generation every time; the cache is measured separately with --cache
        config(['pdf-templates.cache.ttl' => 0]);

        $rows = [];
        foreach ($templates as $template) {
            if (! $template->isHtml() && ! $pdf->wysiwygOnServer()) {
                $rows[] = [$template->slug, $template->mode, 'skipped: PDFME_RENDERER_URL not set', '', '', '', '', '', '', ''];

                continue;
            }

            try {
                $data = $this->data($pdf, $template, $docs);
                for ($i = 0; $i < (int) $this->option('warmup'); $i++) {
                    $pdf->render($template, $data);
                }

                memory_reset_peak_usage();
                $times = [];
                for ($i = 0; $i < $runs; $i++) {
                    $start = hrtime(true);
                    $bytes = $pdf->render($template, $data);
                    $times[] = (hrtime(true) - $start) / 1e6;
                }
                $peak = memory_get_peak_usage(true);

                sort($times);
                $avg = array_sum($times) / count($times);
                $rows[] = [
                    $template->slug,
                    $template->mode,
                    $this->ms($times[0]),
                    $this->ms($avg),
                    $this->ms($times[(int) ceil(0.95 * count($times)) - 1]),
                    $this->ms(end($times)),
                    round($peak / 1048576, 1).' MB',
                    round(strlen($bytes) / 1024, 1).' KB',
                    round(1000 / $avg, 1),
                    $this->option('cache') ? $this->cacheHit($pdf, $template, $data) : '',
                ];
            } catch (Throwable $e) {
                $rows[] = [$template->slug, $template->mode, 'error: '.$e->getMessage(), '', '', '', '', '', '', ''];
            }
        }

        $this->components->info("{$runs} runs per template, {$docs} document(s) per PDF");
        $this->table(['Template', 'Mode', 'Min', 'Avg', 'p95', 'Max', 'Peak mem', 'Size', 'PDF/s', 'Cache hit'], $rows);
        $this->line('  PDF/s = sequential, one PHP process. Under load, multiply by the PHP workers (and renderer instances for WYSIWYG).');

        return self::SUCCESS;
    }

    protected function templates()
    {
        $slugs = $this->argument('template');

        return PdfTemplate::query()
            ->when($slugs, fn ($q) => $q->whereIn('slug', $slugs), fn ($q) => $q->active())
            ->orderBy('name')
            ->get();
    }

    // Raw JSON for one document; with --docs the object is repeated as a list (several documents in one PDF)
    protected function data(PdfTemplates $pdf, PdfTemplate $template, int $docs): string
    {
        $json = $this->option('data') ? File::get(TemplateFiles::path($this->option('data'))) : ($template->sample_data ?: '{}');
        if ($docs === 1) {
            return $json;
        }

        $decoded = $pdf->decode($json);
        $one = array_is_list($decoded) && $decoded ? $decoded[0] : $decoded;

        return json_encode(array_fill(0, $docs, $one ?: new \stdClass), JSON_UNESCAPED_UNICODE);
    }

    protected function cacheHit(PdfTemplates $pdf, PdfTemplate $template, string $data): string
    {
        config(['pdf-templates.cache.ttl' => 60, 'pdf-templates.cache.store' => 'array']);
        try {
            $pdf->render($template, $data); // miss: fills the cache
            $start = hrtime(true);
            $pdf->render($template, $data);

            return $this->ms((hrtime(true) - $start) / 1e6);
        } finally {
            config(['pdf-templates.cache.ttl' => 0]);
        }
    }

    protected function ms(float $ms): string
    {
        return $ms < 10 ? number_format($ms, 2).' ms' : round($ms).' ms';
    }
}
