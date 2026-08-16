<?php

namespace App\Support\Mail;

use DOMDocument;
use DOMElement;
use RuntimeException;

/** Gemeinsamer Save-/Publish-/Runtime-Vertrag der Systemmail-Schale. */
final class TemplateDocumentContract
{
    public static function assertValid(string $html): void
    {
        if (preg_match('/^\s*<!doctype\s+html\b/i', $html) !== 1
            || preg_match('/<html\b[^>]*>.*<head\b[^>]*>.*<\/head>.*<body\b[^>]*>.*<\/body>.*<\/html>\s*$/is', $html) !== 1
            || preg_match_all('/<head\b/i', $html) !== 1
            || preg_match_all('/<\/head\s*>/i', $html) !== 1
            || preg_match_all('/<body\b/i', $html) !== 1
            || preg_match_all('/<\/body\s*>/i', $html) !== 1) {
            throw new RuntimeException('Die Nachrichtenvorlage besitzt keine eindeutige vollstaendige HTML-Schale.');
        }

        if (CssSemantic::containsReservedTrainToken($html)) {
            throw new RuntimeException('Die Nachrichtenvorlage enthaelt eine reservierte Zugquelle ausserhalb des Signaturblocks.');
        }

        self::assertCanonicalHeadStyle($html);
        self::assertCanonicalMarkFragment($html);
        self::assertApplicationSlot($html);
        self::assertSignatureSlot($html);
    }

    /**
     * Das animierte RT-Zeichen und sein MSO-Standbild sind ein untrennbarer
     * Headervertrag. Ohne das Standbild zeigt Outlook bei deaktivierten
     * Animationen nur den transparenten ersten GIF-Frame.
     */
    private static function assertCanonicalMarkFragment(string $html): void
    {
        $master = file_get_contents(resource_path('mail-templates/email-master.html'));
        if (! is_string($master)) {
            throw new RuntimeException('Die kanonische Nachrichtenvorlage konnte nicht gelesen werden.');
        }

        $startMarker = '<!-- RT_TEMPLATE_MARK_START -->';
        $endMarker = '<!-- RT_TEMPLATE_MARK_END -->';
        $fragment = static function (string $source) use ($startMarker, $endMarker): ?string {
            if (substr_count($source, $startMarker) !== 1
                || substr_count($source, $endMarker) !== 1
                || substr_count($source, 'RT_TEMPLATE_MARK_START') !== 1
                || substr_count($source, 'RT_TEMPLATE_MARK_END') !== 1) {
                return null;
            }

            $start = strpos($source, $startMarker);
            $end = strpos($source, $endMarker);
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }

            return substr($source, $start, ($end + strlen($endMarker)) - $start);
        };

        $masterFragment = $fragment($master);
        $documentFragment = $fragment($html);
        $applicationSlot = strpos($html, '<!-- RT_APPLICATION_CONTENT_START -->');
        $markOffset = strpos($html, $startMarker);

        if ($masterFragment === null
            || $documentFragment === null
            || ! hash_equals($masterFragment, $documentFragment)
            || substr_count($html, '{{ICON_RT_SRC}}') !== 1
            || substr_count($html, '{{ICON_RT_STILL_SRC}}') !== 1
            || $applicationSlot === false
            || $markOffset === false
            || $markOffset >= $applicationSlot) {
            throw new RuntimeException('Das RT-Zeichen und sein Outlook-Standbild muessen den kanonischen Headervertrag behalten.');
        }
    }

    private static function assertCanonicalHeadStyle(string $html): void
    {
        $master = file_get_contents(resource_path('mail-templates/email-master.html'));
        if (! is_string($master)) {
            throw new RuntimeException('Die kanonische Nachrichtenvorlage konnte nicht gelesen werden.');
        }

        preg_match_all('/<style\b[^>]*>.*?<\/style\s*>/is', $master, $masterStyles);
        preg_match_all(
            '/<style\b[^>]*>.*?<\/style\s*>/is',
            $html,
            $documentStyles,
            PREG_OFFSET_CAPTURE,
        );

        if (count($masterStyles[0] ?? []) !== 1
            || count($documentStyles[0] ?? []) !== 1
            || substr_count($html, '{{RESPONSIVE_CSS}}') !== 1
            || ! hash_equals($masterStyles[0][0], $documentStyles[0][0][0])) {
            throw new RuntimeException('Der kanonische Head-Styleblock der Nachrichtenvorlage wurde veraendert oder vervielfacht.');
        }

        preg_match('/<head\b[^>]*>/i', $html, $headOpen, PREG_OFFSET_CAPTURE);
        preg_match('/<\/head\s*>/i', $html, $headClose, PREG_OFFSET_CAPTURE);
        $styleOffset = $documentStyles[0][0][1];
        $headContentStart = ($headOpen[0][1] ?? -1) + strlen($headOpen[0][0] ?? '');
        $headContentEnd = $headClose[0][1] ?? -1;

        if ($headContentStart < 0
            || $headContentEnd < 0
            || $styleOffset < $headContentStart
            || $styleOffset >= $headContentEnd) {
            throw new RuntimeException('Der kanonische Styleblock liegt nicht im eindeutigen Dokumentkopf.');
        }

        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $styles = $loaded ? $document->getElementsByTagName('style') : null;
        $style = $styles?->item(0);
        if ($styles?->length !== 1
            || ! $style instanceof DOMElement
            || ! $style->parentNode instanceof DOMElement
            || strtolower($style->parentNode->tagName) !== 'head') {
            throw new RuntimeException('Der kanonische Styleblock ist kein echtes Element im Dokumentkopf.');
        }
    }

    private static function assertApplicationSlot(string $html): void
    {
        $startComment = '<!-- RT_APPLICATION_CONTENT_START -->';
        $endComment = '<!-- RT_APPLICATION_CONTENT_END -->';
        $start = strpos($html, $startComment);
        $slot = strpos($html, '{{APPLICATION_CONTENT}}');
        $end = strpos($html, $endComment);

        if (substr_count($html, '{{APPLICATION_CONTENT}}') !== 1
            || substr_count($html, $startComment) !== 1
            || substr_count($html, $endComment) !== 1
            || substr_count($html, 'RT_APPLICATION_CONTENT_START') !== 1
            || substr_count($html, 'RT_APPLICATION_CONTENT_END') !== 1
            || $start === false
            || $slot === false
            || $end === false
            || ! ($start < $slot && $slot < $end)) {
            throw new RuntimeException('Die Nachrichtenvorlage besitzt nicht die exakten Anwendungsslot-Kommentare.');
        }

        $sentinel = 'rt-application-contract-slot-'.hash('sha256', $html);
        while (str_contains($html, $sentinel)) {
            $sentinel .= 'x';
        }
        $projected = preg_replace(
            '/<!-- RT_APPLICATION_CONTENT_START -->.*?<!-- RT_APPLICATION_CONTENT_END -->/s',
            '<tr id="'.$sentinel.'"><td></td></tr>',
            $html,
            1,
            $replacementCount,
        );
        if (! is_string($projected) || $replacementCount !== 1) {
            throw new RuntimeException('Der Anwendungsslot konnte nicht eindeutig projiziert werden.');
        }

        self::assertProjectedTableRow(
            $projected,
            $sentinel,
            'Der Anwendungsslot ist kein echter Tabellenanker im Dokumentkoerper.',
        );
    }

    private static function assertSignatureSlot(string $html): void
    {
        if (substr_count($html, '{{SIGNATURE_BLOCK}}') !== 1) {
            throw new RuntimeException('Die Nachrichtenvorlage besitzt keinen eindeutigen Signaturblock.');
        }

        $slotOffset = strpos($html, '{{SIGNATURE_BLOCK}}');
        preg_match('/<body\b[^>]*>/i', $html, $bodyOpen, PREG_OFFSET_CAPTURE);
        preg_match('/<\/body\s*>/i', $html, $bodyClose, PREG_OFFSET_CAPTURE);
        $bodyContentStart = ($bodyOpen[0][1] ?? -1) + strlen($bodyOpen[0][0] ?? '');
        $bodyContentEnd = $bodyClose[0][1] ?? -1;

        if ($slotOffset === false
            || $bodyContentStart < 0
            || $bodyContentEnd < 0
            || $slotOffset < $bodyContentStart
            || $slotOffset >= $bodyContentEnd) {
            throw new RuntimeException('Der Signaturblock liegt nicht im Dokumentkoerper.');
        }

        $sentinel = 'rt-signature-contract-slot-'.hash('sha256', $html);
        while (str_contains($html, $sentinel)) {
            $sentinel .= 'x';
        }
        $projected = str_replace(
            '{{SIGNATURE_BLOCK}}',
            '<tr id="'.$sentinel.'"><td></td></tr>',
            $html,
        );
        self::assertProjectedTableRow(
            $projected,
            $sentinel,
            'Der Signaturblock liegt nicht als echte Tabellenzeile im Dokumentkoerper.',
        );
    }

    private static function assertProjectedTableRow(
        string $html,
        string $sentinel,
        string $message,
    ): void {
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $slot = $loaded ? $document->getElementById($sentinel) : null;
        $parent = $slot?->parentNode;
        if (! $slot instanceof DOMElement
            || strtolower($slot->tagName) !== 'tr'
            || ! $parent instanceof DOMElement
            || ! in_array(strtolower($parent->tagName), ['table', 'thead', 'tbody', 'tfoot'], true)) {
            throw new RuntimeException($message);
        }

        $hasTable = false;
        $hasBody = false;
        for ($ancestor = $parent; $ancestor !== null; $ancestor = $ancestor->parentNode) {
            if (! $ancestor instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($ancestor->tagName);
            $hasTable = $hasTable || $tag === 'table';
            $hasBody = $hasBody || $tag === 'body';
        }

        if (! $hasTable || ! $hasBody) {
            throw new RuntimeException($message);
        }
    }
}
