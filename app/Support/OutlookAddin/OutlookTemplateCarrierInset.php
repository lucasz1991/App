<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Output-only experiment. Authored template and its Word branch stay opaque. */
final class OutlookTemplateCarrierInset
{
    public const ATTRIBUTE = 'data-rt-template-carrier';

    public const MARKER = 'inset-v1';

    private const OPEN = '<!--[if !mso]><!--><table data-rt-template-carrier="inset-v1" role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="width:100%;table-layout:fixed;border-collapse:collapse;"><tr><td width="1%" valign="top" style="width:1%;padding:0;font-size:0;line-height:0;"></td><td width="99%" valign="top" style="width:99%;padding:0;"><!--<![endif]-->';

    private const CLOSE = '<!--[if !mso]><!--></td></tr></table><!--<![endif]-->';

    private const TOKENS = '~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    public static function project(string $html): string
    {
        if (str_contains($html, self::ATTRIBUTE)) {
            self::assertRuntime($html);

            return $html;
        }
        [$start, $end] = self::templateRange($html);
        $projected = substr($html, 0, $start).self::OPEN.substr($html, $start, $end - $start).self::CLOSE.substr($html, $end);
        if (self::restore($projected) !== $html || self::wordView($projected) !== self::wordView($html)) {
            self::fail();
        }

        return $projected;
    }

    public static function restore(string $html): string
    {
        if (! str_contains($html, self::ATTRIBUTE)) {
            return $html;
        }
        [$start, $end] = self::templateRange($html);
        $prefix = $start - strlen(self::OPEN);
        if ($prefix < 0 || substr_count($html, self::ATTRIBUTE) !== 1
            || substr($html, $prefix, strlen(self::OPEN)) !== self::OPEN
            || substr($html, $end, strlen(self::CLOSE)) !== self::CLOSE) {
            self::fail();
        }

        return substr($html, 0, $prefix).substr($html, $start, $end - $start).substr($html, $end + strlen(self::CLOSE));
    }

    public static function assertRuntime(string $html): void
    {
        $restored = self::restore($html);
        if ($restored === $html || self::project($restored) !== $html) {
            self::fail();
        }
    }

    /** Only normal tokens; conditional Word openings and STYLE content are opaque. */
    private static function templateRange(string $html): array
    {
        if (preg_match('~<(?:html|head|body|script|iframe)\b~i', $html)) {
            self::fail();
        }
        preg_match_all(self::TOKENS, $html, $tokens, PREG_OFFSET_CAPTURE);
        $stack = $found = [];
        $roots = $modes = 0;
        foreach ($tokens[0] as [$tag, $offset]) {
            if (str_starts_with($tag, '<!--') || preg_match('~\A<style\b~i', $tag)) {
                continue;
            }
            preg_match('~\A<(/?)([a-z][a-z0-9:-]*)\b~i', $tag, $name);
            $element = strtolower($name[2]);
            if ($name[1] === '/') {
                $node = array_pop($stack);
                if ($node === null || $node['tag'] !== $element || preg_match('~\A</[a-z][a-z0-9:-]*\s*>\z~i', $tag) !== 1) {
                    self::fail();
                }
                if ($node['target']) {
                    $found[] = [$node['start'], $offset + strlen($tag)];
                }

                continue;
            }
            $attrs = self::attributes($tag);
            if ($stack === []) {
                $roots++;
            }
            if (array_key_exists('data-rt-compose-document', $attrs)) {
                if ($stack !== [] || $element !== 'div'
                    || ! in_array($attrs['data-rt-compose-document'], ['combined-v1', 'combined-native-v1'], true)) {
                    self::fail();
                }
                $modes++;
            }
            if (in_array($element, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true)) {
                continue;
            }
            if (preg_match('~/\s*>\z~', $tag)) {
                self::fail();
            }
            $target = $element === 'div'
                && in_array('rt-outlook-template', preg_split('/\s+/', $attrs['class'] ?? ''), true);
            $stack[] = ['tag' => $element, 'target' => $target, 'start' => $offset];
        }
        if ($stack !== [] || count($found) !== 1 || $roots !== 1 || $modes !== 1) {
            self::fail();
        }

        return $found[0];
    }

    /** Quote-aware attributes, never class-like substrings inside another value. */
    private static function attributes(string $tag): array
    {
        preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $tag, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attrs = [];
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            if (array_key_exists($key, $attrs) || ($key === 'class' && $match[4] !== null)) {
                self::fail();
            }
            $raw = $match[2] ?? $match[3] ?? $match[4] ?? '';
            $attrs[$key] = CssSemantic::decodeHtmlEntitiesOnce($raw);
            if ($key === 'class' && $raw !== $attrs[$key]) {
                self::fail();
            }
        }

        return $attrs;
    }

    private static function wordView(string $html): string
    {
        $html = preg_replace('~<!--\[if !mso\]><!-->.*?<!--<!\[endif\]-->~s', '', $html);

        return preg_replace('~<!--\[if mso\]>(.*?)<!\[endif\]-->~s', '$1', $html);
    }

    private static function fail(): never
    {
        throw new RuntimeException('Der gemeinsame Outlook-Vorlageneinzug besitzt keinen eindeutigen reversiblen Ausgabe-Vertrag.');
    }
}
