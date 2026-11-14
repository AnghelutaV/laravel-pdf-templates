<?php

namespace AnghelutaV\PdfTemplates\Http\Controllers;

use AnghelutaV\PdfTemplates\FontLibrary;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

// Fonts for HTML mode (dompdf): upload a .ttf or fetch a family from Google Fonts
class FontController extends Controller
{
    public function index(FontLibrary $fonts)
    {
        return response()->json($this->state($fonts));
    }

    public function store(Request $request, FontLibrary $fonts)
    {
        $inputs = $request->validate([
            'family' => 'required|string|max:63',
            'weight' => ['required', Rule::in(FontLibrary::WEIGHTS)],
            'style' => ['required', Rule::in(FontLibrary::STYLES)],
            // The signature is checked by FontLibrary (mime types of .ttf vary by system)
            'file' => 'required|file|max:'.(int) config('pdf-templates.html.font_max_kb', 4096),
        ]);

        try {
            $fonts->store($request->file('file'), $inputs['family'], $inputs['weight'], $inputs['style']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->state($fonts));
    }

    public function google(Request $request, FontLibrary $fonts)
    {
        $inputs = $request->validate(['family' => 'required|string|max:63']);

        try {
            $added = $fonts->google($inputs['family']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->state($fonts) + ['added' => count($added)]);
    }

    public function destroy(Request $request, FontLibrary $fonts)
    {
        $inputs = $request->validate(['family' => 'required|string|max:63']);
        abort_unless($fonts->delete($inputs['family']), 404);

        return response()->json($this->state($fonts));
    }

    protected function state(FontLibrary $fonts): array
    {
        return [
            'fonts' => $fonts->all(),
            // Families from config html.fonts cannot be deleted here
            'config' => array_keys((array) config('pdf-templates.html.fonts', [])),
            'families' => $fonts->families(),
        ];
    }
}
