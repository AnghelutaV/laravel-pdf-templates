<?php

namespace AnghelutaV\PdfTemplates;

use InvalidArgumentException;

// URLs the server itself calls (image import, webhooks): http(s) only, and not this server or the local network
class PublicUrl
{
    public static function assert(string $url, string $what = 'URL'): void
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException("The {$what} must be an http(s) URL.");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            throw new InvalidArgumentException("Unknown host {$host}.");
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException("{$host} is a private address.");
            }
        }
    }
}
