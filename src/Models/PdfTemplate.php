<?php

namespace AnghelutaV\PdfTemplates\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property ?string $category optional group, used to filter the list
 * @property string $slug
 * @property string $mode wysiwyg|html
 * @property ?string $schema pdfme template JSON (WYSIWYG)
 * @property ?string $html
 * @property ?string $css
 * @property string $paper
 * @property string $orientation
 * @property ?array $settings HTML mode page setup: margins, header, footer, word_break (see PageSetup)
 * @property ?string $sample_data JSON
 * @property bool $active
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PdfTemplate extends Model
{
    const MODE_WYSIWYG = 'wysiwyg';

    const MODE_HTML = 'html';

    const MODES = [self::MODE_WYSIWYG, self::MODE_HTML];

    const PAPERS = ['a4', 'a5', 'a3', 'letter', 'legal'];

    const ORIENTATIONS = ['portrait', 'landscape'];

    // Empty A4 page for a new WYSIWYG template (pdfme BlankPdf)
    const BLANK_SCHEMA = '{"basePdf":{"width":210,"height":297,"padding":[10,10,10,10]},"schemas":[[]]}';

    const STARTER_HTML = <<<'HTML'
<h1>{title}</h1>
<p>Client: <strong>{client.name}</strong>, {client.address|default:—}</p>

<table class="items">
    <thead><tr><th>#</th><th>Item</th><th class="num">Total</th></tr></thead>
    <tbody>
    {#each items}
        <tr><td>{@number}</td><td>{name}</td><td class="num">{total|number:2} €</td></tr>
    {else}
        <tr><td colspan="3">No items</td></tr>
    {/each}
    </tbody>
</table>

{#if notes}<p class="notes">{notes|nl2br}</p>{/if}
HTML;

    const STARTER_CSS = <<<'CSS'
@page { margin: 18mm 16mm; }
body { font-size: 10pt; color: #1b3240; }
h1 { font-size: 20pt; margin: 0 0 8px; }
.items { width: 100%; border-collapse: collapse; margin-top: 12px; }
.items th { background: #1b3240; color: #fff; text-align: left; padding: 5px; }
.items td { border-bottom: 1px solid #d5dde3; padding: 5px; }
.num { text-align: right; }
.notes { margin-top: 14px; color: #5b6670; }
CSS;

    const STARTER_SAMPLE = <<<'JSON'
{
    "title": "Test document",
    "client": { "name": "John Smith", "address": "1 Main Street" },
    "items": [
        { "name": "Wall plastering", "total": 1140 },
        { "name": "Painting", "total": 480 }
    ],
    "notes": "First line\nSecond line"
}
JSON;

    protected $guarded = ['id'];

    protected $casts = [
        'active' => 'boolean',
        'settings' => 'array',
    ];

    public function getTable()
    {
        return config('pdf-templates.table', parent::getTable());
    }

    /** @return HasMany<PdfTemplateVersion, $this> newest first */
    public function versions(): HasMany
    {
        return $this->hasMany(PdfTemplateVersion::class, 'template_id')->latest('id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $template) {
            if (! $template->slug) {
                $template->slug = static::uniqueSlug($template->name, $template->id);
            }
        });

        // History: the state before a save that changes the content is kept (config history.keep, 0 = off)
        static::updating(function (self $template) {
            $content = array_diff(PdfTemplateVersion::CONTENT, ['name', 'sample_data']);
            $keep = (int) config('pdf-templates.history.keep', 50);
            if ($keep <= 0 || ! $template->isDirty($content)) {
                return;
            }

            $original = [];
            foreach (PdfTemplateVersion::CONTENT as $key) {
                $original[$key] = $template->getOriginal($key);
            }
            $template->versions()->create($original + [
                'author' => static::author(),
                'created_at' => now(),
            ]);

            // Only the newest $keep versions stay
            $ids = $template->versions()->skip($keep)->take(PHP_INT_MAX)->pluck('id');
            if ($ids->isNotEmpty()) {
                PdfTemplateVersion::whereKey($ids)->delete();
            }
        });

        static::deleting(fn (self $template) => $template->versions()->delete());
    }

    // Who saved: the user's name or e-mail, when someone is signed in
    protected static function author(): ?string
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }
        $name = $user->getAttribute('name') ?? $user->getAttribute('email') ?? $user->getAuthIdentifier();

        return $name === null ? null : mb_substr((string) $name, 0, 255);
    }

    // Puts a version back; the current state becomes a version itself (the update keeps it)
    public function restore(PdfTemplateVersion $version): void
    {
        // paper / orientation are required columns: a version without them keeps the current ones
        $this->update(array_filter(
            $version->only(PdfTemplateVersion::CONTENT),
            fn ($value, $key) => $value !== null || ! in_array($key, ['name', 'paper', 'orientation'], true),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'template';
        $slug = $base;
        $i = 1;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.++$i;
        }

        return $slug;
    }

    // New template with the starter content of its mode
    public static function makeStarter(string $name, string $mode, ?string $description = null): self
    {
        return new self([
            'name' => $name,
            'mode' => $mode,
            'description' => $description,
        ] + ($mode === self::MODE_HTML
            ? ['html' => self::STARTER_HTML, 'css' => self::STARTER_CSS, 'sample_data' => self::STARTER_SAMPLE]
            : ['schema' => self::BLANK_SCHEMA, 'sample_data' => '{}']));
    }

    /** @return list<string> the categories in use, A–Z (a category is optional free text) */
    public static function categories(): array
    {
        return static::query()->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')->pluck('category')->all();
    }

    public function isHtml(): bool
    {
        return $this->mode === self::MODE_HTML;
    }

    public function scopeSearch(Builder $query, ?string $word): Builder
    {
        return $query->when($word, fn ($q) => $q->where(fn ($q) => $q
            ->where('name', 'LIKE', "%{$word}%")
            ->orWhere('slug', 'LIKE', "%{$word}%")));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
