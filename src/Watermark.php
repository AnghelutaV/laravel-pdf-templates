<?php

namespace AnghelutaV\PdfTemplates;

/**
 * A diagonal text over every page ("DRAFT", "SAMPLE", "COPY"), in both modes:
 *   PdfTemplates::make('invoice', $data)->watermark('DRAFT')
 *   PdfTemplates::render('invoice', $data, ['watermark' => ['text' => 'COPY', 'opacity' => 0.1, 'color' => '#dc2626']])
 *   API: ?watermark=DRAFT&watermark_color=%23dc2626&watermark_opacity=0.2
 * HTML templates draw it with dompdf; WYSIWYG ones get a text field on every page (pdfme), so it uses the template's font.
 */
class Watermark
{
    const DEFAULTS = ['text' => '', 'opacity' => 0.15, 'color' => '#808080', 'angle' => 45, 'size' => null];

    /**
     * 'DRAFT' or ['text' => …, 'opacity' => 0–1, 'color' => '#rrggbb', 'angle' => degrees, 'size' => pt or null (fits the page)].
     *
     * @return array{text: string, opacity: float, color: string, angle: float, size: ?float}|null null when there is no text
     */
    public static function normalize(string|array|null $watermark): ?array
    {
        if ($watermark === null) {
            return null;
        }
        $watermark = is_string($watermark) ? ['text' => $watermark] : $watermark;
        $text = trim(mb_substr((string) ($watermark['text'] ?? ''), 0, 60));
        if ($text === '') {
            return null;
        }
        $color = (string) ($watermark['color'] ?? self::DEFAULTS['color']);
        $size = $watermark['size'] ?? null;

        return [
            'text' => $text,
            'opacity' => max(0.02, min(1, (float) ($watermark['opacity'] ?? self::DEFAULTS['opacity']))),
            'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : self::DEFAULTS['color'],
            'angle' => fmod((float) ($watermark['angle'] ?? self::DEFAULTS['angle']), 360),
            'size' => is_numeric($size) && $size > 0 ? min(300, (float) $size) : null,
        ];
    }

    /**
     * Font size (pt) that makes the text run across most of the page diagonal (or along the width at 0°).
     * $width / $height in pt; $widthAt1pt = the text's width at 1 pt when the caller can measure it, otherwise
     * about 0.7 em per character.
     */
    public static function fitSize(array $watermark, float $width, float $height, ?float $widthAt1pt = null): float
    {
        if ($watermark['size']) {
            return (float) $watermark['size'];
        }
        $angle = deg2rad(abs($watermark['angle']));
        // The longest line through the centre at this angle that stays on the page
        $span = min(
            abs(cos($angle)) > 1e-6 ? $width / abs(cos($angle)) : INF,
            abs(sin($angle)) > 1e-6 ? $height / abs(sin($angle)) : INF,
        );

        return max(12, min(160, $span * 0.7 / ($widthAt1pt ?: mb_strlen($watermark['text']) * 0.7)));
    }
}
