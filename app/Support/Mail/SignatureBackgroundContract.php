<?php

namespace App\Support\Mail;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Opt-in V22: ein optionaler Zellhintergrund, niemals eine zweite Inhaltszeile.
 *
 * Der Hintergrund ist reine Dekoration. Ein Client ohne CSS-Bildunterstuetzung
 * behaelt dieselben normal fliessenden Kontakte auf der Hintergrundfarbe.
 * Insbesondere wird kein ungetesteter, fest hoher VML-Textkasten erzeugt.
 */
final class SignatureBackgroundContract
{
    public const SIZES = [60, 80, 100, 110, 125, 150, 175, 200];

    public static function applies(string $html): bool
    {
        return SignatureArtifactVersion::usesOptionalBackground(
            SignatureArtifactVersion::detect('signature', $html),
        );
    }

    public static function assertValid(string $html): void
    {
        [$carrier, $styles] = self::inspect($html);
        $enabled = $carrier->getAttribute('data-rt-signature-background') === '1';
        $expected = $enabled ? "url('{{TRAIN_SRC}}')" : 'none';
        if (self::cssValue($styles['background-image'] ?? '') !== $expected
            || substr_count($html, '{{TRAIN_SRC}}') !== ($enabled ? 1 : 0)
            || str_contains($html, '{{TRAIN_IDLE_SRC}}')
            || str_contains($html, '{{TRAIN_STILL_SRC}}')) {
            throw new RuntimeException('Der optionale Signaturhintergrund besitzt keine eindeutige Bildbindung.');
        }
    }

    public static function render(string $html, string $source): string
    {
        self::assertValid($html);
        if (! str_contains($html, '{{TRAIN_SRC}}')) {
            return $html;
        }

        // Die Quelle stammt aus dem serverseitigen Medienvertrag. Dennoch
        // darf sie weder die CSS-Zeichenkette beenden noch fremde Hosts laden.
        $source = trim($source);
        self::assertImageSource($source);
        $rendered = str_replace(
            '{{TRAIN_SRC}}',
            htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            $html,
        );
        self::assertRuntime($rendered, $source);

        return $rendered;
    }

    public static function assertRuntime(string $html, ?string $expectedSource = null): void
    {
        [$carrier, $styles] = self::inspect($html);
        $image = self::cssValue($styles['background-image'] ?? '');
        if ($carrier->getAttribute('data-rt-signature-background') === '0') {
            if ($image !== 'none') {
                throw new RuntimeException('Ein deaktivierter Signaturhintergrund darf kein Bild laden.');
            }

            return;
        }
        if (preg_match('/^url\(([\'"])([^\'"\r\n]+)\1\)$/D', $image, $match) !== 1) {
            throw new RuntimeException('Der gerenderte Signaturhintergrund besitzt keine eindeutige Bildquelle.');
        }
        self::assertImageSource($match[2]);
        if ($expectedSource !== null && ! hash_equals($expectedSource, $match[2])) {
            throw new RuntimeException('Der Signaturhintergrund verwendet nicht das erwartete Medium.');
        }
    }

    /** @return array{DOMElement, array<string, string>} */
    private static function inspect(string $html): array
    {
        if (! self::applies($html)) {
            throw new RuntimeException('Der optionale Hintergrundvertrag benoetigt die Version V22.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $source = preg_match('/<(?:html|body)\b/i', $html) === 1
                ? '<?xml encoding="UTF-8">'.$html
                : '<?xml encoding="UTF-8"><table><tbody>'.$html.'</tbody></table>';
            $loaded = $dom->loadHTML($source, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('Der optionale Signaturhintergrund konnte nicht gelesen werden.');
        }

        $carriers = [];
        $frames = [];
        $signatureRows = [];
        foreach ($dom->getElementsByTagName('tr') as $row) {
            if ($row->getAttribute(SignatureArtifactVersion::ATTRIBUTE) !== SignatureArtifactVersion::V22) {
                continue;
            }
            $signatureRows[] = $row;
            for ($next = $row->nextSibling; $next !== null; $next = $next->nextSibling) {
                if ($next instanceof DOMElement) {
                    if (strtolower($next->tagName) === 'tr') {
                        $signatureRows[] = $next;
                    }
                    break;
                }
            }
        }
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            $classes = preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (in_array('rt-sign-cell', $classes, true)) {
                $carriers[] = $element;
            }
            if (in_array('rt-sign-content-frame', $classes, true)) {
                $frames[] = $element;
            }
            if (array_intersect($classes, [
                'rt-sign-stage', 'rt-sign-train-layer', 'rt-sign-train-frame',
                'rt-sign-train-slot', 'rt-sign-train', 'rt-sign-train-mso',
                'rt-train-idle-overlay', 'rt-train-idle-image',
            ]) !== [] || $element->hasAttribute('data-rt-train')
                || $element->hasAttribute('data-rt-layer-train')
                || $element->hasAttribute('data-rt-train-background')) {
                throw new RuntimeException('V22 darf keine zusaetzliche Zugzeile oder alte Ueberlappung enthalten.');
            }
            // Nur das eigentliche Signaturfragment pruefen, nicht eine
            // umgebende Mailvorlage mit eigenem, bereits validiertem Layout.
            $inSignature = false;
            for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
                foreach ($signatureRows as $row) {
                    if ($node->isSameNode($row)) {
                        $inSignature = true;
                        break 2;
                    }
                }
            }
            if (! $inSignature) {
                continue;
            }
            $style = self::declarations($element->getAttribute('style'));
            foreach ($style as $property => $value) {
                $value = self::cssValue($value);
                if (in_array($property, ['z-index', 'transform', 'float'], true)
                    || ($property === 'position' && $value !== 'static')
                    || (str_starts_with($property, 'margin') && preg_match('/-\s*(?:\d|\.)/', $value) === 1)
                    || (strtolower($element->tagName) !== 'img'
                        && in_array($property, ['height', 'min-height', 'max-height'], true)
                        && ! in_array($value, ['auto', 'none', '0', '0px'], true))) {
                    throw new RuntimeException('V22 muss die Kontaktdaten ohne feste Buehnenhoehe oder Ueberlappung darstellen.');
                }
            }
            if (strtolower($element->tagName) !== 'img' && $element->hasAttribute('height')) {
                throw new RuntimeException('V22 darf keine feste Tabellenhoehe speichern.');
            }
        }
        if (count($carriers) !== 1 || count($frames) !== 1
            || strtolower($carriers[0]->tagName) !== 'td'
            || strtolower($frames[0]->tagName) !== 'table'
            || ! $frames[0]->parentNode?->isSameNode($carriers[0])) {
            throw new RuntimeException('V22 benoetigt einen normalen Inhaltsrahmen direkt in der Signaturzelle.');
        }
        $carrier = $carriers[0];
        if ($carrier->hasAttribute('background')
            || ! in_array($carrier->getAttribute('data-rt-signature-background'), ['0', '1'], true)) {
            throw new RuntimeException('Der Signaturhintergrund muss explizit ein- oder ausgeschaltet sein.');
        }
        $presets = array_map('strval', self::SIZES);
        foreach (['desktop', 'tablet', 'mobile'] as $breakpoint) {
            if (! in_array($carrier->getAttribute('data-rt-bg-'.$breakpoint), $presets, true)) {
                throw new RuntimeException('Die Hintergrundgroesse fuer '.$breakpoint.' ist kein freigegebener Wert.');
            }
        }
        $styles = self::declarations($carrier->getAttribute('style'));
        foreach ([
            'background-repeat' => 'no-repeat',
            'background-position' => '65% bottom',
            'background-size' => $carrier->getAttribute('data-rt-bg-desktop').'% auto',
        ] as $property => $expected) {
            if (self::cssValue($styles[$property] ?? '') !== $expected) {
                throw new RuntimeException('Der Signaturhintergrund besitzt keine passende '.$property.'-Angabe.');
            }
        }
        if (trim($styles['background-color'] ?? '') === '' || isset($styles['background'])) {
            throw new RuntimeException('Der Signaturhintergrund benoetigt eine eindeutige bildunabhaengige Grundfarbe.');
        }

        return [$carrier, $styles];
    }

    private static function assertImageSource(string $source): void
    {
        if ($source === '' || preg_match('/[\s\x00-\x1f\x7f\'"()<>\\\\]/', $source) === 1) {
            throw new RuntimeException('Die Hintergrund-Bildquelle ist nicht CSS-sicher.');
        }
        (new EmailHtmlSanitizer)->assertClean('<img src="'
            .htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').'" alt="">');
    }

    private static function cssValue(string $value): string
    {
        return trim((string) preg_replace('/\s*!important\s*$/i', '', trim($value)));
    }

    /** Semikola in zitierten data:-Bildquellen sind keine Deklarationstrenner. */
    private static function declarations(string $style): array
    {
        $style = CssSemantic::decodeHtmlEntitiesOnce($style);
        if (str_contains($style, '/*') || str_contains($style, '*/') || str_contains($style, '\\')) {
            throw new RuntimeException('Die Signatur besitzt mehrdeutige CSS-Deklarationen.');
        }
        $parts = [];
        $start = 0;
        $quote = null;
        $depth = 0;
        for ($index = 0, $length = strlen($style); $index < $length; $index++) {
            $char = $style[$index];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ';' && $depth === 0) {
                $parts[] = substr($style, $start, $index - $start);
                $start = $index + 1;
            }
        }
        if ($quote !== null || $depth !== 0) {
            throw new RuntimeException('Die Signatur besitzt unvollstaendige CSS-Deklarationen.');
        }
        $parts[] = substr($style, $start);
        $result = [];
        foreach ($parts as $part) {
            if (trim($part) === '') {
                continue;
            }
            $colon = strpos($part, ':');
            if ($colon === false) {
                throw new RuntimeException('Die Signatur besitzt eine unlesbare CSS-Deklaration.');
            }
            $property = strtolower(trim(substr($part, 0, $colon)));
            if (str_starts_with($property, 'background') && array_key_exists($property, $result)) {
                throw new RuntimeException('Der Signaturhintergrund besitzt mehrdeutige CSS-Deklarationen.');
            }
            $result[$property] = trim(substr($part, $colon + 1));
        }

        return $result;
    }
}
