<?php

namespace AnghelutaV\PdfTemplates;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Logic-less placeholder engine for HTML templates (single braces, safe: no PHP is executed).
 *
 *   {name} {client.name} {items.0.name}   escaped value, dot paths
 *   {price|number:2} {name|upper} {date|date:d.m.Y} {x|default:—} {text|nl2br} {html|raw}
 *   {#each items}...{@number} {name} {this}...{else}empty{/each}
 *   {#if paid}...{else}...{/if}   {#unless paid}...{/unless}   {@root.title} inside loops
 */
class TemplateEngine
{
    const OPEN = '/\{#(each|if|unless)\s+([^{}]+)\}/';

    const TAG = '/\{(?:#(each|if|unless)\s[^{}]*|\/(each|if|unless)|(else))\}/';

    const VAR = '/\{(@?[A-Za-z_][\w.@-]*)((?:\|[^{}|]+)*)\}/u';

    public function __construct(
        protected string $decimalSeparator = ',',
        protected string $thousandsSeparator = ' ',
    ) {}

    public function render(string $template, array $data): string
    {
        return $this->renderNodes($template, [$data], []);
    }

    /**
     * Data paths a template reads: [path => 'value'|'list'|'condition'], loop items as "items.*.name".
     * Inside a loop a plain {name} is assumed to belong to the loop item (the engine falls back to the root).
     */
    public function fields(string $template, string $prefix = ''): array
    {
        $fields = [];
        $pos = 0;
        $add = function (string $path, string $kind) use (&$fields, $prefix) {
            if ($path === 'else' || $path === 'this' || (str_starts_with($path, '@') && ! str_starts_with($path, '@root.'))) {
                return;
            }
            $full = match (true) {
                str_starts_with($path, '@root.') => substr($path, 6),
                str_starts_with($path, 'this.') => $prefix.substr($path, 5),
                default => $prefix.$path,
            };
            $fields[$full] ??= $kind;
        };
        $vars = function (string $chunk) use ($add) {
            preg_match_all(self::VAR, $chunk, $m);
            array_map(fn ($path) => $add($path, 'value'), $m[1]);
        };

        while (preg_match(self::OPEN, $template, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1];
            $type = $m[1][0];
            $path = trim($m[2][0]);
            [$body, $else, $end] = $this->splitBlock($template, $type, $start + strlen($m[0][0]));

            $vars(substr($template, $pos, $start - $pos));
            $add($path, $type === 'each' ? 'list' : 'condition');

            $itemPrefix = match (true) {
                $type !== 'each' => $prefix,
                $path === 'this' => rtrim($prefix, '.').'.*.',
                str_starts_with($path, '@root.') => substr($path, 6).'.*.',
                default => $prefix.$path.'.*.',
            };
            $fields += $this->fields($body, $itemPrefix) + $this->fields($else, $prefix);
            $pos = $end;
        }
        $vars(substr($template, $pos));

        return $fields;
    }

    // $scopes: data contexts, innermost last; $meta: loop variables (@index, @number, @first, @last)
    protected function renderNodes(string $tpl, array $scopes, array $meta): string
    {
        $out = '';
        $pos = 0;

        while (preg_match(self::OPEN, $tpl, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1];
            $type = $m[1][0];
            [$body, $else, $end] = $this->splitBlock($tpl, $type, $start + strlen($m[0][0]));

            $out .= $this->renderVars(substr($tpl, $pos, $start - $pos), $scopes, $meta);
            $value = $this->resolve(trim($m[2][0]), $scopes, $meta);

            if ($type === 'each') {
                $items = is_array($value) ? array_values($value) : [];
                $count = count($items);

                if (! $count) {
                    $out .= $this->renderNodes($else, $scopes, $meta);
                }
                foreach ($items as $i => $item) {
                    $out .= $this->renderNodes($body, [...$scopes, $item], [
                        '@index' => $i,
                        '@number' => $i + 1,
                        '@first' => $i === 0,
                        '@last' => $i === $count - 1,
                    ]);
                }
            } else {
                $truthy = ($this->truthy($value) xor $type === 'unless');
                $out .= $this->renderNodes($truthy ? $body : $else, $scopes, $meta);
            }

            $pos = $end;
        }

        return $out.$this->renderVars(substr($tpl, $pos), $scopes, $meta);
    }

    // Finds the matching {/type} (and a top level {else}); returns [body, else, offset after the close tag]
    protected function splitBlock(string $tpl, string $type, int $from): array
    {
        $depth = 0;
        $else = null;
        $pos = $from;

        while (preg_match(self::TAG, $tpl, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $tagStart = $m[0][1];
            $pos = $tagStart + strlen($m[0][0]);

            if (! empty($m[1][0])) {
                $depth++;
            } elseif (! empty($m[2][0])) {
                if ($depth === 0) {
                    if ($m[2][0] !== $type) {
                        throw new InvalidArgumentException("{#{$type}} closed by {/{$m[2][0]}}");
                    }

                    return $else
                        ? [substr($tpl, $from, $else[0] - $from), substr($tpl, $else[1], $tagStart - $else[1]), $pos]
                        : [substr($tpl, $from, $tagStart - $from), '', $pos];
                }
                $depth--;
            } elseif ($depth === 0 && ! $else) {
                $else = [$tagStart, $pos];
            }
        }

        throw new InvalidArgumentException("{#{$type}} without {/{$type}}");
    }

    protected function renderVars(string $tpl, array $scopes, array $meta): string
    {
        return preg_replace_callback(self::VAR, function ($m) use ($scopes, $meta) {
            $value = $this->resolve($m[1], $scopes, $meta);
            $raw = false;
            $nl2br = false;

            foreach (array_filter(explode('|', $m[2])) as $filter) {
                [$name, $arg] = array_pad(explode(':', $filter, 2), 2, null);
                match (trim($name)) {
                    'raw' => $raw = true,
                    'nl2br' => $nl2br = true,
                    default => $value = $this->filter(trim($name), $arg, $value),
                };
            }

            $value = $this->stringify($value);
            $value = $raw ? $value : e($value);

            return $nl2br ? nl2br($value) : $value;
        }, $tpl);
    }

    protected function filter(string $name, ?string $arg, mixed $value): mixed
    {
        return match ($name) {
            'upper' => mb_strtoupper($this->stringify($value)),
            'lower' => mb_strtolower($this->stringify($value)),
            'number' => is_numeric($value)
                ? number_format((float) $value, (int) ($arg ?? 0), $this->decimalSeparator, $this->thousandsSeparator)
                : $value,
            'date' => $this->formatDate($value, $arg ?? 'd.m.Y'),
            'default' => $this->stringify($value) === '' ? (string) $arg : $value,
            default => $value,
        };
    }

    protected function formatDate(mixed $value, string $format): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        try {
            return Carbon::parse($value)->format($format);
        } catch (Throwable) {
            return $value;
        }
    }

    protected function resolve(string $path, array $scopes, array $meta): mixed
    {
        if (array_key_exists($path, $meta)) {
            return $meta[$path];
        }

        $current = end($scopes);
        if ($path === 'this') {
            return $current;
        }
        if (str_starts_with($path, 'this.')) {
            return is_array($current) ? Arr::get($current, substr($path, 5)) : null;
        }
        if (str_starts_with($path, '@root.')) {
            return Arr::get($scopes[0], substr($path, 6));
        }

        // Innermost scope first, so loop items shadow the root data
        foreach (array_reverse($scopes) as $scope) {
            if (is_array($scope) && Arr::has($scope, $path)) {
                return Arr::get($scope, $path);
            }
        }

        return null;
    }

    protected function truthy(mixed $value): bool
    {
        return ! in_array($value, [null, false, '', '0', 'false', 0, []], true);
    }

    protected function stringify(mixed $value): string
    {
        return match (true) {
            $value === null, $value === false => '',
            $value === true => '1',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
