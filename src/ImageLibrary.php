<?php

namespace AnghelutaV\PdfTemplates;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Uploaded images (logos, stamps, signatures) on a Laravel disk (config images.disk / images.path).
 * Templates and data use them as "pdf-asset:logo.png"; resolve() swaps that for a data URL right before
 * rendering, so the templates stay small and the files work on any disk (local, public, s3…).
 */
class ImageLibrary
{
    const PREFIX = 'pdf-asset:';

    const EXTENSIONS = ['png', 'jpg', 'jpeg', 'svg'];

    const MIME = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml'];

    // pdf-asset:logo.png → name
    const PATTERN = '#pdf-asset:([A-Za-z0-9][A-Za-z0-9._-]*\.(?:png|jpe?g|svg))#i';

    /** @var array<string, ?string> data URLs resolved during this request */
    protected array $dataUrls = [];

    public function disk(): Filesystem
    {
        return Storage::disk(config('pdf-templates.images.disk') ?: config('filesystems.default'));
    }

    public function path(string $name = ''): string
    {
        return trim((string) config('pdf-templates.images.path', 'pdf-templates/images'), '/').($name !== '' ? '/'.basename($name) : '');
    }

    public static function valid(string $name): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*\.(png|jpe?g|svg)$#i', $name);
    }

    /** @return list<array{name: string, size: int, modified: int}> newest first */
    public function all(): array
    {
        $disk = $this->disk();

        return collect($disk->files($this->path()))
            ->map(fn (string $file) => basename($file))
            ->filter(fn (string $name) => static::valid($name))
            ->map(fn (string $name) => [
                'name' => $name,
                'size' => $disk->size($this->path($name)),
                'modified' => $disk->lastModified($this->path($name)),
            ])
            ->sortByDesc('modified')
            ->values()
            ->all();
    }

    public function exists(string $name): bool
    {
        return static::valid($name) && $this->disk()->exists($this->path($name));
    }

    // Keeps the original name (slugged); "logo.png", "logo-2.png"… when it is taken
    public function store(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());
        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'image';
        $name = "{$base}.{$extension}";

        for ($i = 2; $this->disk()->exists($this->path($name)); $i++) {
            $name = "{$base}-{$i}.{$extension}";
        }

        $this->disk()->putFileAs($this->path(), $file, $name);

        return $name;
    }

    // Same naming as store(), for downloaded files
    public function storeContents(string $filename, string $contents): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $base = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'image';
        $name = "{$base}.{$extension}";

        for ($i = 2; $this->disk()->exists($this->path($name)); $i++) {
            $name = "{$base}-{$i}.{$extension}";
        }
        $this->disk()->put($this->path($name), $contents);

        return $name;
    }

    /**
     * Downloads an image from the internet into the library (moving a template from a service that loads
     * remote images). Public http(s) hosts only, no redirects, PNG / JPEG / SVG within images.max_kb.
     *
     * @throws \InvalidArgumentException
     */
    public function download(string $url): string
    {
        [$contents, $extension] = $this->fetch($url);
        $base = pathinfo((string) (parse_url($url, PHP_URL_PATH) ?? ''), PATHINFO_FILENAME) ?: 'image';

        return $this->storeContents("{$base}.{$extension}", $contents);
    }

    /**
     * A remote image as a data URL, for rendering (dompdf itself never goes online): an image URL in the template or
     * in the data. Same rules as download(); kept in the cache for html.remote_images_ttl minutes. Null when it
     * cannot be used (private host, not an image, too big, unreachable).
     */
    public function remoteDataUrl(string $url): ?string
    {
        if (array_key_exists($url, $this->dataUrls)) {
            return $this->dataUrls[$url];
        }
        $ttl = (int) config('pdf-templates.html.remote_images_ttl', 60) * 60;
        $load = function () use ($url): ?string {
            try {
                [$contents, $extension] = $this->fetch($url, 10);
            } catch (\InvalidArgumentException) {
                return null;
            }

            return 'data:'.self::MIME[$extension].';base64,'.base64_encode($contents);
        };

        return $this->dataUrls[$url] = $ttl > 0 ? Cache::remember('pdf-templates:remote-image:'.sha1($url), $ttl, $load) : $load();
    }

    /**
     * Downloads a public image: [contents, png|jpg|svg].
     *
     * @return array{0: string, 1: string}
     *
     * @throws \InvalidArgumentException
     */
    protected function fetch(string $url, int $timeout = 15): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('Only http(s) URLs can be imported.');
        }
        // Not this server, not the local network (the request is made by the server)
        PublicUrl::assert($url, 'image URL');

        $maxBytes = (int) config('pdf-templates.images.max_kb', 4096) * 1024;
        try {
            $response = Http::timeout($timeout)->withOptions(['allow_redirects' => false])->get($url);
        } catch (ConnectionException) {
            throw new \InvalidArgumentException("{$host} could not be reached.");
        }
        if (! $response->successful()) {
            throw new \InvalidArgumentException("HTTP {$response->status()} for {$url}");
        }
        $contents = $response->body();
        if (strlen($contents) > $maxBytes) {
            throw new \InvalidArgumentException('The image is larger than '.($maxBytes / 1024).' KB.');
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $extension = match (true) {
            str_starts_with($contents, "\x89PNG") => 'png',
            str_starts_with($contents, "\xFF\xD8\xFF") => 'jpg',
            $type === 'image/svg+xml' || (bool) preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', $contents) => 'svg',
            default => throw new \InvalidArgumentException('Not a PNG, JPEG or SVG image.'),
        };

        return [$contents, $extension];
    }

    public function delete(string $name): bool
    {
        // Flysystem reports success for a missing file too
        return $this->exists($name) && $this->disk()->delete($this->path($name));
    }

    public function contents(string $name): ?string
    {
        return $this->exists($name) ? $this->disk()->get($this->path($name)) : null;
    }

    public static function mime(string $name): string
    {
        return self::MIME[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    public function dataUrl(string $name): ?string
    {
        if (! array_key_exists($name, $this->dataUrls)) {
            $contents = $this->contents($name);
            $this->dataUrls[$name] = $contents === null ? null : 'data:'.static::mime($name).';base64,'.base64_encode($contents);
        }

        return $this->dataUrls[$name];
    }

    // "pdf-asset:logo.png" anywhere in the text (HTML, CSS, JSON) → data URL; unknown names stay as they are
    public function resolve(string $text): string
    {
        if (! str_contains($text, self::PREFIX)) {
            return $text;
        }

        return (string) preg_replace_callback(self::PATTERN, fn (array $m) => $this->dataUrl($m[1]) ?? $m[0], $text);
    }
}
