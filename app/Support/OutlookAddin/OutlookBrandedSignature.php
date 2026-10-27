<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Output-only V27 branding, before the existing CSS and CID compilers. */
final class OutlookBrandedSignature
{
    public const RED = '#e90032';

    public const MARKER = 'v1';

    private const TOKENS = '~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    private const CELL_STYLE = 'padding:0;font-size:0;line-height:0;';

    /** Managed source changes participate in the existing payload cache key. */
    public static function assetHashes(): array
    {
        $hashes = [];
        foreach (['fill', 'label', 'mail', 'phone'] as $kind) {
            $url = self::asset($kind);
            $path = public_path(ltrim($url, '/'));
            if (! is_file($path) || ! is_readable($path) || is_link($path)
                || ($binary = @file_get_contents($path)) === false || strlen($binary) > 1048576
                || ($image = @getimagesizefromstring($binary)) === false || ($image['mime'] ?? '') !== 'image/png') {
                throw new RuntimeException('Ein verwaltetes Outlook-Markenbild fehlt oder ist kein gueltiges PNG.');
            }
            $hashes[$url] = hash('sha256', $binary);
        }

        return $hashes;
    }

    public static function project(string $rows, string $baseUrl): string
    {
        if (! preg_match('~\bdata-rt-artifact-version\s*=\s*(?:"v27"|\'v27\'|v27(?=[\s>]))~i', $rows)) {
            return $rows;
        }
        $nodes = self::nodes($rows);
        $artifacts = array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs']['data-rt-artifact-version'])));
        if ($artifacts === [] || ! array_filter($artifacts, static fn (int $index): bool => $nodes[$index]['attrs']['data-rt-artifact-version'] === 'v27')) {
            return $rows;
        }
        if (count($artifacts) !== 1 || $nodes[$artifacts[0]]['tag'] !== 'tr') {
            self::fail();
        }
        $url = parse_url($baseUrl);
        if (! is_array($url) || ! filter_var($baseUrl, FILTER_VALIDATE_URL)
            || ! in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            || empty($url['host']) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($url)) !== []) {
            self::fail();
        }
        $baseUrl = rtrim($baseUrl, '/');
        $artifact = $artifacts[0];
        $edits = self::roleEdits($nodes, $artifact);
        $authored = array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, 'design-hotline-row')
            && self::has($node, 'design-hotline-spaced') && ! isset($node['attrs']['data-rt-brand-hotline'])));
        $owned = array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs']['data-rt-brand-hotline'])));
        if ($authored !== [] || $owned !== []) {
            if (count($authored) + count($owned) !== 1) {
                self::fail();
            }
            $cell = ($authored ?: $owned)[0];
            if ($nodes[$cell]['tag'] !== 'td' || ! self::within($nodes, $cell, $artifact)) {
                self::fail();
            }
            $table = self::outerTable($rows, $nodes, $cell, $artifact);
            $inner = self::singleChild($rows, $nodes, $cell, 'table');
            $row = self::singleRow($rows, $nodes, $inner);
            $columns = self::direct($nodes, $row);
            if (count($columns) !== 5 || array_filter($columns, static fn (int $index): bool => $nodes[$index]['tag'] !== 'td')) {
                self::fail();
            }
            self::children($rows, $nodes, $row, $columns);
            if ($authored !== []) {
                $strong = self::singleChild($rows, $nodes, $columns[0], 'strong');
                self::plainText($rows, $nodes, $strong, '24/7');
                self::plainText($rows, $nodes, $columns[1], 'erreichbar');
                self::plainText($rows, $nodes, $columns[3], '');
                [$mailOpen, $mailClose] = self::action($rows, $nodes, $columns[2], 'mailto:');
                [$phoneOpen, $phoneClose] = self::action($rows, $nodes, $columns[4], 'tel:');
                $classes = array_values(array_filter(self::classes($nodes[$cell]), static fn (string $class): bool => $class !== 'design-hotline-spaced'));
                $classes[] = 'rt-brand-hotline-cell';
                $opening = self::opening($nodes[$cell], [
                    'class' => implode(' ', $classes), 'data-rt-brand-hotline' => self::MARKER,
                    'style' => self::CELL_STYLE, 'bgcolor' => self::RED,
                ]);
                $edits[] = [$nodes[$cell]['start'], $nodes[$cell]['openEnd'] - $nodes[$cell]['start'], $opening];
                $edits[] = [$nodes[$cell]['openEnd'], $nodes[$cell]['close'] - $nodes[$cell]['openEnd'], self::raster($mailOpen, $mailClose, $phoneOpen, $phoneClose, $baseUrl)];
                $tableClasses = self::classes($nodes[$table]);
                if (! in_array('rt-hotline-banner', $tableClasses, true)) {
                    $tableClasses[] = 'rt-hotline-banner';
                }
                $edits[] = [$nodes[$table]['start'], $nodes[$table]['openEnd'] - $nodes[$table]['start'], self::opening($nodes[$table], [
                    'class' => implode(' ', $tableClasses), 'width' => '100%', 'border' => '0',
                    'cellspacing' => '0', 'cellpadding' => '0', 'bgcolor' => self::RED,
                    'style' => 'width:100%;border-collapse:collapse;',
                ])];
            } else {
                if ($nodes[$cell]['attrs']['data-rt-brand-hotline'] !== self::MARKER
                    || ! self::has($nodes[$cell], 'rt-brand-hotline-cell')
                    || ! self::has($nodes[$cell], 'design-hotline-row') || self::has($nodes[$cell], 'design-hotline-spaced')
                    || ($nodes[$cell]['attrs']['style'] ?? '') !== self::CELL_STYLE
                    || ($nodes[$cell]['attrs']['bgcolor'] ?? '') !== self::RED) {
                    self::fail();
                }
                [$mailOpen, $mailClose] = self::action($rows, $nodes, $columns[2], 'mailto:');
                [$phoneOpen, $phoneClose] = self::action($rows, $nodes, $columns[3], 'tel:');
                if (self::inner($rows, $nodes[$cell]) !== self::raster($mailOpen, $mailClose, $phoneOpen, $phoneClose, $baseUrl)
                    || ! self::has($nodes[$table], 'rt-hotline-banner')) {
                    self::fail();
                }
            }
        }

        return self::replace($rows, $edits);
    }

    private static function raster(string $mailOpen, string $mailClose, string $phoneOpen, string $phoneClose, string $baseUrl): string
    {
        $cell = ' style="'.self::CELL_STYLE.'"';

        return '<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="'.self::RED.'" style="width:100%;table-layout:fixed;"><tbody><tr>'
            .'<td class="rt-brand-hotline-inset" width="40"'.$cell.'>'.self::image('fill', '40', '', $baseUrl).'</td>'
            .'<td width="152"'.$cell.'>'.self::image('label', '152', '24/7 erreichbar', $baseUrl).'</td>'
            .'<td width="42"'.$cell.'>'.$mailOpen.self::image('mail', '42', 'E-Mail an die Hotline', $baseUrl).$mailClose.'</td>'
            .'<td width="42"'.$cell.'>'.$phoneOpen.self::image('phone', '42', 'Hotline anrufen', $baseUrl).$phoneClose.'</td>'
            .'<td class="rt-brand-hotline-fill"'.$cell.'>'.self::image('fill', '100%', '', $baseUrl).'</td>'
            .'</tr></tbody></table>';
    }

    private static function image(string $kind, string $width, string $alt, string $baseUrl): string
    {
        return '<img src="'.htmlspecialchars($baseUrl.self::asset($kind), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" width="'.$width.'" height="34" align="top" alt="'.$alt.'" border="0" style="display:block;">';
    }

    private static function asset(string $kind): string
    {
        return '/mail-assets/outlook-hotline-'.$kind.'-v1.png';
    }

    /** Keep every original anchor attribute, including its encoded href/ARIA. */
    private static function action(string $html, array $nodes, int $cell, string $scheme): array
    {
        $anchor = self::singleChild($html, $nodes, $cell, 'a');
        $image = self::singleChild($html, $nodes, $anchor, 'img');
        $href = $nodes[$anchor]['attrs']['href'] ?? '';
        if (! str_starts_with(strtolower($href), $scheme) || strlen($href) <= strlen($scheme)
            || preg_match('/[\x00-\x20<>]/', $href) || trim($nodes[$image]['attrs']['src'] ?? '') === '') {
            self::fail();
        }

        return [$nodes[$anchor]['opening'], substr($html, $nodes[$anchor]['close'], $nodes[$anchor]['end'] - $nodes[$anchor]['close'])];
    }

    private static function outerTable(string $html, array $nodes, int $cell, int $artifact): int
    {
        $row = $nodes[$cell]['parent'];
        if ($row === null || $nodes[$row]['tag'] !== 'tr') {
            self::fail();
        }
        self::children($html, $nodes, $row, [$cell]);
        $table = $nodes[$row]['parent'];
        if ($table !== null && $nodes[$table]['tag'] === 'tbody') {
            self::children($html, $nodes, $table, [$row]);
            $row = $table;
            $table = $nodes[$table]['parent'];
        }
        if ($table === null || $nodes[$table]['tag'] !== 'table') {
            self::fail();
        }
        self::children($html, $nodes, $table, [$row]);
        $parent = $nodes[$table]['parent'];
        if ($parent === null || $nodes[$parent]['tag'] !== 'td' || ! self::has($nodes[$parent], 'rt-sign-cell')
            || ! self::within($nodes, $parent, $artifact)) {
            self::fail();
        }

        return $table;
    }

    private static function roleEdits(array $nodes, int $artifact): array
    {
        $roles = array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, 'rt-personal-role') || self::has($node, 'rt-sign-role')));
        if (count($roles) > 1) {
            self::fail();
        }
        $edits = [];
        foreach ($roles as $role) {
            $person = $nodes[$role]['parent'];
            while ($person !== null && ! self::has($nodes[$person], 'rt-person-kopf')) {
                $person = $nodes[$person]['parent'];
            }
            if ($nodes[$role]['tag'] !== 'p' || ! self::within($nodes, $role, $artifact) || $person === null) {
                self::fail();
            }
            foreach ($nodes as $index => $node) {
                if ($node['tag'] !== 'span' || ! self::within($nodes, $index, $role) || self::direct($nodes, $index) !== []) {
                    continue;
                }
                if ((isset($node['attrs']['bgcolor']) && ! self::white($node['attrs']['bgcolor']))
                    || self::ambiguousBackground($node['attrs']['style'] ?? '')) {
                    continue;
                }
                $changes = [];
                $removedWhite = false;
                if (isset($node['attrs']['bgcolor']) && self::white($node['attrs']['bgcolor'])) {
                    $changes['bgcolor'] = null;
                    $removedWhite = true;
                }
                $style = $node['attrs']['style'] ?? '';
                if (isset($node['attrs']['style'])) {
                    $style = self::withoutWhiteBackground($node['attrs']['style']);
                    if ($style !== $node['attrs']['style']) {
                        $removedWhite = true;
                    }
                }
                if ($removedWhite) {
                    // The authored sheet may otherwise restore its old white
                    // highlight after this narrow output-only cleanup. This
                    // own leaf value also receives its own compiler mirror.
                    $style = rtrim($style, "; \t\r\n");
                    $changes['style'] = ($style === '' ? '' : $style.';').'background-color:transparent!important;';
                }
                if ($changes !== []) {
                    $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, $changes)];
                }
            }
        }

        return $edits;
    }

    private static function white(string $value): bool
    {
        return preg_match('/\A(?:#fff(?:fff)?|white|rgb\(\s*255\s*,\s*255\s*,\s*255\s*\)|rgba\(\s*255\s*,\s*255\s*,\s*255\s*,\s*1(?:\.0+)?\s*\))\z/i', trim(preg_replace('/\s*!important\s*\z/i', '', $value))) === 1;
    }

    private static function withoutWhiteBackground(string $style): string
    {
        $parts = [];
        foreach (self::styleParts($style) as $part) {
            if (! preg_match('/\A\s*background(?:-color)?\s*:\s*(.*?)\s*;?\z/is', $part, $match) || ! self::white(rtrim($match[1], ';'))) {
                $parts[] = $part;
            }
        }

        return implode('', $parts);
    }

    /** Do not infer paint precedence from authored fallbacks or image rules. */
    private static function ambiguousBackground(string $style): bool
    {
        $backgrounds = 0;
        foreach (self::styleParts($style) as $part) {
            if (! preg_match('/\A\s*(background(?:-[a-z-]+)?)\s*:\s*(.*?)\s*;?\z/is', $part, $match)) {
                continue;
            }
            if (! in_array(strtolower($match[1]), ['background', 'background-color'], true)
                || ++$backgrounds > 1 || ! self::white(rtrim($match[2], ';'))) {
                return true;
            }
        }

        return false;
    }

    /** Preserve every byte while recognizing only unquoted CSS separators. */
    private static function styleParts(string $style): array
    {
        $parts = [];
        $start = 0;
        $quote = null;
        $depth = 0;
        for ($offset = 0; $offset <= strlen($style); $offset++) {
            $char = $style[$offset] ?? ';';
            if ($char === '\\') {
                $offset++;
            } elseif ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ';' && $depth === 0) {
                $length = min(strlen($style) - $start, $offset - $start + 1);
                $parts[] = substr($style, $start, $length);
                $start = $offset + 1;
            }
        }
        if ($quote !== null || $depth !== 0) {
            self::fail();
        }

        return $parts;
    }

    private static function opening(array $node, array $changes): string
    {
        $edits = [];
        $added = '';
        foreach ($changes as $name => $value) {
            $attribute = $value === null ? '' : ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            if (isset($node['ranges'][$name])) {
                [$offset, $length] = $node['ranges'][$name];
                $edits[] = [$offset, $length, $attribute];
            } else {
                $added .= $attribute;
            }
        }
        $opening = self::replace($node['opening'], $edits);

        return substr($opening, 0, -1).$added.'>';
    }

    private static function singleChild(string $html, array $nodes, int $parent, string $tag): int
    {
        $children = self::direct($nodes, $parent);
        if (count($children) !== 1 || $nodes[$children[0]]['tag'] !== $tag) {
            self::fail();
        }
        self::children($html, $nodes, $parent, $children);

        return $children[0];
    }

    private static function singleRow(string $html, array $nodes, int $table): int
    {
        $children = self::direct($nodes, $table);
        if (count($children) !== 1) {
            self::fail();
        }
        if ($nodes[$children[0]]['tag'] === 'tbody') {
            $tbody = $children[0];
            self::children($html, $nodes, $table, [$tbody]);

            return self::singleChild($html, $nodes, $tbody, 'tr');
        }

        return self::singleChild($html, $nodes, $table, 'tr');
    }

    private static function plainText(string $html, array $nodes, int $node, string $expected): void
    {
        $text = preg_replace('~<!--.*?-->|<style\b[^>]*>.*?</style\s*>~is', '', self::inner($html, $nodes[$node]));
        $text = trim(str_replace("\xc2\xa0", ' ', CssSemantic::decodeHtmlEntitiesOnce($text)));
        if (self::direct($nodes, $node) !== [] || $text !== $expected) {
            self::fail();
        }
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

    private static function classes(array $node): array
    {
        return preg_split('/\s+/', trim($node['attrs']['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    }

    private static function has(array $node, string $class): bool
    {
        return in_array($class, self::classes($node), true);
    }

    private static function direct(array $nodes, int $parent): array
    {
        return array_keys(array_filter($nodes, static fn (array $node): bool => $node['parent'] === $parent));
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

    private static function inner(string $html, array $node): string
    {
        return substr($html, $node['openEnd'], $node['close'] - $node['openEnd']);
    }

    private static function replace(string $html, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $last = strlen($html) + 1;
        foreach ($edits as [$offset, $length, $replacement]) {
            if ($offset < 0 || $offset + $length > $last) {
                self::fail();
            }
            $html = substr_replace($html, $replacement, $offset, $length);
            $last = $offset;
        }

        return $html;
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
            $ranges = [];
            $position = 0;
            while ($position < strlen($attributes)) {
                if (preg_match('~\G\s*/?\s*\z~', $attributes, $space, 0, $position)) {
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
                $ranges[$key] = [strlen($name[0]) + $position, strlen($attribute[0])];
                $position += strlen($attribute[0]);
            }
            $void = in_array($tag, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true);
            if (! $void && preg_match('~/\s*>\z~', $token)) {
                self::fail();
            }
            $nodes[] = ['tag' => $tag, 'attrs' => $attrs, 'ranges' => $ranges, 'opening' => $token,
                'parent' => $stack === [] ? null : $stack[array_key_last($stack)], 'start' => $offset,
                'openEnd' => $cursor, 'close' => $void ? $cursor : null, 'end' => $void ? $cursor : null];
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
        throw new RuntimeException('Die Outlook-Markenleiste besitzt keinen eindeutigen V27-Inhaltsvertrag.');
    }
}
