<?php

namespace AnghelutaV\PdfTemplates;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
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
