<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use App\Support\Mail\OutlookSignatureInlineStyle;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\CssSelector\Exception\ExceptionInterface;

/** Unwired contact-leaf presentation only; no source, stylesheet or media rewrite. */
final class OutlookTrainContactForeground
{
    public const MARKER = 'leaf-v1';

    public const MAX_CHARACTERS = 30000;

    public const MAX_CSS_BYTES = 12288;

    private const ATTRIBUTE = 'data-rt-contact-foreground';

    private const SHAPE = 'data-rt-contact-shape';

    private const TEXT_CLASS = 'ft';

    private const ICON_CLASS = 'fi';

    private const TEXT_OPEN = '<span class="ft" style="background-color:#fff;">';

    // Natural dimensions follow the unchanged IMG, including responsive CSS.
    // No fixed wrapper width/height, font, line-height or clipping is added.
    private const ICON_OPEN = '<span class="ft fi" style="display:inline-block;background-color:#fff;">';

    public static function project(string $html): string
    {
        return self::projectVariant($html, false);
    }

    public static function projectMobile(string $html): string
    {
        return self::projectVariant($html, true);
    }

    private static function projectVariant(string $html, bool $mobile): string
    {
        if (str_contains($html, self::ATTRIBUTE)) {
            self::assertRuntime($html);
            self::assertMode(self::restore($html), $mobile);

            return $html;
        }
        self::rejectReserved($html);
        if (! str_contains($html, 'data-rt-train-table-overlay')) {
            return $html;
        }
        self::assertMode($html, $mobile);
        OutlookTrainTableOverlay::assertRuntime($html);
        OutlookTrainTableOverlay::assertForegroundIdentifiersAbsent($html);
        $context = self::context($html, false);
        $nodes = $context['nodes'];
        $ranges = [];
        foreach ($context['pairs'] as [$paragraph, $image]) {
            $ranges[] = [$nodes[$paragraph]['openEnd'], $nodes[$paragraph]['close'], self::TEXT_OPEN.self::inner($html, $nodes[$paragraph]).'</span>'];
            $ranges[] = [$nodes[$image]['start'], $nodes[$image]['end'], self::ICON_OPEN.self::whole($html, $nodes[$image]).'</span>'];
        }
        $stage = $nodes[$context['stage']];
        $shape = self::shape($html, $context);
        $ranges[] = [$stage['start'], $stage['openEnd'], substr($stage['opening'], 0, -1).' '.self::ATTRIBUTE.'="'.self::MARKER.'" '.self::SHAPE.'="'.$shape.'">'];
        $output = self::replace($html, $ranges);
        $output = self::mirror($output, false);
        self::budget($output);
        self::assertCascadeStable($html, $output);
        if (self::restore($output) !== $html) {
            self::fail();
        }

        return $output;
    }

    public static function assertRuntime(string $html): void
    {
        if (! str_contains($html, self::ATTRIBUTE)) {
            self::fail();
        }
        $base = self::restore($html);
        $mobile = str_contains($base, 'data-rt-train-table-overlay="'.OutlookTrainTableOverlay::MOBILE_MARKER.'"');
        if (self::projectVariant($base, $mobile) !== $html) {
            self::fail();
        }
    }

    /** Strip only these exact owned wrappers, preserving every original byte. */
    public static function restore(string $html): string
    {
        if (! str_contains($html, self::ATTRIBUTE)) {
            self::rejectReserved($html);

            return $html;
        }
        self::budget($html);
        $projected = $html;
        $html = self::mirror($html, true);
        $context = self::context($html, true);
        $nodes = $context['nodes'];
        $stage = $nodes[$context['stage']];
        $shape = $stage['attrs'][self::SHAPE] ?? '';
        if (($stage['attrs'][self::ATTRIBUTE] ?? '') !== self::MARKER
            || preg_match('/\A[0-9a-f]{16}\z/', $shape) !== 1
            || array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][self::ATTRIBUTE]))) !== [$context['stage']]
            || array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][self::SHAPE]))) !== [$context['stage']]) {
            self::fail();
        }
        $ranges = [];
        foreach ($context['wrappers'] as $wrapper) {
            $node = $nodes[$wrapper];
            $ranges[] = [$node['start'], $node['openEnd'], ''];
            $ranges[] = [$node['close'], $node['end'], ''];
        }
        $attribute = ' '.self::ATTRIBUTE.'="'.self::MARKER.'"';
        $checksum = ' '.self::SHAPE.'="'.$shape.'"';
        if (substr_count($stage['opening'], $attribute) !== 1 || substr_count($stage['opening'], $checksum) !== 1) {
            self::fail();
        }
        $ranges[] = [$stage['start'], $stage['openEnd'], str_replace([$attribute, $checksum], '', $stage['opening'])];
        $base = self::replace($html, $ranges);
        self::rejectReserved($base);
        OutlookTrainTableOverlay::assertRuntime($base);
        $original = self::context($base, false);
        if (! hash_equals($shape, self::shape($base, $original))) {
            self::fail();
        }
        self::assertCascadeStable($base, $projected);

        return $base;
    }

    /** Only direct compiler-owned contact row pairs in the current ledger. */
    private static function context(string $html, bool $projected): array
    {
        $nodes = self::nodes($html);
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        $stage = self::one($nodes, 'tt', 'div');
        $content = self::one($nodes, 'rt-sign-content', 'td');
        $ledger = self::one($nodes, 'rt-sign-ledger', 'table');
        if ($nodes[$root]['parent'] !== null || ! self::within($nodes, $stage, $root)
            || ! self::within($nodes, $content, $stage) || ! self::within($nodes, $ledger, $content)) {
            self::fail();
        }
        $tables = [];
        foreach ($nodes as $index => $node) {
            if (self::hasClass($node, 'rt-contact') && self::within($nodes, $index, $ledger)) {
                $tables[] = $index;
            }
        }
        if (count($tables) < 1 || count($tables) > 2) {
            self::fail();
        }
        $pairs = $wrappers = [];
        foreach ($tables as $table) {
            if ($nodes[$table]['tag'] !== 'table' || ($nodes[$table]['attrs']['role'] ?? '') !== 'presentation') {
                self::fail();
            }
            $children = $nodes[$table]['children'];
            if (count($children) === 1 && $nodes[$children[0]]['tag'] === 'tbody') {
                $children = $nodes[$children[0]]['children'];
            }
            foreach ($children as $row) {
                $cells = $nodes[$row]['children'];
                if ($nodes[$row]['tag'] !== 'tr' || count($cells) !== 2
                    || $nodes[$cells[0]]['tag'] !== 'td' || $nodes[$cells[1]]['tag'] !== 'td'
                    || ! self::hasClass($nodes[$cells[0]], 'rt-contact-icon')
                    || ! self::hasClass($nodes[$cells[1]], 'rt-contact-text')
                    || count($nodes[$cells[0]]['children']) !== 1 || count($nodes[$cells[1]]['children']) !== 1) {
                    self::fail();
                }
                $image = $nodes[$cells[0]]['children'][0];
                $paragraph = $nodes[$cells[1]]['children'][0];
                if ($nodes[$paragraph]['tag'] !== 'p' || ! self::hasClass($nodes[$paragraph], 'rt-delivery-contact-value')) {
                    self::fail();
                }
                if ($projected) {
                    $iconWrapper = $image;
                    $textWrappers = $nodes[$paragraph]['children'];
                    if ($nodes[$iconWrapper]['opening'] !== self::ICON_OPEN || $nodes[$iconWrapper]['tag'] !== 'span'
                        || count($nodes[$iconWrapper]['children']) !== 1 || count($textWrappers) !== 1) {
                        self::fail();
                    }
                    $textWrapper = $textWrappers[0];
                    if ($nodes[$textWrapper]['opening'] !== self::TEXT_OPEN || $nodes[$textWrapper]['tag'] !== 'span'
                        || self::whole($html, $nodes[$textWrapper]) !== self::inner($html, $nodes[$paragraph])
                        || substr($html, $nodes[$iconWrapper]['close'], $nodes[$iconWrapper]['end'] - $nodes[$iconWrapper]['close']) !== '</span>'
                        || substr($html, $nodes[$textWrapper]['close'], $nodes[$textWrapper]['end'] - $nodes[$textWrapper]['close']) !== '</span>') {
                        self::fail();
                    }
                    $image = $nodes[$iconWrapper]['children'][0];
                    if (self::inner($html, $nodes[$iconWrapper]) !== self::whole($html, $nodes[$image])) {
                        self::fail();
                    }
                    $wrappers[] = $iconWrapper;
                    $wrappers[] = $textWrapper;
                }
                if ($nodes[$image]['tag'] !== 'img' || ($nodes[$image]['attrs']['width'] ?? '') !== '17'
                    || ($nodes[$image]['attrs']['height'] ?? '') !== '17' || ! isset($nodes[$image]['attrs']['src'])
                    || (! $projected && trim(self::inner($html, $nodes[$cells[0]])) !== self::whole($html, $nodes[$image]))
                    || ($projected && trim(self::inner($html, $nodes[$cells[0]])) !== self::whole($html, $nodes[$iconWrapper]))) {
                    self::fail();
                }
                $pairs[] = [$paragraph, $image];
            }
        }
        if ($pairs === [] || count($pairs) > 7) {
            self::fail();
        }
        $owned = array_keys(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, self::TEXT_CLASS) || self::hasClass($node, self::ICON_CLASS)));
        $expected = $wrappers;
        sort($owned);
        sort($expected);
        if ($owned !== $expected) {
            self::fail();
        }

        return compact('nodes', 'root', 'stage', 'content', 'ledger', 'tables', 'pairs', 'wrappers');
    }

    /** A structural mutation checksum, not authentication or source authority. */
    private static function shape(string $html, array $context): string
    {
        $tables = array_map(static fn (int $index): string => self::whole($html, $context['nodes'][$index]), $context['tables']);

        return substr(hash('sha256', $context['nodes'][$context['root']]['opening']."\0".implode("\0", $tables)), 0, 16);
    }

    private static function assertMode(string $html, bool $mobile): void
    {
        $expected = $mobile ? OutlookTrainTableOverlay::MOBILE_MARKER : OutlookTrainTableOverlay::MARKER;
        if (! str_contains($html, 'data-rt-train-table-overlay="'.$expected.'"')) {
            self::fail();
        }
    }

    private static function rejectReserved(string $html): void
    {
        foreach ([self::ATTRIBUTE, self::SHAPE] as $reserved) {
            if (str_contains($html, $reserved)) {
                self::fail();
            }
        }
        OutlookTrainTableOverlay::assertForegroundIdentifiersAbsent($html);
    }

    /** Exactly two new stage-qualified rules in the existing owned mirror. */
    private static function mirror(string $html, bool $restore): string
    {
        $nodes = self::nodes($html);
        $root = $nodes[self::one($nodes, 'rt-outlook-signature', 'div')];
        preg_match_all('/(?:^|\s)(rts[0-9a-f]{10})(?=\s|$)/', $root['attrs']['class'] ?? '', $scope);
        if (count($scope[1]) !== 1) {
            self::fail();
        }
        $mobile = str_contains($html, 'data-rt-train-table-overlay="'.OutlookTrainTableOverlay::MOBILE_MARKER.'"');
        $prefix = $mobile ? '.m'.base_convert(substr($scope[1][0], 3), 16, 36).'.rtm' : '.'.$scope[1][0];
        $rule = $prefix.' .tt .ft{background:#fff!important;}'.$prefix.' .tt .fi{display:inline-block!important;}';
        $attribute = $mobile ? 'data-rt-outlook-mobile-css' : OutlookSignatureInlineStyle::ATTRIBUTE;
        if (preg_match_all('~(<style '.$attribute.'="1">)(.*?)(</style>)~s', $html, $styles, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) !== 1) {
            self::fail();
        }
        $match = $styles[0];
        $css = $match[2][0];
        if ($restore) {
            if (! str_ends_with($css, $rule) || substr_count($css, $rule) !== 1) {
                self::fail();
            }
            $css = substr($css, 0, -strlen($rule));
        } else {
            if (str_contains($css, $rule)) {
                self::fail();
            }
            $css .= $rule;
        }

        return substr_replace($html, $css, $match[2][1], strlen($match[2][0]));
    }

    /** Original selectors must keep their original targets and specificity branches. */
    private static function assertCascadeStable(string $before, string $after): void
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $before, $styles);
        $selectors = [];
        foreach ($styles[1] as $css) {
            foreach (self::selectorPreludes($css) as $prelude) {
                foreach (self::selectorBranches($prelude) as $selector) {
                    $selectors[$selector] = true;
                }
            }
        }
        if ($selectors === []) {
            return;
        }
        [$original, $oldNodes] = self::cascadeDom($before);
        [$projected, $newNodes] = self::cascadeDom($after);
        if (count($oldNodes) !== count($newNodes)) {
            self::fail();
        }
        $oldMap = $newMap = [];
        foreach ($oldNodes as $index => $node) {
            if ($node->tagName !== $newNodes[$index]->tagName) {
                self::fail();
            }
            $oldMap[$node->getNodePath()] = 'original:'.$index;
            $newMap[$newNodes[$index]->getNodePath()] = 'original:'.$index;
        }
        $converter = new CssSelectorConverter;
        foreach (array_keys($selectors) as $selector) {
            // XPath models no hover/focus/visited state or generated content.
            // Do not mistake Symfony's never-match dynamic conversion for proof.
            if (preg_match('/:(?:hover|active|focus(?:-visible|-within)?|visited|target|before|after|first-line|first-letter)\b/i', self::decodedSelector($selector))) {
                throw new RuntimeException('Autoren-CSS mit dynamischen oder erzeugten Selektoren wird vom experimentellen Kontakt-Vordergrund nicht unterstuetzt.');
            }
            try {
                $query = $converter->toXPath($selector);
            } catch (ExceptionInterface) {
                throw new RuntimeException('Die Autoren-CSS-Selektoren koennen fuer den experimentellen Kontakt-Vordergrund nicht eindeutig geprueft werden.');
            }
            if (self::matchedIdentities($original, $query, $oldMap) !== self::matchedIdentities($projected, $query, $newMap)) {
                throw new RuntimeException('Der experimentelle Kontakt-Vordergrund wuerde die Autoren-CSS-Selektorkaskade veraendern.');
            }
        }
    }

    /** Read-only DOMs are matching models, never an output serializer. */
    private static function cascadeDom(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            self::fail();
        }
        $xpath = new DOMXPath($document);
        $original = [];
        foreach ($xpath->query('//*') as $node) {
            if (! in_array(self::TEXT_CLASS, preg_split('/\s+/', $node->getAttribute('class')), true)) {
                $original[] = $node;
            }
        }

        return [$xpath, $original];
    }

    private static function matchedIdentities(DOMXPath $xpath, string $query, array $map): array
    {
        $matched = $xpath->query($query);
        if ($matched === false) {
            self::fail();
        }
        $identities = [];
        foreach ($matched as $node) {
            $identities[] = $map[$node->getNodePath()] ?? 'new-wrapper:'.$node->getNodePath();
        }
        sort($identities, SORT_STRING);

        return $identities;
    }

    /** Bounded brace lexer; quoted strings/comments/escapes cannot invent rules. */
    private static function selectorPreludes(string $css): array
    {
        $start = 0;
        $quote = null;
        $selectors = [];
        for ($index = 0, $length = strlen($css); $index < $length; $index++) {
            $character = $css[$index];
            if ($character === '\\') {
                $index++;

                continue;
            }
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
            if ($character === '/' && ($css[$index + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $index + 2);
                if ($end === false) {
                    self::fail();
                }
                $index = $end + 1;

                continue;
            }
            if ($character === '{') {
                $prelude = trim(substr($css, $start, $index - $start));
                $normalized = trim(preg_replace('~\A(?:\s|/\*.*?\*/)*~s', '', $prelude));
                if ($normalized !== '' && ! str_starts_with($normalized, '@')) {
                    $selectors[] = $prelude;
                }
                $start = $index + 1;
            } elseif ($character === '}') {
                $start = $index + 1;
            }
        }
        if ($quote !== null) {
            self::fail();
        }

        return $selectors;
    }

    /** Each comma branch is separate so a weaker branch cannot hide a loss. */
    private static function selectorBranches(string $selector): array
    {
        $branches = [];
        $quote = null;
        $depth = $start = 0;
        for ($index = 0, $length = strlen($selector); $index < $length; $index++) {
            $character = $selector[$index];
            if ($character === '\\') {
                $index++;

                continue;
            }
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '/' && ($selector[$index + 1] ?? '') === '*') {
                $end = strpos($selector, '*/', $index + 2);
                if ($end === false) {
                    self::fail();
                }
                $index = $end + 1;
            } elseif ($character === '[' || $character === '(') {
                $depth++;
            } elseif ($character === ']' || $character === ')') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $branches[] = trim(substr($selector, $start, $index - $start));
                $start = $index + 1;
            }
        }
        $branches[] = trim(substr($selector, $start));
        if ($quote !== null || $depth !== 0 || in_array('', $branches, true)) {
            self::fail();
        }

        return $branches;
    }

    private static function decodedSelector(string $selector): string
    {
        return preg_replace_callback('~\\\\([0-9a-fA-F]{1,6})(?:\r\n|[ \t\r\n\f])?|\\\\([^\r\n\f])~', static function (array $match): string {
            if (($match[1] ?? '') === '') {
                return $match[2];
            }
            $point = hexdec($match[1]);

            return $point > 0 && $point <= 0x10FFFF && ! ($point >= 0xD800 && $point <= 0xDFFF) ? mb_chr($point, 'UTF-8') : "\u{FFFD}";
        }, $selector);
    }

    private static function budget(string $html): void
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        if (array_sum(array_map('strlen', $styles[1])) >= self::MAX_CSS_BYTES
            || intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) > self::MAX_CHARACTERS) {
            throw new RuntimeException('Der experimentelle Kontakt-Vordergrund ueberschreitet das unveraenderte native HTML-/CSS-Budget.');
        }
    }

    private static function one(array $nodes, string $class, string $tag): int
    {
        $found = array_keys(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, $class)));
        if (count($found) !== 1 || $nodes[$found[0]]['tag'] !== $tag) {
            self::fail();
        }

        return $found[0];
    }

    private static function within(array $nodes, int $child, int $ancestor): bool
    {
        while (($parent = $nodes[$child]['parent']) !== null) {
            if ($parent === $ancestor) {
                return true;
            }
            $child = $parent;
        }

        return false;
    }

    private static function hasClass(array $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', $node['attrs']['class'] ?? ''), true);
    }

    private static function inner(string $html, array $node): string
    {
        return substr($html, $node['openEnd'], $node['close'] - $node['openEnd']);
    }

    private static function whole(string $html, array $node): string
    {
        return substr($html, $node['start'], $node['end'] - $node['start']);
    }

    private static function attributes(string $tag): array
    {
        preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $tag, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attrs = [];
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            $raw = $match[2] ?? $match[3] ?? $match[4] ?? '';
            if (isset($attrs[$key]) || (in_array($key, ['class', 'style'], true) && $match[4] !== null)) {
                self::fail();
            }
            $attrs[$key] = CssSemantic::decodeHtmlEntitiesOnce($raw);
            if ($key === 'class' && $attrs[$key] !== $raw) {
                self::fail();
            }
        }

        return $attrs;
    }

    /** Offset parsing only; never serialize or normalize opaque original bytes. */
    private static function nodes(string $html): array
    {
        $tag = '(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*';
        preg_match_all('~<!--.*?-->|<style\b'.$tag.'>.*?</style\s*>|</?[a-z][a-z0-9:-]*'.$tag.'>~is', $html, $tokens, PREG_OFFSET_CAPTURE);
        $nodes = $stack = [];
        foreach ($tokens[0] as [$token, $offset]) {
            if (str_starts_with($token, '<!--') || preg_match('~\A<style\b~i', $token)) {
                continue;
            }
            preg_match('~\A<(/?)([a-z][a-z0-9:-]*)\b~i', $token, $name);
            $element = strtolower($name[2]);
            if ($name[1] === '/') {
                $index = array_pop($stack);
                if ($index === null || $nodes[$index]['tag'] !== $element || preg_match('~\A</[a-z][a-z0-9:-]*\s*>\z~i', $token) !== 1) {
                    self::fail();
                }
                $nodes[$index]['close'] = $offset;
                $nodes[$index]['end'] = $offset + strlen($token);

                continue;
            }
            $void = in_array($element, ['br', 'hr', 'img', 'meta', 'link'], true);
            if (! $void && preg_match('~/\s*>\z~', $token)) {
                self::fail();
            }
            $parent = $stack === [] ? null : $stack[array_key_last($stack)];
            $index = count($nodes);
            $nodes[] = ['tag' => $element, 'attrs' => self::attributes($token), 'parent' => $parent, 'children' => [], 'start' => $offset, 'openEnd' => $offset + strlen($token), 'opening' => $token, 'close' => $void ? $offset + strlen($token) : null, 'end' => $void ? $offset + strlen($token) : null];
            if ($parent !== null) {
                $nodes[$parent]['children'][] = $index;
            }
            if (! $void) {
                $stack[] = $index;
            }
        }
        if ($stack !== []) {
            self::fail();
        }

        return $nodes;
    }

    private static function replace(string $html, array $ranges): string
    {
        usort($ranges, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $last = strlen($html) + 1;
        foreach ($ranges as [$start, $end, $replacement]) {
            if ($end > $last || $start > $end) {
                self::fail();
            }
            $html = substr_replace($html, $replacement, $start, $end - $start);
            $last = $start;
        }

        return $html;
    }

    private static function fail(): never
    {
        throw new RuntimeException('Der experimentelle Kontakt-Vordergrund besitzt keinen eindeutigen reversiblen V27-Vertrag.');
    }
}
