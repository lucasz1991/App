<?php

namespace App\Support\Mail;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/** V30: linked train images in normal table flow, never overlapping contacts. */
final class SignatureHotline
{
    public static function applies(string $html): bool
    {
        return SignatureArtifactVersion::detect('signature', $html) === SignatureArtifactVersion::V30;
    }

    public static function assertValid(string $html): void
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><table id="rt-hotline-root"><tbody>'.$html.'</tbody></table>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//*[@id="rt-hotline-root"]/tbody/tr');
        $banners = $xpath->query('//table[@data-rt-hotline-train]');
        if ($rows->length !== 2 || $banners->length !== 1
            || $rows->item(0)->getAttribute(SignatureArtifactVersion::ATTRIBUTE) !== SignatureArtifactVersion::V30) {
            throw new RuntimeException('V30 benoetigt zwei Hauptzeilen und genau einen Hotline-Zugbalken.');
        }
        $banner = $banners->item(0);
        $links = $banner->getElementsByTagName('a');
        $images = $banner->getElementsByTagName('img');
        if ($links->length !== 3 || $images->length !== 4) {
            throw new RuntimeException('V30 benoetigt drei verlinkte Waggons und eine Lok als IMG.');
        }
        foreach (['mailto:', 'tel:', 'mailto:'] as $index => $scheme) {
            $link = $links->item($index);
            if (! str_starts_with(strtolower(trim($link->getAttribute('href'))), $scheme)
                || $link->getElementsByTagName('img')->length !== 1) {
                throw new RuntimeException('Die Hotline-Waggons benoetigen direkte E-Mail-, Telefon- und E-Mail-Bildlinks.');
            }
        }
        foreach ($images as $image) {
            if (! $image instanceof DOMElement || trim($image->getAttribute('src')) === ''
                || (int) $image->getAttribute('width') <= 0 || (int) $image->getAttribute('height') <= 0) {
                throw new RuntimeException('Die V30-Zugbilder benoetigen Quelle und proportionale Bildmasse.');
            }
        }
        // Older protected carriers must not be mixed with this opt-in layout.
        if (str_contains($html, '{{TRAIN_SRC}}') || preg_match('/\b(?:rt-sign-train|data-rt-layer-train)\b/i', $html)) {
            throw new RuntimeException('V30 darf keinen zweiten alten Zug-Layer enthalten.');
        }
        foreach ($dom->getElementsByTagName('*') as $element) {
            $style = CssSemantic::decodeHtmlEntitiesOnce($element->getAttribute('style'));
            if (preg_match('/(?:^|;)\s*(?:position\s*:|(?:margin(?:-[a-z]+)?)\s*:[^;]*-\d|(?:height|min-height|max-height)\s*:)/i', $style)
                && strtolower($element->tagName) !== 'img') {
                throw new RuntimeException('V30-Inhalte bleiben ohne Positionierung, negative Abstaende und feste Hoehen im Tabellenfluss.');
            }
        }
    }

    public static function assertRuntime(string $html): void
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $rows = (new DOMXPath($dom))->query('//tr[@data-rt-artifact-version="v30"]');
        if ($rows->length !== 1) {
            throw new RuntimeException('Die V30-Laufzeitfassung besitzt keine eindeutige Signatur.');
        }
        $row = $rows->item(0);
        $legal = $row->nextSibling;
        while ($legal !== null && ! $legal instanceof DOMElement) {
            $legal = $legal->nextSibling;
        }
        if (! $legal instanceof DOMElement || $legal->tagName !== 'tr') {
            throw new RuntimeException('Die V30-Rechtszeile fehlt.');
        }
        self::assertValid($dom->saveHTML($row).$dom->saveHTML($legal));
    }
}
