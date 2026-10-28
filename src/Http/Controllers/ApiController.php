<?php

namespace AnghelutaV\PdfTemplates\Http\Controllers;

use AnghelutaV\PdfTemplates\Exceptions\RenderException;
use AnghelutaV\PdfTemplates\Models\PdfTemplate;
use AnghelutaV\PdfTemplates\PdfTemplates;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Throwable;

// POST {api.prefix}/{slug}, header X-API-KEY, body = JSON data. Query: ?download=1, ?filename=name
class ApiController extends Controller
{
    public function __invoke(Request $request, string $slug, PdfTemplates $pdf)
    {
        $key = (string) config('pdf-templates.api.key');
        if ($key === '') {
            return response()->json(['error' => 'PDF API is disabled (PDF_TEMPLATES_API_KEY is not set).'], 403);
        }
        if (! hash_equals($key, (string) $request->header('X-API-KEY'))) {
            return response()->json(['error' => 'Invalid API key.'], 401);
        }

        $template = PdfTemplate::active()->where('slug', $slug)->first();
        if (! $template) {
            return response()->json(['error' => "Template '{$slug}' not found or inactive."], 404);
        }

        try {
            $filename = $request->query('filename', $slug);

            return $request->boolean('download')
                ? $pdf->download($template, $request->getContent(), $filename)
                : $pdf->inline($template, $request->getContent(), $filename);
        } catch (Throwable $e) {
            // Bad JSON / template syntax is the caller's problem; anything else is worth a log entry
            if (! $e instanceof InvalidArgumentException && ! $e instanceof RenderException) {
                report($e);
            }

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
