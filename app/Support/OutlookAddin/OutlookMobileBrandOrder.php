<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Last-mile mobile order only; move owned bytes after canonical inverses. */
final class OutlookMobileBrandOrder
{
    private const TOKENS = '~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    public static function project(string $html): string
    {
        if (! str_contains($html, 'rt-mobile-ledger') || ! str_contains($html, 'data-rt-personal-columns')) {
            return $html;
        }
        $nodes = self::nodes($html);
        $mobile = array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, 'rt-mobile-ledger')));
        $personal = array_keys(array_filter($nodes, static fn (array $node): bool => array_key_exists('data-rt-personal-columns', $node['attrs'])));
        // Marker-like text in comments, styles or ordinary text is not an opt-in.
        if ($mobile === [] || $personal === []) {
            return $html;
        }
        if (count($mobile) !== 1 || count($personal) !== 1) {
            self::fail();
        }
        $root = $mobile[0];
        $flow = $personal[0];
        $artifacts = array_keys(array_filter($nodes, static fn (array $node): bool => array_key_exists('data-rt-artifact-version', $node['attrs'])));
        if ($nodes[$root]['tag'] !== 'div' || ! self::has($nodes[$root], 'rt-outlook-signature')
            || ! self::has($nodes[$root], 'rtm') || $nodes[$flow]['tag'] !== 'div'
            || ! self::has($nodes[$flow], 'rt-personal-columns')
            || $nodes[$flow]['attrs']['data-rt-personal-columns'] !== 'v1'
            || count($artifacts) !== 1 || $nodes[$artifacts[0]]['tag'] !== 'tr'
            || $nodes[$artifacts[0]]['attrs']['data-rt-artifact-version'] !== 'v27'
            || ! self::within($nodes, $artifacts[0], $root) || ! self::within($nodes, $flow, $artifacts[0])) {
            self::fail();
        }
        $left = self::one($nodes, 'rt-personal-left', 'table');
        $right = self::one($nodes, 'rt-personal-right', 'table');
        self::children($html, $nodes, $flow, [$left, $right]);
        $leftCell = self::columnCell($html, $nodes, $left, 'rt-personal-cell-left');
        $rightCell = self::columnCell($html, $nodes, $right, 'rt-personal-cell-right');
        $name = self::one($nodes, 'rt-personal-name', 'div');
        $direct = self::one($nodes, 'rt-personal-direct', 'div');
        $company = self::one($nodes, 'rt-personal-company-block', 'table');
        $contacts = self::one($nodes, 'rt-personal-company-contacts', 'div');
        $logo = self::one($nodes, 'rt-personal-logo-frame', 'table');
        $logoImage = self::one($nodes, 'rt-logo', 'img');
        $logoCell = self::tableCell($html, $nodes, $logo);
        self::children($html, $nodes, $logoCell, [$logoImage]);
        if (trim($nodes[$logoImage]['attrs']['src'] ?? '') === '') {
            self::fail();
        }
        self::children($html, $nodes, $rightCell, [$company]);
        $companyCell = self::tableCell($html, $nodes, $company);
        if ($nodes[$logo]['parent'] === $leftCell) {
            self::children($html, $nodes, $leftCell, [$logo, $name, $direct]);
            self::children($html, $nodes, $companyCell, [$contacts]);

            return $html;
        }
        self::children($html, $nodes, $leftCell, [$name, $direct]);
        self::children($html, $nodes, $companyCell, [$logo, $contacts]);
        $moving = substr($html, $nodes[$logo]['start'], $nodes[$logo]['end'] - $nodes[$logo]['start']);
        // All source offsets are read before editing. The source is strictly
        // after the destination, so removing it cannot shift the insertion.
        if ($nodes[$logo]['start'] <= $nodes[$name]['start']) {
            self::fail();
        }
        $output = substr_replace($html, '', $nodes[$logo]['start'], strlen($moving));

        return substr_replace($output, $moving, $nodes[$name]['start'], 0);
    }

    private static function columnCell(string $html, array $nodes, int $table, string $class): int
    {
        $cell = self::tableCell($html, $nodes, $table);
        if (! self::has($nodes[$cell], $class)) {
            self::fail();
        }

        return $cell;
    }

    private static function tableCell(string $html, array $nodes, int $table): int
    {
        $children = self::direct($nodes, $table);
        if (count($children) !== 1) {
            self::fail();
        }
        $row = $children[0];
        if ($nodes[$row]['tag'] === 'tbody') {
            self::children($html, $nodes, $table, [$row]);
            $children = self::direct($nodes, $row);
            if (count($children) !== 1) {
                self::fail();
            }
            $table = $row;
            $row = $children[0];
        }
        if ($nodes[$row]['tag'] !== 'tr') {
            self::fail();
        }
        self::children($html, $nodes, $table, [$row]);
        $cells = self::direct($nodes, $row);
        if (count($cells) !== 1 || $nodes[$cells[0]]['tag'] !== 'td') {
            self::fail();
        }
        self::children($html, $nodes, $row, $cells);

        return $cells[0];
    }

    private static function children(string $html, array $nodes, int $parent, array $expected): void
    {
        if (self::direct($nodes, $parent) !== $expected) {
            self::fail();
        }
        $cursor = $nodes[$parent]['openEnd'];
        foreach ($expected as $child) {
            self::trivia(substr($html, $cursor, $nodes[$child]['start'] - $cursor));
            $cursor = $nodes[$child]['end'];
        }
        self::trivia(substr($html, $cursor, $nodes[$parent]['close'] - $cursor));
    }

    private static function trivia(string $html): void
    {
        if (trim(preg_replace('~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>~is', '', $html)) !== '') {
            self::fail();
        }
    }

    private static function direct(array $nodes, int $parent): array
    {
        return array_keys(array_filter($nodes, static fn (array $node): bool => $node['parent'] === $parent));
    }

    private static function one(array $nodes, string $class, string $tag): int
    {
        $matches = array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, $class)));
        if (count($matches) !== 1 || $nodes[$matches[0]]['tag'] !== $tag) {
            self::fail();
        }

        return $matches[0];
    }

    private static function has(array $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($node['attrs']['class'] ?? '')), true);
    }

    private static function within(array $nodes, int $child, int $parent): bool
    {
        for ($index = $child; $index !== null; $index = $nodes[$index]['parent']) {
            if ($index === $parent) {
                return true;
            }
        }

        return false;
    }

    private static function nodes(string $html): array
    {
        if (strlen($html) > 1000000) {
            self::fail();
        }
        preg_match_all(self::TOKENS, $html, $tokens, PREG_OFFSET_CAPTURE);
        $nodes = [];
        $stack = [];
        $cursor = 0;
        foreach ($tokens[0] as [$token, $offset]) {
            if (str_contains(substr($html, $cursor, $offset - $cursor), '<')) {
                self::fail();
            }
            $cursor = $offset + strlen($token);
            if (str_starts_with($token, '<!--') || preg_match('~\A<style\b~i', $token)) {
                continue;
            }
            preg_match('~\A<(/?)([a-z][a-z0-9:-]*)\b~i', $token, $name);
            $tag = strtolower($name[2]);
            if ($name[1] === '/') {
                $index = array_pop($stack);
                if ($index === null || $nodes[$index]['tag'] !== $tag || ! preg_match('~\A</[a-z][a-z0-9:-]*\s*>\z~i', $token)) {
                    self::fail();
                }
                $nodes[$index]['close'] = $offset;
                $nodes[$index]['end'] = $cursor;

                continue;
            }
            $attributes = substr($token, strlen($name[0]), -1);
            $attrs = [];
            $position = 0;
            while ($position < strlen($attributes)) {
                if (preg_match('~\G\s*/?\s*\z~', $attributes, $space, 0, $position)) {
                    $position = strlen($attributes);
                    break;
                }
                if (! preg_match('~\G\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $attributes, $attribute, PREG_UNMATCHED_AS_NULL, $position)) {
                    self::fail();
                }
                $key = strtolower($attribute[1]);
                if (array_key_exists($key, $attrs)) {
                    self::fail();
                }
                $attrs[$key] = CssSemantic::decodeHtmlEntitiesOnce($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '');
                $position += strlen($attribute[0]);
            }
            $void = in_array($tag, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true);
            if (! $void && preg_match('~/\s*>\z~', $token)) {
                self::fail();
            }
            $nodes[] = ['tag' => $tag, 'attrs' => $attrs, 'parent' => $stack === [] ? null : $stack[array_key_last($stack)],
                'start' => $offset, 'openEnd' => $cursor, 'close' => $void ? $cursor : null, 'end' => $void ? $cursor : null];
            if (count($nodes) > 10000) {
                self::fail();
            }
            if (! $void) {
                $stack[] = array_key_last($nodes);
            }
        }
        if ($stack !== [] || str_contains(substr($html, $cursor), '<')) {
            self::fail();
        }

        return $nodes;
    }

    private static function fail(): never
    {
        throw new RuntimeException('Die mobile Logoreihenfolge besitzt keinen eindeutigen persoenlichen Signaturvertrag.');
    }
}
