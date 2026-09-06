<?php

namespace App\Support\Mail;

use RuntimeException;

/**
 * Exakter Vertrauenspfad fuer versionskontrollierte progressive Mail-CSS.
 *
 * Frei editierbares CSS durchlaeuft weiterhin EmailHtmlSanitizer. Diese
 * Klasse akzeptiert dagegen ausschliesslich den bytegenau aus der
 * versionierten Blade-Quelle gerenderten Runtime-Block. Damit werden
 * Keyframes, @supports und die geschuetzte Zugpositionierung nicht global
 * fuer Benutzercode freigegeben.
 */
final class TrustedEmailCss
{
    public const RUNTIME_MARKER = 'RT_SERVER_SIGNATURE_RUNTIME_START';

    public static function responsive(?string $border = null, bool $includeOptionalBackground = true): string
    {
        $border = self::normalizeBorder($border);
        $css = self::compiledResponsive($border, $includeOptionalBackground);

        self::assertResponsive($css, $border, $includeOptionalBackground);

        return $css;
    }

    public static function assertResponsive(string $css, ?string $border = null, bool $includeOptionalBackground = true): void
    {
        $border = self::normalizeBorder($border);
        $expected = self::compiledResponsive($border, $includeOptionalBackground);

        if (substr_count($expected, self::RUNTIME_MARKER) !== 1
            || ! hash_equals($expected, $css)) {
            throw new RuntimeException(
                'Das progressive System-Mail-CSS entspricht nicht der versionierten Runtime-Quelle.'
            );
        }
    }

    public static function fingerprint(?string $border = null): string
    {
        return hash('sha256', self::responsive($border));
    }

    /** One document-specific projection for preview, MIME and Office fragments. */
    public static function forDocument(string $html, ?string $border = null, bool $includeOptionalBackground = false): string
    {
        $css = self::responsive($border, $includeOptionalBackground);
        $version = SignatureArtifactVersion::detect('signature', $html);
        if (! in_array($version, [SignatureArtifactVersion::V25, SignatureArtifactVersion::V26], true)) {
            return $css;
        }

        $css = TrustedOutlookSignatureCss::filterDocumentRuntime($css, $html);
        if ($version === SignatureArtifactVersion::V26) {
            $css .= SignatureImgOverlap::css($html);
        }

        return $css;
    }

    /**
     * Entfernt ausschliesslich Dokumentationskommentare und Zeilenlayout aus
     * der versionierten, bereits vertrauenswuerdigen CSS-Quelle. Deklarationen,
     * Selektoren und die Laufzeitmarke bleiben bytegenau in ihrer Reihenfolge.
     * Das spart pro Systemmail mehrere KiB und verhindert, dass die identische
     * Hell-/Dunkel-Vorschau den Livewire-Konfigurationsblock aufblaeht.
     */
    private static function compiledResponsive(string $border, bool $includeOptionalBackground): string
    {
        $rendered = trim(view('emails.parts.responsive-css', [
            'border' => $border,
            'includeOptionalBackground' => $includeOptionalBackground,
        ])->render());

        if (substr_count($rendered, self::RUNTIME_MARKER) !== 1) {
            throw new RuntimeException(
                'Die versionierte Runtime-CSS-Quelle besitzt keine eindeutige Vertrauensmarke.'
            );
        }

        $withoutComments = preg_replace_callback(
            '/\/\*[\s\S]*?\*\//',
            static fn (array $match): string => str_contains($match[0], self::RUNTIME_MARKER)
                ? '/* '.self::RUNTIME_MARKER.' */'
                : '',
            $rendered,
        );
        if (! is_string($withoutComments)) {
            throw new RuntimeException('Das versionierte Runtime-CSS konnte nicht kompakt ausgegeben werden.');
        }

        $lines = preg_split('/\R/u', $withoutComments);
        if (! is_array($lines)) {
            throw new RuntimeException('Das versionierte Runtime-CSS konnte nicht in Zeilen gelesen werden.');
        }

        return implode('', array_map(static fn (string $line): string => trim($line), $lines));
    }

    private static function normalizeBorder(?string $border): string
    {
        $border = trim((string) ($border ?: '#e6e8ec'));

        if (preg_match('/^#[0-9a-f]{6}$/i', $border) !== 1) {
            throw new RuntimeException('Die Rahmenfarbe des System-Mail-CSS ist ungueltig.');
        }

        return strtolower($border);
    }
}
