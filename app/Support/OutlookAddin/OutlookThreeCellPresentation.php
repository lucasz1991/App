<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Output-only native three-cell grid; the historical V9 projection stays immutable. */
final class OutlookThreeCellPresentation
{
    public const FLOW = 'outlook-desktop-flow-v10';

    public const REVISION = 'outlook-three-cell-presentation-v1';

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
        $before = $html;
        $html = self::document($html);
        if ($html === $before) {
            return $html;
        }
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        if (intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) > $limit
            || strlen(implode('', $styles[1])) > ($limit === 30000 ? 12288 : 24576)) {
            self::fail();
        }

        return $html;
    }

    public static function document(string $html): string
    {
        if (! str_contains($html, 'data-rt-desktop-flow="'.OutlookThreeColumnPresentation::FLOW.'"')
            && ! str_contains($html, 'data-rt-desktop-flow="'.self::FLOW.'"')) {
            return $html;
        }
        preg_match_all('~<style\b[^>]*data-rt-mail-document-css=(["\'])signature\1[^>]*>(.*?)</style\s*>~is', $html, $authored);
        if (array_filter($authored[2], static fn (string $css): bool => trim($css) !== '') !== []) {
            return $html;
        }
        $nodes = self::nodes($html);
        $roots = array_keys(array_filter($nodes, static fn (array $node): bool => in_array($node['attrs']['data-rt-desktop-flow'] ?? '', [OutlookThreeColumnPresentation::FLOW, self::FLOW], true)));
        if (count($roots) !== 1) {
            self::fail();
        }
        $rootIndex = $roots[0];
        $root = $nodes[$rootIndex];
        if (($root['attrs']['data-rt-desktop-flow'] ?? '') === self::FLOW) {
            self::assertCurrent($html, $nodes, $rootIndex);

            return $html;
        }
        OutlookThreeColumnPresentation::document($html, self::has($root, 'rt-system-columns'));
        if (! preg_match('/(?:^|\s)rt-df9([a-f0-9]{10})(?:\s|$)/', $root['attrs']['class'] ?? '', $scopeMatch)) {
            self::fail();
        }
        $oldScope = 'rt-df9'.$scopeMatch[1];
        $scope = 'rt-df10'.$scopeMatch[1];
        $body = self::singleChild($html, $nodes, $rootIndex, 'tbody');
        $rows = self::direct($nodes, $body);
        $oldCell = self::singleChild($html, $nodes, $rows[0], 'td');
        $groups = self::direct($nodes, $oldCell);
        $columns = '';
        foreach (['person', 'company', 'dispatch'] as $position => $name) {
            $column = $nodes[$groups[$position]];
            $classes = str_replace('rt-v9-column', 'rt-v10-column', $column['attrs']['class']);
            $opening = self::opening($column, ['class' => $classes, 'align' => null, 'style' => 'width:240px;max-width:100%;table-layout:fixed;border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;']);
            $content = $opening.substr($html, $column['openEnd'], $column['end'] - $column['openEnd']);
            if ($position === 2) {
                $content = self::dispatchLeaf($content);
            }
            $columns .= '<td class="rt-v10-grid-cell rt-v10-grid-'.$name.'" width="240" valign="top" style="display:inline-block;width:240px;max-width:100%;padding:0;border:0;box-sizing:border-box;vertical-align:top;">'.$content.'</td>';
        }
        $trainCell = self::singleChild($html, $nodes, $rows[1], 'td');
        $trainIndex = self::singleChild($html, $nodes, $trainCell, 'table');
        $train = $nodes[$trainIndex];
        $images = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'img' && self::has($node, 'rt-v9-train-image')));
        if (count($images) !== 1 || ! self::within($nodes, $images[0], $trainIndex)) {
            self::fail();
        }
        $image = $nodes[$images[0]];
        $trainHtml = substr($html, $train['start'], $train['end'] - $train['start']);
        $trainHtml = self::replace($trainHtml, [
            [0, $train['openEnd'] - $train['start'], self::opening($train, ['width' => '364', 'align' => null, 'style' => 'width:364px;max-width:100%;table-layout:fixed;border-collapse:collapse;'])],
            [$image['start'] - $train['start'], $image['openEnd'] - $image['start'], self::opening($image, ['class' => 'rt-delivery-train rt-v10-train-image', 'width' => '324', 'height' => '70', 'style' => 'display:block;width:100%;max-width:100%;height:auto;margin:0;border:0;vertical-align:bottom;'])],
        ]);
        $rootOpen = self::opening($root, ['class' => str_replace(['rt-v9-columns', $oldScope], ['rt-v10-columns', $scope], $root['attrs']['class']), 'data-rt-desktop-flow' => self::FLOW, 'width' => '720', 'style' => 'width:720px;max-width:100%;table-layout:fixed;border-collapse:collapse;']);
        $flow = $rootOpen.'<tbody><tr class="rt-v10-grid-row" style="display:block;padding:0;font-size:0;line-height:0;">'.$columns.'</tr><tr class="rt-v10-train-row" style="display:block;"><td colspan="3" style="display:block;width:100%;padding:0;font-size:0;line-height:0;vertical-align:bottom;">'.$trainHtml.'</td></tr></tbody></table>';
        $html = substr_replace($html, $flow, $root['start'], $root['end'] - $root['start']);
        $html = preg_replace('~<style\b(?=[^>]*data-rt-three-column-css="1")[^>]*>.*?</style\s*>~is', '', $html, 1, $removed);
        if ($removed !== 1) {
            self::fail();
        }
        $html = self::legalLeaves($html);
        $html = str_replace($oldScope, $scope, $html);
        $css = '.'.$scope.'.rt-v10-columns{width:720px!important;max-width:100%!important;direction:ltr!important;text-align:left!important;}'
            .'.'.$scope.' .rt-v10-column{width:240px!important;max-width:100%!important;float:none!important;border:0!important;}'
            .'.'.$scope.' .rt-v9-cell{padding:0 40px!important;}'
            .'.'.$scope.' .rt-df-train{width:364px!important;max-width:100%!important;border:0!important;}'
            .'.'.$scope.' .rt-personal-logo-frame{width:160px!important;max-width:100%!important;border:0!important;background-color:transparent!important;}'
            .'.'.$scope.' .rt-v9-address{word-break:normal!important;overflow-wrap:anywhere!important;}'
            .'@media(max-width:600px){.'.$scope.' .rt-v10-grid-cell,.'.$scope.' .rt-v10-column{width:100%!important;max-width:100%!important;}.'.$scope.' .rt-v9-cell,.'.$scope.' .rt-v9-legal-cell{padding-left:18px!important;padding-right:18px!important;}.'.$scope.' .rt-v9-train-cell{padding-right:18px!important;}}';
        $style = '<style data-rt-three-cell-css="1">'.$css.'</style>';
        if (self::has($root, 'rt-system-columns')) {
            $html = preg_replace('~</head\s*>~i', $style.'$0', $html, 1, $inserted);
            if ($inserted !== 1) {
                self::fail();
            }
        } else {
            $nodes = self::nodes($html);
            $lines = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'td' && self::has($node, 'rt-v9-top-rule')));
            if (count($lines) !== 1) {
                self::fail();
            }
            $html = substr_replace($html, $style, $nodes[$lines[0]]['openEnd'], 0);
        }
        $nodes = self::nodes($html);
        $newRoots = array_keys(array_filter($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-desktop-flow'] ?? '') === self::FLOW));
        if (count($newRoots) !== 1) {
            self::fail();
        }
        self::assertCurrent($html, $nodes, $newRoots[0]);

        return $html;
    }

    /** Word imports the phone anchor into a fresh character run without its inherited paint. */
    private static function dispatchLeaf(string $html): string
    {
        $nodes = self::nodes($html);
        $phones = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'a' && str_starts_with($node['attrs']['href'] ?? '', 'tel:')));
        $edits = [];
        foreach ($phones as $index) {
            $node = $nodes[$index];
            $content = self::inner($html, $node);
            if (self::direct($nodes, $index) !== [] || ! preg_match('/\A\s*\+?[0-9][0-9 ()-]*\s*\z/', CssSemantic::decodeHtmlEntitiesOnce($content))) {
                continue;
            }
            $edits[] = [$node['openEnd'], $node['close'] - $node['openEnd'], '<span style="font-size:13px;line-height:16px;color:#e90032;">'.$content.'</span>'];
        }
        if (count($edits) !== 1) {
            self::fail();
        }

        return self::replace($html, $edits);
    }

    /** Put legal typography on every owned text run, rather than relying on TD inheritance. */
    private static function legalLeaves(string $html): string
    {
        $nodes = self::nodes($html);
        $cells = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'td' && self::has($node, 'rt-v9-legal-cell')));
        if (count($cells) !== 1) {
            self::fail();
        }
        $cell = $nodes[$cells[0]];
        $content = self::inner($html, $cell);
        preg_match_all(self::TOKENS, $content, $tokens, PREG_OFFSET_CAPTURE);
        $cursor = 0;
        $edits = [];
        $style = 'font-size:9px;line-height:14px;color:#718096;';
        foreach (array_merge($tokens[0], [['', strlen($content)]]) as [$token, $offset]) {
            $text = substr($content, $cursor, $offset - $cursor);
            if (trim(str_replace("\xc2\xa0", ' ', CssSemantic::decodeHtmlEntitiesOnce($text))) !== '') {
                $edits[] = [$cursor, strlen($text), '<span style="'.$style.'">'.$text.'</span>'];
            }
            $cursor = $offset + strlen($token);
        }
        if ($edits === []) {
            self::fail();
        }
        $content = '<div style="margin:0;line-height:14px;">'.self::replace($content, $edits).'</div>';

        return substr_replace($html, $content, $cell['openEnd'], $cell['close'] - $cell['openEnd']);
    }

    private static function assertCurrent(string $html, array $nodes, int $root): void
    {
        if ($nodes[$root]['tag'] !== 'table' || ! self::has($nodes[$root], 'rt-v10-columns') || ($nodes[$root]['attrs']['width'] ?? '') !== '720') {
            self::fail();
        }
        $body = self::singleChild($html, $nodes, $root, 'tbody');
        $rows = self::direct($nodes, $body);
        if (count($rows) !== 2 || ! self::has($nodes[$rows[0]], 'rt-v10-grid-row') || ! self::has($nodes[$rows[1]], 'rt-v10-train-row')) {
            self::fail();
        }
        $cells = self::rowCells($html, $nodes, $rows[0], 3);
        foreach (['rt-personal-left', 'rt-personal-right', 'rt-dispatch-column'] as $position => $class) {
            $cell = $nodes[$cells[$position]];
            if (! self::has($cell, 'rt-v10-grid-cell') || ($cell['attrs']['width'] ?? '') !== '240') {
                self::fail();
            }
            $column = $nodes[self::singleChild($html, $nodes, $cells[$position], 'table')];
            if (! self::has($column, $class) || ! self::has($column, 'rt-v10-column') || ($column['attrs']['width'] ?? '') !== '240' || isset($column['attrs']['align']) || preg_match('/(?:^|;)\s*float\s*:/i', $column['attrs']['style'] ?? '')) {
                self::fail();
            }
        }
        $trainCell = self::singleChild($html, $nodes, $rows[1], 'td');
        $train = self::singleChild($html, $nodes, $trainCell, 'table');
        if (($nodes[$trainCell]['attrs']['colspan'] ?? '') !== '3' || ($nodes[$train]['attrs']['width'] ?? '') !== '364' || isset($nodes[$train]['attrs']['align'])) {
            self::fail();
        }
        $images = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'img' && self::has($node, 'rt-v10-train-image')));
        if (count($images) !== 1 || ($nodes[$images[0]]['attrs']['width'] ?? '') !== '324' || ($nodes[$images[0]]['attrs']['height'] ?? '') !== '70' || ! self::within($nodes, $images[0], $train)) {
            self::fail();
        }
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

    private static function rowCells(string $html, array $nodes, int $row, int $count): array
    {
        $columns = self::direct($nodes, $row);
        if (count($columns) !== $count || array_filter($columns, static fn (int $index): bool => $nodes[$index]['tag'] !== 'td')) {
            self::fail();
        }
        self::children($html, $nodes, $row, $columns);

        return $columns;
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
        throw new RuntimeException('Die dreizellige Outlook-Signatur besitzt keinen eindeutigen Ausgabe-Vertrag.');
    }
}
