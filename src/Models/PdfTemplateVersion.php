<?php

namespace AnghelutaV\PdfTemplates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved state of a template, taken right before a save changed its content (config history).
 *
 * @property int $id
 * @property int $template_id
 * @property string $name
 * @property ?string $schema
 * @property ?string $html
 * @property ?string $css
 * @property ?string $paper
 * @property ?string $orientation
 * @property ?array $settings
 * @property ?string $sample_data
 * @property ?string $author
 * @property ?Carbon $created_at
 */
class PdfTemplateVersion extends Model
{
    // The content a version keeps (and puts back on restore)
    const CONTENT = ['name', 'schema', 'html', 'css', 'paper', 'orientation', 'settings', 'sample_data'];

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
    ];

    public function getTable()
    {
        return config('pdf-templates.history.table', 'pdf_template_versions');
    }

    /** @return BelongsTo<PdfTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PdfTemplate::class, 'template_id');
    }
}
