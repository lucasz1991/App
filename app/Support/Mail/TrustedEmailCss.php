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

    public static function responsive(?string $border = null): string
    {
        $border = self::normalizeBorder($border);
        $css = trim(view('emails.parts.responsive-css', [
            'border' => $border,
        ])->render());

        self::assertResponsive($css, $border);

        return $css;
    }

    public static function assertResponsive(string $css, ?string $border = null): void
    {
        $border = self::normalizeBorder($border);
        $expected = trim(view('emails.parts.responsive-css', [
            'border' => $border,
        ])->render());

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

    private static function normalizeBorder(?string $border): string
    {
        $border = trim((string) ($border ?: '#e6e8ec'));

        if (preg_match('/^#[0-9a-f]{6}$/i', $border) !== 1) {
            throw new RuntimeException('Die Rahmenfarbe des System-Mail-CSS ist ungueltig.');
        }

        return strtolower($border);
    }
}
