<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Throwable;

/** Read-only admission of an existing compiled V30/V17 source, never mail input. */
final class OutlookUniversalSourceDescriptor
{
    /** The caller retains its previous output when a custom source is not eligible.
     * No database/configuration mutation, publication or transport occurs here.
     *
     * @return array{descriptor:array,authorContent:HtmlString,options:array,sourceAuthorContentHash:string}|null
     */
    public static function projectFromCompiled(string $html, array $profileValues, string $sourceHash): ?array
    {
        try {
            if (strlen($html) > 500000 || ! mb_check_encoding($html, 'UTF-8') || str_contains($html, "\0")
                || ! preg_match('/\A[0-9a-f]{64}\z/', $sourceHash)) {
                return null;
            }
            $v17 = substr_count($html, 'data-rt-desktop-flow="'.OutlookCompactReferencePresentation::FLOW.'"');
            $v16 = substr_count($html, 'data-rt-desktop-flow="'.OutlookV30ReferencePresentation::FLOW.'"');
            if ($v17 + $v16 !== 1) {
                return null;
            }
            $admitted = OutlookCompactReferencePresentation::document($html);
            if ($v17 === 1 && $admitted !== $html
                || substr_count($admitted, 'data-rt-desktop-flow="'.OutlookCompactReferencePresentation::FLOW.'"') !== 1) {
                return null;
            }
            $html = $admitted;
            foreach (['VORNAME_NACHNAME', 'POSITION', 'E_MAIL', 'DURCHWAHL', 'DURCHWAHL_TEL', 'MOBIL', 'MOBIL_TEL',
                'FIRMENNAME', 'FIRMEN_WEBSITE_HREF', 'FIRMEN_WEBSITE_LABEL', 'FIRMENSTRASSE', 'FIRMEN_PLZ_ORT'] as $key) {
                if (! is_string($profileValues[$key] ?? null) || ! mb_check_encoding($profileValues[$key], 'UTF-8')
                    || preg_match('/[\x00-\x1f\x7f]/', $profileValues[$key])) {
                    return null;
                }
            }
            $dom = new DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            try {
                $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            if (! $loaded) {
                return null;
            }
            $xpath = new DOMXPath($dom);
            $directCell = self::one($xpath, 'rt-v17-direct');
            $company = self::one($xpath, 'rt-v17-company');
            foreach (['VORNAME_NACHNAME' => 'rt-personal-name', 'POSITION' => 'rt-personal-role'] as $key => $class) {
                $valueNode = self::one($xpath, $class)->cloneNode(true);
                if ($key === 'VORNAME_NACHNAME') {
                    foreach (iterator_to_array($xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," rt-personal-role ")]', $valueNode)) as $role) {
                        $role->parentNode?->removeChild($role);
                    }
                }
                if (self::normalize($valueNode->textContent) !== self::normalize($profileValues[$key])) {
                    return null;
                }
            }
            $companyTexts = [];
            foreach ($xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," rt-company-contact-text ")]', $company) as $contact) {
                $companyTexts[] = self::normalize(self::textWithBreaks($contact));
            }
            if (! in_array(self::normalize($profileValues['FIRMEN_WEBSITE_LABEL']), $companyTexts, true)
                || ! in_array(self::normalize($profileValues['FIRMENSTRASSE'].' '.$profileValues['FIRMEN_PLZ_ORT']), $companyTexts, true)) {
                return null;
            }
            self::contains($directCell, $profileValues['E_MAIL']);
            $direct = [['type' => 'email', 'value' => $profileValues['E_MAIL'], 'label' => $profileValues['E_MAIL']]];
            foreach ([['DURCHWAHL', 'DURCHWAHL_TEL'], ['MOBIL', 'MOBIL_TEL']] as [$label, $value]) {
                if ($profileValues[$label] !== '') {
                    self::contains($directCell, $profileValues[$label]);
                    $direct[] = ['type' => 'phone', 'value' => $profileValues[$value], 'label' => $profileValues[$label]];
                }
            }
            $actualDirectLinks = [];
            foreach ($xpath->query('.//a[@href]', $directCell) as $link) {
                $actualDirectLinks[$link->getAttribute('href')] = true;
            }
            $expectedDirectLinks = array_map(static fn (array $contact): string => ($contact['type'] === 'email' ? 'mailto:' : 'tel:').$contact['value'], $direct);
            $actualDirectLinks = array_keys($actualDirectLinks);
            sort($actualDirectLinks);
            sort($expectedDirectLinks);
            if ($actualDirectLinks !== $expectedDirectLinks) {
                return null;
            }
            $webLinks = array_values(array_filter(iterator_to_array($xpath->query('.//a[@href]', $company)), static fn (DOMElement $node): bool => self::normalize($node->textContent) === self::normalize($profileValues['FIRMEN_WEBSITE_LABEL'])));
            if (count($webLinks) !== 1 || $webLinks[0]->getAttribute('href') !== $profileValues['FIRMEN_WEBSITE_HREF']) {
                return null;
            }
            $card = self::one($xpath, 'rt-v17-dispatch-block');
            $links = [];
            foreach ($xpath->query('.//a[@href]', $card) as $link) {
                $links[$link->getAttribute('href')] = true;
            }
            if (count($links) !== 3) {
                return null;
            }
            $select = static function (string $prefix) use ($links): string {
                $found = array_values(array_filter(array_keys($links), static fn (string $url): bool => str_starts_with($url, $prefix)));
                if (count($found) !== 1) {
                    throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
                }

                return $found[0];
            };
            $phone = substr($select('tel:'), 4);
            $phoneLabel = $phone;
            if (is_string($profileValues['NOTFALLNUMMER'] ?? null) && trim($profileValues['NOTFALLNUMMER']) !== '') {
                $phoneLabel = $profileValues['NOTFALLNUMMER'];
                if (preg_replace('/[^0-9]/', '', $phoneLabel) !== preg_replace('/[^0-9]/', '', $phone)
                    || (isset($profileValues['NOTFALLNUMMER_TEL']) && $profileValues['NOTFALLNUMMER_TEL'] !== $phone)) {
                    return null;
                }
            }
            $legal = self::one($xpath, 'rt-v17-legal-cell');
            $legalSpans = $xpath->query('./div/span', $legal);
            if ($legalSpans->length !== 2) {
                return null;
            }
            $registration = self::normalize($legalSpans->item(0)->textContent);
            $confidentiality = self::normalize($legalSpans->item(1)->textContent);
            if ($registration === '' || $confidentiality === '') {
                return null;
            }
            [$author, $options] = self::author($html, $profileValues['VORNAME_NACHNAME']);

            return ['descriptor' => [
                'sourceHash' => $sourceHash, 'kind' => 'employee',
                'identity' => ['name' => $profileValues['VORNAME_NACHNAME'], 'role' => $profileValues['POSITION']],
                'direct' => $direct,
                'company' => ['name' => $profileValues['FIRMENNAME'], 'website' => $profileValues['FIRMEN_WEBSITE_HREF'],
                    'websiteLabel' => $profileValues['FIRMEN_WEBSITE_LABEL'], 'street' => $profileValues['FIRMENSTRASSE'],
                    'postalCity' => $profileValues['FIRMEN_PLZ_ORT'], 'registration' => $registration, 'confidentiality' => $confidentiality],
                'dispatch' => ['email' => substr($select('mailto:'), 7), 'whatsapp' => $select('https://wa.me/'), 'phone' => $phone, 'phoneLabel' => $phoneLabel],
            ], 'authorContent' => new HtmlString($author), 'options' => $options,
                'sourceAuthorContentHash' => hash('sha256', $author)];
        } catch (Throwable) {
            // An unknown authored source is not permission to approximate it.
            return null;
        }
    }

    private static function author(string $html, string $name): array
    {
        $nodes = self::nodes($html);
        $roots = array_filter($nodes, static fn (array $node): bool => $node['parent'] === null && $node['tag'] !== 'style');
        if (count($roots) !== 1 || array_filter($nodes, static fn (array $node): bool => in_array($node['tag'], ['script', 'iframe', 'object'], true)) !== []) {
            throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
        }
        $root = reset($roots);
        if ($root['tag'] !== 'div' || (! self::has($root, 'rt-outlook-signature') && ! self::has($root, 'rt-combined-compose-document'))) {
            throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
        }
        $outside = $html;
        $top = array_filter($nodes, static fn (array $node): bool => $node['parent'] === null);
        usort($top, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($top as $node) {
            $outside = substr_replace($outside, '', $node['start'], $node['end'] - $node['start']);
        }
        if (trim((string) preg_replace('~<!--.*?-->~s', '', $outside)) !== '') {
            throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
        }
        $options = ['showGreeting' => false, 'showFarewell' => false];
        $spacers = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'p' && self::has($node, 'design-spacer')));
        if ($spacers === []) {
            if (str_contains($html, 'rt-outlook-template')) {
                throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
            }

            return ['', $options];
        }
        if (count($spacers) !== 1) {
            throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
        }
        $spacer = $spacers[0];
        $cell = $nodes[$spacer]['parent'];
        while (is_int($cell) && ! ($nodes[$cell]['tag'] === 'td' && self::has($nodes[$cell], 'rt-v17-template-pad'))) {
            $cell = $nodes[$cell]['parent'];
        }
        if (! is_int($cell)) {
            throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
        }
        self::assertNoOtherAuthoredContent($html, $nodes[$cell]);
        $edits = [];
        foreach (['design-salutation' => 'Greeting', 'design-greeting' => 'Farewell'] as $class => $role) {
            $matches = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'p' && self::has($node, $class)));
            if (count($matches) > 1) {
                throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
            }
            if ($matches !== []) {
                $paragraph = $nodes[$matches[0]];
                if ($paragraph['parent'] !== $cell) {
                    throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
                }
                $inner = substr($html, $paragraph['openEnd'], $paragraph['close'] - $paragraph['openEnd']);
                $withName = $role === 'Farewell' && preg_match('~<br\s*/?>\s*'.preg_quote(htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '~').'\s*\z~u', $inner) === 1;
                if ($withName) {
                    $inner = preg_replace('~<br\s*/?>\s*'.preg_quote(htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '~').'\s*\z~u', '', $inner);
                }
                if (str_contains($inner, '<')) {
                    throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
                }
                $options['show'.$role] = true;
                $options[strtolower($role)] = html_entity_decode($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($role === 'Farewell') {
                    $options['farewellName'] = $withName;
                }
                $edits[] = [$paragraph['start'], $paragraph['end'], ''];
            }
        }
        $wrappers = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'table' && self::has($node, 'rt-compose-editable-v1')));
        if (count($wrappers) > 1) {
            throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
        }
        if ($wrappers !== []) {
            $wrapper = $nodes[$wrappers[0]];
            if ($wrapper['parent'] !== $cell || ! ($wrapper['start'] < $nodes[$spacer]['start'] && $wrapper['end'] > $nodes[$spacer]['end'])) {
                throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
            }
            $remaining = substr($html, $wrapper['openEnd'], $wrapper['close'] - $wrapper['openEnd']);
            $paragraph = substr($html, $nodes[$spacer]['start'], $nodes[$spacer]['end'] - $nodes[$spacer]['start']);
            $remaining = str_replace($paragraph, '', $remaining, $count);
            if ($count !== 1) {
                throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
            }
            foreach (['RT-COMPOSE-EDITABLE-START-V1', 'RT-COMPOSE-EDITABLE-END-V1'] as $marker) {
                if (substr_count($remaining, $marker) !== 1) {
                    throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
                }
                $remaining = str_replace($marker, '', $remaining);
            }
            if (self::normalize(strip_tags($remaining)) !== '') {
                throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
            }
            $edits[] = [$wrapper['start'], $wrapper['end'], $paragraph];
        }
        $author = substr($html, $nodes[$cell]['openEnd'], $nodes[$cell]['close'] - $nodes[$cell]['openEnd']);
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $end, $replacement]) {
            $author = substr_replace($author, $replacement, $start - $nodes[$cell]['openEnd'], $end - $start);
        }
        if (str_contains($author, 'RT-COMPOSE-EDITABLE-') || str_contains($author, 'rt-office-metadata')) {
            throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
        }

        return [$author, $options];
    }

    private static function one(DOMXPath $xpath, string $class): DOMElement
    {
        $found = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]');
        if ($found->length !== 1 || ! $found->item(0) instanceof DOMElement) {
            throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
        }

        return $found->item(0);
    }

    /** A custom header/footer outside the known message/signature is not ours. */
    private static function assertNoOtherAuthoredContent(string $html, array $authorCell): void
    {
        $sentinel = 'rt-u2e-author-admission-'.hash('sha256', $html);
        $projection = substr_replace($html, '<td id="'.$sentinel.'"></td>', $authorCell['start'], $authorCell['end'] - $authorCell['start']);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$projection, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
        }
        $xpath = new DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//style|//head|//*[@hidden]|//*[contains(concat(" ",normalize-space(@class)," ")," rt-office-metadata ")]|//*[contains(concat(" ",normalize-space(@class)," ")," rt-outlook-signature ")]|//*[contains(translate(@style,"ABCDEFGHIJKLMNOPQRSTUVWXYZ ","abcdefghijklmnopqrstuvwxyz"),"display:none")]')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        if (self::normalize($dom->getElementsByTagName('body')->item(0)?->textContent ?? '') !== '') {
            throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
        }
    }

    private static function contains(DOMElement $element, string $value): void
    {
        if ($value === '' || ! str_contains(self::normalize($element->textContent), self::normalize($value))) {
            throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
        }
    }

    private static function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00a0}", ' ', $value)));
    }

    private static function textWithBreaks(\DOMNode $node): string
    {
        if ($node->nodeName === 'br') {
            return ' ';
        }
        if ($node instanceof \DOMText) {
            return $node->textContent;
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::textWithBreaks($child);
        }

        return $text;
    }

    private static function has(array $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', $node['attrs']['class'] ?? '', -1, PREG_SPLIT_NO_EMPTY), true);
    }

    /** Offset-only parsing leaves the admitted author's bytes unnormalised. */
    private static function nodes(string $html): array
    {
        preg_match_all('~<!--.*?-->|<(?:style|script)\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</(?:style|script)\s*>|<!doctype\b[^>]*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is', $html, $tokens, PREG_OFFSET_CAPTURE);
        $nodes = [];
        $stack = [];
        foreach ($tokens[0] as [$token, $offset]) {
            if (str_starts_with($token, '<!')) {
                continue;
            }
            if (! preg_match('~\A<(\/?)([a-z][a-z0-9:-]*)\b~i', $token, $name)) {
                throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
            }
            $tag = strtolower($name[2]);
            if ($name[1] !== '') {
                $index = array_pop($stack);
                if (! is_int($index) || $nodes[$index]['tag'] !== $tag) {
                    throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
                }
                $nodes[$index]['close'] = $offset;
                $nodes[$index]['end'] = $offset + strlen($token);

                continue;
            }
            preg_match('~\A<[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~i', $token, $opening);
            $attrs = [];
            preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', substr($opening[0], strlen($name[0]), -1), $attributes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($attributes as $attribute) {
                $attrs[strtolower($attribute[1])] = html_entity_decode($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $raw = in_array($tag, ['style', 'script'], true);
            $leaf = $raw || in_array($tag, ['img', 'br', 'hr', 'meta', 'link', 'input', 'col', 'area', 'base', 'embed', 'param', 'source', 'wbr'], true) || str_ends_with($opening[0], '/>');
            $nodes[] = ['tag' => $tag, 'attrs' => $attrs, 'parent' => $stack === [] ? null : end($stack),
                'start' => $offset, 'openEnd' => $offset + strlen($opening[0]),
                'close' => $raw ? $offset + strripos($token, '</'.$tag) : ($leaf ? $offset + strlen($token) : null),
                'end' => $leaf ? $offset + strlen($token) : null];
            if (! $leaf) {
                $stack[] = count($nodes) - 1;
            }
            if (count($nodes) > 4096) {
                throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
            }
        }
        if ($stack !== []) {
            throw new RuntimeException('U2E_SOURCE_UNAVAILABLE');
        }

        return $nodes;
    }
}
