<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use RuntimeException;

/**
 * Kompakter Outlook-Ausschnitt aus dem kanonischen System-Mail-CSS.
 *
 * setSignatureAsync akzeptiert hoechstens 30.000 Zeichen. Deshalb darf der
 * Add-in-Pfad nicht den kompletten, vorlagenweiten Runtime-Block einbetten.
 * Statt eine zweite Handkopie der Signaturregeln zu pflegen, filtert diese
 * Klasse ausschliesslich relevante Regeln aus TrustedEmailCss und entfernt
 * dabei Regeln anderer Signaturartefakte.
 */
final class TrustedOutlookSignatureCss
{
    public const RUNTIME_MARKER = 'RT_OUTLOOK_SIGNATURE_RUNTIME_START';

    private const MAX_CSS_BYTES = 12000;

    public static function responsive(string $signatureHtml, ?string $border = '#dfe3e6'): string
    {
        $artifactVersion = SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $signatureHtml,
        );
        $documentTraits = self::documentTraits($signatureHtml);
        $css = '/* '.self::RUNTIME_MARKER.' */'
            .self::filterStylesheet(
                TrustedEmailCss::responsive($border),
                $artifactVersion,
                $documentTraits,
            );

        self::assertResponsive($css, $artifactVersion, $documentTraits);

        return $css;
    }

    public static function style(string $signatureHtml, ?string $border = '#dfe3e6'): string
    {
        return '<style data-rt-outlook-signature-css="1">'
            .self::responsive($signatureHtml, $border)
            .'</style>';
    }

    public static function publishedStyle(string $signatureHtml, string $publishedCss): string
    {
        $publishedCss = trim($publishedCss);
        if ($publishedCss === '') {
            return '';
        }

        if (stripos($publishedCss, '</style') !== false) {
            throw new RuntimeException('Das veroeffentlichte Signatur-CSS kann nicht sicher eingebettet werden.');
        }

        $artifactVersion = SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $signatureHtml,
        );
        $css = self::filterStylesheet(
            $publishedCss,
            $artifactVersion,
            self::documentTraits($signatureHtml),
        );

        return $css === ''
            ? ''
            : '<style data-rt-mail-document-css="signature">'.$css.'</style>';
    }

    /** @param array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string} $documentTraits */
    private static function filterStylesheet(
        string $css,
        ?string $artifactVersion,
        array $documentTraits,
    ): string {
        $result = '';
        $length = strlen($css);
        $offset = 0;

        while ($offset < $length) {
            self::skipTrivia($css, $offset);
            if ($offset >= $length) {
                break;
            }

            $openingBrace = self::nextOpeningBrace($css, $offset);
            if ($openingBrace === null) {
                break;
            }

            $prelude = trim(substr($css, $offset, $openingBrace - $offset));
            $closingBrace = self::matchingBrace($css, $openingBrace);
            $body = substr($css, $openingBrace + 1, $closingBrace - $openingBrace - 1);
            $offset = $closingBrace + 1;

            if ($prelude === '') {
                continue;
            }

            if (str_starts_with(strtolower($prelude), '@media')) {
                if (! str_contains(strtolower($prelude), 'max-width')) {
                    continue;
                }

                $filteredBody = self::filterStylesheet(
                    $body,
                    $artifactVersion,
                    $documentTraits,
                );
                if ($filteredBody !== '') {
                    $result .= self::compactPrelude($prelude).'{'.$filteredBody.'}';
                }

                continue;
            }

            // Keyframes und @supports betreffen nur die im Add-in bewusst
            // statisch transportierten Animationen und werden nicht benoetigt.
            if (str_starts_with($prelude, '@')) {
                continue;
            }

            $selectors = self::relevantSelectors(
                $prelude,
                $artifactVersion,
                $documentTraits,
            );
            if ($selectors === []) {
                continue;
            }

            $body = self::compactDeclarations($body);
            if ($artifactVersion === SignatureArtifactVersion::V21
                && self::selectorsContain($selectors, '.rt-sign-train-layer')) {
                $body = preg_replace_callback(
                    '/(?:^|;)margin-bottom:-[^;}]+;?/i',
                    static fn (array $match): string => str_starts_with($match[0], ';') ? ';' : '',
                    $body,
                ) ?? $body;
            }

            if ($body !== '') {
                $result .= implode(',', $selectors).'{'.$body.'}';
            }
        }

        return $result;
    }

    /**
     * @param  array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string}  $documentTraits
     * @return list<string>
     */
    private static function relevantSelectors(
        string $prelude,
        ?string $artifactVersion,
        array $documentTraits,
    ): array {
        $selectors = preg_split('/\s*,\s*/u', trim($prelude));
        if (! is_array($selectors)) {
            throw new RuntimeException('Das kanonische Signatur-CSS konnte nicht gelesen werden.');
        }

        $relevant = [];
        foreach ($selectors as $selector) {
            $selector = trim($selector);
            if ($selector === ''
                || str_contains($selector, 'data-rt-signature-density')
                || ! self::matchesArtifact($selector, $artifactVersion)
                || ! self::isSignatureSelector($selector, $documentTraits)
                || ! self::matchesDocument($selector, $documentTraits)) {
                continue;
            }

            $relevant[] = self::compactPrelude($selector);
        }

        return array_values(array_unique($relevant));
    }

    private static function matchesArtifact(string $selector, ?string $artifactVersion): bool
    {
        preg_match_all(
            '/\[data-rt-artifact-version\s*=\s*(["\'])(v\d+)\1\]/i',
            $selector,
            $matches,
        );
        $versions = $matches[2] ?? [];

        if ($versions === []) {
            return true;
        }

        return $artifactVersion !== null
            && count(array_filter(
                $versions,
                static fn (string $version): bool => $version === $artifactVersion,
            )) === count($versions);
    }

    /**
     * @param  array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string}  $documentTraits
     */
    private static function isSignatureSelector(string $selector, array $documentTraits): bool
    {
        $selector = strtolower(trim($selector));
        if (in_array($selector, ['a', 'img', 'table', 'td'], true)) {
            return true;
        }

        // Pagebuilder-Klassen sind nicht auf RailTime-Praefixe beschraenkt.
        // Zulassen duerfen wir sie trotzdem nur, wenn mindestens eine Klasse
        // des Selektors im konkreten, bereits validierten Dokument vorkommt;
        // matchesDocument() prueft anschliessend auch alle weiteren Klassen.
        if (preg_match_all('/\.([a-z_][a-z0-9_-]*)/i', $selector, $matches) > 0) {
            foreach ($matches[1] ?? [] as $className) {
                if (isset($documentTraits['classes'][strtolower($className)])) {
                    return true;
                }
            }
        }

        foreach ([
            '.rt-sign',
            '.rt-contact',
            '.rt-company-contact',
            '.rt-person-kopf',
            '.rt-pad',
            '.rt-logo',
            '.rt-train',
            '.rt-stack',
        ] as $needle) {
            if (str_contains($selector, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verwirft Varianten, die im konkreten Dokument nicht vorkommen. Das ist
     * nicht nur eine Groessenoptimierung: eine fremde Layer-Variante darf die
     * kanonische Geometrie des aktiven Artefakts nicht spaeter uebersteuern.
     *
     * @param  array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string}  $documentTraits
     */
    private static function matchesDocument(string $selector, array $documentTraits): bool
    {
        $lowerSelector = strtolower($selector);
        if (str_contains($lowerSelector, '.rt-card')
            || (str_contains($lowerSelector, 'rt-train-idle') && ! $documentTraits['has_idle'])) {
            return false;
        }

        preg_match_all('/\.([a-z_][a-z0-9_-]*)/i', $selector, $classMatches);
        foreach ($classMatches[1] ?? [] as $className) {
            if (! isset($documentTraits['classes'][strtolower($className)])) {
                return false;
            }
        }

        foreach (['align', 'size', 'mobile'] as $trait) {
            preg_match_all(
                '/\[data-rt-layer-'.$trait.'\s*=\s*(["\'])([^"\']+)\1\]/i',
                $selector,
                $attributeMatches,
            );
            foreach ($attributeMatches[2] ?? [] as $expected) {
                if ($documentTraits[$trait] === null
                    || strcasecmp($documentTraits[$trait], trim($expected)) !== 0) {
                    return false;
                }
            }

            if (preg_match('/\[data-rt-layer-'.$trait.'(?:\s|\])/i', $selector) === 1
                && $documentTraits[$trait] === null) {
                return false;
            }
        }

        return true;
    }

    /** @return array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string} */
    private static function documentTraits(string $signatureHtml): array
    {
        preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/is', $signatureHtml, $classMatches);
        $classes = [];
        foreach ($classMatches[2] ?? [] as $classAttribute) {
            foreach (preg_split('/\s+/u', trim($classAttribute)) ?: [] as $className) {
                if ($className !== '') {
                    $classes[strtolower($className)] = true;
                }
            }
        }

        return [
            'classes' => $classes,
            'has_idle' => str_contains(strtolower($signatureHtml), 'rt-train-idle'),
            'align' => self::attributeValue($signatureHtml, 'data-rt-layer-align'),
            'size' => self::attributeValue($signatureHtml, 'data-rt-layer-size'),
            'mobile' => self::attributeValue($signatureHtml, 'data-rt-layer-mobile'),
        ];
    }

    private static function attributeValue(string $html, string $attribute): ?string
    {
        if (preg_match(
            '/\b'.preg_quote($attribute, '/').'\s*=\s*(["\'])(.*?)\1/is',
            $html,
            $match,
        ) !== 1) {
            return null;
        }

        $value = trim($match[2]);

        return $value !== '' ? $value : null;
    }

    /** @param list<string> $selectors */
    private static function selectorsContain(array $selectors, string $needle): bool
    {
        foreach ($selectors as $selector) {
            if (str_contains(strtolower($selector), strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private static function compactPrelude(string $prelude): string
    {
        $prelude = preg_replace('/\s+/u', ' ', trim($prelude)) ?? trim($prelude);

        return preg_replace('/\s*>\s*/u', '>', $prelude) ?? $prelude;
    }

    private static function compactDeclarations(string $body): string
    {
        $body = preg_replace('/\/\*.*?\*\//s', '', trim($body)) ?? trim($body);
        $body = preg_replace('/\s*!important\b/i', '!important', $body) ?? $body;

        return preg_replace('/\s*([:;,])\s*/u', '$1', $body) ?? $body;
    }

    private static function skipTrivia(string $css, int &$offset): void
    {
        $length = strlen($css);
        while ($offset < $length) {
            if (preg_match('/\s/A', $css, $match, 0, $offset) === 1) {
                $offset += strlen($match[0]);

                continue;
            }

            if (substr($css, $offset, 2) === '/*') {
                $end = strpos($css, '*/', $offset + 2);
                if ($end === false) {
                    throw new RuntimeException('Das kanonische Signatur-CSS enthaelt einen offenen Kommentar.');
                }

                $offset = $end + 2;

                continue;
            }

            break;
        }
    }

    private static function nextOpeningBrace(string $css, int $offset): ?int
    {
        $length = strlen($css);
        $quote = null;

        for ($index = $offset; $index < $length; $index++) {
            $character = $css[$index];
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '{') {
                return $index;
            }
        }

        return null;
    }

    private static function matchingBrace(string $css, int $openingBrace): int
    {
        $length = strlen($css);
        $depth = 0;
        $quote = null;

        for ($index = $openingBrace; $index < $length; $index++) {
            $character = $css[$index];
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        throw new RuntimeException('Das kanonische Signatur-CSS besitzt eine offene Regel.');
    }

    /** @param array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string} $documentTraits */
    private static function assertResponsive(
        string $css,
        ?string $artifactVersion,
        array $documentTraits,
    ): void {
        if (substr_count($css, self::RUNTIME_MARKER) !== 1
            || strlen($css) > self::MAX_CSS_BYTES
            || stripos($css, '</style') !== false
            || ! str_contains($css, '@media only screen and (max-width: 860px)')
            || ! str_contains($css, '@media only screen and (max-width: 480px)')) {
            throw new RuntimeException('Das kompakte Outlook-Signatur-CSS ist ungueltig oder zu gross.');
        }

        if ($artifactVersion === SignatureArtifactVersion::V20
            && (! str_contains($css, 'margin-bottom:-304px!important')
                || ! str_contains($css, 'margin-bottom:-272px!important'))) {
            throw new RuntimeException('Das Outlook-Signatur-CSS enthaelt nicht die kanonische V20-Mobilgeometrie.');
        }

        if ($artifactVersion === SignatureArtifactVersion::V20
            && $documentTraits['mobile'] === 'stop60'
            && (! str_contains($css, 'width:164%!important')
                || ! str_contains($css, 'margin-left:-40%!important'))) {
            throw new RuntimeException('Das Outlook-Signatur-CSS enthaelt nicht die kanonische V20-stop60-Geometrie.');
        }

        if ($artifactVersion === SignatureArtifactVersion::V21
            && preg_match('/margin-bottom:\s*-\d/i', $css) === 1) {
            throw new RuntimeException('Das Outlook-Signatur-CSS darf V21 nicht ueberlagern.');
        }
    }
}
