<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use DOMDocument;
use DOMElement;

/**
 * Persistente Releasekennung eines portablen Signaturentwurfs.
 *
 * Die JSON-Bundle-Version beschreibt nur das Transportformat. Die fachliche
 * Signaturversion bleibt deshalb als data-Attribut an der ersten Hauptzeile
 * erhalten und kann auch nach Import, Speichern und Veroeffentlichen noch in
 * einer Testmail ausgewiesen werden.
 */
final class SignatureArtifactVersion
{
    public const ATTRIBUTE = 'data-rt-artifact-version';

    public const V7 = 'v7';

    public const V8 = 'v8';

    public const V9 = 'v9';

    public const V10 = 'v10';

    /**
     * V8 bis V10 teilen dieselbe abgeschlossene Zug-Timeline: Das Haupt-GIF
     * endet im Ankunftsbild und benoetigt deshalb kein separates Idle-Overlay.
     */
    public static function usesArrivalHoldTrain(?string $version): bool
    {
        return in_array($version, [self::V8, self::V9, self::V10], true);
    }

    public static function detect(MailDocumentKind|string $kind, string $html): ?string
    {
        $kind = is_string($kind) ? MailDocumentKind::tryFrom($kind) : $kind;
        if ($kind !== MailDocumentKind::Signature || trim($html) === '') {
            return null;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $source = preg_match('/<(?:html|body)\b/i', $html) === 1
                ? '<?xml encoding="UTF-8">'.$html
                : '<?xml encoding="UTF-8"><table><tbody>'.$html.'</tbody></table>';
            $loaded = $document->loadHTML(
                $source,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return null;
        }

        $markers = [];
        $layers = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            if ($element->hasAttribute(self::ATTRIBUTE)) {
                $markers[] = $element;
            }
            if ($element->tagName === 'div' && $element->hasAttribute('data-rt-layer-train')) {
                $layers[] = $element;
            }
        }

        if (count($markers) === 1 && strtolower($markers[0]->tagName) === 'tr') {
            $version = strtolower(trim($markers[0]->getAttribute(self::ATTRIBUTE)));

            return preg_match('/^v[1-9][0-9]{0,3}$/', $version) === 1 ? $version : null;
        }

        if ($markers !== [] || count($layers) !== 1) {
            return null;
        }

        // Das bereits ausgegebene v7-Bundle besass noch keinen Marker. Seine
        // eindeutige Geometrie bleibt als rueckwaertskompatibler Fingerabdruck
        // erkennbar, damit eine Testmail auch einen schon importierten Stand
        // ehrlich als v7 ausweisen kann.
        $layer = $layers[0];
        if (strtolower(trim($layer->getAttribute('data-rt-layer-align'))) === 'left'
            && trim($layer->getAttribute('data-rt-layer-size')) === '125'
            && strtolower(trim($layer->getAttribute('data-rt-layer-mobile'))) === 'left') {
            return self::V7;
        }

        return null;
    }
}
