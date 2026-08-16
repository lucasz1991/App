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
        // Firmenkontakte existieren bewusst nur einmal im DOM. Versteckte
        // Desktop-/Mobilkopien werden beim Antworten oder Weiterleiten von
        // manchen Mailclients gleichzeitig sichtbar.
        'WEBSITE' => 1,
        'COMPANY_PHONE' => 1,
        'COMPANY_EMAIL' => 1,
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
        'COMPANY_EMAIL' => [
            'tableClass' => 'rt-company-contact',
            'forbiddenClass' => null,
            'valueToken' => '{{FIRMEN_EMAIL}}',
        ],
    ];

    public static function assertValid(string $html, bool $allowLegacyTrainStill = false): void
    {
        self::assertContract(
            $html,
            allowLegacyTrainStill: $allowLegacyTrainStill,
            allowLegacyPaddedCarrier: false,
        );
    }

    /**
     * Laufzeitvertrag fuer bereits veroeffentlichte Signaturen.
     *
     * Neue Editor-/Publish-Staende muessen immer Schema 7 besitzen. Bis der
     * autoritative Seeder nach dem Deployment gelaufen ist, darf der Versand
     * jedoch noch genau die alte Schema-6-Topologie lesen. Sie wird bewusst
     * nicht per Regex umgebaut: ein solcher Umbau waere bei verschachtelten
     * Mailtabellen nicht positionssicher. Jede andere Zwischenform bricht
     * fail-closed ab.
     */
    public static function assertRuntimeValid(string $html): void
    {
        self::assertContract(
            $html,
            allowLegacyTrainStill: true,
            allowLegacyPaddedCarrier: true,
        );
    }

    private static function assertContract(
        string $html,
        bool $allowLegacyTrainStill,
        bool $allowLegacyPaddedCarrier,
    ): void {
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

        // Dieselbe kanonische Carrier-Semantik gilt auch vor der Outlook-
        // Bildprojektion. Kein Ausgabezweig darf die Background-Pruefung
        // umgehen.
        SignatureTrainCarrier::normalize($html);
        self::assertTableStructure($html, $allowLegacyPaddedCarrier);
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

    private static function assertTableStructure(
        string $html,
        bool $allowLegacyPaddedCarrier,
    ): void {
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

        $carrier = $trainCells[0];
        $carrierClasses = self::classes($carrier);
        $contentCells = [];
        foreach ($wrapper->getElementsByTagName('td') as $cell) {
            if (in_array('rt-sign-content', self::classes($cell), true)) {
                $contentCells[] = $cell;
            }
        }

        if (self::isSchemaSevenCarrier($html, $carrier, $carrierClasses, $contentCells)) {
            return;
        }

        if ($allowLegacyPaddedCarrier
            && self::isExactLegacySchemaSixCarrier($html, $carrier, $carrierClasses, $contentCells)) {
            return;
        }

        throw new RuntimeException(
            'Der Zug-Carrier muss aussen exakt padding:0 und innen genau einen mail-sicheren rt-pad/rt-sign-content-Wrapper mit Inhaltspadding besitzen.'
        );
    }

    /**
     * @param  list<string>  $carrierClasses
     * @param  list<DOMElement>  $contentCells
     */
    private static function isSchemaSevenCarrier(
        string $html,
        DOMElement $carrier,
        array $carrierClasses,
        array $contentCells,
    ): bool {
        if (! self::hasExactClasses($carrierClasses, ['rt-sign-cell'])
            || ! self::hasExactPadding($carrier, 'zero')
            || count($contentCells) !== 1
            || substr_count($html, 'rt-sign-content') !== 1) {
            return false;
        }

        $content = $contentCells[0];
        $contentClasses = self::classes($content);
        if (! self::hasExactClasses($contentClasses, ['rt-pad', 'rt-sign-content'])
            || ! self::hasExactPadding($content, 'content')) {
            return false;
        }

        $contentRow = $content->parentNode;
        $contentTable = $contentRow instanceof DOMElement
            ? self::closestAncestorElement($contentRow, 'table')
            : null;
        if (! $contentRow instanceof DOMElement
            || strtolower($contentRow->tagName) !== 'tr'
            || ! $contentTable instanceof DOMElement
            || ! $contentTable->parentNode?->isSameNode($carrier)
            || ! self::isMailSafeWrapperTable($contentTable)
            || ! self::firstElementChild($carrier)?->isSameNode($contentTable)
            || ! self::firstTableRow($contentTable)?->isSameNode($contentRow)) {
            return false;
        }

        $rowElements = self::elementChildren($contentRow);

        return count($rowElements) === 1 && $rowElements[0]->isSameNode($content);
    }

    /**
     * Exakt die bis Schema 6 ausgelieferte Form: das Inhaltspadding liegt auf
     * dem aeusseren rt-pad/rt-sign-cell, die erste direkte Tabelle enthaelt
     * unmittelbar die zweispaltige rt-stack-Zeile. Diese Ausnahme existiert
     * ausschliesslich im Runtime-Einstieg und verschwindet nach dem Seeder.
     *
     * @param  list<string>  $carrierClasses
     * @param  list<DOMElement>  $contentCells
     */
    private static function isExactLegacySchemaSixCarrier(
        string $html,
        DOMElement $carrier,
        array $carrierClasses,
        array $contentCells,
    ): bool {
        if ($contentCells !== []
            || str_contains($html, 'rt-sign-content')
            || ! self::hasExactClasses($carrierClasses, ['rt-pad', 'rt-sign-cell'])
            || ! self::hasExactPadding($carrier, 'content')) {
            return false;
        }

        $contentTable = self::firstElementChild($carrier);
        if (! $contentTable instanceof DOMElement
            || strtolower($contentTable->tagName) !== 'table'
            || ! self::isMailSafeWrapperTable($contentTable)) {
            return false;
        }

        $contentRow = self::firstTableRow($contentTable);

        return $contentRow instanceof DOMElement
            && in_array('rt-stack', self::classes($contentRow), true);
    }

    /** @return list<string> */
    private static function classes(DOMElement $element): array
    {
        return preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @param list<string> $actual @param list<string> $expected */
    private static function hasExactClasses(array $actual, array $expected): bool
    {
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private static function isMailSafeWrapperTable(DOMElement $table): bool
    {
        return strtolower($table->tagName) === 'table'
            && strtolower(trim($table->getAttribute('role'))) === 'presentation'
            && trim($table->getAttribute('width')) === '100%'
            && trim($table->getAttribute('border')) === '0'
            && trim($table->getAttribute('cellspacing')) === '0'
            && trim($table->getAttribute('cellpadding')) === '0';
    }

    private static function firstTableRow(DOMElement $table): ?DOMElement
    {
        $child = self::firstElementChild($table);
        if ($child instanceof DOMElement && strtolower($child->tagName) === 'tbody') {
            $child = self::firstElementChild($child);
        }

        return $child instanceof DOMElement && strtolower($child->tagName) === 'tr'
            ? $child
            : null;
    }

    private static function firstElementChild(DOMNode $node): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<DOMElement> */
    private static function elementChildren(DOMNode $node): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private static function hasExactPadding(DOMElement $element, string $mode): bool
    {
        if (! $element->hasAttribute('style')) {
            return false;
        }

        try {
            $declarations = self::paddingDeclarations(
                $element->getAttribute('style'),
                rejectBottomBorder: $mode === 'zero',
            );
        } catch (RuntimeException) {
            return false;
        }

        if (array_keys($declarations) !== ['padding']) {
            return false;
        }

        $value = strtolower(trim(preg_replace(
            '/[ \t\r\n\f]+/',
            ' ',
            $declarations['padding'],
        ) ?? $declarations['padding']));
        if ($mode === 'zero') {
            return $value === '0';
        }

        if (preg_match(
            '/^(?:0|(?:\d+(?:\.\d+)?|\.\d+)px)(?: (?:0|(?:\d+(?:\.\d+)?|\.\d+)px)){0,3}$/',
            $value,
        ) !== 1) {
            return false;
        }

        preg_match_all('/(?:\d+(?:\.\d+)?|\.\d+)px/', $value, $lengths);
        foreach ($lengths[0] ?? [] as $length) {
            if ((float) substr($length, 0, -2) > 0) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private static function paddingDeclarations(string $style, bool $rejectBottomBorder): array
    {
        $style = CssSemantic::decodeHtmlEntitiesOnce($style);
        if (preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', $style) !== 0
            || str_contains($style, '/*')
            || str_contains($style, '*/')
            || str_contains($style, '\\')) {
            throw new RuntimeException('Der Padding-Vertrag enthaelt unlesbares CSS.');
        }

        $declarations = [];
        foreach (self::splitCssAtTopLevel($style, ';') as $segment) {
            $colon = strpos($segment, ':');
            if ($colon === false) {
                continue;
            }

            $property = strtolower(trim(substr($segment, 0, $colon)));
            if ($property === 'mso-padding-alt') {
                throw new RuntimeException('mso-padding-alt darf den Padding-Vertrag nicht ueberschreiben.');
            }
            if ($rejectBottomBorder && self::canCreateBottomBorder($property)) {
                throw new RuntimeException('Der Zug-Carrier darf unterhalb des Zuges keinen Rahmen erzeugen.');
            }
            if ($property !== 'padding' && ! str_starts_with($property, 'padding-')) {
                continue;
            }
            if (! in_array($property, ['padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left'], true)
                || array_key_exists($property, $declarations)) {
                throw new RuntimeException('Der Padding-Vertrag ist mehrdeutig.');
            }

            $declarations[$property] = trim(substr($segment, $colon + 1));
        }

        return $declarations;
    }

    private static function canCreateBottomBorder(string $property): bool
    {
        // Nur die kanonische rote Oberkante darf am aeusseren TD bleiben.
        // Insbesondere mso-border-alt und mso-border-bottom-alt koennen im
        // Word-Renderer trotz padding:0 wieder Abstand zum Legal-Footer
        // erzeugen.
        return str_contains($property, 'border')
            && ! str_starts_with($property, 'border-top');
    }

    /** @return list<string> */
    private static function splitCssAtTopLevel(string $value, string $delimiter): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }
            if ($character === '(') {
                $depth++;

                continue;
            }
            if ($character === ')') {
                if ($depth === 0) {
                    throw new RuntimeException('Der Padding-Vertrag enthaelt unbalanciertes CSS.');
                }
                $depth--;

                continue;
            }
            if ($character === $delimiter && $depth === 0) {
                $parts[] = substr($value, $start, $index - $start);
                $start = $index + 1;
            }
        }

        if ($quote !== null || $depth !== 0) {
            throw new RuntimeException('Der Padding-Vertrag enthaelt unbalanciertes CSS.');
        }

        $parts[] = substr($value, $start);

        return $parts;
    }
}
