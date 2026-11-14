<?php

namespace AnghelutaV\PdfTemplates;

/**
 * HTML mode page setup, edited in the editor menu and stored in pdf_templates.settings:
 *
 *   margins     [top, right, bottom, left] in mm (null = the template's own @page CSS)
 *   header      { left, center, right, font_size, html }   repeated on every page, in the top margin
 *   footer      { left, center, right, font_size, html }   repeated on every page, in the bottom margin
 *   word_break  normal | break-word | break-all
 *   engine      dompdf (default) | chrome (only when config chrome.enabled is on)
 *
 * Texts and the custom HTML (which replaces left / center / right when it is set) are template syntax with
 * the page variables {currentPage} (alias {pageNumber}), {totalPages}, {date} and {dateTime}.
 */
class PageSetup
{
    const BANDS = ['header', 'footer'];

    const SLOTS = ['left', 'center', 'right'];

    const WORD_BREAKS = ['normal', 'break-word', 'break-all'];

    const ENGINES = ['dompdf', 'chrome'];

    // A band with no margins set still needs room
    const DEFAULT_MARGINS = [20, 15, 20, 15];

    const PAGE = '/\{\s*(currentPage|pageNumber|totalPages)\s*\}|\{\{\s*(currentPage|pageNumber|totalPages)\s*\}\}/';

    public function __construct(protected array $settings) {}

    public static function from(?array $settings): self
    {
        return new self($settings ?? []);
    }

    /** @return array<string, mixed> validation rules for the settings array (update / render) */
    public static function rules(string $prefix = 'settings'): array
    {
        $rules = [
            $prefix => 'nullable|array',
            "{$prefix}.margins" => 'nullable|array|size:4',
            "{$prefix}.margins.*" => 'nullable|numeric|min:0|max:150',
            "{$prefix}.word_break" => 'nullable|in:'.implode(',', self::WORD_BREAKS),
            "{$prefix}.engine" => 'nullable|in:'.implode(',', self::ENGINES),
        ];
        foreach (self::BANDS as $band) {
            $rules["{$prefix}.{$band}"] = 'nullable|array';
            $rules["{$prefix}.{$band}.font_size"] = 'nullable|numeric|min:4|max:72';
            $rules["{$prefix}.{$band}.html"] = 'nullable|string|max:20000';
            foreach (self::SLOTS as $slot) {
                $rules["{$prefix}.{$band}.{$slot}"] = 'nullable|string|max:500';
            }
        }

        return $rules;
    }

    /** @return ?array{0: float, 1: float, 2: float, 3: float} */
    public function margins(): ?array
    {
        $margins = $this->settings['margins'] ?? null;
        if (! is_array($margins) || count(array_filter($margins, fn ($m) => is_numeric($m))) === 0) {
            return $this->hasBand() ? self::DEFAULT_MARGINS : null;
        }

        return array_map(fn ($m, $default) => is_numeric($m) ? (float) $m : (float) $default, array_values($margins) + [0, 0, 0, 0], self::DEFAULT_MARGINS);
    }

    public function band(string $band): ?string
    {
        $config = $this->settings[$band] ?? null;
        if (! is_array($config)) {
            return null;
        }
        if (trim((string) ($config['html'] ?? '')) !== '') {
            return (string) $config['html'];
        }

        $cells = '';
        $any = false;
        foreach (self::SLOTS as $slot) {
            $text = trim((string) ($config[$slot] ?? ''));
            $any = $any || $text !== '';
            $cells .= '<td style="text-align: '.$slot.';">'.$text.'</td>';
        }

        return $any ? '<table class="pt-band"><tr>'.$cells.'</tr></table>' : null;
    }

    // chrome when the template asks for it and the config allows it; dompdf otherwise
    public function engine(): string
    {
        return ($this->settings['engine'] ?? null) === 'chrome' && Renderers\ChromeRenderer::enabled() ? 'chrome' : 'dompdf';
    }

    /**
     * Header / footer for Chrome (its headerTemplate / footerTemplate: a separate page without the template's CSS),
     * filled with the data; page variables become Chrome's pageNumber / totalPages. Null when the band is empty.
     */
    public function chromeBand(string $band, TemplateEngine $engine, array $data, string $font): ?string
    {
        $content = $this->band($band);
        $margins = $this->margins();
        if ($content === null || ! $margins) {
            return null;
        }
        $content = (string) preg_replace_callback(self::PAGE, function (array $m) {
            $name = $m[1] !== '' ? $m[1] : $m[2];

            return $name === 'totalPages' ? '<span class="totalPages"></span>' : '<span class="pageNumber"></span>';
        }, $content);
        $now = now();
        $values = $data + [
            'date' => $now->format((string) config('pdf-templates.html.date_format', 'd.m.Y')),
            'dateTime' => $now->format((string) config('pdf-templates.html.date_format', 'd.m.Y').' H:i'),
        ];
        $size = (float) ($this->settings[$band]['font_size'] ?? 9) ?: 9;
        [, $right, , $left] = $margins;

        return '<style>.pt-band { width: 100%; border-collapse: collapse; } .pt-band td { width: 33.33%; padding: 0; vertical-align: middle; }</style>'
            .'<div style="width: 100%; box-sizing: border-box; padding: 0 '.$right.'mm 0 '.$left.'mm; font-family: \''.e($font).'\', sans-serif; font-size: '.$size.'pt; -webkit-print-color-adjust: exact;">'
            .$engine->render($content, $values).'</div>';
    }

    public function hasBand(): bool
    {
        return $this->band('header') !== null || $this->band('footer') !== null;
    }

    // CSS added after the template's own CSS (so the margins set here win over its @page)
    public function css(): string
    {
        $css = '';
        if ($margins = $this->margins()) {
            [$top, $right, $bottom, $left] = $margins;
            $css .= "@page { margin: {$top}mm {$right}mm {$bottom}mm {$left}mm; }\n";

            $css .= '.pt-page-header, .pt-page-footer { position: fixed; left: 0; right: 0; overflow: hidden; }'."\n";
            $css .= ".pt-page-header { top: -{$top}mm; height: {$top}mm; }\n";
            $css .= ".pt-page-footer { bottom: -{$bottom}mm; height: {$bottom}mm; }\n";
            $css .= '.pt-band { width: 100%; height: 100%; border-collapse: collapse; }'."\n";
            $css .= '.pt-band td { width: 33.33%; padding: 0; vertical-align: middle; }'."\n";
            foreach (self::BANDS as $band) {
                $size = (float) ($this->settings[$band]['font_size'] ?? 9) ?: 9;
                $css .= ".pt-page-{$band} { font-size: {$size}pt; }\n";
            }
            $css .= '.pt-page-number:before { content: counter(page); }'."\n";
            $css .= '.pt-page-count:before { content: counter(pages); }'."\n";
        }
        $break = $this->settings['word_break'] ?? null;
        if (in_array($break, ['break-word', 'break-all'], true)) {
            $css .= $break === 'break-word'
                ? "body, td, th { word-wrap: break-word; overflow-wrap: break-word; }\n"
                : "body, td, th { word-break: break-all; }\n";
        }

        return $css;
    }

    // The fixed header / footer elements, filled with the data; they go first in <body> (dompdf repeats
    // fixed elements on the pages after the one they appear on)
    public function html(TemplateEngine $engine, array $data): string
    {
        if (! $this->margins()) {
            return '';
        }

        $out = '';
        foreach (self::BANDS as $band) {
            $content = $this->band($band);
            if ($content === null) {
                continue;
            }
            // Page variables become counters; {date} / {dateTime} are today unless the data has them
            $content = (string) preg_replace_callback(self::PAGE, function (array $m) {
                $name = $m[1] !== '' ? $m[1] : $m[2];

                return $name === 'totalPages' ? '<span class="pt-page-count"></span>' : '<span class="pt-page-number"></span>';
            }, $content);
            $now = now();
            $values = $data + [
                'date' => $now->format((string) config('pdf-templates.html.date_format', 'd.m.Y')),
                'dateTime' => $now->format((string) config('pdf-templates.html.date_format', 'd.m.Y').' H:i'),
            ];
            $out .= '<div class="pt-page-'.$band.'">'.$engine->render($content, $values).'</div>';
        }

        return $out;
    }
}
