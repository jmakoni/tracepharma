<?php

declare(strict_types=1);

namespace App\Support\Logging;

final class RedactsUrls
{
    /**
     * Host and path only — strips credentials, query string, and fragment.
     */
    public static function redactUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return '[invalid-url]';
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';

        return $scheme.$host.$port.$path;
    }

    public static function redactUrlsInText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $redacted = preg_replace_callback(
            '#https?://[^\s\'"<>]+#i',
            static fn (array $matches): string => self::redactUrl($matches[0]),
            $text,
        );

        return is_string($redacted) ? $redacted : $text;
    }
}
