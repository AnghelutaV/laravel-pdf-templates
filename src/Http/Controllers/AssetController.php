<?php

namespace AnghelutaV\PdfTemplates\Http\Controllers;

use AnghelutaV\PdfTemplates\PdfTemplatesServiceProvider;
use Illuminate\Routing\Controller;

// Serves the package JS/CSS straight from resources/assets, so there is nothing to publish
class AssetController extends Controller
{
    const TYPES = [
        'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
    ];

    public function __invoke(string $file)
    {
        $path = realpath(PdfTemplatesServiceProvider::assetPath($file));
        $type = self::TYPES[pathinfo($file, PATHINFO_EXTENSION)] ?? null;
        abort_unless($path && $type, 404);

        return response()->file($path, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
