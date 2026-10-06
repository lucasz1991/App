<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use App\Support\Mail\SignatureTableOverlapDelivery;
use RuntimeException;

/** Native output only: splice trusted marker bytes without a DOM round-trip. */
final class OutlookNativeMetadataPlacement
{
    public static function signature(string $html, string $metadata): string
    {
        if (preg_match('~\bdata-rt-train-delivery\s*=\s*(["\'])'.preg_quote(SignatureTableOverlapDelivery::MARKER, '~').'\1~i', $html) !== 1) {
            return $metadata.$html;
        }
        if (! SignatureTableOverlapDelivery::applies($html)) {
            self::fail();
        }
        self::assertMetadata($html, $metadata);
        $nodes = self::nodes($html);
        $root = self::oneClass($nodes, 'rt-outlook-signature', 'div');
        if ($nodes[$root]['parent'] !== null) {
            self::fail();
        }
        $frame = self::oneChild($nodes, $root, 'table');
        self::assertFrame($nodes[$frame]);
        $rows = self::rows($nodes, $frame);
        if (count($rows) !== 2) {
            self::fail();
        }
        $main = self::oneChild($nodes, $rows[0], 'td');
        $legal = self::oneChild($nodes, $rows[1], 'td');
        if (count($nodes[$rows[0]]['children']) !== 1 || count($nodes[$rows[1]]['children']) !== 1
            || ! self::hasClass($nodes[$main], 'rt-sign-cell') || ! self::hasClass($nodes[$legal], 'rt-pad')
            || ($nodes[$main]['attrs']['width'] ?? '') !== '100%' || ($nodes[$legal]['attrs']['width'] ?? '') !== '100%'
            || ! self::withinBoundedMain($nodes, $main)) {
            self::fail();
        }

        return self::splice($html, $metadata, $nodes[$legal]['close']);
    }

    public static function template(string $html, string $metadata): string
    {
        if (! str_contains($html, 'rt-native-compose-frame')) {
            return $metadata.$html;
        }
        $marker = self::assertMetadata($html, $metadata);
        if ($marker !== 'RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE'
            || (self::nodes($metadata)[0]['attrs']['data-rt-template-signature-mode'] ?? '') !== 'native') {
            self::fail();
        }
        $nodes = self::nodes($html);
        $root = self::oneClass($nodes, 'rt-outlook-template', 'div');
        $frame = self::oneClass($nodes, 'rt-native-compose-frame', 'table');
        if ($nodes[$root]['parent'] !== null || ! self::descendsFrom($nodes, $frame, $root)) {
            self::fail();
        }
        self::assertFrame($nodes[$frame]);
        $mark = self::oneClass($nodes, 'rt-mark', 'img');
        self::assertMark($nodes[$mark]);
        $cell = $nodes[$mark]['parent'];
        while ($cell !== null && $nodes[$cell]['tag'] !== 'td') {
            $cell = $nodes[$cell]['parent'];
        }
        if ($cell === null || ! self::descendsFrom($nodes, $cell, $frame)
            || ($nodes[$cell]['attrs']['width'] ?? '') !== '44') {
            self::fail();
        }
        $style = $nodes[$cell]['attrs']['style'] ?? '';
        foreach (['font-size', 'line-height'] as $property) {
            if (preg_match('/(?:^|;)\s*'.$property.'\s*:\s*0(?:px|pt)?\s*(?:!important\s*)?(?:;|$)/i', $style) !== 1) {
                self::fail();
            }
        }
        $inner = substr($html, $nodes[$cell]['openEnd'], $nodes[$cell]['close'] - $nodes[$cell]['openEnd']);
        preg_match_all('~<!--.*?-->~s', $inner, $comments);
        $fallbacks = 0;
        foreach ($comments[0] as $comment) {
            if (preg_match('~\A<!--\[if mso\]>(.*?)<!\[endif\]-->\z~is', $comment, $branch) !== 1) {
                continue;
            }
            $fallback = self::nodes($branch[1]);
            if (count($fallback) !== 1 || $fallback[0]['tag'] !== 'img' || ! self::hasClass($fallback[0], 'rt-mark')) {
                self::fail();
            }
            self::assertMark($fallback[0]);
            $fallbacks++;
        }
        if ($fallbacks !== 1) {
            self::fail();
        }

        return self::splice($html, $metadata, $nodes[$cell]['close']);
    }

    private static function assertMetadata(string $html, string $metadata): string
    {
        $nodes = self::nodes($metadata);
        if (count($nodes) !== 1 || $nodes[0]['tag'] !== 'span' || ! self::hasClass($nodes[0], 'rt-office-metadata')
            || ! array_key_exists('hidden', $nodes[0]['attrs']) || ($nodes[0]['attrs']['aria-hidden'] ?? '') !== 'true'
            || substr_count($metadata, 'data-rt-outlook-marker-css="1"') !== 1 || $nodes[0]['children'] !== []) {
            self::fail();
        }
        $marker = substr($metadata, $nodes[0]['openEnd'], $nodes[0]['close'] - $nodes[0]['openEnd']);
        if (preg_match('/\A[A-Z0-9_:-]+\z/i', $marker) !== 1 || substr_count($metadata, '<!-- '.$marker.' -->') !== 1
            || str_contains($html, $marker)
            || (str_starts_with($marker, 'RT-SIGNATURE-VERSION:') && preg_match('/RT-SIGNATURE-VERSION:[0-9a-f]{16}/i', $html))) {
            self::fail();
        }

        return $marker;
    }

    /** @return list<array{tag:string,attrs:array<string,string>,parent:?int,children:list<int>,openEnd:int,close:?int}> */
    private static function nodes(string $html): array
    {
        // Complete comments (including hidden MSO IMG) and complete styles are
        // opaque. Quoted attributes may contain >, </td> or CSS-like literals.
        $tag = '(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*';
        $pattern = '~<!--.*?-->|<style\b'.$tag.'>.*?</style\s*>|</?[a-z][a-z0-9:-]*'.$tag.'>~is';
        preg_match_all($pattern, $html, $tokens, PREG_OFFSET_CAPTURE);
        $nodes = [];
        $stack = [];
        foreach ($tokens[0] as [$token, $offset]) {
            if (str_starts_with($token, '<!--') || preg_match('~\A<style\b~i', $token)) {
                continue;
            }
            if (preg_match('~\A<(/?)([a-z][a-z0-9:-]*)\b~i', $token, $name) !== 1) {
                self::fail();
            }
            $element = strtolower($name[2]);
            if ($name[1] === '/') {
                $index = array_pop($stack);
                if ($index === null || $nodes[$index]['tag'] !== $element || preg_match('~\A</[a-z][a-z0-9:-]*\s*>\z~i', $token) !== 1) {
                    self::fail();
                }
                $nodes[$index]['close'] = $offset;

                continue;
            }
            $parent = $stack === [] ? null : $stack[array_key_last($stack)];
            $index = count($nodes);
            $void = in_array($element, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true);
            if (! $void && preg_match('~/\s*>\z~', $token)) {
                self::fail();
            }
            $nodes[] = ['tag' => $element, 'attrs' => self::attributes($token), 'parent' => $parent, 'children' => [], 'openEnd' => $offset + strlen($token), 'close' => $void ? $offset + strlen($token) : null];
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

    /** @return array<string,string> */
    private static function attributes(string $tag): array
    {
        preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $tag, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $result = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (array_key_exists($name, $result)) {
                self::fail();
            }
            $result[$name] = CssSemantic::decodeHtmlEntitiesOnce($match[2] ?? $match[3] ?? $match[4] ?? '');
        }

        return $result;
    }

    private static function oneClass(array $nodes, string $class, string $tag): int
    {
        $matches = [];
        foreach ($nodes as $index => $node) {
            if (self::hasClass($node, $class)) {
                if ($node['tag'] !== $tag) {
                    self::fail();
                }
                $matches[] = $index;
            }
        }
        if (count($matches) !== 1) {
            self::fail();
        }

        return $matches[0];
    }

    private static function oneChild(array $nodes, int $parent, string $tag): int
    {
        $matches = array_values(array_filter($nodes[$parent]['children'], static fn (int $index): bool => $nodes[$index]['tag'] === $tag));
        if (count($matches) !== 1) {
            self::fail();
        }

        return $matches[0];
    }

    private static function rows(array $nodes, int $table): array
    {
        $rows = [];
        foreach ($nodes[$table]['children'] as $child) {
            if ($nodes[$child]['tag'] === 'tr') {
                $rows[] = $child;
            } elseif ($nodes[$child]['tag'] === 'tbody') {
                foreach ($nodes[$child]['children'] as $row) {
                    if ($nodes[$row]['tag'] !== 'tr') {
                        self::fail();
                    }
                    $rows[] = $row;
                }
            } else {
                self::fail();
            }
        }

        return $rows;
    }

    private static function withinBoundedMain(array $nodes, int $main): bool
    {
        $matches = 0;
        foreach ($nodes as $index => $node) {
            if (($node['attrs']['data-rt-train-delivery'] ?? '') === SignatureTableOverlapDelivery::MARKER) {
                if ($node['tag'] !== 'table' || ! self::descendsFrom($nodes, $index, $main)) {
                    self::fail();
                }
                $matches++;
            }
        }

        return $matches === 1;
    }

    private static function descendsFrom(array $nodes, int $child, int $ancestor): bool
    {
        for ($parent = $nodes[$child]['parent']; $parent !== null; $parent = $nodes[$parent]['parent']) {
            if ($parent === $ancestor) {
                return true;
            }
        }

        return false;
    }

    private static function hasClass(array $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($node['attrs']['class'] ?? '')), true);
    }

    private static function assertFrame(array $node): void
    {
        foreach (['role' => 'presentation', 'width' => '100%', 'cellpadding' => '0', 'cellspacing' => '0'] as $name => $value) {
            if (($node['attrs'][$name] ?? '') !== $value) {
                self::fail();
            }
        }
    }

    private static function assertMark(array $node): void
    {
        if (($node['attrs']['width'] ?? '') !== '44' || ($node['attrs']['height'] ?? '') !== '44') {
            self::fail();
        }
    }

    private static function splice(string $html, string $metadata, ?int $offset): string
    {
        if ($offset === null) {
            self::fail();
        }

        return substr($html, 0, $offset).$metadata.substr($html, $offset);
    }

    private static function fail(): never
    {
        throw new RuntimeException('Die native Metadatenposition ist nicht eindeutig oder entspricht nicht dem freigegebenen Ausgabevertrag.');
    }
}
