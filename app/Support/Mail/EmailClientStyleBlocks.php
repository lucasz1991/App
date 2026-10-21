<?php

declare(strict_types=1);

namespace App\Support\Mail;

/** Split delivery CSS without changing rule order, declarations or MSO comments. */
final class EmailClientStyleBlocks
{
    public static function project(string $html, callable $accept): string
    {
        return preg_replace_callback('~<!--.*?-->|(<style\b[^>]*>)(.*?)(</style>)~is', static function (array $match) use ($accept): string {
            if (! isset($match[2]) || ! $accept($match[1], $match[2])) {
                return $match[0];
            }

            return implode("\n", array_map(static fn (string $chunk): string => $match[1].$chunk.$match[3], self::chunks($match[2])));
        }, $html) ?? $html;
    }

    /** @return list<string> */
    private static function chunks(string $css): array
    {
        $chunks = [];
        $start = 0;
        $depth = 0;
        $media = false;
        $quote = null;
        $comment = false;
        $length = strlen($css);
        for ($i = 0; $i < $length; $i++) {
            $character = $css[$i];
            $next = $css[$i + 1] ?? '';
            if ($comment) {
                if ($character === '*' && $next === '/') {
                    $comment = false;
                    $i++;
                }

                continue;
            }
            if ($quote !== null) {
                if ($character === '\\') {
                    $i++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($character === '/' && $next === '*') {
                $comment = true;
                $i++;

                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }
            if ($depth === 0 && strcasecmp(substr($css, $i, 6), '@media') === 0
                && preg_match('/\s|\(/', $css[$i + 6] ?? '') === 1) {
                if (trim(substr($css, $start, $i - $start)) !== '') {
                    $chunks[] = substr($css, $start, $i - $start);
                }
                $start = $i;
                $media = true;
            }
            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;
                if ($media && $depth === 0) {
                    $chunks[] = substr($css, $start, $i + 1 - $start);
                    $start = $i + 1;
                    $media = false;
                }
            }
        }
        if ($depth !== 0 || $quote !== null || $comment) {
            return [$css];
        }
        if (trim(substr($css, $start)) !== '') {
            $chunks[] = substr($css, $start);
        }

        return $chunks ?: [$css];
    }
}
