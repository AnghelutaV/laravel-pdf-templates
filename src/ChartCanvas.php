<?php

namespace AnghelutaV\PdfTemplates;

// What Chart draws: SVG elements for the shapes, a list of text labels laid over them
class ChartCanvas
{
    public string $shapes = '';

    /** @var list<array{x: float, y: float, w: float, align: string, text: string, size: float, color: string, bold: bool}> */
    public array $labels = [];

    public function __construct(public int $width, public int $height) {}

    public function label(float $x, float $y, float $w, string $align, string $text, float $size, string $color, bool $bold = false): void
    {
        $this->labels[] = ['x' => round($x, 2), 'y' => round($y, 2), 'w' => round(max(1, $w), 2), 'align' => $align, 'text' => $text, 'size' => $size, 'color' => $color, 'bold' => $bold];
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color, float $width = 1): void
    {
        $this->shapes .= sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="%s"/>', $this->n($x1), $this->n($y1), $this->n($x2), $this->n($y2), $color, $width);
    }

    public function rect(float $x, float $y, float $w, float $h, string $color, float $radius = 0): void
    {
        if ($w <= 0 || $h <= 0) {
            return;
        }
        $this->shapes .= sprintf('<rect x="%s" y="%s" width="%s" height="%s"%s fill="%s"/>', $this->n($x), $this->n($y), $this->n($w), $this->n($h), $radius ? ' rx="'.$radius.'"' : '', $color);
    }

    public function circle(float $x, float $y, float $r, string $color): void
    {
        $this->shapes .= sprintf('<circle cx="%s" cy="%s" r="%s" fill="%s" stroke="#ffffff" stroke-width="1"/>', $this->n($x), $this->n($y), $r, $color);
    }

    /** @param  list<array{0: float, 1: float}>  $points */
    public function polyline(array $points, string $color, float $width): void
    {
        $this->shapes .= sprintf('<polyline points="%s" fill="none" stroke="%s" stroke-width="%s" stroke-linejoin="round"/>', $this->points($points), $color, $width);
    }

    /** @param  list<array{0: float, 1: float}>  $points */
    public function polygon(array $points, string $color, float $opacity = 1): void
    {
        $this->shapes .= sprintf('<polygon points="%s" fill="%s" fill-opacity="%s"/>', $this->points($points), $color, $opacity);
    }

    // A pie slice, or a ring segment when $inner > 0; angles in radians, clockwise from 3 o'clock
    public function slice(float $cx, float $cy, float $r, float $inner, float $from, float $to, string $color): void
    {
        // A full circle cannot be one arc: two halves
        if ($to - $from >= 2 * M_PI - 1e-6) {
            $this->slice($cx, $cy, $r, $inner, $from, $from + M_PI, $color);
            $this->slice($cx, $cy, $r, $inner, $from + M_PI, $to, $color);

            return;
        }
        $large = $to - $from > M_PI ? 1 : 0;
        $p = fn (float $radius, float $angle) => $this->n($cx + cos($angle) * $radius).' '.$this->n($cy + sin($angle) * $radius);
        $d = 'M'.$p($r, $from).' A'.$this->n($r).' '.$this->n($r).' 0 '.$large.' 1 '.$p($r, $to);
        $d .= $inner > 0
            ? ' L'.$p($inner, $to).' A'.$this->n($inner).' '.$this->n($inner).' 0 '.$large.' 0 '.$p($inner, $from).' Z'
            : ' L'.$this->n($cx).' '.$this->n($cy).' Z';
        $this->shapes .= '<path d="'.$d.'" fill="'.$color.'" stroke="#ffffff" stroke-width="1.5"/>';
    }

    /** @param  list<array{0: float, 1: float}>  $points */
    protected function points(array $points): string
    {
        return implode(' ', array_map(fn ($p) => $this->n($p[0]).','.$this->n($p[1]), $points));
    }

    protected function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
