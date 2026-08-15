<?php

namespace App\Support\Mail;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use RuntimeException;

/** Gemeinsamer Save-/Publish-/Web-/Outlook-Vertrag der Signaturquelle. */
final class SignatureDocumentContract
{
    /** @var list<string> */
    private const REQUIRED_TOKENS = [
        '{{LOGO_SRC}}',
        '{{VORNAME_NACHNAME}}',
        '{{POSITION}}',
        '{{E_MAIL}}',
        '{{FIRMENNAME}}',
        '{{FIRMENSTRASSE}}',
        '{{FIRMEN_PLZ_ORT}}',
        '{{FIRMEN_TELEFON}}',
        '{{FIRMEN_EMAIL}}',
        '{{GESCHAEFTSFUEHRUNG}}',
        '{{REGISTERGERICHT}}',
        '{{HRB}}',
        '{{UST_ID}}',
        '{{STEUERNUMMER}}',
    ];

    /** @var array<string, int> */
    private const EXPECTED_MARKER_PAIRS = [
        'PHONE' => 1,
        'MOBILE' => 1,
        'WEBSITE' => 2,
        'COMPANY_PHONE' => 2,
    ];

    /** @var array<string, array{tableClass:string, forbiddenClass:?string, valueToken:string}> */
    private const MARKER_CONTEXTS = [
        'PHONE' => [
            'tableClass' => 'rt-contact',
            'forbiddenClass' => 'rt-company-contact',
            'valueToken' => '{{DURCHWAHL}}',
        ],
        'MOBILE' => [
            'tableClass' => 'rt-contact',
            'forbiddenClass' => 'rt-company-contact',
            'valueToken' => '{{MOBIL}}',
        ],
        'WEBSITE' => [
            'tableClass' => 'rt-company-contact',
            'forbiddenClass' => null,
            'valueToken' => '{{FIRMEN_WEBSITE_LABEL}}',
        ],
        'COMPANY_PHONE' => [
            'tableClass' => 'rt-company-contact',
            'forbiddenClass' => null,
            'valueToken' => '{{FIRMEN_TELEFON}}',
        ],
    ];

    public static function assertValid(string $html, bool $allowLegacyTrainStill = false): void
    {
        $decodedHtml = CssSemantic::decodeHtmlEntitiesOnce($html);
        if (preg_match('/<style\b/i', $decodedHtml) === 1) {
            throw new RuntimeException('Das Signaturfragment darf keinen eigenen style-Block enthalten.');
        }

        foreach (self::REQUIRED_TOKENS as $needle) {
            if (! str_contains($html, $needle)) {
                throw new RuntimeException('Die Signatur besitzt nicht mehr alle Pflichtwerte und Kontaktmarker.');
            }
        }

        self::assertExactMarkers($html);
        self::assertLegacyTrainStill($html, $decodedHtml, $allowLegacyTrainStill);
        self::assertTableStructure($html);

        // Dieselbe kanonische Carrier-Semantik gilt auch vor der Outlook-
        // Bildprojektion. Kein Ausgabezweig darf die Background-Pruefung
        // umgehen.
        SignatureTrainCarrier::normalize($html);
    }

    private static function assertExactMarkers(string $html): void
    {
        $mainName = 'RT_SIGNATURE_MAIN_END';
        $mainComment = '<!-- '.$mainName.' -->';
        if (substr_count($html, $mainComment) !== 1
            || substr_count($html, $mainName) !== 1) {
            throw new RuntimeException('Der Hauptsignatur-Marker muss genau einmal als exakter Kommentar vorliegen.');
        }

        $wrapperId = 'rt-signature-marker-contract-'.hash('sha256', $html);
        while (str_contains($html, $wrapperId)) {
            $wrapperId .= 'x';
        }
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><table id="'.$wrapperId.'"><tbody>'.$html.'</tbody></table>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $wrapper = $loaded ? $document->getElementById($wrapperId) : null;
        if (! $wrapper instanceof DOMElement) {
            throw new RuntimeException('Die Signaturmarker konnten nicht strukturell gelesen werden.');
        }

        $comments = [];
        $commentNodes = [];
        $nodes = (new DOMXPath($document))->query('.//comment()', $wrapper);
        if ($nodes === false) {
            throw new RuntimeException('Die Signaturmarker konnten nicht strukturell gelesen werden.');
        }
        foreach ($nodes as $node) {
            if ($node instanceof DOMComment) {
                $name = trim($node->data);
                $comments[] = $name;
                $commentNodes[$name][] = $node;
            }
        }
        if (count(array_filter($comments, static fn (string $value): bool => $value === $mainName)) !== 1) {
            throw new RuntimeException('Der Hauptsignatur-Marker ist kein echter HTML-Kommentar.');
        }

        $tbody = $wrapper->getElementsByTagName('tbody')->item(0);
        if (! $tbody instanceof DOMElement) {
            throw new RuntimeException('Die Signatur besitzt keinen eindeutigen Tabellenkoerper.');
        }
        $topRows = [];
        foreach ($tbody->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'tr') {
                $topRows[] = $child;
            }
        }
        $mainNode = ($commentNodes[$mainName] ?? [])[0] ?? null;
        if (count($topRows) !== 2
            || ! $mainNode instanceof DOMComment
            || ! $mainNode->parentNode?->isSameNode($tbody)
            || ! self::nextSignificantSibling($topRows[0])?->isSameNode($mainNode)
            || ! self::nextSignificantSibling($mainNode)?->isSameNode($topRows[1])) {
            throw new RuntimeException('Der Hauptsignatur-Marker muss als direkter Sibling zwischen den zwei obersten Zeilen liegen.');
        }

        foreach (self::EXPECTED_MARKER_PAIRS as $marker => $expectedPairs) {
            $startName = 'RT_'.$marker.'_START';
            $endName = 'RT_'.$marker.'_END';
            $startComment = '<!-- '.$startName.' -->';
            $endComment = '<!-- '.$endName.' -->';
            $starts = self::offsets($html, $startComment);
            $ends = self::offsets($html, $endComment);
            $commentStartCount = count(array_filter(
                $comments,
                static fn (string $value): bool => $value === $startName,
            ));
            $commentEndCount = count(array_filter(
                $comments,
                static fn (string $value): bool => $value === $endName,
            ));
            $startNodes = $commentNodes[$startName] ?? [];
            $endNodes = $commentNodes[$endName] ?? [];

            if (count($starts) !== $expectedPairs
                || count($ends) !== $expectedPairs
                || substr_count($html, $startName) !== $expectedPairs
                || substr_count($html, $endName) !== $expectedPairs
                || $commentStartCount !== $expectedPairs
                || $commentEndCount !== $expectedPairs) {
                throw new RuntimeException('Die Kontaktmarker muessen in ihrer exakten Kommentaranzahl vorliegen.');
            }

            for ($index = 0; $index < $expectedPairs; $index++) {
                if ($starts[$index] >= $ends[$index]
                    || ($index > 0 && $starts[$index] <= $ends[$index - 1])) {
                    throw new RuntimeException('Die Kontaktmarker-Paare sind nicht geordnet oder ueberlappen sich.');
                }

                $startNode = $startNodes[$index] ?? null;
                $endNode = $endNodes[$index] ?? null;
                $row = $startNode instanceof DOMComment
                    ? self::nextSignificantSibling($startNode)
                    : null;
                $closing = $row instanceof DOMNode
                    ? self::nextSignificantSibling($row)
                    : null;
                if (! $startNode instanceof DOMComment
                    || ! $endNode instanceof DOMComment
                    || ! $row instanceof DOMElement
                    || strtolower($row->tagName) !== 'tr'
                    || ! $closing?->isSameNode($endNode)
                    || ! $startNode->parentNode?->isSameNode($row->parentNode)
                    || ! $endNode->parentNode?->isSameNode($row->parentNode)) {
                    throw new RuntimeException('Jedes Kontaktmarker-Paar muss als Sibling-Kommentare genau eine Tabellenzeile umschliessen.');
                }

                $context = self::MARKER_CONTEXTS[$marker];
                $table = self::closestAncestorElement($row, 'table');
                $tableClasses = $table instanceof DOMElement
                    ? preg_split('/\s+/', trim($table->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: []
                    : [];
                if (! $table instanceof DOMElement
                    || ! in_array($context['tableClass'], $tableClasses, true)
                    || ($context['forbiddenClass'] !== null
                        && in_array($context['forbiddenClass'], $tableClasses, true))
                    || ! str_contains($row->textContent, $context['valueToken'])) {
                    throw new RuntimeException('Kontaktmarker muessen ihre kanonische Tabelle und Wertzeile behalten.');
                }
            }
        }
    }

    private static function closestAncestorElement(DOMNode $node, string $tagName): ?DOMElement
    {
        for ($ancestor = $node->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
            if ($ancestor instanceof DOMElement
                && strtolower($ancestor->tagName) === strtolower($tagName)) {
                return $ancestor;
            }
        }

        return null;
    }

    private static function nextSignificantSibling(DOMNode $node): ?DOMNode
    {
        for ($sibling = $node->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
            if ($sibling instanceof DOMText && trim($sibling->wholeText) === '') {
                continue;
            }

            return $sibling;
        }

        return null;
    }

    /** @return list<int> */
    private static function offsets(string $html, string $needle): array
    {
        $offsets = [];
        $offset = 0;
        while (($found = strpos($html, $needle, $offset)) !== false) {
            $offsets[] = $found;
            $offset = $found + strlen($needle);
        }

        return $offsets;
    }

    private static function assertLegacyTrainStill(
        string $html,
        string $decodedHtml,
        bool $allowLegacyTrainStill,
    ): void {
        $background = SignatureTrainCarrier::carrierAttribute($html, 'background');
        $stillCount = substr_count($decodedHtml, '{{TRAIN_STILL_SRC}}');
        $rawStillCount = substr_count($html, '{{TRAIN_STILL_SRC}}');

        if (! $allowLegacyTrainStill) {
            if ($stillCount !== 0 || $background !== null) {
                throw new RuntimeException('TRAIN_STILL_SRC und legacy background-Attribute sind in neuen Signaturen nicht zulaessig.');
            }

            return;
        }

        if ($stillCount === 0) {
            if ($background !== null) {
                throw new RuntimeException('Die Signatur besitzt ein unzulaessiges background-Attribut.');
            }

            return;
        }

        if ($stillCount !== 1
            || $rawStillCount !== 1
            || $background === null
            || ! in_array($background['quote'], ['"', "'"], true)
            || $background['raw'] !== '{{TRAIN_STILL_SRC}}'
            || $background['decoded'] !== '{{TRAIN_STILL_SRC}}') {
            throw new RuntimeException('TRAIN_STILL_SRC ist nur als exaktes legacy background-Attribut am Zug-Carrier zulaessig.');
        }
    }

    private static function assertTableStructure(string $html): void
    {
        $wrapperId = 'rt-signature-contract-'.hash('sha256', $html);
        while (str_contains($html, $wrapperId)) {
            $wrapperId .= 'x';
        }
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><table id="'.$wrapperId.'"><tbody>'.$html.'</tbody></table>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $wrapper = $loaded ? $document->getElementById($wrapperId) : null;
        $tbody = $wrapper?->getElementsByTagName('tbody')->item(0);
        if (! $wrapper instanceof DOMElement || ! $tbody instanceof DOMElement) {
            throw new RuntimeException('Die Signatur ist kein eindeutiges Tabellenfragment.');
        }

        $topRows = [];
        foreach ($tbody->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'tr') {
                $topRows[] = $child;
            }
        }
        if (count($topRows) !== 2) {
            throw new RuntimeException('Die Signatur muss genau zwei oberste Tabellenzeilen behalten.');
        }

        $trainCells = [];
        foreach ($wrapper->getElementsByTagName('td') as $cell) {
            $classes = preg_split('/\s+/', trim($cell->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (in_array('rt-sign-cell', $classes, true)) {
                $trainCells[] = $cell;
            }
        }

        if (count($trainCells) !== 1
            || ! $trainCells[0]->parentNode?->isSameNode($topRows[0])) {
            throw new RuntimeException('Der kanonische Zug-Carrier muss in der ersten obersten Signaturzeile liegen.');
        }
    }
}
