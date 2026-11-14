<?php

namespace AnghelutaV\PdfTemplates;

use Closure;
use Illuminate\Support\HtmlString;

/**
 * Charts for HTML templates, drawn from the data at render time:
 *   {{ chart("bar", sales, "month", "total") }}
 *   {{ chart("line", sales, "month", "income,costs", "title='2026' width=520 height=240 legend=1") }}
 *   {{ chart("pie", shares) }}                         shares = {"Tiles": 40, "Paint": 25} (an object: key → value)
 * Types: bar, hbar (horizontal), stacked, line, area, pie, doughnut. Options (a "key=value key='a b'" string):
 * width, height (px), title, colors (#hex,#hex…), legend (1/0), values (1/0: numbers on the bars / slices),
 * decimals, prefix, suffix, min, max.
 *
 * The shapes are an SVG image; the texts are HTML laid over it, so they use the template's font (diacritics and
 * all: dompdf's SVG text would not). resources/assets/charts.js draws the same charts for WYSIWYG templates.
 */
class Chart
{
    const TYPES = ['bar', 'hbar', 'stacked', 'line', 'area', 'pie', 'doughnut'];

    const COLORS = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#475569'];

    /** @var Closure(float, int): string */
    protected Closure $format;

    public function __construct(?Closure $format = null)
    {
        $this->format = $format ?? fn (float $n, int $decimals) => number_format($n, $decimals, ',', ' ');
    }

    /** @param  Closure(float, int): string  $format  number formatting (the template's separators) */
    public function formatUsing(Closure $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function html(mixed $type, mixed $data, mixed $label = null, mixed $values = null, mixed $options = null): HtmlString
    {
        $model = $this->model((string) $type, $data, $label === null ? null : (string) $label, $values === null ? null : (string) $values, self::options((string) $options));
        if (! $model) {
            return new HtmlString('');
        }
        [$width, $height] = [$model['width'], $model['height']];

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'">'.$model['shapes'].'</svg>';
        $html = '<div class="pt-chart" style="position: relative; width: '.$width.'px; height: '.$height.'px; overflow: hidden;">'
            .'<img src="data:image/svg+xml;base64,'.base64_encode($svg).'" style="position: absolute; left: 0; top: 0; width: '.$width.'px; height: '.$height.'px;" alt="">';
        foreach ($model['labels'] as $l) {
            $left = match ($l['align']) {
                'center' => $l['x'] - $l['w'] / 2,
                'right' => $l['x'] - $l['w'],
                default => $l['x'],
            };
            $html .= '<div style="position: absolute; left: '.round($left, 1).'px; top: '.round($l['y'] - $l['size'] * 0.6, 1).'px; width: '.round($l['w'], 1).'px;'
                .' text-align: '.$l['align'].'; font-size: '.$l['size'].'px; line-height: '.round($l['size'] * 1.2, 1).'px; white-space: nowrap; overflow: hidden;'
                .' color: '.$l['color'].';'.($l['bold'] ? ' font-weight: bold;' : '').'">'.e($l['text']).'</div>';
        }

        return new HtmlString($html.'</div>');
    }

    /**
     * "width=520 title='Sales 2026' colors=#2563eb,#16a34a" → ['width' => '520', 'title' => 'Sales 2026', …]
     *
     * @return array<string, string>
     */
    public static function options(string $source): array
    {
        preg_match_all('/(\w+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+))/u', $source, $matches, PREG_SET_ORDER);
        $options = [];
        foreach ($matches as $m) {
            $options[strtolower($m[1])] = ($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
        }

        return $options;
    }

    /**
     * The chart as shapes (SVG elements, no text) and labels (x / y = anchor point of the text's middle line,
     * w = box width, align = left|center|right), in px. Null when there is nothing to draw.
     *
     * @return array{width: int, height: int, shapes: string, labels: list<array{x: float, y: float, w: float, align: string, text: string, size: float, color: string, bold: bool}>}|null
     */
    public function model(string $type, mixed $data, ?string $label, ?string $values, array $options): ?array
    {
        $type = in_array(strtolower($type), self::TYPES, true) ? strtolower($type) : 'bar';
        [$categories, $series] = $this->series($data, $label, $values);
        if (! $categories || ! $series) {
            return null;
        }

        $o = [
            'width' => max(80, min(2000, (int) ($options['width'] ?? 480))),
            'height' => max(60, min(2000, (int) ($options['height'] ?? 240))),
            'title' => (string) ($options['title'] ?? ''),
            'colors' => array_values(array_filter(explode(',', (string) ($options['colors'] ?? '')), fn ($c) => preg_match('/^#[0-9a-f]{3,8}$/i', trim($c)))) ?: self::COLORS,
            'decimals' => max(0, min(6, (int) ($options['decimals'] ?? 0))),
            'prefix' => (string) ($options['prefix'] ?? ''),
            'suffix' => (string) ($options['suffix'] ?? ''),
            'min' => isset($options['min']) && is_numeric($options['min']) ? (float) $options['min'] : null,
            'max' => isset($options['max']) && is_numeric($options['max']) ? (float) $options['max'] : null,
        ];
        $o['colors'] = array_map('trim', $o['colors']);
        $o['legend'] = isset($options['legend']) ? $this->flag($options['legend']) : (count($series) > 1 || in_array($type, ['pie', 'doughnut'], true));
        $o['values'] = isset($options['values']) ? $this->flag($options['values']) : (count($series) === 1 && count($categories) <= 12 && $type !== 'line' && $type !== 'area');

        $c = new ChartCanvas($o['width'], $o['height']);
        $top = 6;
        if ($o['title'] !== '') {
            $c->label($o['width'] / 2, 11, $o['width'] - 8, 'center', $o['title'], 13, '#111827', true);
            $top = 26;
        }

        if (in_array($type, ['pie', 'doughnut'], true)) {
            $this->pie($c, $type, $categories, $series[0], $o, $top);
        } else {
            $legendHeight = $o['legend'] ? 18 : 0;
            $this->axes($c, $type, $categories, $series, $o, $top, $o['height'] - $legendHeight);
            if ($o['legend']) {
                $this->legend($c, array_column($series, 'name'), $o, $o['height'] - 9);
            }
        }

        return ['width' => $o['width'], 'height' => $o['height'], 'shapes' => $c->shapes, 'labels' => $c->labels];
    }

    /**
     * Categories and series from the data: a list of rows (label key + one or more value keys) or an object
     * {label: value}.
     *
     * @return array{0: list<string>, 1: list<array{name: string, values: list<float>}>}
     */
    protected function series(mixed $data, ?string $label, ?string $values): array
    {
        if (! is_array($data) || ! $data) {
            return [[], []];
        }
        if (! array_is_list($data)) {
            return [array_map('strval', array_keys($data)), [['name' => (string) ($values ?: ''), 'values' => array_map(fn ($v) => $this->number($v), array_values($data))]]];
        }

        $rows = array_values(array_filter($data, 'is_array'));
        if (! $rows) {
            // A plain list of numbers
            return [array_map(fn ($i) => (string) ($i + 1), array_keys($data)), [['name' => '', 'values' => array_map(fn ($v) => $this->number($v), $data)]]];
        }
        $keys = $values !== null && trim($values) !== ''
            ? array_map('trim', explode(',', $values))
            : array_values(array_filter(array_keys($rows[0]), fn ($key) => $key !== $label && is_numeric($rows[0][$key])));
        $categories = array_map(fn ($row, $i) => $label !== null && isset($row[$label]) ? (string) $row[$label] : (string) ($i + 1), $rows, array_keys($rows));

        return [$categories, array_map(fn ($key) => [
            'name' => (string) $key,
            'values' => array_map(fn ($row) => $this->number(data_get($row, $key)), $rows),
        ], array_values(array_filter($keys, fn ($key) => $key !== '')))];
    }

    protected function axes(ChartCanvas $c, string $type, array $categories, array $series, array $o, float $top, float $bottomEdge): void
    {
        $stacked = $type === 'stacked';
        $all = $stacked
            ? array_map(fn ($i) => array_sum(array_map(fn ($s) => max(0, $s['values'][$i]), $series)), array_keys($categories))
            : array_merge(...array_column($series, 'values'));
        $negatives = $stacked ? array_map(fn ($i) => array_sum(array_map(fn ($s) => min(0, $s['values'][$i]), $series)), array_keys($categories)) : $all;
        [$min, $max, $step] = $this->scale($o['min'] ?? min(0, ...$negatives), $o['max'] ?? max(0, ...$all));
        $ticks = [];
        for ($v = $min; $v <= $max + $step / 1000; $v += $step) {
            $ticks[] = round($v, 10);
        }
        $tickText = fn (float $v) => $o['prefix'].($this->format)($v, $step < 1 ? max($o['decimals'], 1) : $o['decimals']).$o['suffix'];
        $horizontal = $type === 'hbar';
        $n = count($categories);

        if ($horizontal) {
            $labelWidth = min($o['width'] * 0.35, max(30, max(array_map(fn ($t) => mb_strlen($t), $categories)) * 6 + 8));
            [$left, $right, $plotTop, $plotBottom] = [$labelWidth, $o['width'] - 12, $top + 2, $bottomEdge - 18];
            $x = fn (float $v) => $left + ($v - $min) / ($max - $min) * ($right - $left);
            foreach ($ticks as $t) {
                $c->line($x($t), $plotTop, $x($t), $plotBottom, '#e5e7eb');
                $c->label($x($t), $plotBottom + 9, 60, 'center', $tickText($t), 9, '#6b7280');
            }
            $c->line($x(0), $plotTop, $x(0), $plotBottom, '#9ca3af');
            $band = ($plotBottom - $plotTop) / $n;
            $barHeight = $band * 0.7 / count($series);
            foreach ($categories as $i => $category) {
                $c->label($left - 6, $plotTop + $band * ($i + 0.5), $labelWidth - 8, 'right', $category, 10, '#374151');
                foreach ($series as $s => $serie) {
                    $v = $serie['values'][$i];
                    $y = $plotTop + $band * $i + $band * 0.15 + $barHeight * $s;
                    $c->rect(min($x(0), $x($v)), $y, abs($x($v) - $x(0)), $barHeight, $o['colors'][$s % count($o['colors'])]);
                    if ($o['values']) {
                        $c->label($x($v) + ($v < 0 ? -4 : 4), $y + $barHeight / 2, 70, $v < 0 ? 'right' : 'left', $o['prefix'].($this->format)($v, $o['decimals']).$o['suffix'], 9, '#374151');
                    }
                }
            }

            return;
        }

        $axisWidth = max(array_map(fn ($t) => mb_strlen($tickText($t)), $ticks)) * 6 + 10;
        [$left, $right, $plotTop, $plotBottom] = [$axisWidth, $o['width'] - 8, $top + ($o['values'] ? 12 : 4), $bottomEdge - 20];
        $y = fn (float $v) => $plotBottom - ($v - $min) / ($max - $min) * ($plotBottom - $plotTop);
        foreach ($ticks as $t) {
            $c->line($left, $y($t), $right, $y($t), '#e5e7eb');
            $c->label($left - 5, $y($t), $axisWidth - 6, 'right', $tickText($t), 9, '#6b7280');
        }
        $c->line($left, $y(0), $right, $y(0), '#9ca3af');

        $band = ($right - $left) / $n;
        // Category labels: every k-th when they would overlap
        $longest = max(array_map(fn ($t) => mb_strlen($t), $categories)) * 6 + 6;
        $every = max(1, (int) ceil($longest / $band));
        foreach ($categories as $i => $category) {
            if ($i % $every === 0) {
                $c->label($left + $band * ($i + 0.5), $plotBottom + 10, $band * $every, 'center', $category, 10, '#374151');
            }
        }

        if ($type === 'line' || $type === 'area') {
            $px = fn (int $i) => $left + $band * ($i + 0.5);
            foreach ($series as $s => $serie) {
                $color = $o['colors'][$s % count($o['colors'])];
                $points = array_map(fn ($v, $i) => [$px($i), $y($v)], $serie['values'], array_keys($serie['values']));
                if ($type === 'area') {
                    $c->polygon([[$points[0][0], $y(max($min, 0))], ...$points, [end($points)[0], $y(max($min, 0))]], $color, 0.18);
                }
                $c->polyline($points, $color, 2);
                foreach ($points as $p) {
                    $c->circle($p[0], $p[1], 2.6, $color);
                }
                if ($o['values']) {
                    foreach ($points as $i => $p) {
                        $c->label($p[0], $p[1] - 10, 60, 'center', $o['prefix'].($this->format)($serie['values'][$i], $o['decimals']).$o['suffix'], 9, '#374151');
                    }
                }
            }

            return;
        }

        $groupWidth = $band * 0.72;
        $barWidth = $stacked ? $groupWidth : $groupWidth / count($series);
        foreach ($categories as $i => $category) {
            [$up, $down] = [0.0, 0.0];
            foreach ($series as $s => $serie) {
                $v = $serie['values'][$i];
                $color = $o['colors'][$s % count($o['colors'])];
                $x = $left + $band * $i + ($band - $groupWidth) / 2 + ($stacked ? 0 : $barWidth * $s);
                if ($stacked) {
                    $from = $v >= 0 ? $up : $down;
                    $to = $from + $v;
                    $v >= 0 ? $up = $to : $down = $to;
                } else {
                    [$from, $to] = [0, $v];
                }
                $c->rect($x, min($y($from), $y($to)), $barWidth, abs($y($to) - $y($from)), $color);
                if ($o['values'] && ! $stacked) {
                    $c->label($x + $barWidth / 2, $v < 0 ? $y($to) + 8 : $y($to) - 7, max($barWidth, 40), 'center', $o['prefix'].($this->format)($v, $o['decimals']).$o['suffix'], 9, '#374151');
                }
            }
            if ($o['values'] && $stacked) {
                $c->label($left + $band * ($i + 0.5), $y($up) - 7, $band, 'center', $o['prefix'].($this->format)($up, $o['decimals']).$o['suffix'], 9, '#374151');
            }
        }
    }

    protected function pie(ChartCanvas $c, string $type, array $categories, array $serie, array $o, float $top): void
    {
        $values = array_map(fn ($v) => max(0, $v), $serie['values']);
        $total = array_sum($values);
        if ($total <= 0) {
            return;
        }
        $legendWidth = $o['legend'] ? min($o['width'] * 0.45, max(array_map(fn ($t) => mb_strlen($t), $categories)) * 6.5 + 70) : 0;
        $size = min($o['width'] - $legendWidth - 12, $o['height'] - $top - 6);
        $r = $size / 2;
        [$cx, $cy] = [6 + $r + max(0, ($o['width'] - $legendWidth - 12 - $size) / 2), $top + ($o['height'] - $top) / 2];
        $inner = $type === 'doughnut' ? $r * 0.55 : 0;

        $angle = -M_PI / 2;
        foreach ($values as $i => $v) {
            if ($v <= 0) {
                continue;
            }
            $sweep = $v / $total * 2 * M_PI;
            $color = $o['colors'][$i % count($o['colors'])];
            $c->slice($cx, $cy, $r, $inner, $angle, $angle + $sweep, $color);
            if ($o['values'] && $sweep > 0.3) {
                $mid = $angle + $sweep / 2;
                $at = $inner ? ($r + $inner) / 2 : $r * 0.62;
                $c->label($cx + cos($mid) * $at, $cy + sin($mid) * $at, 50, 'center', round($v / $total * 100).'%', 10, '#ffffff', true);
            }
            $angle += $sweep;
        }

        if ($o['legend']) {
            $x = $o['width'] - $legendWidth + 4;
            $rowHeight = min(18, ($o['height'] - $top) / max(1, count($categories)));
            $y = $cy - $rowHeight * count($categories) / 2 + $rowHeight / 2;
            foreach ($categories as $i => $category) {
                $c->rect($x, $y - 5, 10, 10, $o['colors'][$i % count($o['colors'])], 2);
                $c->label($x + 15, $y, $legendWidth - 22, 'left', $category.'  '.$o['prefix'].($this->format)($serie['values'][$i], $o['decimals']).$o['suffix'], 10, '#374151');
                $y += $rowHeight;
            }
        }
    }

    protected function legend(ChartCanvas $c, array $names, array $o, float $y): void
    {
        $widths = array_map(fn ($name) => mb_strlen($name) * 6.2 + 26, $names);
        $x = max(4, ($o['width'] - array_sum($widths)) / 2);
        foreach ($names as $i => $name) {
            $c->rect($x, $y - 5, 10, 10, $o['colors'][$i % count($o['colors'])], 2);
            $c->label($x + 14, $y, $widths[$i] - 14, 'left', $name, 10, '#374151');
            $x += $widths[$i];
        }
    }

    /**
     * Round axis bounds and step: 0–87 → 0–100 by 20.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    protected function scale(float $min, float $max): array
    {
        if ($max <= $min) {
            $max = $min + 1;
        }
        $raw = ($max - $min) / 5;
        $magnitude = 10 ** floor(log10($raw));
        $step = $magnitude * match (true) {
            $raw / $magnitude <= 1 => 1,
            $raw / $magnitude <= 2 => 2,
            $raw / $magnitude <= 2.5 => 2.5,
            $raw / $magnitude <= 5 => 5,
            default => 10,
        };

        return [floor($min / $step) * $step, ceil($max / $step) * $step, $step];
    }

    protected function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    protected function flag(mixed $value): bool
    {
        return ! in_array(strtolower((string) $value), ['0', 'false', 'no', 'off', ''], true);
    }
}
