<?php

namespace AnghelutaV\PdfTemplates;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fonts for HTML mode (dompdf), uploaded in the editor menu or fetched from Google Fonts by name.
 * dompdf reads font files from the local disk, so they live in a local directory (config html.font_dir),
 * with fonts.json listing [family, weight, style, file]. Fonts from config html.fonts are added to them.
 */
class FontLibrary
{
    const WEIGHTS = ['normal', 'bold'];

    const STYLES = ['normal', 'italic'];

    // Plain CSS API with a client that is not a browser: Google answers with TrueType (.ttf) files
    const GOOGLE_CSS = 'https://fonts.googleapis.com/css';

    public function dir(): string
    {
        return rtrim((string) (config('pdf-templates.html.font_dir') ?: storage_path('app/pdf-templates/fonts')), '/\\');
    }

    /** @return list<array{family: string, weight: string, style: string, file: string}> */
    public function all(): array
    {
        $index = $this->dir().'/fonts.json';
        $list = File::exists($index) ? json_decode((string) File::get($index), true) : [];

        return array_values(array_filter(
            is_array($list) ? $list : [],
            fn ($font) => is_array($font) && isset($font['family'], $font['file']) && File::exists($this->dir().'/'.basename($font['file'])),
        ));
    }

    /** @return array<string, list<array{0: string, 1: string, 2: string}>> family => [[path, weight, style]], config fonts first */
    public function fonts(): array
    {
        $fonts = (array) config('pdf-templates.html.fonts', []);
        foreach ($this->all() as $font) {
            $fonts[$font['family']][] = [$this->dir().'/'.basename($font['file']), $font['weight'], $font['style']];
        }

        return $fonts;
    }

    /** @return list<string> */
    public function families(): array
    {
        return array_values(array_unique([
            (string) config('pdf-templates.html.default_font', 'DejaVu Sans'),
            ...array_keys($this->fonts()),
        ]));
    }

    public static function validFamily(string $family): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]{0,62}$/', $family);
    }

    // A TrueType file, checked by its signature (dompdf only reads .ttf / .otf with TrueType outlines)
    public static function isTrueType(string $contents): bool
    {
        return in_array(substr($contents, 0, 4), ["\x00\x01\x00\x00", 'true', 'OTTO'], true);
    }

    public function store(UploadedFile $file, string $family, string $weight = 'normal', string $style = 'normal'): array
    {
        return $this->put($family, $weight, $style, (string) file_get_contents($file->getRealPath()));
    }

    /** Adds (or replaces) one variant; returns its entry */
    public function put(string $family, string $weight, string $style, string $contents): array
    {
        $family = trim($family);
        if (! static::validFamily($family) || ! in_array($weight, self::WEIGHTS, true) || ! in_array($style, self::STYLES, true)) {
            throw new InvalidArgumentException('Invalid font family, weight or style.');
        }
        if (! static::isTrueType($contents)) {
            throw new InvalidArgumentException('Not a TrueType font (.ttf).');
        }

        File::ensureDirectoryExists($this->dir());
        $file = Str::slug($family).'-'.$weight.'-'.$style.'.ttf';
        File::put($this->dir().'/'.$file, $contents);

        $entry = compact('family', 'weight', 'style', 'file');
        $list = array_filter($this->all(), fn ($font) => ! ($font['family'] === $family && $font['weight'] === $weight && $font['style'] === $style));
        $this->save([...$list, $entry]);

        return $entry;
    }

    /**
     * Regular, bold, italic and bold italic of a Google Fonts family, when they exist.
     *
     * @return list<array{family: string, weight: string, style: string, file: string}>
     */
    public function google(string $family): array
    {
        $family = trim($family);
        if (! static::validFamily($family)) {
            throw new InvalidArgumentException('Invalid font family.');
        }

        try {
            $css = Http::withUserAgent('laravel-pdf-templates')->timeout(15)
                ->get(self::GOOGLE_CSS, ['family' => $family.':400,700,400italic,700italic']);
        } catch (ConnectionException) {
            throw new InvalidArgumentException('Google Fonts could not be reached.');
        }
        if ($css->failed()) {
            throw new InvalidArgumentException("\"{$family}\" was not found on Google Fonts.");
        }

        $added = [];
        preg_match_all('/@font-face\s*\{([^}]*)\}/', $css->body(), $faces);
        foreach ($faces[1] as $face) {
            preg_match('/font-style:\s*(\w+)/', $face, $style);
            preg_match('/font-weight:\s*(\d+)/', $face, $weight);
            preg_match('#url\((https://fonts\.gstatic\.com/[^)\s]+)\)#', $face, $url);
            if (! $url) {
                continue;
            }
            $variant = [((int) ($weight[1] ?? 400)) >= 600 ? 'bold' : 'normal', ($style[1] ?? 'normal') === 'italic' ? 'italic' : 'normal'];
            // One file per variant (the first one: no unicode-range subsets for this client)
            if (isset($added[implode('-', $variant)])) {
                continue;
            }
            $file = Http::timeout(30)->get($url[1]);
            if ($file->successful() && static::isTrueType($file->body())) {
                $added[implode('-', $variant)] = $this->put($family, $variant[0], $variant[1], $file->body());
            }
        }

        if (! $added) {
            throw new InvalidArgumentException("\"{$family}\" has no TrueType files on Google Fonts.");
        }

        return array_values($added);
    }

    public function delete(string $family): bool
    {
        $list = $this->all();
        $left = [];
        foreach ($list as $font) {
            if ($font['family'] === $family) {
                File::delete($this->dir().'/'.basename($font['file']));
            } else {
                $left[] = $font;
            }
        }
        $this->save($left);

        return count($left) !== count($list);
    }

    protected function save(array $list): void
    {
        File::ensureDirectoryExists($this->dir());
        usort($list, fn ($a, $b) => [$a['family'], $a['weight'], $a['style']] <=> [$b['family'], $b['weight'], $b['style']]);
        File::put($this->dir().'/fonts.json', json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
    }
}
