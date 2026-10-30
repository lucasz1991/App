<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Keep the two portable flow rows in the existing percentage-width content frame. */
final class OutlookContentFramePresentation
{
    public const FLOW = 'outlook-desktop-flow-v12';

    public const REVISION = 'outlook-content-frame-presentation-v1';

    private const TOKENS = '~<!doctype\b[^>]*>|<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    public static function payload(array $payload): array
    {
        foreach (['signature', 'template'] as $key) {
            if (isset($payload[$key]['html'])) {
                $payload[$key]['html'] = self::bounded($payload[$key]['html'], $key === 'signature' ? 30000 : 99000);
            }
        }
        foreach ($payload['templates'] ?? [] as $index => $entry) {
            foreach (['html', 'composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                if (isset($entry[$field])) {
                    $entry[$field] = self::bounded($entry[$field], $field === 'mobileComposeHtml' ? 30000 : 99000);
                }
            }
            if (isset($entry['signature']['html'])) {
                $entry['signature']['html'] = self::bounded($entry['signature']['html'], 30000);
            }
            $payload['templates'][$index] = $entry;
        }

        return $payload;
    }

    private static function bounded(string $html, int $limit): string
    {
        $result = self::document($html);
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $result, $styles);
        if (intdiv(strlen(mb_convert_encoding($result, 'UTF-16LE', 'UTF-8')), 2) > $limit
            || strlen(implode('', $styles[1])) > ($limit === 30000 ? 12288 : 24576)) {
            self::fail();
        }

        return $result;
    }

    public static function document(string $html): string
    {
        preg_match_all('~<style\b[^>]*data-rt-mail-document-css=(["\'])signature\1[^>]*>(.*?)</style\s*>~is', $html, $authored);
        if (array_filter($authored[2], static fn (string $css): bool => trim($css) !== '') !== []) {
            return $html;
        }
        if (! str_contains($html, 'data-rt-desktop-flow="'.OutlookFluidSignaturePresentation::FLOW.'"')
            && ! str_contains($html, 'data-rt-desktop-flow="'.self::FLOW.'"')) {
            return $html;
        }
        $nodes = self::nodes($html);
        $roots = array_keys(array_filter($nodes, static fn (array $node): bool => in_array($node['attrs']['data-rt-desktop-flow'] ?? '', [self::FLOW, OutlookFluidSignaturePresentation::FLOW], true)));
        if (count($roots) !== 1) {
            self::fail();
        }
        $rootIndex = $roots[0];
        $root = $nodes[$rootIndex];
        if (($root['attrs']['data-rt-desktop-flow'] ?? '') === self::FLOW) {
            self::assertCurrent($html, $nodes, $rootIndex);

            return $html;
        }
        // The historical projector proves ownership, exact leaves, gutters and media.
        OutlookFluidSignaturePresentation::document($html);
        if (! preg_match('/(?:^|\s)rt-df11([a-f0-9]{10})(?:\s|$)/', $root['attrs']['class'] ?? '', $scope)) {
            self::fail();
        }
        $cell = $root['parent'];
        $row = $cell === null ? null : $nodes[$cell]['parent'];
        $body = $row === null ? null : $nodes[$row]['parent'];
        $frame = $body === null ? null : $nodes[$body]['parent'];
        if ($frame === null || $nodes[$cell]['tag'] !== 'td'
            || $nodes[$row]['tag'] !== 'tr' || $nodes[$body]['tag'] !== 'tbody'
            || $nodes[$frame]['tag'] !== 'table' || ($nodes[$frame]['attrs']['data-rt-train-delivery'] ?? '') !== 'bounded-img-v2'
            || ($nodes[$frame]['attrs']['role'] ?? '') !== 'presentation'
            || ($nodes[$cell]['attrs']['width'] ?? '') !== '100%' || ($nodes[$frame]['attrs']['width'] ?? '') !== '100%') {
            self::fail();
        }
        self::children($html, $nodes, $cell, [$rootIndex]);
        self::children($html, $nodes, $row, [$cell]);
        self::children($html, $nodes, $body, [$row]);
        self::children($html, $nodes, $frame, [$body]);
        $flowBody = self::only($html, $nodes, $rootIndex, 'tbody');
        $flowRows = self::direct($nodes, $flowBody);
        if (count($flowRows) !== 2) {
            self::fail();
        }
        $main = self::only($html, $nodes, $flowRows[0], 'td');
        $rowsHtml = self::inner($html, $nodes[$flowBody]);
        $rowsHtml = substr_replace($rowsHtml, self::opening($nodes[$main], ['width' => '100%']), $nodes[$main]['start'] - $nodes[$flowBody]['openEnd'], $nodes[$main]['openEnd'] - $nodes[$main]['start']);
        $classes = array_values(array_unique(array_merge(['rt-sign-content-frame'], self::classes($nodes[$frame]), self::classes($root))));
        $changes = ['class' => implode(' ', $classes), 'data-rt-desktop-flow' => self::FLOW];
        foreach (['data-rt-system-flow', 'data-rt-personal-flow', 'data-rt-personal-columns'] as $attribute) {
            if (isset($root['attrs'][$attribute])) {
                $changes[$attribute] = $root['attrs'][$attribute];
            }
        }
        $replacement = self::opening($nodes[$frame], $changes).'<tbody>'.$rowsHtml.'</tbody></table>';
        $html = substr_replace($html, $replacement, $nodes[$frame]['start'], $nodes[$frame]['end'] - $nodes[$frame]['start']);
        $oldScope = 'rt-df11'.$scope[1];
        $newScope = 'rt-df12'.$scope[1];
        $html = preg_replace_callback(self::TOKENS, static function (array $match) use ($oldScope, $newScope): string {
            $token = $match[0];
            if (preg_match('~\A<style\b~i', $token)) {
                return str_replace([$oldScope, '.rt-v11-'], [$newScope, '.rt-v12-'], $token);
            }
            if (! preg_match('~\A<[a-z]~i', $token)) {
                return $token;
            }

            return preg_replace_callback('~\bclass=("|\')([^"\']*)\1~i', static fn (array $class): string => 'class='.$class[1].str_replace([$oldScope, 'rt-v11-'], [$newScope, 'rt-v12-'], $class[2]).$class[1], $token);
        }, $html);
        $html = self::framePaint($html);
        $nodes = self::nodes($html);
        $roots = array_keys(array_filter($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-desktop-flow'] ?? '') === self::FLOW));
        if (count($roots) !== 1) {
            self::fail();
        }
        self::assertCurrent($html, $nodes, $roots[0]);

        return $html;
    }

    /** Word retained the plain top rule but discarded the historical important left rules. */
    private static function framePaint(string $html): string
    {
        $nodes = self::nodes($html);
        $edits = [];
        foreach ($nodes as $index => $node) {
            if ($node['tag'] !== 'table' || (! self::has($node, 'rt-managed-combined-v1')
                && (! self::has($node, 'rt-managed-signature-v1') || self::has($node, 'rt-combined-signature-frame')))) {
                continue;
            }
            $body = self::only($html, $nodes, $index, 'tbody');
            foreach (self::direct($nodes, $body) as $row) {
                $cell = $nodes[self::only($html, $nodes, $row, 'td')];
                $style = str_replace('border-left:3px solid #e90032!important;', 'border-left:3px solid #e90032;', $cell['attrs']['style'] ?? '', $changed);
                if ($changed !== 1) {
                    self::fail();
                }
                $edits[] = [$cell['start'], $cell['openEnd'] - $cell['start'], self::opening($cell, ['style' => $style])];
            }
        }
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$offset, $length, $value]) {
            $html = substr_replace($html, $value, $offset, $length);
        }

        return $html;
    }

    private static function assertCurrent(string $html, array $nodes, int $root): void
    {
        $node = $nodes[$root];
        if ($node['tag'] !== 'table' || ! self::has($node, 'rt-sign-content-frame') || ! self::has($node, 'rt-v12-columns')
            || ($node['attrs']['width'] ?? '') !== '100%' || ! preg_match('/(?:^|;)\s*width\s*:\s*100%\s*;/', $node['attrs']['style'] ?? '')
            || preg_match('/(?:^|;)\s*max-width\s*:/i', $node['attrs']['style'] ?? '')) {
            self::fail();
        }
        $body = self::only($html, $nodes, $root, 'tbody');
        $rows = self::direct($nodes, $body);
        if (count($rows) !== 2 || $nodes[$rows[0]]['tag'] !== 'tr' || $nodes[$rows[1]]['tag'] !== 'tr') {
            self::fail();
        }
        self::children($html, $nodes, $body, $rows);
        $main = self::only($html, $nodes, $rows[0], 'td');
        if (! self::has($nodes[$main], 'rt-v12-columns-cell') || ($nodes[$main]['attrs']['width'] ?? '') !== '100%'
            || ($nodes[$main]['attrs']['style'] ?? '') !== 'padding:10px 0 0 18px;font-size:0;line-height:0;vertical-align:top;') {
            self::fail();
        }
        $system = self::has($node, 'rt-system-columns');
        $widths = $system ? [176, 172, 164] : [176, 204, 172, 164];
        $children = self::direct($nodes, $main);
        if (count($children) !== count($widths) + 1 || $nodes[$children[count($widths)]]['tag'] !== 'br'
            || ($nodes[$children[count($widths)]]['attrs']['clear'] ?? '') !== 'all') {
            self::fail();
        }
        self::children($html, $nodes, $main, $children);
        foreach ($widths as $position => $width) {
            $column = $nodes[$children[$position]];
            if ($column['tag'] !== 'table' || ! self::has($column, 'rt-v12-column')
                || ($column['attrs']['width'] ?? '') !== (string) $width || ($column['attrs']['align'] ?? '') !== 'left') {
                self::fail();
            }
        }
        $trainHost = self::only($html, $nodes, $rows[1], 'td');
        $train = self::only($html, $nodes, $trainHost, 'table');
        if (! self::has($nodes[$train], 'rt-v12-train') || ($nodes[$train]['attrs']['width'] ?? '') !== '218') {
            self::fail();
        }
        $images = array_filter($nodes, static fn (array $entry): bool => $entry['tag'] === 'img' && self::has($entry, 'rt-v12-train-image'));
        if (count($images) !== 1 || ($image = reset($images))['attrs']['width'] !== '200' || ($image['attrs']['height'] ?? '') !== '43') {
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

    private static function only(string $html, array $nodes, int $parent, string $tag): int
    {
        $children = self::direct($nodes, $parent);
        if (count($children) !== 1 || $nodes[$children[0]]['tag'] !== $tag) {
            self::fail();
        }
        self::children($html, $nodes, $parent, $children);

        return $children[0];
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

    private static function trivia(string $value): void
    {
        if (trim(preg_replace('~<!--.*?-->|<style\b[^>]*>.*?</style\s*>~is', '', $value)) !== '') {
            self::fail();
        }
    }

    private static function inner(string $html, array $node): string
    {
        return substr($html, $node['openEnd'], $node['close'] - $node['openEnd']);
    }

    private static function opening(array $node, array $changes): string
    {
        $tag = $node['opening'];
        $edits = [];
        $new = '';
        foreach ($changes as $key => $value) {
            $attribute = $value === null ? '' : ' '.$key.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            if (isset($node['ranges'][$key])) {
                [$offset, $length] = $node['ranges'][$key];
                $edits[] = [$offset, $length, $attribute];
            } else {
                $new .= $attribute;
            }
        }
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$offset, $length, $replacement]) {
            $tag = substr_replace($tag, $replacement, $offset, $length);
        }

        return substr($tag, 0, -1).$new.'>';
    }

    private static function nodes(string $html): array
    {
        if (strlen($html) > 1000000) {
            self::fail();
        }
        preg_match_all(self::TOKENS, $html, $tokens, PREG_OFFSET_CAPTURE);
        $nodes = $stack = [];
        $cursor = 0;
        foreach ($tokens[0] as [$token, $offset]) {
            if (str_contains(substr($html, $cursor, $offset - $cursor), '<')) {
                self::fail();
            }
            $cursor = $offset + strlen($token);
            if (str_starts_with($token, '<!--') || preg_match('~\A<(?:style\b|!doctype\b)~i', $token)) {
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
            $attrs = $ranges = [];
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
        throw new RuntimeException('Die Outlook-Inhaltstabelle besitzt keinen eindeutigen Ausgabe-Vertrag.');
    }
}
