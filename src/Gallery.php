<?php

namespace AnghelutaV\PdfTemplates;

use AnghelutaV\PdfTemplates\Models\PdfTemplate;

/**
 * Starter templates offered in the "New template" dialog: the package's gallery and examples, plus the folders in
 * config gallery.paths (same layout as export / import: <dir>/<key>/meta.json, template.json or template.html +
 * style.css, sample.json), so a team can ship its own house styles.
 */
class Gallery
{
    public function __construct(protected TemplateFiles $files) {}

    /** @return array<string, array<string, mixed>> key => template attributes (name, mode, description, category…) */
    public function all(): array
    {
        $dirs = [
            ...array_filter((array) config('pdf-templates.gallery.paths', [])),
            __DIR__.'/../database/gallery',
            __DIR__.'/../database/examples',
        ];
        $templates = [];
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                // The first folder wins: an app can replace a starter by giving it the same key
                $templates += $this->files->read($dir);
            }
        }
        // Examples are named "Example: …" for the list; in the gallery the name alone reads better
        foreach ($templates as &$template) {
            $template['name'] = preg_replace('/^Example:\s*/', '', (string) ($template['name'] ?? ''));
        }

        return $templates;
    }

    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    // An unsaved template made from a starter; name / description given in the dialog win
    public function make(string $key, ?string $name = null, ?string $description = null): ?PdfTemplate
    {
        $starter = $this->find($key);
        if (! $starter) {
            return null;
        }

        return new PdfTemplate(array_filter([
            ...array_intersect_key($starter, array_flip(['mode', 'description', 'category', 'paper', 'orientation', 'settings', 'schema', 'html', 'css', 'sample_data'])),
            'name' => $name ?: $starter['name'],
            'description' => $description ?: ($starter['description'] ?? null),
        ], fn ($value) => $value !== null && $value !== ''));
    }
}
