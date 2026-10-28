<?php

namespace AnghelutaV\PdfTemplates;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use Illuminate\Support\Facades\File;

/**
 * Templates as plain files, one folder per slug, so they can live in git and move between environments:
 *   <dir>/<slug>/meta.json      name, mode, description, paper, orientation, active
 *   <dir>/<slug>/template.json  WYSIWYG (pdfme template)
 *   <dir>/<slug>/template.html  HTML mode, with style.css
 *   <dir>/<slug>/sample.json    test data
 */
class TemplateFiles
{
    const META = ['name', 'mode', 'description', 'paper', 'orientation', 'active'];

    // Relative paths (console arguments) are taken from the project root
    public static function path(string $path): string
    {
        return preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path) ? $path : base_path($path);
    }

    public function export(PdfTemplate $template, string $dir): string
    {
        $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$template->slug;
        File::ensureDirectoryExists($path);
        File::cleanDirectory($path);

        File::put($path.'/meta.json', json_encode($template->only(self::META), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $files = $template->isHtml()
            ? ['template.html' => $template->html, 'style.css' => $template->css]
            : ['template.json' => $this->pretty($template->schema)];
        $files['sample.json'] = $this->pretty($template->sample_data);

        foreach (array_filter($files, fn ($content) => $content !== null && $content !== '') as $file => $content) {
            File::put($path.'/'.$file, $content);
        }

        return $path;
    }

    // [slug => attributes] for every template folder in $dir
    public function read(string $dir): array
    {
        $templates = [];

        foreach (glob(rtrim($dir, '/\\').'/*/meta.json') ?: [] as $metaFile) {
            $path = dirname($metaFile);
            $meta = json_decode(File::get($metaFile), true) ?: [];
            $read = fn ($file) => File::exists("{$path}/{$file}") ? File::get("{$path}/{$file}") : null;

            $templates[basename($path)] = [
                'name' => $meta['name'] ?? basename($path),
                'mode' => $meta['mode'] ?? PdfTemplate::MODE_WYSIWYG,
                'description' => $meta['description'] ?? null,
                'paper' => $meta['paper'] ?? 'a4',
                'orientation' => $meta['orientation'] ?? 'portrait',
                'active' => $meta['active'] ?? true,
                'schema' => $read('template.json'),
                'html' => $read('template.html'),
                'css' => $read('style.css'),
                'sample_data' => $read('sample.json'),
            ];
        }

        return $templates;
    }

    /**
     * Creates or updates templates from [slug => attributes]; existing slugs are kept unless $overwrite.
     *
     * @return array<string, string> slug => added|updated|skipped
     */
    public function import(array $templates, bool $overwrite = false): array
    {
        $result = [];

        foreach ($templates as $slug => $attributes) {
            $existing = PdfTemplate::where('slug', $slug)->first();

            if ($existing && ! $overwrite) {
                $result[$slug] = 'skipped';

                continue;
            }

            ($existing ?? new PdfTemplate(['slug' => $slug]))->fill($attributes)->save();
            $result[$slug] = $existing ? 'updated' : 'added';
        }

        return $result;
    }

    protected function pretty(?string $json): ?string
    {
        $decoded = $json ? json_decode($json) : null;

        return $decoded === null ? $json : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }
}
