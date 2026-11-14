<?php

namespace AnghelutaV\PdfTemplates\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An API key of one client. Only its SHA-256 hash is stored; the key is shown once, when it is created.
 *
 * @property int $id
 * @property string $name
 * @property string $key_hash
 * @property string $prefix
 * @property ?int $per_minute requests per minute, null = no limit
 * @property ?int $per_month documents per calendar month, null = no limit
 * @property ?Carbon $last_used_at
 * @property ?Carbon $revoked_at
 * @property ?Carbon $created_at
 */
class PdfTemplateApiKey extends Model
{
    const PREFIX = 'pt_';

    protected $guarded = ['id'];

    protected $hidden = ['key_hash'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'per_minute' => 'integer',
        'per_month' => 'integer',
    ];

    public function getTable()
    {
        return config('pdf-templates.api.keys_table', 'pdf_template_api_keys');
    }

    /**
     * A new key for `$name`: [the model, the plain key to give to the client].
     *
     * @return array{0: self, 1: string}
     */
    public static function generate(string $name): array
    {
        $plain = self::PREFIX.Str::random(40);
        $key = static::create(['name' => $name, 'key_hash' => hash('sha256', $plain), 'prefix' => substr($plain, 0, 10)]);

        return [$key, $plain];
    }

    // The active key matching a plain key, or null
    public static function findByKey(string $plain): ?self
    {
        if ($plain === '') {
            return null;
        }

        return static::active()->where('key_hash', hash('sha256', $plain))->first();
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    // Documents rendered with this key since the 1st of the month (failed renders do not count): the monthly limit
    public function documentsThisMonth(): int
    {
        return (int) $this->renders()
            ->where('created_at', '>=', now()->startOfMonth())
            ->where('status', '!=', PdfTemplateRender::FAILED)
            ->sum('documents');
    }

    /** @return HasMany<PdfTemplateRender, $this> */
    public function renders(): HasMany
    {
        return $this->hasMany(PdfTemplateRender::class, 'api_key_id');
    }
}
