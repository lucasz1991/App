<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

/**
 * Syntax-only serialization for the validated mobile document. It retains
 * ordinary inline fallback, owned internal rules, links, IMG and MSO branches.
 * Unknown declaration grammars stay byte-identical rather than being guessed.
 */
final class OutlookMobileHtmlSerialization
{
    private const TOKENS = '~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    public static function project(string $html): string
    {
        // CSS attr(style) can expose the literal inline serialization itself.
        // Escaped function spellings are deliberately not interpreted here.
        // A false-positive skip is safe: the original strict budget still wins.
        preg_match_all(self::TOKENS, $html, $tokens);
        foreach ($tokens[0] as $token) {
            if (! str_starts_with($token, '<!--')
                && (str_contains($token, '\\') || preg_match('/\battr\s*\(/i', $token))) {
                return $html;
            }
        }

        return preg_replace_callback(self::TOKENS, static function (array $match): string {
            $token = $match[0];
            if (str_starts_with($token, '<!--') || str_starts_with($token, '</')) {
                return $token;
            }
            if (preg_match('~\A(<style\b[^>]*>)(.*)(</style\s*>)\z~is', $token, $style)) {
                return $style[1].self::stylesheet($style[2]).$style[3];
            }

            // Class/style/data/CID serialization remains compatible with the
            // following final presentation projector and Office marker reader.
            return self::openingTag($token);
        }, $html);
    }

    /** Attribute lexer: quoted values are opaque, never searched as markup. */
    private static function openingTag(string $tag): string
    {
        if (! preg_match('~\A<([a-z][a-z0-9:-]*)\b~i', $tag, $opening)) {
            return $tag;
        }
        $offset = strlen($opening[0]);
        $attributes = [];
        $classes = '';
        while (preg_match('~\G(\s+)([a-z_:][a-z0-9_:.-]*)(?:(\s*=\s*)("([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $tag, $match, PREG_UNMATCHED_AS_NULL, $offset)) {
            $offset += strlen($match[0]);
            $attributes[] = $match;
            if (strtolower($match[2]) === 'class') {
                $classes = $match[5] ?? $match[6] ?? $match[7] ?? '';
            }
        }
        $tail = substr($tag, $offset);
        if (preg_match('~\A\s*/?>\z~', $tail) !== 1) {
            return $tag;
        }
        $contact = strtolower($opening[1]) === 'td' && in_array('xg', preg_split('/\s+/', $classes), true);
        $result = $opening[0];
        $lastUnquoted = false;
        foreach ($attributes as $attribute) {
            $name = strtolower($attribute[2]);
            $value = $attribute[5] ?? $attribute[6] ?? $attribute[7];
            $lastUnquoted = $attribute[7] !== null;
            if ($name === 'style' && ($attribute[5] !== null || $attribute[6] !== null)) {
                $quote = $attribute[5] !== null ? '"' : "'";
                $result .= $attribute[1].$attribute[2].$attribute[3].$quote.self::declarations($value, $contact).$quote;
            } elseif ($attribute[5] !== null
                && in_array($name, ['width', 'height', 'border', 'cellpadding', 'cellspacing', 'align', 'valign', 'role', 'dir', 'bgcolor'], true)
                && preg_match('~\A[^"\'\s`=<>]+\z~', $value)) {
                $result .= $attribute[1].$attribute[2].'='.$value;
                $lastUnquoted = true;
            } else {
                $result .= $attribute[0];
            }
        }
        // In HTML an unquoted final value otherwise consumes the slash in />.
        if ($lastUnquoted && str_starts_with($tail, '/>')) {
            $tail = ' '.$tail;
        }

        return $result.$tail;
    }

    /** Preserve selectors, comments, all rule order and media conditions. */
    private static function stylesheet(string $css): string
    {
        $result = '';
        $start = 0;
        $depth = 0;
        $opening = null;
        $quote = null;
        for ($index = 0, $length = strlen($css); $index < $length; $index++) {
            $character = $css[$index];
            if ($character === '\\') {
                $index++;

                continue;
            }
            if ($quote !== null) {
                if ($quote === $character) {
                    $quote = null;
                }

                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }
            if (substr($css, $index, 2) === '/*') {
                $end = strpos($css, '*/', $index + 2);
                if ($end === false) {
                    return $css;
                }
                $index = $end + 1;

                continue;
            }
            if ($character === '{') {
                if ($depth++ === 0) {
                    $opening = $index;
                }
            } elseif ($character === '}') {
                if (--$depth < 0 || $opening === null) {
                    return $css;
                }
                if ($depth === 0) {
                    $prelude = substr($css, $start, $opening - $start);
                    $body = substr($css, $opening + 1, $index - $opening - 1);
                    // Leading comments are kept opaque. A nested unknown at-rule
                    // is not converted into an ordinary declaration rule.
                    $semanticPrelude = preg_replace('~/\*.*?\*/~s', '', $prelude);
                    $body = str_starts_with(ltrim($semanticPrelude), '@')
                        ? (preg_match('/\A\s*@media\b/i', $semanticPrelude) ? self::stylesheet($body) : $body)
                        : self::declarations($body);
                    $result .= $prelude.'{'.$body.'}';
                    $start = $index + 1;
                }
            }
        }

        return $depth === 0 && $quote === null ? $result.substr($css, $start) : $css;
    }

    private static function declarations(string $css, bool $contact = false): string
    {
        // Preserve any literal/escape/function/comment and any invalid or
        // custom declaration as one opaque declaration-list contract.
        if (strpbrk($css, '\\(){}') !== false || str_contains($css, '/*')) {
            return $css;
        }
        $quote = null;
        for ($index = 0, $length = strlen($css); $index < $length; $index++) {
            $character = $css[$index];
            if ($quote !== null) {
                if ($character === ';') {
                    return $css;
                }
                if ($character === $quote) {
                    $quote = null;
                }
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            }
        }
        if ($quote !== null) {
            return $css;
        }
        $parts = [];
        foreach (explode(';', $css) as $part) {
            if (trim($part) === '') {
                continue;
            }
            if (preg_match('/\A\s*([a-z][a-z-]*)\s*:\s*(.*?)\s*\z/i', $part, $match) !== 1) {
                return $css;
            }
            $property = $match[1];
            $value = preg_match('/["\']/', $match[2]) ? $match[2]
                : preg_replace('/\s*!\s*important\s*\z/i', '!important', $match[2]);
            if (preg_match('/\A(?:color|background-color|border-(?:top-|right-|bottom-|left-)?color)\z/i', $property)) {
                $value = preg_replace('/\A#([a-f0-9])\1([a-f0-9])\2([a-f0-9])\3(?=!important\z|\z)/i', '#$1$2$3', $value);
            }
            if (preg_match('/\A(?:width|height|min-width|min-height|max-width|max-height|top|right|bottom|left|padding(?:-(?:top|right|bottom|left))?|margin(?:-(?:top|right|bottom|left))?|border-spacing|mso-table-[lr]space)\z/i', $property)
                && preg_match('/\A(?:-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|pt|em|rem)?|auto|none)(?: (?:-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|pt|em|rem)?|auto|none))*(?:!important)?\z/', $value)) {
                $value = preg_replace('/(?<![\w.])0(?:px|pt|em|rem)\b/', '0', $value);
            }
            $parts[] = $property.':'.$value;
        }
        // The mobile compiler appends exactly this contact typography after
        // the existing same numeric values. Its internal mirror already drops
        // the identical pair; do the same for only that generated TD suffix.
        // Different fallbacks, priorities and font/all resets remain intact.
        if ($contact && str_ends_with($css, ';font-size:13px;line-height:20px;word-break:normal;overflow-wrap:anywhere;')
            && ! preg_match('/(?:\A|;)\s*(?:font|all)\s*:/i', $css)
            && count(array_filter($parts, static fn (string $part): bool => str_starts_with(strtolower($part), 'font-size:'))) === 2
            && count(array_filter($parts, static fn (string $part): bool => str_starts_with(strtolower($part), 'line-height:'))) === 2
            && count(array_keys($parts, 'font-size:13px', true)) === 2
            && count(array_keys($parts, 'line-height:20px', true)) === 2) {
            unset($parts[array_search('font-size:13px', $parts, true)], $parts[array_search('line-height:20px', $parts, true)]);
        }
        // Same supported/unsupported value and priority on both occurrences:
        // neither is a compatibility fallback. Keep the final occurrence and
        // its position, and retain all different-value or reset/alias cases.
        if (! preg_match('/(?:\A|;)\s*(?:all|word-wrap)\s*:/i', $css)) {
            $wrap = array_filter($parts, static fn (string $part): bool => preg_match('/\Aoverflow-wrap:/i', $part) === 1);
            if (count($wrap) === 2 && count(array_unique($wrap)) === 1
                && in_array(reset($wrap), ['overflow-wrap:anywhere', 'overflow-wrap:anywhere!important'], true)) {
                unset($parts[array_key_first($wrap)]);
            }
        }

        return implode(';', $parts);
    }
}
