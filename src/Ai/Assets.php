<?php

namespace AnghelutaV\PdfTemplates\Ai;

// Embedded files (data URLs: background PDF, images, fonts) can be MBs; the model only sees [ASSET_n]
class Assets
{
    const MIN_LENGTH = 200;

    /** @var array<string, string> */
    protected array $stash = [];

    public function stash(string $text): string
    {
        return (string) preg_replace_callback(
            // Also inside JSON strings, where "/" may be escaped as "\/"
            '#data:[a-z0-9.+/\\\\-]+;base64,[a-z0-9+/=\\\\\s]{'.self::MIN_LENGTH.',}#i',
            function (array $match) {
                $key = array_search($match[0], $this->stash, true);
                if ($key === false) {
                    $key = '[ASSET_'.(count($this->stash) + 1).']';
                    $this->stash[$key] = $match[0];
                }

                return $key;
            },
            $text,
        );
    }

    public function restore(string $text): string
    {
        return strtr($text, $this->stash);
    }

    public function count(): int
    {
        return count($this->stash);
    }
}
