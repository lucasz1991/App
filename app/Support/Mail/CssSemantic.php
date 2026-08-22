<?php

namespace App\Support\Mail;

use RuntimeException;

/** Kleine semantische CSS-Helfer fuer editierbare Maildokumentquellen. */
final class CssSemantic
{
    public const PROTECTED_EDITABLE_CSS_MESSAGE = 'Separate Mail-CSS-Regeln duerfen keine Animationen, Keyframes oder geschuetzten Signaturselektoren enthalten.';

    /** @var list<string> */
    private const RESERVED_TRAIN_TOKENS = [
        '{{TRAIN_SRC}}',
        '{{TRAIN_IDLE_SRC}}',
        '{{TRAIN_STILL_SRC}}',
    ];

    /** @var list<string> */
    private const RESERVED_RUNTIME_TOKENS = [
        '{{TRAIN_SRC}}',
        '{{TRAIN_IDLE_SRC}}',
        '{{TRAIN_STILL_SRC}}',
        '{{RESPONSIVE_CSS}}',
        '{{SIGNATURE_BLOCK}}',
        '{{APPLICATION_CONTENT}}',
    ];

    /** @var list<string> */
    private const PROTECTED_SELECTOR_IDENTIFIERS = [
        'rt-sign-cell',
        'rt-sign-train-background',
        'rt-sign-train',
        'rt-sign-train-layer',
        'rt-sign-train-frame',
        'rt-sign-train-slot',
        'rt-sign-content-frame',
        'rt-sign-train-mso',
        'rt-sign-stage',
        'rt-train-main-layer',
        'rt-train-main-image',
        'rt-train-idle-overlay',
        'rt-train-idle-runtime-layer',
        'rt-train-idle-surface',
        'rt-train-idle-image',
        'rt-classic-outlook-train',
        'data-rt-train-main-layer',
        'data-rt-train-main-image',
        'data-rt-train-idle-overlay',
        'data-rt-train-idle-image',
        'data-rt-train',
        'data-rt-train-background',
        'data-rt-train-align',
        'data-rt-train-size',
        'data-rt-train-mobile',
        'data-rt-train-mso',
        'data-rt-layer-train',
        'data-rt-layer-align',
        'data-rt-layer-size',
        'data-rt-layer-mobile',
    ];

    /**
     * Dekodiert jede Entity aus dem Rohtext genau einmal. Numerische Browser-
     * Controls werden bewusst ebenfalls materialisiert, auch wenn PHPs
     * html_entity_decode sie nach HTML5 sonst unveraendert liesse.
     */
    public static function decodeHtmlEntitiesOnce(string $value): string
    {
        $decoded = preg_replace_callback(
            '/&(?:#(?:[xX][0-9A-Fa-f]+|[0-9]+);?|[A-Za-z][A-Za-z0-9]+;)/',
            static function (array $match): string {
                if (preg_match('/^&#([xX]?)([0-9A-Fa-f]+);?$/', $match[0], $numeric) === 1) {
                    $base = $numeric[1] === '' ? 10 : 16;
                    $codepoint = intval($numeric[2], $base);
                    if ($codepoint > 0x10FFFF
                        || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                        return "\u{FFFD}";
                    }

                    $character = mb_chr($codepoint, 'UTF-8');

                    return is_string($character) ? $character : "\u{FFFD}";
                }

                return html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            },
            $value,
        );

        if (! is_string($decoded)) {
            throw new RuntimeException('Die CSS-Quelle enthaelt unlesbare HTML-Entities.');
        }

        return $decoded;
    }

    /** Erkennt ein echtes CSS-!important ausserhalb von Strings. */
    public static function containsImportant(string $css): bool
    {
        $css = self::normalizeCssNewlines(self::decodeHtmlEntitiesOnce($css));
        $length = strlen($css);

        for ($index = 0; $index < $length; $index++) {
            $character = $css[$index];
            if ($character === '"' || $character === "'") {
                $stringEnd = self::skipString($css, $index, $character);
                if ($stringEnd === null) {
                    return true;
                }
                $index = $stringEnd;

                continue;
            }
            if ($character === '/' && ($css[$index + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $index + 2);
                if ($end === false) {
                    return true;
                }
                $index = $end + 1;

                continue;
            }
            if ($character !== '!') {
                continue;
            }

            $cursor = self::skipWhitespaceAndComments($css, $index + 1);
            if ($cursor === null) {
                return true;
            }
            [$identifier, , $validIdentifier] = self::readIdentifier($css, $cursor);
            if (! $validIdentifier) {
                return true;
            }
            if (strtolower($identifier) === 'important') {
                return true;
            }
        }

        return false;
    }

    /**
     * Zugquellen gehoeren ausschliesslich in den serverkontrollierten
     * Signatur-Carrier. Eine freie CSS-Regel koennte sonst Logo, Footer oder
     * Pseudoelement als zweiten Zug ausgeben.
     */
    public static function containsReservedTrainToken(string $source): bool
    {
        $decoded = self::decodeHtmlEntitiesOnce($source);

        foreach (self::RESERVED_TRAIN_TOKENS as $token) {
            if (str_contains($decoded, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Freie CSS-Quellen duerfen weder Bildquellen noch serverseitige HTML-
     * beziehungsweise Responsive-Einhaengepunkte substituieren lassen.
     */
    public static function containsReservedRuntimeToken(string $source): bool
    {
        $decoded = self::decodeHtmlEntitiesOnce($source);

        foreach (self::RESERVED_RUNTIME_TOKENS as $token) {
            if (str_contains($decoded, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Erkennt alle frei editierbaren CSS-Konstrukte, die den serverseitigen
     * Bewegungs- und Carrier-Vertrag ueberschreiben oder per Pseudoelement
     * verdoppeln koennten. Identifier werden inklusive CSS-Escapes gelesen.
     */
    public static function containsForbiddenAnimationOrProtectedSelector(string $css): bool
    {
        $css = self::normalizeCssNewlines(self::decodeHtmlEntitiesOnce($css));
        $controlState = preg_match(
            '/[\x{0000}-\x{0008}\x{000B}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u',
            $css,
        );
        if ($controlState !== 0) {
            return true;
        }

        $length = strlen($css);
        $braces = 0;
        $brackets = 0;
        $parentheses = 0;

        for ($index = 0; $index < $length; $index++) {
            $character = $css[$index];
            if ($character === '"' || $character === "'") {
                $stringEnd = self::skipString($css, $index, $character);
                if ($stringEnd === null) {
                    return true;
                }
                $index = $stringEnd;

                continue;
            }
            if ($character === '/' && ($css[$index + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $index + 2);
                if ($end === false) {
                    return true;
                }
                $index = $end + 1;

                continue;
            }

            if ($character === '{') {
                $braces++;

                continue;
            }
            if ($character === '}') {
                if ($braces === 0) {
                    return true;
                }
                $braces--;

                continue;
            }
            if ($character === '[') {
                [$attributeEnd, $targetsProtectedClass, $validAttribute] = self::inspectAttributeSelector(
                    $css,
                    $index,
                );
                if (! $validAttribute || $targetsProtectedClass) {
                    return true;
                }
                $index = $attributeEnd;

                continue;
            }
            if ($character === ']') {
                return true;
            }
            if ($character === '(') {
                $parentheses++;

                continue;
            }
            if ($character === ')') {
                if ($parentheses === 0) {
                    return true;
                }
                $parentheses--;

                continue;
            }

            if ($character !== '\\'
                && preg_match('/[A-Za-z_-]/', $character) !== 1) {
                continue;
            }

            [$identifier, $cursor, $validIdentifier] = self::readIdentifierAcrossAdjacentComments($css, $index);
            if (! $validIdentifier || $cursor <= $index) {
                return true;
            }
            $normalized = strtolower($identifier);
            if ($normalized === 'keyframes' || str_ends_with($normalized, '-keyframes')) {
                return true;
            }
            if (in_array($normalized, self::PROTECTED_SELECTOR_IDENTIFIERS, true)) {
                return true;
            }

            $isAnimationProperty = $normalized === 'animation'
                || str_starts_with($normalized, 'animation-')
                || preg_match('/^-[a-z]+-animation(?:-|$)/', $normalized) === 1;
            // Outlooks Word-Renderer kann mso-padding-alt auch ueber einen
            // generischen Selektor wie `td` auf den geschuetzten Carrier
            // anwenden und damit dessen Inline-padding:0 umgehen. Darum ist
            // diese Property in jeder frei editierbaren CSS-Spalte gesperrt;
            // readIdentifier normalisiert dabei auch CSS-Escapes.
            $isProtectedGeometryProperty = $normalized === 'mso-padding-alt';
            if ($isAnimationProperty || $isProtectedGeometryProperty) {
                $propertyEnd = self::skipWhitespaceAndComments($css, $cursor);
                if ($propertyEnd === null) {
                    return true;
                }
                if (($css[$propertyEnd] ?? '') === ':') {
                    return true;
                }
            }

            $index = $cursor - 1;
        }

        return $braces !== 0 || $brackets !== 0 || $parentheses !== 0;
    }

    /** @return array{int, bool, bool} Endindex, Treffer, syntaktisch lesbar */
    private static function inspectAttributeSelector(string $css, int $start): array
    {
        $length = strlen($css);
        $cursor = self::skipWhitespaceAndComments($css, $start + 1);
        if ($cursor === null || $cursor >= $length) {
            return [$start, false, false];
        }

        [$attribute, $cursor, $validAttribute] = self::readIdentifier($css, $cursor);
        if (! $validAttribute || $attribute === '') {
            return [$start, false, false];
        }
        $cursor = self::skipWhitespaceAndComments($css, $cursor);
        if ($cursor === null || $cursor >= $length) {
            return [$start, false, false];
        }

        $normalizedAttribute = strtolower($attribute);
        $isClassAttribute = $normalizedAttribute === 'class';
        $isProtectedAttribute = in_array(
            $normalizedAttribute,
            self::PROTECTED_SELECTOR_IDENTIFIERS,
            true,
        );
        if ($css[$cursor] === ']') {
            return [$cursor, $isClassAttribute || $isProtectedAttribute, true];
        }

        $operator = $css[$cursor];
        if (str_contains('~|^$*', $operator) && ($css[$cursor + 1] ?? '') === '=') {
            $operator .= '=';
            $cursor += 2;
        } elseif ($operator === '=') {
            $cursor++;
        } else {
            return [$start, false, false];
        }

        $cursor = self::skipWhitespaceAndComments($css, $cursor);
        if ($cursor === null || $cursor >= $length) {
            return [$start, false, false];
        }

        if ($css[$cursor] === '"' || $css[$cursor] === "'") {
            [$value, $cursor, $validValue] = self::readCssString(
                $css,
                $cursor,
                $css[$cursor],
            );
        } else {
            [$value, $cursor, $validValue] = self::readIdentifier($css, $cursor);
        }
        if (! $validValue || $value === '') {
            return [$start, false, false];
        }

        $cursor = self::skipWhitespaceAndComments($css, $cursor);
        if ($cursor === null || $cursor >= $length) {
            return [$start, false, false];
        }

        $flag = '';
        if ($css[$cursor] !== ']') {
            [$flag, $cursor, $validFlag] = self::readIdentifier($css, $cursor);
            if (! $validFlag || ! in_array(strtolower($flag), ['i', 's'], true)) {
                return [$start, false, false];
            }
            $cursor = self::skipWhitespaceAndComments($css, $cursor);
            if ($cursor === null || ($css[$cursor] ?? '') !== ']') {
                return [$start, false, false];
            }
        }

        // Der Carrier besitzt mehrere Klassen. Teilstring-, Praefix- und
        // Suffixselektoren koennen deshalb auch dann treffen, wenn ihr Wert
        // keinen isolierten geschuetzten Klassennamen enthaelt. Jede
        // syntaktisch lesbare Auswahl ueber das class-Attribut wird daher
        // bewusst fail-closed behandelt; andere Attributstrings bleiben frei.
        return [$cursor, $isClassAttribute || $isProtectedAttribute, true];
    }

    /** @return array{string, int, bool} Wert, Index nach Schlussquote, gueltig */
    private static function readCssString(string $css, int $start, string $quote): array
    {
        $value = '';
        $length = strlen($css);
        for ($index = $start + 1; $index < $length; $index++) {
            $character = $css[$index];
            if ($character === $quote) {
                return [$value, $index + 1, true];
            }
            if ($character === "\r" || $character === "\n" || $character === "\f") {
                return ['', $index, false];
            }
            if ($character !== '\\') {
                $value .= $character;

                continue;
            }
            if ($index + 1 >= $length) {
                return ['', $length, false];
            }

            $index++;
            if (preg_match('/[0-9A-Fa-f]/', $css[$index]) === 1) {
                $hex = '';
                for ($digits = 0; $digits < 6 && $index < $length; $digits++, $index++) {
                    if (preg_match('/[0-9A-Fa-f]/', $css[$index]) !== 1) {
                        break;
                    }
                    $hex .= $css[$index];
                }
                if ($index < $length && str_contains(" \t\r\n\f", $css[$index])) {
                    $index++;
                }
                $index--;
                $codepoint = hexdec($hex);
                if ($codepoint <= 0
                    || $codepoint > 0x10FFFF
                    || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                    return ['', $index, false];
                }
                $decoded = mb_chr($codepoint, 'UTF-8');
                if (! is_string($decoded)) {
                    return ['', $index, false];
                }
                $value .= $decoded;

                continue;
            }
            if ($css[$index] === "\r" || $css[$index] === "\n" || $css[$index] === "\f") {
                return ['', $index, false];
            }
            $value .= $css[$index];
        }

        return ['', $length, false];
    }

    private static function skipString(string $css, int $start, string $quote): ?int
    {
        $length = strlen($css);
        for ($index = $start + 1; $index < $length; $index++) {
            if ($css[$index] === "\r" || $css[$index] === "\n" || $css[$index] === "\f") {
                return null;
            }
            if ($css[$index] === '\\') {
                if ($index + 1 >= $length
                    || $css[$index + 1] === "\r"
                    || $css[$index + 1] === "\n"
                    || $css[$index + 1] === "\f") {
                    return null;
                }
                $index++;

                continue;
            }
            if ($css[$index] === $quote) {
                return $index;
            }
        }

        return null;
    }

    private static function normalizeCssNewlines(string $css): string
    {
        // CSS Syntax preprocessing: CRLF ist ein einzelner Zeilenumbruch,
        // alleinstehendes CR und Form Feed werden ebenfalls zu LF. Dadurch
        // konsumiert ein Hex-Escape seinen Whitespace-Terminator genauso wie
        // im Browser und kann Identifier nicht am zweiten CRLF-Byte teilen.
        return str_replace(["\r\n", "\r", "\f"], "\n", $css);
    }

    private static function skipWhitespaceAndComments(string $css, int $start): ?int
    {
        $length = strlen($css);
        $index = $start;
        while ($index < $length) {
            if (str_contains(" \t\r\n\f", $css[$index])) {
                $index++;

                continue;
            }
            if ($css[$index] === '/' && ($css[$index + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $index + 2);
                if ($end === false) {
                    return null;
                }
                $index = $end + 2;

                continue;
            }

            break;
        }

        return $index;
    }

    /** @return array{string, int, bool} */
    private static function readIdentifierAcrossAdjacentComments(string $css, int $start): array
    {
        [$identifier, $cursor, $valid] = self::readIdentifier($css, $start);
        $length = strlen($css);

        while ($valid && $cursor < $length && substr($css, $cursor, 2) === '/*') {
            $commentEnd = strpos($css, '*/', $cursor + 2);
            if ($commentEnd === false) {
                return [$identifier, $length, false];
            }

            $cursor = $commentEnd + 2;
            $next = $css[$cursor] ?? '';
            if ($next !== '\\' && preg_match('/[A-Za-z0-9_-]/', $next) !== 1) {
                break;
            }

            [$suffix, $cursor, $validSuffix] = self::readIdentifier($css, $cursor);
            if (! $validSuffix || $suffix === '') {
                return [$identifier, $cursor, false];
            }
            $identifier .= $suffix;
        }

        return [$identifier, $cursor, $valid];
    }

    /** @return array{string, int, bool} */
    private static function readIdentifier(string $css, int $start): array
    {
        $identifier = '';
        $length = strlen($css);
        $index = $start;
        $valid = true;

        while ($index < $length) {
            $character = $css[$index];
            if (preg_match('/[A-Za-z0-9_-]/', $character) === 1) {
                $identifier .= $character;
                $index++;

                continue;
            }
            if ($character !== '\\') {
                break;
            }

            if ($index + 1 >= $length) {
                $valid = false;
                $index = $length;

                break;
            }

            $index++;
            if (preg_match('/[0-9A-Fa-f]/', $css[$index]) === 1) {
                $hex = '';
                for ($digits = 0; $digits < 6 && $index < $length; $digits++, $index++) {
                    if (preg_match('/[0-9A-Fa-f]/', $css[$index]) !== 1) {
                        break;
                    }
                    $hex .= $css[$index];
                }
                if ($index < $length && str_contains(" \t\r\n\f", $css[$index])) {
                    $index++;
                }
                $codepoint = hexdec($hex);
                $decoded = $codepoint > 0 && $codepoint <= 0x10FFFF
                    && ! ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)
                    ? mb_chr($codepoint, 'UTF-8')
                    : false;
                if (is_string($decoded)) {
                    $identifier .= $decoded;
                } else {
                    $valid = false;
                }

                continue;
            }

            if ($css[$index] === "\r" || $css[$index] === "\n" || $css[$index] === "\f") {
                $valid = false;
                break;
            }
            $identifier .= $css[$index];
            $index++;
        }

        return [$identifier, $index, $valid];
    }
}
