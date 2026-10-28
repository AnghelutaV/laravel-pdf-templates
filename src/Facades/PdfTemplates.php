<?php

namespace AnghelutaV\PdfTemplates\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \AnghelutaV\PdfTemplates\Models\PdfTemplate find(\AnghelutaV\PdfTemplates\Models\PdfTemplate|string $template)
 * @method static \AnghelutaV\PdfTemplates\PendingPdf make(\AnghelutaV\PdfTemplates\Models\PdfTemplate|string $template, string|array|null $data = [])
 * @method static string render(\AnghelutaV\PdfTemplates\Models\PdfTemplate|string $template, string|array|null $data = [])
 * @method static \Illuminate\Http\Response download(\AnghelutaV\PdfTemplates\Models\PdfTemplate|string $template, string|array|null $data = [], ?string $filename = null)
 * @method static \Illuminate\Http\Response inline(\AnghelutaV\PdfTemplates\Models\PdfTemplate|string $template, string|array|null $data = [], ?string $filename = null)
 * @method static array options(?string $mode = null)
 * @method static array fields(\AnghelutaV\PdfTemplates\Models\PdfTemplate|string $template)
 * @method static bool wysiwygOnServer()
 *
 * @see \AnghelutaV\PdfTemplates\PdfTemplates
 */
class PdfTemplates extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AnghelutaV\PdfTemplates\PdfTemplates::class;
    }
}
