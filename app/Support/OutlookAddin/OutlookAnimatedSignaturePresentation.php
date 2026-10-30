<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use RuntimeException;

/** New linked-animation source only. Historical V14 remains byte-identical. */
final class OutlookAnimatedSignaturePresentation
{
    public const FLOW = 'outlook-desktop-flow-v15';

    public const REVISION = 'outlook-animated-signature-presentation-v1';

    private const TOKENS = '~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    private const STYLES = [
        'train' => ['width:min(547px,100%)!important;max-width:100%!important;border-collapse:collapse;', 'width:242px;max-width:100%!important;border-collapse:collapse;'],
        'train-image' => ['display:block;width:min(529px,100%)!important;max-width:100%!important;height:auto!important;margin:0;border:0;vertical-align:bottom;', 'display:block;width:224px;max-width:100%!important;height:auto;margin:0;border:0;vertical-align:bottom;'],
        'dispatch-caption' => ['display:block;width:min(199px,100%)!important;max-width:100%!important;height:auto!important;border:0;', 'display:block;width:177px;max-width:100%!important;height:auto;border:0;'],
    ];

    public static function payload(array $payload): array
    {
        foreach (['signature', 'template'] as $key) {
            if (isset($payload[$key]['html'])) {
                $payload[$key]['html'] = self::document($payload[$key]['html']);
            }
        }
        foreach ($payload['templates'] ?? [] as $index => $entry) {
            foreach (['html', 'composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                if (isset($entry[$field])) {
                    $entry[$field] = self::document($entry[$field]);
                }
            }
            if (isset($entry['signature']['html'])) {
                $entry['signature']['html'] = self::document($entry['signature']['html']);
            }
            $payload['templates'][$index] = $entry;
        }

        return $payload;
    }

    public static function document(string $html): string
    {
        preg_match_all('~<style\b[^>]*data-rt-mail-document-css=(["\'])signature\1[^>]*>(.*?)</style\s*>~is', $html, $authored);
        if (array_filter($authored[2], static fn (string $css): bool => trim($css) !== '') !== []) {
            return $html;
        }
        if (str_contains($html, 'data-rt-desktop-flow="'.self::FLOW.'"')) {
            self::project($html, false);

            return $html;
        }
        if (! str_contains($html, 'data-rt-desktop-flow="'.OutlookDispatchCardPresentation::FLOW.'"') || ! self::linkedSignature($html)) {
            return $html;
        }
        OutlookDispatchCardPresentation::document($html);
        $result = self::project($html, true);
        self::project($result, false);

        return $result;
    }

    private static function linkedSignature(string $html): bool
    {
        preg_match_all(self::TOKENS, $html, $tokens);
        $found = 0;
        foreach ($tokens[0] as $tag) {
            if (preg_match('~\A<tr\b~i', $tag) && preg_match('~\sdata-rt-artifact-version\s*=\s*(?:"v27"|\'v27\'|v27(?=\s|>))(?=\s|>)~i', $tag)
                && preg_match('~\sdata-rt-linked\s*=\s*(?:"animated-v1"|\'animated-v1\'|animated-v1(?=\s|>))(?=\s|>)~i', $tag)) {
                $found++;
            }
        }
        if ($found > 1) {
            self::fail();
        }

        return $found === 1;
    }

    private static function project(string $html, bool $forward): string
    {
        $from = $forward ? 14 : 15;
        $to = $forward ? 15 : 14;
        $count = array_fill_keys(array_keys(self::STYLES), 0);
        $roots = 0;
        $result = preg_replace_callback(self::TOKENS, static function (array $match) use ($forward, $from, $to, &$count, &$roots): string {
            $tag = $match[0];
            if (preg_match('~\A<style\b~i', $tag)) {
                return str_replace(['rt-df'.$from, '.rt-v'.$from.'-'], ['rt-df'.$to, '.rt-v'.$to.'-'], $tag);
            }
            if (! preg_match('~\A<[a-z]~i', $tag)) {
                return $tag;
            }
            if (str_contains($tag, 'data-rt-desktop-flow="outlook-desktop-flow-v'.$from.'"')) {
                $roots++;
            }
            if (preg_match('~\sclass="([^"]*)"~', $tag, $classes)) {
                $tokens = preg_split('/\s+/', trim($classes[1]));
                foreach (self::STYLES as $role => [$old, $new]) {
                    if (! in_array('rt-v'.$from.'-'.$role, $tokens, true)) {
                        continue;
                    }
                    $expected = $forward ? $old : $new;
                    $replacement = $forward ? $new : $old;
                    if (! preg_match('~\sstyle="([^"]*)"~', $tag, $style) || $style[1] !== $expected) {
                        self::fail();
                    }
                    $tag = str_replace($style[0], ' style="'.$replacement.'"', $tag);
                    $count[$role]++;
                }
            }
            $tag = preg_replace_callback('~\bclass=("|\')([^"\']*)\1~i', static fn (array $c): string => 'class='.$c[1].str_replace(['rt-df'.$from, 'rt-v'.$from.'-'], ['rt-df'.$to, 'rt-v'.$to.'-'], $c[2]).$c[1], $tag);

            return str_replace(['data-rt-desktop-flow="outlook-desktop-flow-v'.$from.'"', 'data-rt-dispatch-card="v'.$from.'"'], ['data-rt-desktop-flow="outlook-desktop-flow-v'.$to.'"', 'data-rt-dispatch-card="v'.$to.'"'], $tag);
        }, $html);
        if ($roots !== 1 || array_values($count) !== [1, 1, 1]) {
            self::fail();
        }
        if (! $forward) {
            OutlookDispatchCardPresentation::document($result);
        }

        return $result;
    }

    private static function fail(): never
    {
        throw new RuntimeException('Die verlinkte animierte Signatur besitzt keinen eindeutigen Ausgabe-Vertrag.');
    }
}
