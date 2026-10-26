<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use App\Support\Mail\OutlookSignatureInlineStyle;
use DOMDocument;
use DOMXPath;

/** Late native output only: clear an exclusively owned compiler alias, never authored CSS. */
final class OutlookBrandAliasVisibility
{
    /** @param list<array{opening:string,role:string}> $images */
    public static function clear(string $html, array $images): ?string
    {
        $token = '~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|<[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';
        preg_match_all($token, $html, $tokens, PREG_OFFSET_CAPTURE);
        $openings = [];
        $styles = [];
        foreach ($tokens[0] as [$tag, $offset]) {
            if (str_starts_with($tag, '<!--')) {
                continue;
            }
            if (preg_match('~\A(<style\b[^>]*>)(.*)</style\s*>\z~is', $tag, $style)) {
                $styles[] = ['attrs' => self::attributes($style[1]), 'css' => $style[2], 'start' => $offset + strlen($style[1])];
            } else {
                $openings[] = $tag;
            }
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return null;
        }
        $xpath = new DOMXPath($dom);
        $edits = [];
        foreach ($images as $image) {
            $opening = $image['opening'] ?? '';
            $role = $image['role'] ?? '';
            $attrs = self::attributes($opening);
            if (! preg_match('~\A<img\b~i', $opening) || ! in_array($role, ['logo', 'mark'], true)
                || count(array_filter($openings, static fn (string $tag): bool => $tag === $opening)) !== 1) {
                return null;
            }
            $classes = preg_split('/\s+/', trim($attrs['class'] ?? ''));
            $roles = $role === 'logo' ? ['rt-logo', 'rt-client-logo'] : ['rt-mark'];
            if (array_intersect($roles, $classes) === []) {
                return null;
            }
            $aliases = array_values(array_filter($classes, static fn (string $class): bool => preg_match('/\Aoi[0-9a-z]+\z/', $class) === 1));
            if (count($aliases) > 1 || array_diff($classes, array_merge($roles, $aliases)) !== []) {
                return null;
            }
            foreach ($styles as $style) {
                preg_match_all('~([^{}]+)\{([^{}]*)\}~s', $style['css'], $rules, PREG_SET_ORDER);
                foreach ($rules as $rule) {
                    $observes = preg_match('/\bimg\b|\.rt-(?:client-)?logo\b|\.rt-mark\b|\*/i', $rule[1]) === 1;
                    if (($style['attrs']['data-rt-mail-document-css'] ?? '') !== ''
                        && ($observes || preg_match('/[\\\\\[\]:+~]/', $rule[1]))) {
                        return null;
                    }
                    if ($observes && preg_match('/(?:\A|;)\s*(?:mso-hide\s*:\s*all|display\s*:\s*none|visibility\s*:\s*hidden)\s*(?:!important\s*)?(?:;|\z)/i', $rule[2])) {
                        return null;
                    }
                }
            }
            if ($aliases === []) {
                continue;
            }
            $alias = $aliases[0];
            $mentions = 0;
            $owned = [];
            foreach ($styles as $index => $style) {
                $mentions += preg_match_all('/\.'.preg_quote($alias, '/').'(?![\w-])/', $style['css']);
                if (($style['attrs'][OutlookSignatureInlineStyle::ATTRIBUTE] ?? '') !== '1' || count($style['attrs']) !== 1) {
                    continue;
                }
                $pattern = '~\.(rts[0-9a-f]{10})\.'.$alias.',\.\1 \.'.$alias.'\{([^{}]*)\}~';
                preg_match_all($pattern, $style['css'], $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($matches as $match) {
                    $owned[] = ['index' => $index, 'scope' => $match[1][0], 'body' => $match[2][0], 'offset' => $match[2][1]];
                }
            }
            if ($mentions !== 2 || count($owned) !== 1) {
                return null;
            }
            $rule = $owned[0];
            $roots = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," rt-outlook-signature ") and contains(concat(" ",normalize-space(@class)," ")," '.$rule['scope'].' ")]');
            if ($roots->length !== 1) {
                return null;
            }
            $family = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," '.$alias.' ")]|self::*[contains(concat(" ",normalize-space(@class)," ")," '.$alias.' ")]', $roots->item(0));
            if ($family->length !== 1 || strtolower($family->item(0)->nodeName) !== 'img'
                || $family->item(0)->getAttribute('src') !== ($attrs['src'] ?? '')) {
                return null;
            }
            $globalFamily = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$alias.' ") and (contains(concat(" ",normalize-space(@class)," ")," '.$rule['scope'].' ") or ancestor::*[contains(concat(" ",normalize-space(@class)," ")," '.$rule['scope'].' ")])]');
            if ($globalFamily->length !== 1 || ! $globalFamily->item(0)->isSameNode($family->item(0))) {
                return null;
            }
            // The generated IMG family uses simple declarations. Escapes,
            // strings, functions or comments are deliberately not interpreted.
            if (preg_match('/["\'()\\\\]|\/\*/', $rule['body'])) {
                return null;
            }
            $parts = explode(';', $rule['body']);
            $kept = [];
            foreach ($parts as $part) {
                if (preg_match('/\A\s*mso-hide\s*:/i', $part)) {
                    if (! preg_match('/\A\s*mso-hide\s*:\s*(?:all|none)\s*(?:!important\s*)?\z/i', $part)) {
                        return null;
                    }
                    if (preg_match('/:\s*all\b/i', $part)) {
                        continue;
                    }
                }
                $kept[] = $part;
            }
            $position = $styles[$rule['index']]['start'] + $rule['offset'];
            $edits[$position] = ['length' => strlen($rule['body']), 'value' => implode(';', $kept)];
        }
        krsort($edits);
        foreach ($edits as $position => $edit) {
            $html = substr_replace($html, $edit['value'], $position, $edit['length']);
        }

        return $html;
    }

    private static function attributes(string $opening): array
    {
        preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))~i', $opening, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attrs = [];
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            if (isset($attrs[$key])) {
                return [];
            }
            $attrs[$key] = CssSemantic::decodeHtmlEntitiesOnce($match[2] ?? $match[3] ?? $match[4] ?? '');
        }

        return $attrs;
    }
}
