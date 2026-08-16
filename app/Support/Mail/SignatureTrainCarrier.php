<?php

namespace App\Support\Mail;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Ein gemeinsamer, fail-closed Vertrag fuer den Zug-Carrier im Editor und
 * beim Versand. Gueltige Legacy-Longhands werden dabei nur im Runtime-Ergebnis
 * auf den heutigen Hauptzug normalisiert; das gespeicherte HTML bleibt gleich.
 */
final class SignatureTrainCarrier
{
    /** @var list<string> */
    private const ALLOWED_STYLE_TOKENS = [
        'SIGNATURE_BG',
        'GRUND_RASTER_SRC',
        'GRUND_MARKE_SRC',
        'SIGNATURE_TRAIN_WASH',
        'TRAIN_SRC',
        'TRAIN_IDLE_SRC',
    ];

    public static function isValid(string $html): bool
    {
        try {
            self::normalize($html);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function normalize(string $html): string
    {
        if (substr_count($html, '{{TRAIN_SRC}}') !== 1) {
            throw new RuntimeException(
                'Die veroeffentlichte Signatur besitzt keinen eindeutigen Zug-Platzhalter.'
            );
        }

        if (substr_count($html, '{{TRAIN_IDLE_SRC}}') > 1) {
            throw new RuntimeException(
                'Die veroeffentlichte Signatur besitzt mehrere Idle-Zug-Platzhalter.'
            );
        }

        $carrier = self::inspectCarrier($html);
        $styles = $carrier['attributes']['style'] ?? [];
        if (count($styles) !== 1 || $styles[0]['valueOffset'] === null) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier besitzt kein eindeutiges style-Attribut.'
            );
        }

        $styleAttribute = $styles[0];
        $style = $styleAttribute['raw'];
        // Genau eine HTML-Entity-Dekodierung spiegelt den Zustand wider, den
        // der Mailclient aus dem Attribut als CSS liest. Andernfalls koennte
        // etwa `&#59;background:none` fuer den Rohparser unsichtbar bleiben.
        $decodedStyle = CssSemantic::decodeHtmlEntitiesOnce($style);
        $normalizedStyle = htmlspecialchars(
            self::normalizeStyle($decodedStyle),
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $normalized = $normalizedStyle === $style
            ? $html
            : substr_replace(
                $html,
                $normalizedStyle,
                $styleAttribute['valueOffset'],
                $styleAttribute['valueLength'],
            );

        if (substr_count($normalized, '{{TRAIN_SRC}}') !== 1
            || str_contains($normalized, '{{TRAIN_IDLE_SRC}}')) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier konnte nicht eindeutig normalisiert werden.'
            );
        }

        return $normalized;
    }

    /**
     * Loest den nach demselben Vertrag validierten Hauptzug aus den vier
     * parallelen Background-Listen. Raster, Wasserzeichen und Grundschleier
     * bleiben unveraendert; der Zug kann danach genau einmal als regulaeres
     * IMG ausgegeben werden.
     */
    public static function withoutMainLayer(string $html): string
    {
        $normalized = self::normalize($html);
        $carrier = self::inspectCarrier($normalized);
        $styles = $carrier['attributes']['style'] ?? [];
        if (count($styles) !== 1 || $styles[0]['valueOffset'] === null) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier besitzt kein eindeutiges style-Attribut.'
            );
        }

        $styleAttribute = $styles[0];
        $projectedStyle = htmlspecialchars(
            self::normalizeStyle(
                CssSemantic::decodeHtmlEntitiesOnce($styleAttribute['raw']),
                removeMainLayer: true,
            ),
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $projected = substr_replace(
            $normalized,
            $projectedStyle,
            $styleAttribute['valueOffset'],
            $styleAttribute['valueLength'],
        );

        if (str_contains($projected, '{{TRAIN_SRC}}')
            || str_contains($projected, '{{TRAIN_IDLE_SRC}}')) {
            throw new RuntimeException(
                'Der Hauptzug konnte nicht eindeutig aus dem Background-Carrier geloest werden.'
            );
        }

        return $projected;
    }

    /**
     * Projiziert den streng validierten Carrier in denselben einfachen
     * Ein-GIF-Vertrag wie Logo und RT-Icon. Versand, Download und Admin-
     * Vorschau teilen dadurch exakt dieselbe Bildstruktur.
     */
    public static function projectAsImage(string $html, string $source, string $padding = '0'): string
    {
        $source = trim($source);
        if ($source === '') {
            throw new RuntimeException('Die Zuganimation besitzt keine eindeutige Bildquelle.');
        }

        $html = self::withoutMainLayer($html);
        $marker = '<!-- RT_SIGNATURE_MAIN_END -->';
        if (substr_count($html, $marker) !== 1) {
            throw new RuntimeException(
                'Die veroeffentlichte Signatur besitzt keinen eindeutigen Bildzeilen-Anker.'
            );
        }

        $source = htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $padding = htmlspecialchars($padding, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $image = '<img class="rt-sign-train" data-rt-train src="'.$source.'" width="100%" '
            .'alt="" style="display:block;width:100%;max-width:1815px;'
            .'height:auto;margin:0;border:0;outline:none;text-decoration:none;">';
        $row = '<tr><td align="left" style="padding:'.$padding
            .';text-align:left;font-size:0;line-height:0;">'.$image.'</td></tr>';

        return str_replace($marker, $marker.$row, $html);
    }

    /**
     * Liefert ein echtes Attribut des per DOM bestaetigten Carrier-TD.
     * Doppelte Attribute sind immer mehrdeutig und werden fail-closed
     * abgelehnt.
     *
     * @return array{raw:string, decoded:string, quote:?string}|null
     */
    public static function carrierAttribute(string $html, string $name): ?array
    {
        $carrier = self::inspectCarrier($html);
        $attributes = $carrier['attributes'][strtolower($name)] ?? [];
        if ($attributes === []) {
            return null;
        }
        if (count($attributes) !== 1) {
            throw new RuntimeException('Der Zug-Carrier besitzt ein Attribut mehrfach.');
        }

        return [
            'raw' => $attributes[0]['raw'],
            'decoded' => $attributes[0]['decoded'],
            'quote' => $attributes[0]['quote'],
        ];
    }

    /**
     * @return array{
     *   attributes:array<string,list<array{
     *     raw:string,
     *     decoded:string,
     *     quote:?string,
     *     valueOffset:?int,
     *     valueLength:int
     *   }>>
     * }
     */
    private static function inspectCarrier(string $html): array
    {
        $wrapperId = 'rt-train-carrier-contract-'.hash('sha256', $html);
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
            throw new RuntimeException('Die veroeffentlichte Signatur besitzt keinen lesbaren Zug-Carrier.');
        }

        $domCells = [];
        $domCarriers = [];
        foreach ($wrapper->getElementsByTagName('td') as $cell) {
            $domCells[] = $cell;
            $classes = preg_split('/\s+/', trim($cell->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (in_array('rt-sign-cell', $classes, true)) {
                $domCarriers[] = $cell;
            }
        }
        if (count($domCarriers) !== 1 || ! $domCarriers[0]->hasAttribute('style')) {
            throw new RuntimeException('Die veroeffentlichte Signatur besitzt keinen eindeutigen echten Zug-Carrier.');
        }

        $sourceCells = [];
        $sourceCarriers = [];
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] !== 'td') {
                continue;
            }
            $sourceOrdinal = count($sourceCells);
            $sourceCells[] = $tag;
            $classAttributes = $tag['attributes']['class'] ?? [];
            if (count($classAttributes) > 1) {
                throw new RuntimeException('Der Zug-Carrier besitzt das class-Attribut mehrfach.');
            }
            if ($classAttributes === []) {
                continue;
            }
            $classes = preg_split(
                '/\s+/',
                trim($classAttributes[0]['decoded']),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
            if (in_array('rt-sign-cell', $classes, true)) {
                $sourceCarriers[] = [
                    'ordinal' => $sourceOrdinal,
                    'tag' => $tag,
                ];
            }
        }

        if (count($sourceCarriers) !== 1 || count($sourceCells) !== count($domCells)) {
            throw new RuntimeException('Die Zug-Carrier-Quelle ist nicht eindeutig lesbar.');
        }
        $domCarrierOrdinal = null;
        foreach ($domCells as $ordinal => $cell) {
            if ($cell->isSameNode($domCarriers[0])) {
                $domCarrierOrdinal = $ordinal;

                break;
            }
        }
        $sourceCarrier = $sourceCarriers[0]['tag'];
        $sourceClass = $sourceCarrier['attributes']['class'][0]['decoded'] ?? null;
        $styleAttributes = $sourceCarrier['attributes']['style'] ?? [];
        if ($domCarrierOrdinal === null
            || $sourceCarriers[0]['ordinal'] !== $domCarrierOrdinal
            || ! is_string($sourceClass)
            || ! hash_equals($domCarriers[0]->getAttribute('class'), $sourceClass)
            || count($styleAttributes) !== 1
            || $styleAttributes[0]['valueOffset'] === null
            || $styleAttributes[0]['quote'] === null
            || ! hash_equals(
                $domCarriers[0]->getAttribute('style'),
                $styleAttributes[0]['decoded'],
            )) {
            throw new RuntimeException('Das echte style-Attribut des Zug-Carriers ist nicht eindeutig abbildbar.');
        }

        return ['attributes' => $sourceCarrier['attributes']];
    }

    /**
     * Minimaler positionssicherer HTML-Starttagscanner. Er ueberspringt
     * Kommentare und komplette gequotete Attributwerte; ein darin nur als
     * Text vorkommendes `<td ... style=...>` kann deshalb nie Carrier sein.
     *
     * @return list<array{
     *   name:string,
     *   startOffset:int,
     *   endOffset:int,
     *   attributes:array<string,list<array{
     *     raw:string,
     *     decoded:string,
     *     quote:?string,
     *     valueOffset:?int,
     *     valueLength:int
     *   }>>
     * }>
     */
    private static function scanStartTags(string $html): array
    {
        $tags = [];
        $length = strlen($html);
        for ($index = 0; $index < $length; $index++) {
            if ($html[$index] !== '<') {
                continue;
            }
            if (substr($html, $index, 4) === '<!--') {
                $commentEnd = strpos($html, '-->', $index + 4);
                if ($commentEnd === false) {
                    throw new RuntimeException('Die Signatur enthaelt einen ungeschlossenen HTML-Kommentar.');
                }
                $index = $commentEnd + 2;

                continue;
            }

            $nameStart = $index + 1;
            if (($html[$nameStart] ?? '') === '/'
                || ($html[$nameStart] ?? '') === '!'
                || ($html[$nameStart] ?? '') === '?') {
                continue;
            }
            if (preg_match('/[A-Za-z]/', $html[$nameStart] ?? '') !== 1) {
                continue;
            }

            $nameEnd = $nameStart;
            while ($nameEnd < $length
                && preg_match('/[A-Za-z0-9:-]/', $html[$nameEnd]) === 1) {
                $nameEnd++;
            }
            $tagEnd = self::findTagEnd($html, $nameEnd);
            if ($tagEnd === null) {
                throw new RuntimeException('Die Signatur enthaelt einen ungeschlossenen HTML-Starttag.');
            }

            $tags[] = [
                'name' => strtolower(substr($html, $nameStart, $nameEnd - $nameStart)),
                'startOffset' => $index,
                'endOffset' => $tagEnd,
                'attributes' => self::parseAttributes($html, $nameEnd, $tagEnd),
            ];
            $index = $tagEnd;
        }

        return $tags;
    }

    private static function findTagEnd(string $html, int $start): ?int
    {
        $quote = null;
        $length = strlen($html);
        for ($index = $start; $index < $length; $index++) {
            $character = $html[$index];
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
            if ($character === '>') {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array<string,list<array{
     *   raw:string,
     *   decoded:string,
     *   quote:?string,
     *   valueOffset:?int,
     *   valueLength:int
     * }>>
     */
    private static function parseAttributes(string $html, int $start, int $tagEnd): array
    {
        $attributes = [];
        $cursor = $start;
        while ($cursor < $tagEnd) {
            while ($cursor < $tagEnd && str_contains(" \t\r\n\f", $html[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $tagEnd || $html[$cursor] === '/') {
                break;
            }

            $nameStart = $cursor;
            while ($cursor < $tagEnd
                && ! str_contains(" \t\r\n\f=/>\"'<", $html[$cursor])) {
                $cursor++;
            }
            if ($cursor === $nameStart) {
                throw new RuntimeException('Der Zug-Carrier enthaelt ein unlesbares HTML-Attribut.');
            }
            $name = strtolower(substr($html, $nameStart, $cursor - $nameStart));
            while ($cursor < $tagEnd && str_contains(" \t\r\n\f", $html[$cursor])) {
                $cursor++;
            }

            $raw = '';
            $quote = null;
            $valueOffset = null;
            $valueLength = 0;
            if ($cursor < $tagEnd && $html[$cursor] === '=') {
                $cursor++;
                while ($cursor < $tagEnd && str_contains(" \t\r\n\f", $html[$cursor])) {
                    $cursor++;
                }
                if ($cursor >= $tagEnd) {
                    throw new RuntimeException('Der Zug-Carrier enthaelt ein Attribut ohne Wert.');
                }

                if ($html[$cursor] === '"' || $html[$cursor] === "'") {
                    $quote = $html[$cursor];
                    $valueOffset = ++$cursor;
                    while ($cursor < $tagEnd && $html[$cursor] !== $quote) {
                        $cursor++;
                    }
                    if ($cursor >= $tagEnd) {
                        throw new RuntimeException('Der Zug-Carrier enthaelt einen ungeschlossenen Attributwert.');
                    }
                    $valueLength = $cursor - $valueOffset;
                    $raw = substr($html, $valueOffset, $valueLength);
                    $cursor++;
                } else {
                    $valueOffset = $cursor;
                    while ($cursor < $tagEnd
                        && ! str_contains(" \t\r\n\f>", $html[$cursor])) {
                        if (str_contains("\"'<=`", $html[$cursor])) {
                            throw new RuntimeException('Der Zug-Carrier enthaelt einen unzulaessigen ungequoteten Attributwert.');
                        }
                        $cursor++;
                    }
                    $valueLength = $cursor - $valueOffset;
                    if ($valueLength === 0) {
                        throw new RuntimeException('Der Zug-Carrier enthaelt ein Attribut ohne Wert.');
                    }
                    $raw = substr($html, $valueOffset, $valueLength);
                }
            }

            $attributes[$name][] = [
                'raw' => $raw,
                'decoded' => CssSemantic::decodeHtmlEntitiesOnce($raw),
                'quote' => $quote,
                'valueOffset' => $valueOffset,
                'valueLength' => $valueLength,
            ];
        }

        return $attributes;
    }

    private static function normalizeStyle(string $style, bool $removeMainLayer = false): string
    {
        $controlState = preg_match(
            '/[\x{0000}-\x{0008}\x{000B}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u',
            $style,
        );
        if ($controlState !== 0) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier enthaelt unzulaessige CSS-Steuerzeichen.'
            );
        }

        $maskedStyle = preg_replace_callback(
            '/\{\{([A-Z][A-Z0-9_]*)\}\}/',
            static fn (array $match): string => in_array($match[1], self::ALLOWED_STYLE_TOKENS, true)
                ? str_repeat('_', strlen($match[0]))
                : $match[0],
            $style,
        );
        if (! is_string($maskedStyle) || preg_match('/[{}\[\]]/', $maskedStyle) !== 0) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier enthaelt unzulaessige CSS-Klammern.'
            );
        }

        // Der kanonische Carrier benoetigt keine CSS-Kommentare. Werden sie
        // vor der Semikolon-Zerlegung zugelassen, kann ein Semikolon IM
        // Kommentar den Parserzustand vom Zustand des Mailclients trennen
        // (`background/*;*/:none`). Deshalb jeden Kommentarmarker fail-closed
        // ablehnen, auch einen einzelnen beziehungsweise unbalancierten.
        if (str_contains($style, '/*') || str_contains($style, '*/')) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier enthaelt unzulaessige CSS-Kommentare.'
            );
        }
        if (str_contains($style, '\\')) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier enthaelt unzulaessige CSS-Escape-Zeichen.'
            );
        }

        $segments = self::splitCssAtTopLevel($style, ';');
        $required = [
            'background-image',
            'background-repeat',
            'background-position',
            'background-size',
        ];
        $declarations = [];

        foreach ($segments as $index => $segment) {
            if (! preg_match(
                '/^([ \t\r\n\f]*)([a-z-]+)([ \t\r\n\f]*:[ \t\r\n\f]*)(.*?)([ \t\r\n\f]*)$/is',
                $segment,
                $match,
            )) {
                continue;
            }

            $property = strtolower($match[2]);
            if ($property === 'background') {
                throw new RuntimeException(
                    'Der veroeffentlichte Zug-Carrier enthaelt eine unzulaessige background-Kurzform.'
                );
            }

            if (! in_array($property, $required, true)) {
                continue;
            }

            if (isset($declarations[$property])) {
                throw new RuntimeException(
                    "Der veroeffentlichte Zug-Carrier enthaelt {$property} mehrfach."
                );
            }

            $value = $match[4];
            $important = '';
            if (preg_match('/^(.*?)([ \t\r\n\f]*!important)$/is', $value, $importantMatch)) {
                throw new RuntimeException(
                    "Der veroeffentlichte Zug-Carrier darf {$property} nicht als !important markieren."
                );
            }

            $declarations[$property] = [
                'segment' => $index,
                'prefix' => $match[1].$match[2].$match[3],
                'value' => $value,
                'important' => $important,
                'suffix' => $match[5],
            ];
        }

        foreach ($required as $property) {
            if (! isset($declarations[$property])) {
                throw new RuntimeException(
                    "Der veroeffentlichte Zug-Carrier besitzt kein {$property}."
                );
            }
        }

        $lists = [];
        $expectedCount = null;
        foreach ($required as $property) {
            $items = self::splitCssAtTopLevel($declarations[$property]['value'], ',');
            foreach ($items as $item) {
                if (trim($item) === '') {
                    throw new RuntimeException(
                        "Der veroeffentlichte Zug-Carrier enthaelt eine leere {$property}-Ebene."
                    );
                }
            }

            $expectedCount ??= count($items);
            if (count($items) !== $expectedCount) {
                throw new RuntimeException(
                    'Die Background-Listen des veroeffentlichten Zug-Carriers sind nicht parallel.'
                );
            }

            $lists[$property] = $items;
        }

        self::assertCanonicalLayerContract($lists);

        $mainIndexes = [];
        $idleIndexes = [];
        foreach ($lists['background-image'] as $index => $image) {
            if (self::cssUrlTargetsToken($image, 'TRAIN_SRC')) {
                $mainIndexes[] = $index;
            }
            if (self::cssUrlTargetsToken($image, 'TRAIN_IDLE_SRC')) {
                $idleIndexes[] = $index;
            }
        }

        if (count($mainIndexes) !== 1) {
            throw new RuntimeException(
                'Die Background-Liste der veroeffentlichten Signatur besitzt keinen eindeutigen Zug.'
            );
        }
        if (count($idleIndexes) > 1) {
            throw new RuntimeException(
                'Die Background-Liste der veroeffentlichten Signatur besitzt mehrere Idle-Zuege.'
            );
        }

        if ($idleIndexes !== []) {
            $idleIndex = $idleIndexes[0];
            foreach ($required as $property) {
                array_splice($lists[$property], $idleIndex, 1);
            }
        }

        $mainIndexes = [];
        foreach ($lists['background-image'] as $index => $image) {
            if (self::cssUrlTargetsToken($image, 'TRAIN_SRC')) {
                $mainIndexes[] = $index;
            }
        }
        if (count($mainIndexes) !== 1) {
            throw new RuntimeException(
                'Der Hauptzug ging bei der Normalisierung der Background-Listen verloren.'
            );
        }

        $mainIndex = $mainIndexes[0];
        if ($removeMainLayer) {
            foreach ($required as $property) {
                array_splice($lists[$property], $mainIndex, 1);
            }
        } else {
            $lists['background-repeat'][$mainIndex] = 'no-repeat';
            $lists['background-position'][$mainIndex] = '75% bottom';
            $lists['background-size'][$mainIndex] = 'auto 100%';
        }

        foreach ($required as $property) {
            $declaration = $declarations[$property];
            $segments[$declaration['segment']] = $declaration['prefix']
                .implode(',', $lists[$property])
                .$declaration['important']
                .$declaration['suffix'];
        }

        return implode(';', $segments);
    }

    /**
     * @param  array<string, list<string>>  $lists
     */
    private static function assertCanonicalLayerContract(array $lists): void
    {
        $layerCount = count($lists['background-image']);
        if (! in_array($layerCount, [4, 5], true)) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier besitzt keine kanonische Layerzahl.'
            );
        }

        $images = $lists['background-image'];
        if (! self::cssUrlTargetsToken($images[0], 'GRUND_RASTER_SRC')
            || ! self::cssUrlTargetsToken($images[1], 'GRUND_MARKE_SRC')
            || ! self::cssLinearGradientTargetsWash($images[2])) {
            throw new RuntimeException(
                'Die Basis-Layer des veroeffentlichten Zug-Carriers sind nicht kanonisch.'
            );
        }

        $mainIndex = 3;
        if ($layerCount === 5) {
            if (! self::cssUrlTargetsToken($images[3], 'TRAIN_IDLE_SRC')) {
                throw new RuntimeException(
                    'Der Legacy-Idle-Layer des veroeffentlichten Zug-Carriers ist nicht kanonisch.'
                );
            }
            $mainIndex = 4;
        }
        if (! self::cssUrlTargetsToken($images[$mainIndex], 'TRAIN_SRC')) {
            throw new RuntimeException(
                'Der Hauptzug-Layer des veroeffentlichten Zug-Carriers ist nicht kanonisch.'
            );
        }

        foreach ($lists['background-repeat'] as $index => $repeat) {
            $expected = $index === 0 ? 'repeat' : 'no-repeat';
            if (self::normalizedCssValue($repeat) !== $expected) {
                throw new RuntimeException(
                    'Die Wiederholungswerte des veroeffentlichten Zug-Carriers sind nicht kanonisch.'
                );
            }
        }

        $fixedPositions = ['left top', 'right center', 'center center'];
        foreach ($fixedPositions as $index => $expected) {
            if (self::normalizedCssValue($lists['background-position'][$index]) !== $expected) {
                throw new RuntimeException(
                    'Die Basispositionen des veroeffentlichten Zug-Carriers sind nicht kanonisch.'
                );
            }
        }
        for ($index = 3; $index < $layerCount; $index++) {
            if (! in_array(
                self::normalizedCssValue($lists['background-position'][$index]),
                ['right bottom', '75% bottom'],
                true,
            )) {
                throw new RuntimeException(
                    'Die Zugpositionen des veroeffentlichten Zug-Carriers sind nicht kanonisch.'
                );
            }
        }

        $expectedSizes = ['64px 64px', 'auto 100%', '100% 100%'];
        foreach ($expectedSizes as $index => $expected) {
            if (self::normalizedCssValue($lists['background-size'][$index]) !== $expected) {
                throw new RuntimeException(
                    'Die Basisgroessen des veroeffentlichten Zug-Carriers sind nicht kanonisch.'
                );
            }
        }
        for ($index = 3; $index < $layerCount; $index++) {
            if (self::normalizedCssValue($lists['background-size'][$index]) !== 'auto 100%') {
                throw new RuntimeException(
                    'Die Zuggroessen des veroeffentlichten Zug-Carriers sind nicht kanonisch.'
                );
            }
        }
    }

    private static function cssLinearGradientTargetsWash(string $entry): bool
    {
        if (! preg_match(
            '/^[ \t\r\n\f]*linear-gradient\((.*)\)[ \t\r\n\f]*$/is',
            $entry,
            $match,
        )) {
            return false;
        }

        $arguments = self::splitCssAtTopLevel($match[1], ',');

        return count($arguments) === 2
            && self::trimCssWhitespace($arguments[0]) === '{{SIGNATURE_TRAIN_WASH}}'
            && self::trimCssWhitespace($arguments[1]) === '{{SIGNATURE_TRAIN_WASH}}';
    }

    private static function normalizedCssValue(string $value): string
    {
        $normalized = preg_replace(
            '/[ \t\r\n\f]+/',
            ' ',
            self::trimCssWhitespace($value),
        );
        if (! is_string($normalized)) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier enthaelt einen unlesbaren CSS-Wert.'
            );
        }

        return strtolower($normalized);
    }

    private static function trimCssWhitespace(string $value): string
    {
        return trim($value, " \t\r\n\f");
    }

    /**
     * Trennt CSS nur ausserhalb von Funktionen und Strings. Damit bleiben
     * insbesondere Gradient-Kommas, Data-URI-Semikolons und gequotete Werte
     * unangetastet. Unbalancierte Eingaben gelten als strukturell unsicher.
     *
     * @return list<string>
     */
    private static function splitCssAtTopLevel(string $value, string $separator): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ($quote !== null) {
                if ($character === "\r" || $character === "\n" || $character === "\f") {
                    throw new RuntimeException(
                        'Der veroeffentlichte Zug-Carrier enthaelt einen unzulaessigen CSS-Stringumbruch.'
                    );
                }
                if ($escaped) {
                    $escaped = false;

                    continue;
                }
                if ($character === '\\') {
                    $escaped = true;

                    continue;
                }
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
                    throw new RuntimeException(
                        'Der veroeffentlichte Zug-Carrier enthaelt unbalanciertes CSS.'
                    );
                }
                $depth--;

                continue;
            }
            if ($character === $separator && $depth === 0) {
                $parts[] = substr($value, $start, $index - $start);
                $start = $index + 1;
            }
        }

        if ($quote !== null || $depth !== 0) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier enthaelt unbalanciertes CSS.'
            );
        }

        $parts[] = substr($value, $start);

        return $parts;
    }

    private static function cssUrlTargetsToken(string $entry, string $token): bool
    {
        if (! preg_match('/^[ \t\r\n\f]*url\((.*)\)[ \t\r\n\f]*$/is', $entry, $match)) {
            return false;
        }

        $target = self::trimCssWhitespace($match[1]);
        if (strlen($target) >= 2
            && (($target[0] === '"' && $target[strlen($target) - 1] === '"')
                || ($target[0] === "'" && $target[strlen($target) - 1] === "'"))) {
            $target = self::trimCssWhitespace(substr($target, 1, -1));
        }

        return $target === '{{'.$token.'}}';
    }
}
