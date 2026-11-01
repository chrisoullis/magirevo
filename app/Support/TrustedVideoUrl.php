<?php

namespace App\Support;

class TrustedVideoUrl
{
    public static function embedUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = trim($parts['path'] ?? '', '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $videoId = null;

        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            if ($path === 'watch') {
                parse_str($parts['query'] ?? '', $query);
                $videoId = $query['v'] ?? null;
            } elseif (count($segments) === 2 && in_array($segments[0] ?? null, ['embed', 'shorts', 'live'], true)) {
                $videoId = $segments[1] ?? null;
            }

            return is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1
                ? "https://www.youtube-nocookie.com/embed/{$videoId}"
                : null;
        }

        if (in_array($host, ['youtube-nocookie.com', 'www.youtube-nocookie.com'], true) && ($segments[0] ?? null) === 'embed') {
            $videoId = $segments[1] ?? null;

            return is_string($videoId) && count($segments) === 2 && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1
                ? "https://www.youtube-nocookie.com/embed/{$videoId}"
                : null;
        }

        if ($host === 'youtu.be') {
            $videoId = count($segments) === 1 ? $segments[0] : null;

            return is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1
                ? "https://www.youtube-nocookie.com/embed/{$videoId}"
                : null;
        }

        if (in_array($host, ['vimeo.com', 'www.vimeo.com'], true)) {
            $videoId = count($segments) === 1 ? $segments[0] : null;
        } elseif ($host === 'player.vimeo.com' && count($segments) === 2 && ($segments[0] ?? null) === 'video') {
            $videoId = $segments[1] ?? null;
        }

        return is_string($videoId) && preg_match('/^[0-9]{1,12}$/', $videoId) === 1
            ? "https://player.vimeo.com/video/{$videoId}"
            : null;
    }
}
