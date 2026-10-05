<?php

declare(strict_types=1);

namespace App\Support\Mail;

use RuntimeException;

/** Internal output compiler, never an import/sanitizer permission boundary. */
final class OutlookSignatureInlineStyle
{
    public const ATTRIBUTE = 'data-rt-outlook-signature-inline-css';

    public const MAX_CSS_BYTES = 12288;

    public static function apply(string $html, string $scopeClass): string
    {
        if (preg_match('/\Arts[0-9a-f]{10}\z/', $scopeClass) !== 1
            || str_contains($html, self::ATTRIBUTE)) {
            throw new RuntimeException('Der interne Outlook-Inline-Scope ist ungueltig oder bereits vorhanden.');
        }

        // Read complete quoted tags; never round-trip the document through
        // DOMDocument, which would change downlevel/MSO conditional comments.
        $tokens = '~<!--.*?-->|<style\b[^>]*>.*?</style\s*>|<[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';
        $usedClasses = [];
        preg_match_all($tokens, $html, $matches);
        foreach ($matches[0] as $tag) {
            if (str_starts_with($tag, '<!--') || preg_match('~\A<style\b~i', $tag)) {
                continue;
            }
            foreach (preg_split('/\s+/', CssSemantic::decodeHtmlEntitiesOnce(self::attributes($tag)['class']['value'] ?? '')) as $class) {
                $usedClasses[$class] = true;
            }
        }

        $rules = [];
        $classes = [];
        $index = 0;
        $roots = 0;
        $output = preg_replace_callback($tokens, static function (array $match) use (
            $scopeClass, &$usedClasses, &$rules, &$classes, &$index, &$roots,
        ): string {
            $tag = $match[0];
            if (str_starts_with($tag, '<!--') || preg_match('~\A<style\b~i', $tag)) {
                return $tag;
            }
            $attributes = self::attributes($tag);
            $classValue = $attributes['class']['value'] ?? '';
            $tokens = preg_split('/\s+/', CssSemantic::decodeHtmlEntitiesOnce($classValue));
            if (in_array('rt-outlook-signature', $tokens, true) && in_array($scopeClass, $tokens, true)) {
                $roots++;
            }
            if (! isset($attributes['style']) || trim($attributes['style']['value']) === '') {
                return $tag;
            }
            $declarations = trim(CssSemantic::decodeHtmlEntitiesOnce($attributes['style']['value']), "; \t\r\n");
            if (preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F{}]/', $declarations)
                || preg_match('/@(?:import|media|supports|font-face|keyframes)\b|(?:expression|javascript)\s*[:(]/i', $declarations)) {
                throw new RuntimeException('Die Outlook-Inline-Stile koennen nicht sicher intern gespiegelt werden.');
            }
            if (! isset($classes[$declarations])) {
                do {
                    $class = 'oi'.base_convert((string) ++$index, 10, 36);
                } while (isset($usedClasses[$class]));
                $usedClasses[$class] = true;
                $classes[$declarations] = $class;
                // The content-bound scope makes these short aliases unique
                // across current signatures and quoted historical versions.
                // Preserve original !important exactly; do not elevate all
                // desktop fallbacks above canonical responsive rules.
                $rules[] = '.'.$scopeClass.'.'.$class.',.'.$scopeClass.' .'.$class.'{'.$declarations.';}';
            }
            $class = $classes[$declarations];
            if (isset($attributes['class'])) {
                return substr_replace($tag, $classValue.' '.$class, $attributes['class']['offset'], strlen($classValue));
            }

            return preg_replace('~\s*/?>\z~', ' class="'.$class.'"$0', $tag);
        }, $html);
        if (! is_string($output) || $roots !== 1) {
            throw new RuntimeException('Die Outlook-Inline-Stile besitzen keine eindeutige Signaturwurzel.');
        }
        $output = '<style '.self::ATTRIBUTE.'="1">'.implode('', $rules).'</style>'.$output;
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $output, $styles);
        if (array_sum(array_map('strlen', $styles[1])) >= self::MAX_CSS_BYTES
            || intdiv(strlen(mb_convert_encoding($output, 'UTF-16LE', 'UTF-8')), 2) > 30000) {
            throw new RuntimeException('Die Outlook-Signatur ueberschreitet das sichere HTML-/CSS-Transportbudget.');
        }

        return $output;
    }

    /** @return array<string, array{value: string, offset: int}> */
    private static function attributes(string $tag): array
    {
        preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i',
            $tag, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        $result = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1][0]);
            if (! in_array($name, ['style', 'class'], true)) {
                continue;
            }
            if (isset($result[$name]) || $match[4][1] >= 0) {
                throw new RuntimeException('Die Outlook-Stilattribute sind doppelt oder nicht eindeutig zitiert.');
            }
            $value = $match[2][1] >= 0 ? $match[2] : $match[3];
            if ($value[1] < 0) {
                throw new RuntimeException('Das Outlook-Stilattribut besitzt keinen Wert.');
            }
            $result[$name] = ['value' => $value[0], 'offset' => $value[1]];
        }

        return $result;
    }
}
