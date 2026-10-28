<?php

namespace AnghelutaV\PdfTemplates\Tests;

use AnghelutaV\PdfTemplates\TemplateEngine;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TemplateEngineTest extends TestCase
{
    protected TemplateEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new TemplateEngine(',', ' ');
    }

    public static function renderCases(): array
    {
        return [
            'value' => ['Hi {name}', ['name' => 'Ana'], 'Hi Ana'],
            'dot path' => ['{client.name}', ['client' => ['name' => 'Ion']], 'Ion'],
            'list index' => ['{items.1}', ['items' => ['a', 'b']], 'b'],
            'missing is empty' => ['[{nope}]', [], '[]'],
            'escaped' => ['{html}', ['html' => '<b>x</b>'], '&lt;b&gt;x&lt;/b&gt;'],
            'raw' => ['{html|raw}', ['html' => '<b>x</b>'], '<b>x</b>'],
            'number' => ['{n|number:2}', ['n' => 1234.5], '1 234,50'],
            'number non numeric untouched' => ['{n|number:2}', ['n' => 'abc'], 'abc'],
            'upper' => ['{s|upper}', ['s' => 'ăbc'], 'ĂBC'],
            'lower' => ['{s|lower}', ['s' => 'ABC'], 'abc'],
            'date' => ['{d|date:d.m.Y}', ['d' => '2026-10-27'], '27.10.2026'],
            'default' => ['{x|default:—}', ['x' => ''], '—'],
            'nl2br' => ['{t|nl2br}', ['t' => "a\n<b>"], "a<br />\n&lt;b&gt;"],
            'chained filters' => ['{s|lower|upper}', ['s' => 'Ab'], 'AB'],
            'each' => ['{#each items}{@number}:{name};{/each}', ['items' => [['name' => 'a'], ['name' => 'b']]], '1:a;2:b;'],
            'each this' => ['{#each tags}[{this}]{/each}', ['tags' => ['x', 'y']], '[x][y]'],
            'each else' => ['{#each tags}x{else}none{/each}', ['tags' => []], 'none'],
            'each first last' => ['{#each a}{#if @first}<{/if}{this}{#if @last}>{/if}{/each}', ['a' => [1, 2, 3]], '<123>'],
            'nested each' => ['{#each rows}{#each this}{this}{/each};{/each}', ['rows' => [[1, 2], [3]]], '12;3;'],
            'root inside loop' => ['{#each items}{@root.t}{name}{/each}', ['t' => 'T', 'items' => [['name' => 'a']]], 'Ta'],
            'loop item shadows root' => ['{#each items}{name}{/each}{name}', ['name' => 'root', 'items' => [['name' => 'item']]], 'itemroot'],
            'if true' => ['{#if paid}yes{else}no{/if}', ['paid' => true], 'yes'],
            'if false values' => ['{#if a}1{/if}{#if b}2{/if}{#if c}3{/if}{#if d}4{/if}', ['a' => '', 'b' => '0', 'c' => 'false', 'd' => []], ''],
            'unless' => ['{#unless paid}due{/unless}', ['paid' => false], 'due'],
            'css braces untouched' => ['.a{color:red} .b { margin: 0 }', [], '.a{color:red} .b { margin: 0 }'],
        ];
    }

    #[DataProvider('renderCases')]
    public function test_render(string $template, array $data, string $expected): void
    {
        $this->assertSame($expected, $this->engine->render($template, $data));
    }

    public function test_unclosed_block_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine->render('{#each items}x', []);
    }

    public function test_mismatched_block_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine->render('{#if a}x{/each}', []);
    }

    public function test_fields(): void
    {
        $fields = $this->engine->fields('{title} {#each items}{name} {@number} {@root.currency}{#each tags}{this}{/each}{/each} {#if paid}{paid_at}{/if}');

        $this->assertSame([
            'title' => 'value',
            'items' => 'list',
            'items.*.name' => 'value',
            'currency' => 'value',
            'items.*.tags' => 'list',
            'paid' => 'condition',
            'paid_at' => 'value',
        ], $fields);
    }
}
