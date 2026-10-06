<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignatureArtifactVersion;
use App\Support\Mail\SignatureTableOverlapDelivery;
use App\Support\Mail\TrustedOutlookSignatureCss;
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
        $desktopPayload = $payload;
        [$payload['signature'], $version] = self::document($payload['signature']);
        if ($version !== null) {
            $payload['version']['signature'] = $version;
        }
        foreach ($payload['templates'] as $index => &$template) {
            if (array_key_exists('signature', $template)) {
                [$template['signature'], $version] = self::document($template['signature']);
                if ($version !== null) {
                    $template['signatureVersion'] = $version;
                }
            }
            if (array_key_exists('composeDocumentMode', $template)) {
                $combined = OutlookMobileCombinedComposeDocument::build(
                    $template,
                    $desktopPayload['templates'][$index]['signature'] ?? $desktopPayload['signature'],
                    $template['signature'] ?? $payload['signature'],
                );
                $template['mobileComposeDocumentMode'] = OutlookMobileCombinedComposeDocument::MODE;
                $template['mobileComposeHtml'] = $combined['html'];
                $template['mobileComposeMedia'] = $combined['media'];
                $template['mobileComposeVersion'] = $combined['version'];
            }
        }
        unset($template);

        return $payload;
    }

    /** @return array{0: array, 1: ?string} */
    private static function document(array $document): array
    {
        // Native desktop personal columns are an output-only reversible view.
        // Restore canonical ledger semantics before the established mobile path.
        $html = OutlookNativePersonalSignature::restore(
            OutlookTrainBottomOverlay::restore($document['html']),
        );
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
        // The mobile compiler mirrors its own adapted inline values below.
        // Keeping the desktop mirror as well would duplicate CSS and compete
        // with the mobile typography while consuming the 12 KiB budget.
        $desktopMirrors = $xpath->query('//style[@'.OutlookSignatureInlineStyle::ATTRIBUTE.'="1"]');
        if ($desktopMirrors->length > 1) {
            throw new RuntimeException('Die mobile Signatur besitzt doppelte Desktop-Inline-Stile.');
        }
        foreach ($desktopMirrors as $style) {
            $style->parentNode->removeChild($style);
        }
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
        $canonicalRuntime = self::canonicalRuntime($xpath, $root, $scope, $match[1], $html);
        // Encode all 40 scope bits without changing the content-bound scope.
        // Ordinary physical mobile values own four-class specificity. Frame
        // and train rules retain their exact three-class reversible contracts.
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
            // The flow-delivery train has its own row. Reserve overlap space
            // only for an older, already compiled signature, never twice.
            $bottom = str_contains($html, 'rt-delivery-train') ? '10px' : '52px';
            self::style($cell, 'padding:20px 18px '.$bottom.';vertical-align:top;font-family:Arial,sans-serif;');
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
            // The preceding icon already owns 17 px. A second 100% width
            // attribute can over-constrain the row after Office strips CSS.
            // Leave the text column automatic so long addresses can wrap.
            $cell->removeAttribute('width');
            $cell->setAttribute('style', $cell->getAttribute('style').';font-size:13px;line-height:20px;word-break:normal;overflow-wrap:anywhere;');
        }
        foreach ($xpath->query(self::classQuery('rt-contact-icon', 'td')) as $cell) {
            $cell->setAttribute('width', '17');
            foreach ($xpath->query('./img', $cell) as $image) {
                // These canonical contact PNGs are square. Both attributes
                // bound their intrinsic 44 px canvas without any stylesheet.
                $image->setAttribute('width', '17');
                $image->setAttribute('height', '17');
                self::style($image, 'display:block;width:17px;height:17px;margin:0 auto;border:0;');
            }
        }
        foreach ($xpath->query(self::classQuery('rt-logo', 'img')) as $image) {
            $image->setAttribute('width', '175');
            // Canonical wordmark is 400 x 68. Both dimensions are required
            // when Office.js or forwarding drops its style attribute.
            $image->setAttribute('height', '30');
            self::style($image, 'display:block;width:175px;max-width:100%;height:auto;margin:0;border:0;');
        }
        foreach ($xpath->query(self::classQuery('rt-address-break', 'br')) as $lineBreak) {
            self::style($lineBreak, 'display:block;');
        }

        self::compactPhysicalLedgerCss($xpath, $root, $ledger, $match[1], $html);

        // Office.js documents inline CSS as unsupported. Mirror the actual
        // trusted inline values into internal, uniquely scoped rules. Geometry
        // of legacy overlap tables is left untouched; the new flow-delivery
        // IMG participates so its bounded dimensions survive inline removal.
        $css = '.'.$markerClass.'{display:none!important;mso-hide:all!important;font-size:0!important;line-height:0!important;}';
        $index = 0;
        $styleClasses = [];
        $coverage = [];
        $usedClasses = [];
        foreach ($xpath->query('.//*[@class]', $root) as $element) {
            foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) as $class) {
                $usedClasses[$class] = true;
            }
        }
        $frames = $xpath->query('./table', $scope);
        foreach ($xpath->query('.//*[@style]', $scope) as $element) {
            if (self::trainGeometry($element)) {
                continue;
            }
            $declarations = trim($element->getAttribute('style'), "; \t\r\n");
            if (str_contains(strtolower($declarations), '</style')) {
                throw new RuntimeException('Die mobile Signatur enthaelt ungueltige Stilwerte.');
            }
            $declarations = self::compactRepeatedTypography($declarations);
            $ordinary = ! ($frames->length === 1 && $element->isSameNode($frames->item(0)))
                && ! in_array('rt-delivery-train', preg_split('/\s+/', trim($element->getAttribute('class'))), true);
            $key = ($ordinary ? '4:' : '3:').$declarations;
            if (! isset($styleClasses[$key])) {
                do {
                    $class = 'm'.base_convert((string) ++$index, 10, 36);
                } while (isset($usedClasses[$class]));
                $usedClasses[$class] = true;
                $styleClasses[$key] = $class;
                $css .= $selector.($ordinary ? '.rtm' : '').' .'.$class.'{'
                    .self::importantDeclarations($declarations).'}';
            }
            $class = $styleClasses[$key];
            $element->setAttribute('class', trim($element->getAttribute('class').' '.$class));
            if ($ordinary) {
                $coverage[$class][] = ['node' => $element, 'properties' => self::coveredProperties($declarations)];
            }
        }
        $css .= $selector.' .rt-contact-icon{width:17px!important;}';
        if ($canonicalRuntime !== null) {
            $canonicalRuntime->textContent = self::projectCoveredRuntime(
                $canonicalRuntime->textContent, $xpath, $scope, $match[1], $coverage,
                self::conditionalElements($xpath, $root),
            );
        }
        $style = $dom->createElement('style');
        $style->setAttribute('data-rt-outlook-mobile-css', '1');
        $style->appendChild($dom->createTextNode($css));
        $root->insertBefore($style, $scope);
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }
        // Compile the real train IMG only after the mobile mirror is complete.
        // Its strict inverse leaves the Word flow fallback and all CID bytes intact.
        $output = OutlookTrainBottomOverlay::projectMobile($output);
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
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $output, $styles);
        if (array_sum(array_map('strlen', $styles[1])) >= OutlookSignatureInlineStyle::MAX_CSS_BYTES
            || intdiv(strlen(mb_convert_encoding($output, 'UTF-16LE', 'UTF-8')), 2) > 30000) {
            throw new RuntimeException('Die mobile Signatur ueberschreitet das Outlook-Transportbudget.');
        }
        $document['html'] = $output;

        return [$document, $version];
    }

    /** Bind only the complete server-generated runtime, before any pruning. */
    private static function canonicalRuntime(DOMXPath $xpath, DOMElement $root, DOMElement $scope, string $scopeClass, string $original): ?DOMElement
    {
        if (! SignatureTableOverlapDelivery::applies($original)) {
            return null;
        }
        $styles = $xpath->query('.//style[@data-rt-outlook-signature-css="1"]', $root);
        if ($styles->length !== 1 || ! $styles->item(0)->parentNode->isSameNode($root)) {
            throw new RuntimeException('Die mobile Runtime besitzt keine eindeutige kanonische Position.');
        }
        $style = $styles->item(0);
        $beforeScope = false;
        for ($node = $style->nextSibling; $node !== null; $node = $node->nextSibling) {
            if ($node->isSameNode($scope)) {
                $beforeScope = true;
                break;
            }
        }
        // These are the existing light/dark server palettes plus the exact
        // historical red ledger border, not a value supplied by authored CSS.
        foreach (['#dfe3e6', '#313944', '#e60033'] as $border) {
            if ($beforeScope && hash_equals(TrustedOutlookSignatureCss::responsive($original, $border, $scopeClass), $style->textContent)) {
                return $style;
            }
        }
        throw new RuntimeException('Die mobile Runtime entspricht nicht dem vollstaendigen kanonischen Server-CSS.');
    }

    /** Preserve declaration order, including quoted semicolons and fallbacks. */
    private static function declarationParts(string $declarations): ?array
    {
        $parts = [];
        $start = 0;
        $quote = null;
        $depth = 0;
        for ($offset = 0, $length = strlen($declarations); $offset < $length; $offset++) {
            $character = $declarations[$offset];
            if ($character === '\\') {
                $offset++;
                if ($offset >= $length) {
                    return null;
                }

                continue;
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
                if (--$depth < 0) {
                    return null;
                }
            } elseif ($character === ';' && $depth === 0) {
                $parts[] = trim(substr($declarations, $start, $offset - $start));
                $start = $offset + 1;
            } elseif ($character === '{' || $character === '}' || substr($declarations, $offset, 2) === '/*') {
                return null;
            }
        }
        if ($quote !== null || $depth !== 0) {
            return null;
        }
        $parts[] = trim(substr($declarations, $start));

        return array_values(array_filter($parts, static fn ($part) => $part !== ''));
    }

    private static function importantDeclarations(string $declarations): string
    {
        $parts = self::declarationParts($declarations);
        if ($parts === null) {
            throw new RuntimeException('Die mobile Inline-Runtime besitzt unlesbare Deklarationen.');
        }

        return implode('', array_map(static fn ($part) => preg_replace('/\s*!important\z/i', '', $part).'!important;', $parts));
    }

    /** Only own, demonstrably valid declarations may cover a canonical rule. */
    private static function coveredProperties(string $declarations): array
    {
        $properties = [];
        foreach (self::declarationParts($declarations) ?? [] as $part) {
            if (preg_match('/\A([a-z][a-z-]*):\s*(.*?)\s*(?:!important)?\z/i', $part, $match) !== 1) {
                continue;
            }
            $property = strtolower($match[1]);
            $valid = self::validCoveredValue($property, preg_replace('/\s*!important\z/i', '', $match[2]));
            $properties[$property] = ($properties[$property] ?? true) && $valid;
        }

        return array_filter($properties);
    }

    private static function validCoveredValue(string $property, string $value): bool
    {
        $value = trim(strtolower($value));
        $length = '(?:0|(?:\d+(?:\.\d+)?|\.\d+)(?:px|pt|em|rem|%))';
        $enums = [
            'display' => ['none', 'block', 'inline', 'inline-block', 'table', 'table-row', 'table-cell'],
            'border-collapse' => ['collapse', 'separate'], 'box-sizing' => ['content-box', 'border-box'],
            'direction' => ['ltr', 'rtl'], 'text-align' => ['left', 'right', 'center', 'justify'],
            'vertical-align' => ['top', 'middle', 'bottom', 'baseline'],
            'word-break' => ['normal', 'break-all', 'keep-all'], 'overflow-wrap' => ['normal', 'break-word', 'anywhere'],
            'table-layout' => ['auto', 'fixed'], 'white-space' => ['normal', 'nowrap'],
            'text-transform' => ['none', 'uppercase', 'lowercase', 'capitalize'],
            'text-decoration' => ['none', 'underline'], 'overflow' => ['visible', 'hidden'],
        ];
        if (isset($enums[$property])) {
            return in_array($value, $enums[$property], true);
        }
        if ($property === 'font-family') {
            return preg_match('/\A(?:["\']?(?:arial|helvetica|trebuchet ms|consolas|courier new|sans-serif|serif|monospace)["\']?)(?:\s*,\s*["\']?(?:arial|helvetica|trebuchet ms|consolas|courier new|sans-serif|serif|monospace)["\']?)*\z/', $value) === 1;
        }
        if ($property === 'font-weight') {
            return preg_match('/\A(?:normal|bold|[1-9]00)\z/', $value) === 1;
        }
        if (in_array($property, ['color', 'background-color'], true)) {
            return preg_match('/\A(?:#[0-9a-f]{3}(?:[0-9a-f]{3})?|transparent|black|white|red)\z/', $value) === 1;
        }
        if (in_array($property, ['width', 'height', 'min-width', 'max-width', 'min-height', 'max-height'], true)) {
            $keyword = in_array($property, ['width', 'height'], true) ? '|auto' : (str_starts_with($property, 'max-') ? '|none' : '');

            return preg_match('/\A(?:'.$length.$keyword.')\z/', $value) === 1;
        }
        if ($property === 'font-size') {
            return preg_match('/\A'.$length.'\z/', $value) === 1;
        }
        if ($property === 'line-height') {
            return preg_match('/\A(?:'.$length.'|\d+(?:\.\d+)?|normal)\z/', $value) === 1;
        }
        if ($property === 'padding' || preg_match('/\Apadding-(?:top|right|bottom|left)\z/', $property)) {
            return preg_match('/\A'.$length.'(?:\s+'.$length.'){0,'.($property === 'padding' ? '3' : '0').'}\z/', $value) === 1;
        }
        if ($property === 'margin' || preg_match('/\Amargin-(?:top|right|bottom|left)\z/', $property)) {
            return preg_match('/\A(?:-?'.$length.'|auto)(?:\s+(?:-?'.$length.'|auto)){0,'.($property === 'margin' ? '3' : '0').'}\z/', $value) === 1;
        }
        if (in_array($property, ['border', 'border-top', 'border-right', 'border-bottom', 'border-left'], true)) {
            return $value === '0' || $value === 'none';
        }

        return false;
    }

    /** Conditional MSO elements have no generated mobile mirror: keep them opaque. */
    private static function conditionalElements(DOMXPath $xpath, DOMElement $root): array
    {
        $elements = [];
        foreach ($xpath->query('.//comment()', $root) as $comment) {
            if (stripos($comment->textContent, '[if') === false || stripos($comment->textContent, 'mso') === false) {
                continue;
            }
            preg_match_all('~<([a-z][a-z0-9]*)\b([^>]*)>~i', $comment->textContent, $tags, PREG_SET_ORDER);
            foreach ($tags as $tag) {
                $classes = [];
                if (preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~is', $tag[2], $match) === 1) {
                    $classes = preg_split('/\s+/', trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                } elseif (preg_match('~\bclass\s*=~i', $tag[2])) {
                    // A class spelling we cannot inspect prevents all pruning.
                    return [['tag' => '*', 'classes' => null]];
                }
                $elements[] = ['tag' => strtolower($tag[1]), 'classes' => $classes];
            }
        }

        return $elements;
    }

    /** Scope-first descendant class/type selectors only; other grammar stays. */
    private static function coveredSelector(string $selector, array $properties, DOMXPath $xpath, DOMElement $scope, string $scopeClass, array $coverage, array $conditional): bool
    {
        $parts = preg_split('/\s+/', trim($selector));
        if (array_shift($parts) !== '.'.$scopeClass || $parts === [] || $properties === []) {
            return false;
        }
        $query = '.';
        $classes = 1;
        $terminal = null;
        foreach ($parts as $part) {
            if (preg_match('/\A([a-z][a-z0-9]*|\*)?((?:\.[a-z][a-z0-9-]*)*)\z/i', $part, $match) !== 1 || $part === '') {
                return false;
            }
            $tag = strtolower($match[1] ?: '*');
            preg_match_all('/\.([a-z][a-z0-9-]*)/i', $match[2], $names);
            $classes += count($names[1]);
            $terminal = ['tag' => $tag, 'classes' => $names[1]];
            $query .= '//'.$tag;
            foreach ($names[1] as $class) {
                $query .= '[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]';
            }
        }
        if ($classes >= 4) {
            return false;
        }
        foreach ($conditional as $element) {
            if (($element['tag'] === '*' || $terminal['tag'] === '*' || $terminal['tag'] === $element['tag'])
                && ($element['classes'] === null || array_diff($terminal['classes'], $element['classes']) === [])) {
                return false;
            }
        }
        $nodes = $xpath->query($query, $scope);
        if ($nodes === false || $nodes->length === 0) {
            return false;
        }
        foreach ($nodes as $node) {
            $covered = false;
            foreach (preg_split('/\s+/', trim($node->getAttribute('class'))) as $class) {
                foreach ($coverage[$class] ?? [] as $own) {
                    if ($own['node']->isSameNode($node) && array_diff($properties, array_keys($own['properties'])) === []) {
                        $covered = true;
                    }
                }
            }
            if (! $covered) {
                return false;
            }
        }

        return true;
    }

    /** Rewrite only an already whole-byte-bound canonical runtime stylesheet. */
    private static function projectCoveredRuntime(string $css, DOMXPath $xpath, DOMElement $scope, string $scopeClass, array $coverage, array $conditional): string
    {
        $output = '';
        $offset = 0;
        while ($offset < strlen($css)) {
            if (preg_match('~\G(?:\s+|/\*.*?\*/)~s', $css, $trivia, 0, $offset)) {
                $output .= $trivia[0];
                $offset += strlen($trivia[0]);

                continue;
            }
            $opening = strpos($css, '{', $offset);
            if ($opening === false) {
                return $output.substr($css, $offset);
            }
            $depth = 1;
            $quote = null;
            for ($closing = $opening + 1; $closing < strlen($css); $closing++) {
                $character = $css[$closing];
                if ($character === '\\') {
                    $closing++;
                } elseif ($quote !== null) {
                    if ($character === $quote) {
                        $quote = null;
                    }
                } elseif ($character === '"' || $character === "'") {
                    $quote = $character;
                } elseif ($character === '{') {
                    $depth++;
                } elseif ($character === '}' && --$depth === 0) {
                    break;
                }
            }
            if ($depth !== 0 || $quote !== null) {
                return $css;
            }
            $prelude = substr($css, $offset, $opening - $offset);
            $body = substr($css, $opening + 1, $closing - $opening - 1);
            if (preg_match('/\A@media\s+only screen and \(max-width:\s*\d+px\)\z/', trim($prelude))) {
                $body = self::projectCoveredRuntime($body, $xpath, $scope, $scopeClass, $coverage, $conditional);
                if (trim($body) !== '') {
                    $output .= $prelude.'{'.$body.'}';
                }
            } elseif (str_starts_with(trim($prelude), '@')) {
                $output .= substr($css, $offset, $closing - $offset + 1);
            } else {
                $properties = [];
                $parts = self::declarationParts($body);
                foreach ($parts ?? [] as $part) {
                    if (preg_match('/\A([a-z][a-z-]*):/i', $part, $match) !== 1) {
                        $parts = null;
                        break;
                    }
                    $properties[] = strtolower($match[1]);
                }
                $selectors = explode(',', $prelude);
                $kept = array_filter($selectors, static fn ($selector) => $parts === null || ! self::coveredSelector($selector, $properties, $xpath, $scope, $scopeClass, $coverage, $conditional));
                if ($kept !== []) {
                    $output .= implode(',', $kept).'{'.$body.'}';
                }
            }
            $offset = $closing + 1;
        }

        return $output;
    }

    /** Remove only canonical wide dimensions already replaced by real mobile rows. */
    private static function compactPhysicalLedgerCss(DOMXPath $xpath, DOMElement $root, DOMElement $ledger, string $scope, string $original): void
    {
        $columns = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-wide-column ")]', $ledger);
        if ($columns->length === 0) {
            return;
        }
        $rows = $xpath->query('./tr|./tbody/tr', $ledger);
        if (! SignatureTableOverlapDelivery::applies($original) || $columns->length !== 2 || $rows->length !== 2) {
            throw new RuntimeException('Die mobile CSS-Kuerzung benoetigt den physischen begrenzten Ledger.');
        }
        foreach ($rows as $index => $row) {
            $cells = $xpath->query('./td', $row);
            if ($row->childElementCount !== 1 || $cells->length !== 1
                || ! $cells->item(0)->isSameNode($columns->item($index))
                || $cells->item(0)->getAttribute('width') !== '100%') {
                throw new RuntimeException('Die mobile CSS-Kuerzung besitzt fremde Ledger-Zellen.');
            }
        }
        $styles = $xpath->query('.//style[@data-rt-outlook-signature-css="1"]', $root);
        if ($styles->length !== 1) {
            throw new RuntimeException('Die mobile CSS-Kuerzung besitzt keine eindeutige kanonische Runtime.');
        }
        $prefix = '.'.$scope.' ';
        $rules = [
            $prefix.'.rt-ledger-brand.rt-delivery-wide-column{width:32%!important;padding:0 22px 0 0!important;border-right:1px solid #e60033!important;}',
            $prefix.'.rt-ledger-contacts.rt-delivery-wide-column{width:68%!important;padding:0 0 0 22px!important;border:0!important;}',
            $prefix.'.rt-ledger-brand.rt-delivery-wide-column,'.$prefix.'.rt-ledger-contacts.rt-delivery-wide-column{display:block!important;width:100%!important;padding:0!important;border:0!important;box-sizing:border-box!important;}',
            $prefix.'.rt-ledger-contacts.rt-delivery-wide-column{padding-top:14px!important;}',
        ];
        $css = $styles->item(0)->textContent;
        foreach ($rules as $rule) {
            if (substr_count($css, $rule) !== 1) {
                throw new RuntimeException('Die mobile CSS-Kuerzung entspricht nicht der kanonischen Ledger-Runtime.');
            }
        }
        // Keep the general presentation/font-weight rule, every other runtime
        // rule, published CSS and conditional IMG branches byte-for-byte.
        $styles->item(0)->textContent = str_replace($rules, '', $css);
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
        if ($rows->length === count($classes)) {
            // The shared delivery compiler already uses one real cell per
            // row. Accept only the exact expected groups, not arbitrary HTML.
            foreach ($rows as $index => $row) {
                $cells = $xpath->query('./td', $row);
                if ($row->childElementCount !== 1 || $cells->length !== 1
                    || ! in_array($classes[$index], preg_split('/\s+/', trim($cells->item(0)->getAttribute('class'))), true)) {
                    throw new RuntimeException('Die mobile Layouttabelle besitzt fremde Zeilen.');
                }
            }
            $table->setAttribute('width', '100%');
            self::style($table, 'display:table;width:100%;table-layout:auto;border-collapse:collapse;');

            return;
        }
        if ($rows->length !== 1) {
            throw new RuntimeException('Die mobile Layouttabelle ist nicht eindeutig.');
        }
        $row = $rows->item(0);
        $cells = $xpath->query('./td|./th', $row);
        if ($cells->length !== count($classes)) {
            throw new RuntimeException('Die mobile Layouttabelle besitzt fremde Spalten.');
        }
        $ordered = iterator_to_array($cells);
        foreach ($ordered as $index => $cell) {
            if (! in_array($classes[$index], preg_split('/\s+/', trim($cell->getAttribute('class'))), true)) {
                throw new RuntimeException('Die mobilen Layoutgruppen stehen nicht in der erwarteten Reihenfolge.');
            }
            if ($cell->tagName === 'th') {
                // The desktop renderer uses presentation TH for received-mail
                // iOS reflow. Mobile compose owns real stacked TD rows instead.
                if (! in_array('rt-delivery-wide-column', preg_split('/\s+/', trim($cell->getAttribute('class'))), true)
                    || $cell->getAttribute('role') !== 'presentation') {
                    throw new RuntimeException('Die mobile Layoutspalte besitzt fremde Kopfzellattribute.');
                }
                $replacement = $dom->createElement('td');
                foreach ($cell->attributes as $attribute) {
                    $replacement->setAttribute($attribute->name, $attribute->value);
                }
                while ($cell->firstChild !== null) {
                    $replacement->appendChild($cell->firstChild);
                }
                $cell->parentNode->replaceChild($replacement, $cell);
                $cell = $replacement;
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
