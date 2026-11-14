<?php

namespace AnghelutaV\PdfTemplates;

use Illuminate\Contracts\Support\Htmlable;
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
 *   {#if status == "paid" and total > 100}   {#if not items|length}   {#if type in types}   {#if note is defined}
 */
class TemplateEngine
{
    const OPEN = '/\{#(each|if|unless)\s+([^{}]+)\}/';

    const TAG = '/\{(?:#(each|if|unless)\s[^{}]*|\/(each|if|unless)|(else))\}/';

    const VAR = '/\{(@?[A-Za-z_][\w.@-]*)((?:\|[^{}|]+)*)\}/u';

    const PATH = '/^@?[A-Za-z_][\w.@-]*$/';

    // An expression to print: {= qty * price|number:2} (what {{ qty * price }} becomes), or a plain {path|filters}
    const OUT = '/\{=\s*((?:[^{}"\']|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')+?)\s*\}|\{(@?[A-Za-z_][\w.@-]*)((?:\|[^{}|]+)*)\}/u';

    // Expression tokens: string, number, operator, word (a data path, a function or a keyword)
    const TOKEN = '/\G\s*(?:("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|(\d+(?:\.\d+)?)|(==|!=|>=|<=|&&|\|\||[-+*\/%<>!()?:,|])|(@?[A-Za-z_][\w@]*(?:\.[\w@]+)*))/u';

    const FUNCTIONS = ['sum', 'avg', 'min', 'max', 'count', 'length', 'round', 'abs', 'floor', 'ceil', 'upper', 'lower', 'trim', 'concat', 'default', 'if', 'chart'];

    const KEYWORDS = ['and', 'or', 'not', 'in', 'is', 'true', 'false', 'none', 'null'];

    public function __construct(
        protected string $decimalSeparator = ',',
        protected string $thousandsSeparator = ' ',
    ) {}

    public function render(string $template, array $data): string
    {
        return $this->renderNodes($this->normalize($template), [$data], []);
    }

    /**
     * Jinja / Handlebars-style templates (APITemplate.io, Twig…) in this engine's syntax, so they can be pasted in:
     *   {{ name }} {{ client.name|upper }} {{ x|default('—') }} {{ html|safe }} {{ price|round(2) }}
     *   {% if paid %}…{% else %}…{% endif %}   {% if not paid %}…{% endif %}
     *   {% for item in items %}{{ loop.index }}. {{ item.name }}{% else %}none{% endfor %}
     *   {% if status == "paid" and total > 100 %}…{% elif status == "draft" %}…{% else %}…{% endif %}
     */
    public function normalize(string $template): string
    {
        if (! str_contains($template, '{{') && ! str_contains($template, '{%')) {
            return $template;
        }

        $template = $this->normalizeLoops($template);

        // {% elif %} opens a nested block inside the {else}; $open counts the blocks each {% endif %} has to close
        $open = [];
        $template = (string) preg_replace_callback('/\{%-?\s*(if|elif|elseif|else|endif|endfor)\b\s*(.*?)\s*-?%\}/s', function (array $m) use (&$open) {
            $condition = trim($m[2]);
            $tag = preg_match('/^not\s+(@?[A-Za-z_][\w.@-]*)$/', $condition, $not) ? '{#unless '.$not[1].'}' : '{#if '.$condition.'}';

            switch ($m[1]) {
                case 'if':
                    $open[] = 1;

                    return $tag;
                case 'elif':
                case 'elseif':
                    if (! $open) {
                        return $m[0];
                    }
                    $open[count($open) - 1]++;

                    return '{else}'.$tag;
                case 'endif':
                    return str_repeat('{/if}', array_pop($open) ?? 1);
                case 'endfor':
                    return '{/each}';
                default:
                    return '{else}';
            }
        }, $template);

        // {{ path|filter(arg) }} → {path|filter:arg}, {{ qty * price|round(2) }} → {= qty * price|number:2};
        // a block tag that closes an {#unless} is fixed below
        $template = (string) preg_replace_callback('/\{\{-?\s*(.+?)\s*-?\}\}/s', function (array $m) {
            $parts = array_map('trim', $this->splitPipes($m[1]));
            $path = array_shift($parts);
            $expression = ! preg_match('/^@?[A-Za-z_][\w.@-]*$/', $path);
            if ($expression && preg_match('/[{}]/', $path)) {
                return $m[0];
            }
            $filters = array_map(function (string $filter) {
                if (! preg_match('/^(\w+)\s*(?:\((.*)\))?$/s', $filter, $f)) {
                    return $filter;
                }
                $arg = isset($f[2]) ? trim($f[2], " \t\"'") : null;

                return match ($f[1]) {
                    'safe' => 'raw',
                    'round' => 'number'.($arg !== null && $arg !== '' ? ':'.$arg : ''),
                    'capitalize', 'title' => 'upper',
                    default => $f[1].($arg !== null && $arg !== '' ? ':'.$arg : ''),
                };
            }, $parts);

            return '{'.($expression ? '= ' : '').$path.($filters ? '|'.implode('|', $filters) : '').'}';
        }, $template);

        return $this->closeUnless($template);
    }

    // "a || b | upper | default:x" → ["a || b ", " upper ", " default:x"]: splits on a single | outside quotes and
    // parentheses (|| is "or")
    protected function splitPipes(string $source): array
    {
        $parts = [];
        $quote = null;
        $depth = 0;
        $start = 0;
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            if ($quote) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === '|' && $depth === 0) {
                if (($source[$i + 1] ?? '') === '|') {
                    $i++;

                    continue;
                }
                $parts[] = substr($source, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($source, $start);

        return $parts;
    }

    // {% for item in items %} … {% endfor %} → {#each items} … {/each}, with item.x → x, item → this, loop.* → @…
    protected function normalizeLoops(string $template): string
    {
        $open = '/\{%-?\s*for\s+(\w+)\s+in\s+([\w.@]+)\s*-?%\}/';
        // Innermost loop first: a {% for %} with no other {% for %} before its {% endfor %}
        while (preg_match($open, $template, $m, PREG_OFFSET_CAPTURE)) {
            $offset = 0;
            $found = null;
            while (preg_match($open, $template, $candidate, PREG_OFFSET_CAPTURE, $offset)) {
                $bodyStart = $candidate[0][1] + strlen($candidate[0][0]);
                if (! preg_match('/\{%-?\s*endfor\s*-?%\}/', $template, $end, PREG_OFFSET_CAPTURE, $bodyStart)) {
                    return $template;
                }
                $next = preg_match($open, $template, $inner, PREG_OFFSET_CAPTURE, $bodyStart) ? $inner[0][1] : PHP_INT_MAX;
                if ($next > $end[0][1]) {
                    $found = [$candidate, $bodyStart, $end];
                    break;
                }
                $offset = $next;
            }
            if (! $found) {
                return $template;
            }
            [$candidate, $bodyStart, $end] = $found;
            $alias = $candidate[1][0];
            $body = substr($template, $bodyStart, $end[0][1] - $bodyStart);
            // Also the {#each item.tags} of an inner loop that was converted before this one
            $body = (string) preg_replace_callback('/\{\{.*?\}\}|\{%.*?%\}|\{#(?:each|if|unless)\s[^{}]*\}/s', function (array $tag) use ($alias) {
                $text = preg_replace('/\b'.preg_quote($alias, '/').'\.(?=[A-Za-z_])/', '', $tag[0]);
                $text = preg_replace('/\b'.preg_quote($alias, '/').'\b(?![\w.(])/', 'this', (string) $text);

                return strtr((string) $text, ['loop.index0' => '@index', 'loop.index' => '@number', 'loop.first' => '@first', 'loop.last' => '@last']);
            }, $body);
            $template = substr($template, 0, $candidate[0][1]).'{#each '.$candidate[2][0].'}'.$body.'{/each}'
                .substr($template, $end[0][1] + strlen($end[0][0]));
        }

        return $template;
    }

    // {% if not x %}…{% endif %} became {#unless x}…{/if}: the matching close tag must be {/unless}
    protected function closeUnless(string $template): string
    {
        if (! str_contains($template, '{#unless ')) {
            return $template;
        }
        $stack = [];

        return (string) preg_replace_callback(self::TAG, function (array $m) use (&$stack) {
            if (! empty($m[1])) {
                $stack[] = $m[1];
            } elseif (! empty($m[2])) {
                $type = array_pop($stack);

                return $type === 'unless' && $m[2] === 'if' ? '{/unless}' : $m[0];
            }

            return $m[0];
        }, $template);
    }

    /**
     * Data paths a template reads: [path => 'value'|'list'|'condition'], loop items as "items.*.name".
     * Inside a loop a plain {name} is assumed to belong to the loop item (the engine falls back to the root).
     */
    public function fields(string $template, string $prefix = ''): array
    {
        $template = $this->normalize($template);
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
            preg_match_all(self::OUT, $chunk, $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $paths = ($match[2] ?? '') !== '' ? [$match[2]] : $this->conditionPaths($this->splitPipes($match[1])[0]);
                array_map(fn ($path) => $add($path, 'value'), $paths);
            }
        };

        while (preg_match(self::OPEN, $template, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1];
            $type = $m[1][0];
            $path = trim($m[2][0]);
            [$body, $else, $end] = $this->splitBlock($template, $type, $start + strlen($m[0][0]));

            $vars(substr($template, $pos, $start - $pos));
            if ($type === 'each') {
                $add($path, 'list');
            } else {
                array_map(fn ($conditionPath) => $add($conditionPath, 'condition'), $this->conditionPaths($path));
            }

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
            $value = $type === 'each'
                ? $this->resolve(trim($m[2][0]), $scopes, $meta)
                : $this->condition(trim($m[2][0]), $scopes, $meta);

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
        // One pass for {path|filters} and {= expression|filters}: a value from the data is never read as a placeholder
        return preg_replace_callback(self::OUT, function ($m) use ($scopes, $meta) {
            if (($m[2] ?? '') !== '') {
                $value = $this->resolve($m[2], $scopes, $meta);
                $filters = array_filter(explode('|', $m[3]));
            } else {
                $parts = $this->splitPipes($m[1]);
                $value = $this->condition(trim((string) array_shift($parts)), $scopes, $meta);
                $filters = array_filter(array_map('trim', $parts));
            }
            $raw = false;
            $nl2br = false;

            foreach ($filters as $filter) {
                [$name, $arg] = array_pad(explode(':', $filter, 2), 2, null);
                match (trim($name)) {
                    'raw' => $raw = true,
                    'nl2br' => $nl2br = true,
                    default => $value = $this->filter(trim($name), $arg, $value),
                };
            }

            // qrcode / barcode / chart(): markup made here, never escaped
            if ($value instanceof Htmlable) {
                return $value->toHtml();
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
            'number', 'round' => is_numeric($value)
                ? number_format((float) $value, (int) ($arg ?? 0), $this->decimalSeparator, $this->thousandsSeparator)
                : $value,
            'date' => $this->formatDate($value, $arg ?? 'd.m.Y'),
            'default' => $this->stringify($value) === '' ? (string) $arg : $value,
            'length', 'count' => is_array($value) ? count($value) : mb_strlen($this->stringify($value)),
            'trim' => trim($this->stringify($value)),
            'abs' => is_numeric($value) ? abs((float) $value) : $value,
            'qrcode', 'qr' => app(Codes::class)->qr($value, $arg),
            'barcode' => app(Codes::class)->barcode($value, $arg),
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

    /**
     * Value of an {#if} / {#unless} condition: a data path, or an expression with
     * == != > >= < <=, and / or / not, in, "is [not] defined|none|empty", parentheses, |length |lower |upper |trim.
     */
    protected function condition(string $expression, array $scopes, array $meta): mixed
    {
        if (preg_match(self::PATH, $expression) && ! in_array(strtolower($expression), self::KEYWORDS, true)) {
            return $this->resolve($expression, $scopes, $meta);
        }

        $tokens = $this->tokens($expression);
        $i = 0;
        $value = $this->parseTernary($tokens, $i, $scopes, $meta);
        if ($i < count($tokens)) {
            throw new InvalidArgumentException("Invalid expression: {$expression}");
        }

        return $value;
    }

    // condition ? yes : no (the lowest priority)
    protected function parseTernary(array $tokens, int &$i, array $scopes, array $meta): mixed
    {
        $condition = $this->parseOr($tokens, $i, $scopes, $meta);
        if (! $this->accept($tokens, $i, '?')) {
            return $condition;
        }
        $yes = $this->parseTernary($tokens, $i, $scopes, $meta);
        if (! $this->accept($tokens, $i, ':')) {
            throw new InvalidArgumentException('Missing ":" after "?"');
        }
        $no = $this->parseTernary($tokens, $i, $scopes, $meta);

        return $this->truthy($condition) ? $yes : $no;
    }

    // + adds numbers and joins texts, - subtracts
    protected function parseAdditive(array $tokens, int &$i, array $scopes, array $meta): mixed
    {
        $left = $this->parseMultiplicative($tokens, $i, $scopes, $meta);
        while ($operator = $this->accept($tokens, $i, '+', '-')) {
            $right = $this->parseMultiplicative($tokens, $i, $scopes, $meta);
            $left = match (true) {
                $operator === '-' => $this->number($left) - $this->number($right),
                is_numeric($left) && is_numeric($right) => $left + $right,
                default => $this->stringify($left).$this->stringify($right),
            };
        }

        return $left;
    }

    // * / % (dividing by 0 gives 0, not an error)
    protected function parseMultiplicative(array $tokens, int &$i, array $scopes, array $meta): mixed
    {
        $left = $this->parseUnary($tokens, $i, $scopes, $meta);
        while ($operator = $this->accept($tokens, $i, '*', '/', '%')) {
            $right = $this->number($this->parseUnary($tokens, $i, $scopes, $meta));
            $left = match (true) {
                $operator === '*' => $this->number($left) * $right,
                $right == 0 => 0,
                $operator === '/' => $this->number($left) / $right,
                default => fmod($this->number($left), $right),
            };
        }

        return $left;
    }

    protected function parseUnary(array $tokens, int &$i, array $scopes, array $meta): mixed
    {
        if ($this->accept($tokens, $i, '-')) {
            return -$this->number($this->parseUnary($tokens, $i, $scopes, $meta));
        }

        return $this->parseOperand($tokens, $i, $scopes, $meta);
    }

    protected function number(mixed $value): int|float
    {
        return is_numeric($value) ? $value + 0 : 0;
    }

    // sum(items, "total") / sum(items, "qty * price"): the value of each row (a key, else a formula on the row)
    protected function column(mixed $list, mixed $key, array $scopes, array $meta): array
    {
        $rows = is_array($list) ? array_values($list) : [];
        if ($key === null) {
            return $rows;
        }

        return array_map(function ($row) use ($key, $scopes, $meta) {
            if (is_array($row) && array_key_exists((string) $key, $row)) {
                return $row[(string) $key];
            }
            if (! is_string($key) || ctype_digit($key)) {
                return null;
            }
            try {
                return $this->condition($key, [...$scopes, $row], $meta);
            } catch (InvalidArgumentException) {
                return null;
            }
        }, $rows);
    }

    protected function call(string $name, array $args, array $scopes, array $meta): mixed
    {
        [$first, $second] = [$args[0] ?? null, $args[1] ?? null];
        $numbers = fn () => array_map(fn ($value) => $this->number($value), $this->column($first, $second, $scopes, $meta));

        return match ($name) {
            'sum' => array_sum($numbers()),
            'avg' => ($values = $numbers()) ? array_sum($values) / count($values) : 0,
            'min' => ($values = $numbers()) ? min($values) : 0,
            'max' => ($values = $numbers()) ? max($values) : 0,
            'count', 'length' => is_array($first) ? count($first) : mb_strlen($this->stringify($first)),
            'round' => round($this->number($first), (int) $this->number($second ?? 0)),
            'abs' => abs($this->number($first)),
            'floor' => floor($this->number($first)),
            'ceil' => ceil($this->number($first)),
            'upper' => mb_strtoupper($this->stringify($first)),
            'lower' => mb_strtolower($this->stringify($first)),
            'trim' => trim($this->stringify($first)),
            'concat' => implode('', array_map(fn ($value) => $this->stringify($value), $args)),
            'default' => $this->stringify($first) === '' ? ($second ?? '') : $first,
            'if' => $this->truthy($first) ? $second : ($args[2] ?? ''),
            // chart("bar", sales, "month", "total", "title='…' width=480"), see Chart
            'chart' => (new Chart(fn (float $n, int $decimals) => number_format($n, $decimals, $this->decimalSeparator, $this->thousandsSeparator)))
                ->html($first, $second, $args[2] ?? null, $args[3] ?? null, $args[4] ?? null),
            default => throw new InvalidArgumentException("Unknown function: {$name}()"),
        };
    }

    // [[type, value], …] with type str | num | op | word
    protected function tokens(string $expression): array
    {
        $tokens = [];
        $pos = 0;
        $expression = rtrim($expression);

        while ($pos < strlen($expression)) {
            if (! preg_match(self::TOKEN, $expression, $m, 0, $pos)) {
                throw new InvalidArgumentException("Invalid condition: {$expression}");
            }
            $pos += strlen($m[0]);
            $tokens[] = match (true) {
                $m[1] !== '' => ['str', stripslashes(substr($m[1], 1, -1))],
                $m[2] !== '' => ['num', str_contains($m[2], '.') ? (float) $m[2] : (int) $m[2]],
                $m[3] !== '' => ['op', $m[3]],
                default => in_array(strtolower($m[4]), self::KEYWORDS, true) ? ['op', strtolower($m[4])] : ['word', $m[4]],
            };
        }

        return $tokens;
    }

    // Data paths a condition reads (not keywords, filter names or "is …" test names)
    protected function conditionPaths(string $expression): array
    {
        if (preg_match(self::PATH, $expression)) {
            return [$expression];
        }

        try {
            $tokens = $this->tokens($expression);
        } catch (InvalidArgumentException) {
            return [];
        }
        $paths = [];
        foreach ($tokens as $i => [$type, $value]) {
            $before = $tokens[$i - 1] ?? null;
            $test = $before === ['op', 'is'] || ($before === ['op', 'not'] && ($tokens[$i - 2] ?? null) === ['op', 'is']);
            $call = ($tokens[$i + 1] ?? null) === ['op', '('];
            if ($type === 'word' && $before !== ['op', '|'] && ! $test && ! $call) {
                $paths[] = $value;
            }
        }

        return array_values(array_unique($paths));
    }

    protected function accept(array $tokens, int &$i, string ...$operators): ?string
    {
        if (($tokens[$i][0] ?? null) === 'op' && in_array($tokens[$i][1], $operators, true)) {
            return $tokens[$i++][1];
        }

        return null;
    }

    protected function parseOr(array $tokens, int &$i, array $scopes, array $meta): mixed
    {
        $left = $this->parseAnd($tokens, $i, $scopes, $meta);
        while ($this->accept($tokens, $i, 'or', '||')) {
            $right = $this->parseAnd($tokens, $i, $scopes, $meta);
            $left = $this->truthy($left) || $this->truthy($right);
        }

        return $left;
    }

    protected function parseAnd(array $tokens, int &$i, array $scopes, array $meta): mixed
    {
        $left = $this->parseNot($tokens, $i, $scopes, $meta);
        while ($this->accept($tokens, $i, 'and', '&&')) {
            $right = $this->parseNot($tokens, $i, $scopes, $meta);
            $left = $this->truthy($left) && $this->truthy($right);
        }

        return $left;
    }

    protected function parseNot(array $tokens, int &$i, array $scopes, array $meta): mixed
    {
        if ($this->accept($tokens, $i, 'not', '!')) {
            return ! $this->truthy($this->parseNot($tokens, $i, $scopes, $meta));
        }

        return $this->parseComparison($tokens, $i, $scopes, $meta);
    }

    protected function parseComparison(array $tokens, int &$i, array $scopes, array $meta): mixed
    {
        $left = $this->parseAdditive($tokens, $i, $scopes, $meta);

        while (true) {
            if ($operator = $this->accept($tokens, $i, '==', '!=', '>=', '<=', '>', '<')) {
                $left = $this->compare($left, $operator, $this->parseAdditive($tokens, $i, $scopes, $meta));
            } elseif ($this->accept($tokens, $i, 'in')) {
                $left = $this->contains($this->parseAdditive($tokens, $i, $scopes, $meta), $left);
            } elseif (($tokens[$i] ?? null) === ['op', 'not'] && ($tokens[$i + 1] ?? null) === ['op', 'in']) {
                $i += 2;
                $left = ! $this->contains($this->parseAdditive($tokens, $i, $scopes, $meta), $left);
            } elseif ($this->accept($tokens, $i, 'is')) {
                $negate = (bool) $this->accept($tokens, $i, 'not');
                $test = strtolower((string) ($tokens[$i][1] ?? ''));
                $i++;
                $result = match ($test) {
                    'defined' => $left !== null,
                    'undefined', 'none', 'null' => $left === null,
                    'empty' => ! $this->truthy($left),
                    default => throw new InvalidArgumentException("Unknown test: is {$test}"),
                };
                $left = $negate ? ! $result : $result;
            } else {
                return $left;
            }
        }
    }

    protected function parseOperand(array $tokens, int &$i, array $scopes, array $meta): mixed
    {
        [$type, $token] = $tokens[$i] ?? throw new InvalidArgumentException('Incomplete condition');
        $i++;

        if ($type === 'op') {
            $value = match ($token) {
                'true' => true,
                'false' => false,
                'none', 'null' => null,
                '(' => $this->parseTernary($tokens, $i, $scopes, $meta),
                default => throw new InvalidArgumentException("Unexpected \"{$token}\" in an expression"),
            };
            if ($token === '(' && ! $this->accept($tokens, $i, ')')) {
                throw new InvalidArgumentException('Missing ")" in an expression');
            }
        } elseif ($type === 'word' && ($tokens[$i] ?? null) === ['op', '(']) {
            // A function call: sum(items, "qty * price"), round(total, 2), concat(a, " ", b)…
            $i++;
            $args = [];
            if (! $this->accept($tokens, $i, ')')) {
                do {
                    $args[] = $this->parseTernary($tokens, $i, $scopes, $meta);
                } while ($this->accept($tokens, $i, ','));
                if (! $this->accept($tokens, $i, ')')) {
                    throw new InvalidArgumentException("Missing \")\" after {$token}(");
                }
            }
            $value = $this->call(strtolower($token), $args, $scopes, $meta);
        } else {
            $value = $type === 'word' ? $this->resolve($token, $scopes, $meta) : $token;
        }

        while ($this->accept($tokens, $i, '|')) {
            $filter = strtolower((string) ($tokens[$i][1] ?? ''));
            $i++;
            $value = match ($filter) {
                'length', 'count' => is_array($value) ? count($value) : mb_strlen($this->stringify($value)),
                'lower' => mb_strtolower($this->stringify($value)),
                'upper' => mb_strtoupper($this->stringify($value)),
                'trim' => trim($this->stringify($value)),
                default => throw new InvalidArgumentException("Unknown filter in a condition: {$filter}"),
            };
        }

        return $value;
    }

    // Numbers compare as numbers, true / false by truthiness, everything else as text
    protected function compare(mixed $left, string $operator, mixed $right): bool
    {
        if (is_bool($left) || is_bool($right)) {
            [$left, $right] = [(int) $this->truthy($left), (int) $this->truthy($right)];
        } elseif (is_numeric($left) && is_numeric($right)) {
            [$left, $right] = [(float) $left, (float) $right];
        } else {
            [$left, $right] = [$this->stringify($left), $this->stringify($right)];
        }

        return match ($operator) {
            '==' => $left === $right,
            '!=' => $left !== $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            default => $left <= $right,
        };
    }

    // "x in list" for a list, "x in text" for a substring
    protected function contains(mixed $haystack, mixed $needle): bool
    {
        if (is_array($haystack)) {
            return in_array($this->stringify($needle), array_map(fn ($item) => $this->stringify($item), $haystack), true);
        }
        $needle = $this->stringify($needle);

        return $needle !== '' && str_contains($this->stringify($haystack), $needle);
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
            // 0.1 + 0.2 → 0.3, 10 / 4 → 2.5, 6.0 → 6
            is_float($value) => is_finite($value) ? (string) round($value, 10) : '',
            default => (string) $value,
        };
    }
}
