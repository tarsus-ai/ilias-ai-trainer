<?php

namespace TarsusIlias\Support;

/**
 * The support links and videos behind the help button, configured as one
 * "Title | URL" line each. YouTube and Vimeo links play inside the panel;
 * anything else opens in a new tab.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Help
{
    public const KEY = 'help_links';

    /**
     * @return array<int, array{title: string, url: string, embed: string}>
     */
    public static function links(): array
    {
        return self::parse((string) Config::get(self::KEY, ''));
    }

    /**
     * @return array<int, array{title: string, url: string, embed: string}>
     */
    public static function parse(string $raw): array
    {
        $links = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) === 2) {
                [$title, $url] = $parts;
            } else {
                $title = '';
                $url = $parts[0];
            }
            if (!preg_match('~^https://[^\s"\'<>]+$~i', $url)) {
                continue;
            }
            if ($title === '') {
                $title = (string) parse_url($url, PHP_URL_HOST);
            }
            $links[] = ['title' => mb_substr($title, 0, 120), 'url' => $url, 'embed' => self::embedUrl($url)];
            if (count($links) >= 20) {
                break;
            }
        }
        return $links;
    }

    /**
     * The privacy-friendly player URL of a YouTube or Vimeo link, '' for others.
     */
    public static function embedUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (preg_match('/(^|\.)youtube(-nocookie)?\.com$/', $host)) {
            $id = '';
            if (preg_match('~^/(embed|shorts|live)/([A-Za-z0-9_-]{6,20})~', $path, $m)) {
                $id = $m[2];
            } elseif (isset($query['v']) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', (string) $query['v'])) {
                $id = (string) $query['v'];
            }
            return $id !== '' ? 'https://www.youtube-nocookie.com/embed/' . $id : '';
        }
        if ($host === 'youtu.be' && preg_match('~^/([A-Za-z0-9_-]{6,20})~', $path, $m)) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }
        if (preg_match('/(^|\.)vimeo\.com$/', $host) && preg_match('~/(\d{5,})(?:/|$)~', $path, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1] . '?dnt=1';
        }
        return '';
    }
}
