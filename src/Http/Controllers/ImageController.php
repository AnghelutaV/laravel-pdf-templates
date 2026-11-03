<?php

namespace AnghelutaV\PdfTemplates\Http\Controllers;

use AnghelutaV\PdfTemplates\ImageLibrary;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

// Image library of the editors (config images.disk); used in templates as pdf-asset:{name}
class ImageController extends Controller
{
    public function index(ImageLibrary $images)
    {
        return response()->json(['images' => $this->withUrls($images->all())]);
    }

    public function store(Request $request, ImageLibrary $images)
    {
        $request->validate([
            'images' => 'required|array|max:20',
            // pdfme draws PNG and JPEG; SVG goes into its SVG field. dompdf handles all three.
            'images.*' => ['file', 'mimes:'.implode(',', ImageLibrary::EXTENSIONS), 'max:'.(int) config('pdf-templates.images.max_kb', 4096)],
        ]);

        $names = array_map(fn ($file) => $images->store($file), $request->file('images'));

        return response()->json([
            'stored' => $names,
            'images' => $this->withUrls($images->all()),
        ]);
    }

    // Thumbnails and the browser-side pdfme generation; the disk may be private
    public function show(string $name, ImageLibrary $images)
    {
        $contents = $images->contents($name) ?? abort(404);

        return response($contents, 200, [
            'Content-Type' => ImageLibrary::mime($name),
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            // An SVG opened directly must not run scripts on this origin
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function destroy(string $name, ImageLibrary $images)
    {
        abort_unless($images->delete($name), 404);

        return response()->json(['images' => $this->withUrls($images->all())]);
    }

    /**
     * @param  list<array{name: string, size: int, modified: int}>  $list
     * @return list<array<string, mixed>>
     */
    protected function withUrls(array $list): array
    {
        return array_map(fn (array $image) => $image + [
            'url' => route(config('pdf-templates.routes.name', 'pdf-templates.').'images.show', $image['name']).'?v='.$image['modified'],
            'ref' => ImageLibrary::PREFIX.$image['name'],
        ], $list);
    }
}
