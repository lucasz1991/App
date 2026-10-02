<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\SignatureArtifactVersion;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/** Last-mile mobile presentation only; never changes a published source/cache. */
final class OutlookMobileSignature
{
    public const PROFILE = 'mobile-ledger-v1';

    /** @return array<string, mixed> */
    public static function payload(array $payload): array
    {
        [$payload['signature'], $version] = self::document($payload['signature']);
        if ($version !== null) {
            $payload['version']['signature'] = $version;
        }
        foreach ($payload['templates'] as &$template) {
            if (array_key_exists('signature', $template)) {
                [$template['signature'], $version] = self::document($template['signature']);
                if ($version !== null) {
                    $template['signatureVersion'] = $version;
                }
            }
        }
        unset($template);

        return $payload;
    }

    /** @return array{0: array, 1: ?string} */
    private static function document(array $document): array
    {
        $html = $document['html'];
        // This opt-in adapter only targets the published V27 ledger geometry.
        // Other designs retain their existing delivery path, not a generic rewrite.
        if (! str_contains($html, 'rt-sign-ledger')
            || SignatureArtifactVersion::detect('signature', $html) !== 'v27') {
            return [$document, null];
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><div id="rt-mobile-root">'.$html.'</div>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('Die mobile Signatur konnte nicht gelesen werden.');
        }
        $xpath = new DOMXPath($dom);
        $roots = $xpath->query('//*[@id="rt-mobile-root"]');
        $scopes = $xpath->query(self::classQuery('rt-outlook-signature'));
        $ledgers = $xpath->query(self::classQuery('rt-sign-ledger', 'table'));
        $versions = $xpath->query('//tr[@data-rt-artifact-version="v27"]');
        if ($roots->length !== 1 || $scopes->length !== 1 || $ledgers->length !== 1 || $versions->length !== 1) {
            throw new RuntimeException('Die mobile Signatur besitzt keinen eindeutigen V27-Vertrag.');
        }
        $root = $roots->item(0);
        $scope = $scopes->item(0);
        if (preg_match('/(?:^|\s)(rts[0-9a-f]{10})(?:\s|$)/', $scope->getAttribute('class'), $match) !== 1) {
            throw new RuntimeException('Der mobile Signatur-Scope fehlt.');
        }
        // Encode all 40 scope bits more compactly; keep three-class rule
        // specificity so retained desktop rules cannot regain precedence.
        $mobileScope = 'm'.base_convert(substr($match[1], 3), 16, 36);
        $scope->setAttribute('class', $scope->getAttribute('class').' rt-mobile-ledger rtm '.$mobileScope);
        $selector = '.'.$mobileScope.'.rtm';
        // Version metadata is outside the visual wrapper. Keep it hidden when
        // Office.js drops inline styles but retains the internal stylesheet.
        $markerClass = $match[1].'vm';
        foreach ($xpath->query('.//span', $root) as $span) {
            if (preg_match('/\ART-SIGNATURE-VERSION:[0-9a-f]{16}\z/i', $span->textContent) === 1) {
                $span->setAttribute('class', trim($span->getAttribute('class').' '.$markerClass));
            }
        }
        $ledger = $ledgers->item(0);
        self::stack($dom, $xpath, $ledger, ['rt-ledger-brand', 'rt-ledger-contacts']);
        $contactCells = $xpath->query(self::classQuery('rt-ledger-contacts'));
        $nested = $xpath->query('./table', $contactCells->item(0));
        if ($contactCells->length !== 1 || $nested->length !== 1) {
            throw new RuntimeException('Die mobilen Kontaktgruppen sind nicht eindeutig.');
        }
        self::stack($dom, $xpath, $nested->item(0), ['rt-ledger-direct', 'rt-ledger-company']);

        foreach ($xpath->query(self::classQuery('rt-sign-ledger-content')) as $cell) {
            self::style($cell, 'padding:20px 18px 52px;vertical-align:top;font-family:Arial,sans-serif;');
        }
        foreach (['rt-ledger-brand', 'rt-ledger-contacts', 'rt-ledger-direct', 'rt-ledger-company'] as $class) {
            foreach ($xpath->query(self::classQuery($class)) as $cell) {
                $padding = in_array($class, ['rt-ledger-contacts', 'rt-ledger-company'], true) ? '14px 0 0' : '0';
                $cell->setAttribute('width', '100%');
                $cell->setAttribute('align', 'left');
                self::style($cell, 'display:table-cell;width:100%;max-width:none;padding:'.$padding.';border:0;vertical-align:top;text-align:left;');
            }
        }
        foreach ($xpath->query(self::classQuery('rt-contact', 'table')) as $table) {
            $table->setAttribute('width', '100%');
            self::style($table, 'width:100%;table-layout:auto;border-collapse:collapse;direction:ltr;margin:0;text-align:left;');
        }
        foreach ($xpath->query(self::classQuery('rt-contact-text')) as $cell) {
            // Allow long addresses to wrap, without character-wide columns.
            $cell->setAttribute('width', '100%');
            $cell->setAttribute('style', $cell->getAttribute('style').';font-size:13px;line-height:20px;word-break:normal;overflow-wrap:anywhere;');
        }
        foreach ($xpath->query(self::classQuery('rt-logo', 'img')) as $image) {
            $image->setAttribute('width', '175');
            $image->removeAttribute('height');
            self::style($image, 'display:block;width:175px;max-width:100%;height:auto;margin:0;border:0;');
        }
        foreach ($xpath->query(self::classQuery('rt-address-break', 'br')) as $lineBreak) {
            self::style($lineBreak, 'display:block;');
        }

        // Office.js documents inline CSS as unsupported. Mirror the actual
        // trusted inline values into internal, uniquely scoped rules. Geometry
        // of the original train table/image is deliberately left untouched.
        $css = '.'.$markerClass.'{display:none!important;mso-hide:all!important;font-size:0!important;line-height:0!important;}';
        $index = 0;
        $styleClasses = [];
        foreach ($xpath->query('.//*[@style]', $scope) as $element) {
            if (self::trainGeometry($element)) {
                continue;
            }
            $declarations = trim($element->getAttribute('style'), "; \t\r\n");
            $declarations = preg_replace('/\s*!important\b/i', '', $declarations);
            if (str_contains(strtolower($declarations), '</style')) {
                throw new RuntimeException('Die mobile Signatur enthaelt ungueltige Stilwerte.');
            }
            $declarations = self::compactRepeatedTypography($declarations);
            if (! isset($styleClasses[$declarations])) {
                $styleClasses[$declarations] = 'm'.base_convert((string) ++$index, 10, 36);
                $css .= $selector.' .'.$styleClasses[$declarations].'{'
                    .str_replace(';', '!important;', $declarations).'!important;}';
            }
            $element->setAttribute('class', trim($element->getAttribute('class').' '.$styleClasses[$declarations]));
        }
        $css .= $selector.' .rt-contact-icon{width:17px!important;}';
        $style = $dom->createElement('style');
        $style->setAttribute('data-rt-outlook-mobile-css', '1');
        $style->appendChild($dom->createTextNode($css));
        $root->insertBefore($style, $scope);
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }
        // The device artifact has its own marker, rather than falsely claiming
        // byte equality with the desktop signature. Media and source stay intact.
        $markerPattern = '/RT-SIGNATURE-VERSION:[0-9a-f]{16}/i';
        if (preg_match_all($markerPattern, $output) !== 2) {
            throw new RuntimeException('Der mobile Signatur-Versionsmarker fehlt.');
        }
        $version = substr(hash('sha256', self::PROFILE."\0"
            .preg_replace($markerPattern, 'RT-SIGNATURE-VERSION:', $output)."\0"
            .json_encode($document['media'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 0, 16);
        $output = preg_replace($markerPattern, 'RT-SIGNATURE-VERSION:'.$version, $output);
        if (intdiv(strlen(mb_convert_encoding($output, 'UTF-16LE', 'UTF-8')), 2) > 30000) {
            throw new RuntimeException('Die mobile Signatur ueberschreitet das Outlook-Transportbudget.');
        }
        $document['html'] = $output;

        return [$document, $version];
    }

    private static function classQuery(string $class, string $tag = '*'): string
    {
        return '//'.$tag.'[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]';
    }

    /** Remove exact repeated numeric typography only; preserve fallback values. */
    private static function compactRepeatedTypography(string $declarations): string
    {
        // Semicolons inside values are not declaration boundaries. Avoid
        // parsing escaped/complex separators; ordinary quoted fonts are safe.
        if (str_contains($declarations, '\\')) {
            return $declarations;
        }
        $quote = null;
        $depth = 0;
        for ($offset = 0, $length = strlen($declarations); $offset < $length; $offset++) {
            $character = $declarations[$offset];
            if ($character === ';' && ($quote !== null || $depth > 0)) {
                return $declarations;
            }
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            }
        }
        if ($quote !== null || $depth !== 0) {
            return $declarations;
        }
        $parts = explode(';', $declarations);
        $seen = [];
        for ($index = count($parts) - 1; $index >= 0; $index--) {
            $part = trim($parts[$index]);
            if (preg_match('/\A(?:font-size|line-height):(?:\d+(?:\.\d+)?(?:px|pt|em|rem|%)?|normal)\z/i', $part) !== 1) {
                continue;
            }
            $key = strtolower($part);
            if (isset($seen[$key])) {
                unset($parts[$index]);
            } else {
                $seen[$key] = true;
            }
        }

        return implode(';', $parts);
    }

    /** Move cells, not their contents, preserving text, links and embedded IMG. */
    private static function stack(DOMDocument $dom, DOMXPath $xpath, DOMElement $table, array $classes): void
    {
        $rows = $xpath->query('./tr|./tbody/tr', $table);
        if ($rows->length !== 1) {
            throw new RuntimeException('Die mobile Layouttabelle ist nicht eindeutig.');
        }
        $row = $rows->item(0);
        $cells = $xpath->query('./td', $row);
        if ($cells->length !== count($classes)) {
            throw new RuntimeException('Die mobile Layouttabelle besitzt fremde Spalten.');
        }
        $ordered = iterator_to_array($cells);
        foreach ($ordered as $index => $cell) {
            if (! in_array($classes[$index], preg_split('/\s+/', trim($cell->getAttribute('class'))), true)) {
                throw new RuntimeException('Die mobilen Layoutgruppen stehen nicht in der erwarteten Reihenfolge.');
            }
            $nextRow = $dom->createElement('tr');
            $row->parentNode->insertBefore($nextRow, $row);
            $nextRow->appendChild($cell);
        }
        $row->parentNode->removeChild($row);
        $table->setAttribute('width', '100%');
        self::style($table, 'display:table;width:100%;table-layout:auto;border-collapse:collapse;');
    }

    private static function style(DOMElement $element, string $style): void
    {
        $element->setAttribute('style', $style);
    }

    private static function trainGeometry(DOMElement $element): bool
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class')));

        return count(array_intersect($classes, [
            'rt-sign-stage', 'rt-sign-content-frame', 'rt-sign-train',
            'rt-v27-image-cell', 'rt-v27-anchor', 'rt-v27-image-slot',
        ])) > 0;
    }
}
