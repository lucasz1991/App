<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Deterministic physical-mobile source; never infers a recipient viewport. */
final class OutlookMobileReferencePresentation
{
    public const REVISION = 'outlook-mobile-reference-v1';

    public const MARKER = 'data-rt-mobile-reference';

    private const CLASS_NAME = 'rt-mobile-reference-v1';

    private const ROW_OPEN = '<tr><td style="padding:0;">';

    private const ROW_CLOSE = '</td></tr>';

    private const BAR_OPEN = '<table data-rt-mobile-reference-bar="v1" class="rt-mobile-reference-bar" role="presentation" width="100%" cellspacing="0" cellpadding="0" style="table-layout:auto!important;"><tbody><tr><td width="80" valign="middle" style="padding-right:10px;">';

    private const BAR_MIDDLE = '</td><td valign="middle" style="padding:0;">';

    private const BAR_CLOSE = '</td></tr></tbody></table>';

    private const OLD_CAPTION = '/m/4a55285090.png';

    private const NEW_CAPTION = '/m/fc43cf451b.png';

    private const TOKENS = '~<!doctype\b[^>]*>|<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    public static function document(string $html): string
    {
        if (! str_contains($html, 'outlook-desktop-flow-v17') && ! str_contains($html, self::MARKER)) {
            return $html;
        }
        $nodes = self::nodes($html);
        $marked = array_keys(array_filter($nodes, static fn (array $n): bool => isset($n['attrs'][self::MARKER])));
        if ($marked !== []) {
            $source = self::canonicalize($html);
            if (self::project($source, true) !== $html) {
                self::fail();
            }

            return $html;
        }
        $root = self::root($nodes);
        if ($root === null || ! self::mobile($nodes, $root)) {
            return $html;
        }
        // Reuse the exact existing source/material inverse before claiming ownership.
        OutlookCompactReferencePresentation::canonicalizeToV16($html);
        $projected = self::project($html, true);
        if (self::project($projected, false) !== $html) {
            self::fail();
        }

        return $projected;
    }

    /** Restores exact numeric V17 bytes before the existing V17/V16 inverses. */
    public static function canonicalize(string $html): string
    {
        if (! str_contains($html, self::MARKER)) {
            return $html;
        }
        $nodes = self::nodes($html);
        if (array_filter($nodes, static fn (array $n): bool => isset($n['attrs'][self::MARKER])) === []) {
            return $html;
        }
        $source = self::project($html, false);
        OutlookCompactReferencePresentation::canonicalizeToV16($source);
        if (self::project($source, true) !== $html) {
            self::fail();
        }

        return $source;
    }

    private static function root(array $nodes): ?int
    {
        $roots = array_keys(array_filter($nodes, static fn (array $n): bool => ($n['attrs']['data-rt-desktop-flow'] ?? '') === 'outlook-desktop-flow-v17'));
        if ($roots === []) {
            return null;
        }
        if (count($roots) !== 1 || $nodes[$roots[0]]['tag'] !== 'table' || ! self::has($nodes[$roots[0]], 'rt-v17-columns')) {
            self::fail();
        }

        return $roots[0];
    }

    private static function mobile(array $nodes, int $root): bool
    {
        $native = false;
        for ($i = $nodes[$root]['parent']; is_int($i); $i = $nodes[$i]['parent']) {
            if ($nodes[$i]['tag'] === 'blockquote') {
                self::fail();
            }
            if (($nodes[$i]['attrs']['data-rt-compose-document'] ?? '') === 'combined-native-v1') {
                $native = true;
            }
        }
        for ($i = $nodes[$root]['parent']; is_int($i); $i = $nodes[$i]['parent']) {
            if ($nodes[$i]['tag'] !== 'div') {
                continue;
            }
            if (self::has($nodes[$i], 'rt-mobile-ledger')) {
                return true;
            }
            if ($native && self::has($nodes[$i], 'xz') && self::has($nodes[$i], 'x10')) {
                return true;
            }
        }

        return false;
    }

    private static function direct(array $nodes, int $parent): array
    {
        return array_keys(array_filter($nodes, static fn (array $n): bool => $n['parent'] === $parent));
    }

    private static function only(array $nodes, int $parent, string $tag): int
    {
        $children = self::direct($nodes, $parent);
        if (count($children) !== 1 || $nodes[$children[0]]['tag'] !== $tag) {
            self::fail();
        }

        return $children[0];
    }

    private static function project(string $html, bool $forward): string
    {
        $nodes = self::nodes($html);
        $root = self::root($nodes);
        if ($root === null || ! self::mobile($nodes, $root) || self::has($nodes[$root], 'rt-system-columns')) {
            self::fail();
        }
        $marks = array_keys(array_filter($nodes, static fn (array $n): bool => isset($n['attrs'][self::MARKER])));
        $class = $nodes[$root]['attrs']['class'] ?? '';
        if ($forward ? ($marks !== [] || self::has($nodes[$root], self::CLASS_NAME)) : ($marks !== [$root] || ($nodes[$root]['attrs'][self::MARKER] ?? '') !== 'v1' || ! str_ends_with($class, ' '.self::CLASS_NAME))) {
            self::fail();
        }
        $body = self::only($nodes, $root, 'tbody');
        $rows = self::direct($nodes, $body);
        if (count($rows) !== ($forward ? 2 : 3) || array_filter($rows, static fn (int $i): bool => $nodes[$i]['tag'] !== 'tr')) {
            self::fail();
        }
        $main = self::only($nodes, $rows[0], 'td');
        $dispatch = self::one($nodes, 'rt-v17-dispatch');
        if ($forward ? $nodes[$dispatch]['parent'] !== $main : $nodes[$dispatch]['parent'] !== self::only($nodes, $rows[2], 'td')) {
            self::fail();
        }
        $changes = [];
        $set = static function (int $i, array $attrs) use (&$changes): void {
            $changes[$i] = array_replace($changes[$i] ?? [], $attrs);
        };
        $style = static function (int $i) use (&$changes, $nodes): string {
            return $changes[$i]['style'] ?? $nodes[$i]['attrs']['style'] ?? '';
        };
        $prop = static function (int $i, string $name, string $old, string $new) use ($forward, $set, $style): void {
            $set($i, ['style' => self::property($style($i), $name, $forward ? $old : $new, $forward ? $new : $old)]);
        };
        $width = static function (int $i, string $old, string $new) use ($forward, $nodes, $set): void {
            if (($nodes[$i]['attrs']['width'] ?? '') !== ($forward ? $old : $new)) {
                self::fail();
            }
            $set($i, ['width' => $forward ? $new : $old]);
        };
        $suffix = static function (int $i, string $tail) use ($forward, $set, $style): void {
            $s = $style($i);
            if ($forward ? str_ends_with($s, $tail) : ! str_ends_with($s, $tail)) {
                self::fail();
            }
            $set($i, ['style' => $forward ? $s.$tail : substr($s, 0, -strlen($tail))]);
        };
        $set($root, [self::MARKER => $forward ? 'v1' : null, 'class' => $forward ? $class.' '.self::CLASS_NAME : substr($class, 0, -strlen(' '.self::CLASS_NAME))]);
        foreach (['identity' => ['220', '100%'], 'direct' => ['280', '52%'], 'company' => ['210', '48%'], 'dispatch' => ['247', '100%']] as $role => [$old,$new]) {
            $i = self::one($nodes, 'rt-v17-'.$role);
            if (! self::within($nodes, $i, $root)) {
                self::fail();
            }
            $width($i, $old, $new);
            $prop($i, 'width', $old.'px!important', $new.'!important');
        }
        $identityCell = self::one($nodes, 'rt-v17-identity-cell');
        if (($nodes[self::one($nodes, 'rt-v17-train-cell')]['attrs']['style'] ?? '') !== 'padding:0 18px 0 0;font-size:0;line-height:0;vertical-align:bottom;') {
            self::fail();
        }
        $direct = self::one($nodes, 'rt-v17-direct');
        $company = self::one($nodes, 'rt-v17-company');
        $prop($identityCell, 'padding', '0 24px 0 0', '0 0 10px');
        $prop(self::one($nodes, 'rt-v17-direct-cell'), 'padding', '0 24px 0 0', '0 6px 0 0');
        $prop(self::one($nodes, 'rt-v17-company-cell'), 'padding', '0 16px 0 0', '0 0 0 6px');
        $logo = self::one($nodes, 'rt-personal-logo-frame');
        $image = self::one($nodes, 'rt-v9-logo');
        $width($logo, '184', '104');
        $width($image, '184', '104');
        if (($nodes[$image]['attrs']['height'] ?? '') !== ($forward ? '32' : '18')) {
            self::fail();
        }
        $set($image, ['height' => $forward ? '18' : '32']);
        if ($forward ? isset($nodes[$logo]['attrs']['align']) : ($nodes[$logo]['attrs']['align'] ?? '') !== 'right') {
            self::fail();
        }
        $set($logo, ['align' => $forward ? 'right' : null]);
        $prop($logo, 'width', '184px!important', '104px!important');
        $prop($logo, 'float', 'none!important', 'right!important');
        $prop($logo, 'margin', '0!important', '0 0 0 12px!important');
        $prop(self::singleCell($nodes, $logo), 'padding', '0 0 13px', '0');
        foreach ($nodes as $i => $n) {
            if (! self::within($nodes, $i, $direct) && ! self::within($nodes, $i, $company)) {
                continue;
            }
            if ($n['tag'] === 'p') {
                if (self::has($n, 'rt-v9-address')) {
                    $prop($i, 'font-size', '13px', '12px');
                    $prop($i, 'line-height', '16px', '18px');
                } else {
                    $before = self::has($n, 'rt-delivery-contact-value')
                        ? 'word-break:normal;font-size:13px;line-height:19px;;line-height:19px!important;'
                        : 'word-break:break-all;font-size:13px!important;line-height:16px!important;;line-height:19px!important;';
                    $after = 'font-size:12px!important;line-height:18px!important;';
                    $needle = $forward ? $before : $after;
                    if (substr_count($style($i), $needle) !== 1) {
                        self::fail();
                    }
                    $set($i, ['style' => str_replace($needle, $forward ? $after : $before, $style($i))]);
                }
            }
            if ($n['tag'] === 'td' && self::has($n, 'rt-contact-text')) {
                $prop($i, 'line-height', '16px!important', '18px!important');
            }
            if ($n['tag'] === 'td' && self::has($n, 'rt-contact-icon')) {
                $width($i, '26', '22');
                $prop($i, 'width', '26px!important', '22px!important');
                $prop($i, 'min-width', '26px!important', '22px!important');
            }
        }
        $block = self::one($nodes, 'rt-v17-dispatch-block');
        $blockCell = self::one($nodes, 'rt-v17-dispatch-block-cell');
        foreach ([$block, $blockCell] as $i) {
            $prop($i, 'border-radius', '8px', '0');
        }
        $prop($blockCell, 'padding', '8px 16px 5px', '6px 12px 4px');
        $caption = self::one($nodes, 'rt-v17-dispatch-caption');
        $width($caption, '215', '80');
        if (($nodes[$caption]['attrs']['height'] ?? '') !== ($forward ? '50' : '18')) {
            self::fail();
        }
        $set($caption, ['height' => $forward ? '18' : '50']);
        if (($nodes[$caption]['attrs']['alt'] ?? '') !== ($forward ? 'Dispo 24/7 erreichbar' : 'Dispo 24/7')) {
            self::fail();
        }
        $set($caption, ['alt' => $forward ? 'Dispo 24/7' : 'Dispo 24/7 erreichbar']);
        $prop($caption, 'width', '215px', '80px');
        $url = $nodes[$caption]['attrs']['src'] ?? '';
        $from = $forward ? self::OLD_CAPTION : self::NEW_CAPTION;
        $to = $forward ? self::NEW_CAPTION : self::OLD_CAPTION;
        if (! str_ends_with($url, $from)) {
            self::fail();
        }
        $origin = substr($url, 0, -strlen($from));
        $u = parse_url($origin);
        if (! is_array($u) || ($u['scheme'] ?? '') !== 'https' || ! isset($u['host']) || (isset($u['user']) || isset($u['pass'])) || isset($u['query']) || isset($u['fragment']) || isset($u['path'])) {
            self::fail();
        }
        $set($caption, ['src' => $origin.$to]);
        $actions = self::one($nodes, 'rt-v17-dispatch-actions');
        $actionCells = self::direct($nodes, self::only($nodes, self::only($nodes, $actions, 'tbody'), 'tr'));
        if (count($actionCells) !== 3) {
            self::fail();
        }
        foreach ($actionCells as $position => $i) {
            $suffix($i, ';padding-top:4px!important;padding-bottom:4px!important;'.($position === 1 ? 'padding-right:6px!important;' : '').($position === 2 ? 'width:94px;white-space:nowrap!important;' : ''));
        }
        $width($actionCells[0], '100%', '46');
        $prop($actionCells[0], 'width', '100%', '46px');
        $width($actionCells[1], '32', '26');
        $prop($actionCells[1], 'width', '32px', '26px');
        if ($forward ? isset($nodes[$actionCells[2]]['attrs']['width']) : ($nodes[$actionCells[2]]['attrs']['width'] ?? '') !== '94') {
            self::fail();
        }
        $set($actionCells[2], ['width' => $forward ? '94' : null]);
        $suffix($actions, ';table-layout:auto!important;');
        $prop($actions, 'border-top', '1px solid #ffffff', '0');
        $wa = self::one($nodes, 'rt-v17-dispatch-whatsapp');
        $prop($wa, 'margin-left', '14px', '6px');
        foreach ($nodes as $i => $n) {
            if (! self::within($nodes, $i, $actions)) {
                continue;
            }
            if ($n['tag'] === 'img') {
                $width($i, '24', '20');
                if (($n['attrs']['height'] ?? '') !== ($forward ? '24' : '20')) {
                    self::fail();
                }
                $set($i, ['height' => $forward ? '20' : '24']);
                $prop($i, 'width', '24px', '20px');
                $prop($i, 'height', '24px', '20px');
            }
            if ($n['tag'] === 'span') {
                $prop($i, 'font-size', '13px', '12px');
                $prop($i, 'line-height', '24px', '20px');
            }
        }
        // Opening-tag edits preserve all text/media; only the existing D table changes rows.
        $edits = [];
        foreach ($changes as $i => $a) {
            $edits[] = [$nodes[$i]['start'], $nodes[$i]['openEnd'] - $nodes[$i]['start'], self::opening($nodes[$i], $a)];
        }
        $html = self::bar(self::replace($html, $edits), $forward);
        $nodes = self::nodes($html);
        $root = self::root($nodes);
        $body = self::only($nodes, $root, 'tbody');
        $rows = self::direct($nodes, $body);
        $main = self::only($nodes, $rows[0], 'td');
        $dispatch = self::one($nodes, 'rt-v17-dispatch');
        $clear = array_values(array_filter(self::direct($nodes, $main), static fn (int $i): bool => $nodes[$i]['tag'] === 'br' && strtolower($nodes[$i]['attrs']['clear'] ?? '') === 'all'));
        if (count($clear) !== 1) {
            self::fail();
        }
        $clear = $clear[0];
        $fragment = substr($html, $nodes[$dispatch]['start'], $nodes[$dispatch]['end'] - $nodes[$dispatch]['start']);
        if ($forward) {
            if ($nodes[$dispatch]['end'] !== $nodes[$clear]['start']) {
                self::fail();
            }

            return self::replace($html, [[$nodes[$dispatch]['start'], strlen($fragment), ''], [$nodes[$body]['close'], 0, self::ROW_OPEN.$fragment.self::ROW_CLOSE]]);
        }
        $last = $rows[2];
        $lastHtml = substr($html, $nodes[$last]['start'], $nodes[$last]['end'] - $nodes[$last]['start']);
        if ($lastHtml !== self::ROW_OPEN.$fragment.self::ROW_CLOSE) {
            self::fail();
        }

        return self::replace($html, [[$nodes[$last]['start'], strlen($lastHtml), ''], [$nodes[$clear]['start'], 0, $fragment]]);
    }

    private static function bar(string $html, bool $forward): string
    {
        $nodes = self::nodes($html);
        $cell = self::one($nodes, 'rt-v17-dispatch-block-cell');
        $caption = self::one($nodes, 'rt-v17-dispatch-caption');
        $actions = self::one($nodes, 'rt-v17-dispatch-actions');
        if (! self::within($nodes, $caption, $cell) || ! self::within($nodes, $actions, $cell)) {
            self::fail();
        }
        $captionHtml = substr($html, $nodes[$caption]['start'], $nodes[$caption]['end'] - $nodes[$caption]['start']);
        $actionsHtml = substr($html, $nodes[$actions]['start'], $nodes[$actions]['end'] - $nodes[$actions]['start']);
        $old = $captionHtml.$actionsHtml;
        $new = self::BAR_OPEN.$captionHtml.self::BAR_MIDDLE.$actionsHtml.self::BAR_CLOSE;
        $start = $nodes[$cell]['openEnd'];
        $length = $nodes[$cell]['close'] - $start;
        if (substr($html, $start, $length) !== ($forward ? $old : $new)) {
            self::fail();
        }

        return substr_replace($html, $forward ? $new : $old, $start, $length);
    }

    private static function singleCell(array $nodes, int $table): int
    {
        $cells = array_keys(array_filter($nodes, static fn (array $node, int $index): bool => $node['tag'] === 'td' && self::within($nodes, $index, $table), ARRAY_FILTER_USE_BOTH));
        if (count($cells) !== 1) {
            self::fail();
        }

        return $cells[0];
    }

    private static function property(string $style, string $property, string $before, string $after): string
    {
        $pattern = '~((?:^|;)\s*'.preg_quote($property, '~').'\s*:\s*)'.preg_quote($before, '~').'(?=\s*(?:;|$))~i';
        $result = preg_replace_callback($pattern, static fn (array $match): string => $match[1].$after, $style, -1, $count);
        if ($count !== 1 || ! is_string($result)) {
            self::fail();
        }

        return $result;
    }

    private static function one(array $nodes, string $class): int
    {
        $matches = array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, $class)));
        if (count($matches) !== 1) {
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

    private static function replace(string $html, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $edge = strlen($html) + 1;
        foreach ($edits as [$offset, $length, $value]) {
            if ($offset + $length > $edge) {
                self::fail();
            }
            $html = substr_replace($html, $value, $offset, $length);
            $edge = $offset;
        }

        return $html;
    }

    private static function opening(array $node, array $changes): string
    {
        $tag = $node['opening'];
        $edits = [];
        $new = '';
        foreach ($changes as $key => $value) {
            if ($key === 'width' && $value !== null && isset($node['ranges'][$key])) {
                [$offset, $length] = $node['ranges'][$key];
                $original = substr($tag, $offset, $length);
                if (! is_string($value) || preg_match('~\A[0-9]+%?\z~', $value) !== 1
                    || preg_match('~\A(\s+width\s*=\s*)(["\']?)([^"\'\s]+)\2\z~i', $original, $match) !== 1) {
                    self::fail();
                }
                $edits[] = [$offset, $length, $match[1].$match[2].$value.$match[2]];

                continue;
            }
            $quoteMode = isset($node['ranges'][$key]) && str_contains(substr($tag, $node['ranges'][$key][0], $node['ranges'][$key][1]), '&#039;') ? ENT_QUOTES : ENT_COMPAT;
            $attribute = $value === null ? '' : ' '.$key.'="'.htmlspecialchars($value, $quoteMode | ENT_SUBSTITUTE, 'UTF-8').'"';
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
        throw new RuntimeException('Die mobile Referenzsignatur besitzt keinen eindeutigen Projektionsvertrag.');
    }
}
