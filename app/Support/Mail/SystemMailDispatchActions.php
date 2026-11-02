<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Support\OutlookAddin\OutlookLinkedAnimatedDelivery;
use RuntimeException;

/** Final, source-admitted System-mail actions; canonical/editor/SDK HTML is unchanged. */
final class SystemMailDispatchActions
{
    public const REVISION = 'system-mail-dispatch-actions-v1';

    private const MARKER = 'data-rt-system-dispatch-actions';

    private const DEFAULT_PHONE = '+491601881839';

    private const NUMBER_IMAGE = '/m/ef89fd23c6.png';

    private const TOKENS = '~<!doctype\b[^>]*>|<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    public static function project(string $html, ?string $profile, string $baseUrl): string
    {
        if ($profile !== OutlookLinkedAnimatedDelivery::PROFILE
            || ! str_contains($html, 'outlook-desktop-flow-v17')) {
            return $html;
        }
        $url = parse_url($baseUrl);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! in_array($url['path'] ?? '', ['', '/'], true)) {
            self::fail();
        }
        $baseUrl = rtrim($baseUrl, '/');
        $nodes = self::nodes($html);
        $roots = self::find($nodes, static fn (array $n): bool => ($n['attrs']['data-rt-desktop-flow'] ?? '') === 'outlook-desktop-flow-v17');
        if ($roots === []) {
            return $html;
        }
        if (count($roots) !== 1) {
            self::fail();
        }
        if (! self::has($nodes[$roots[0]], 'rt-system-columns')) {
            return $html;
        }
        $root = $roots[0];
        if ($nodes[$root]['tag'] !== 'table') {
            self::fail();
        }
        for ($i = $root; is_int($i); $i = $nodes[$i]['parent']) {
            if ($nodes[$i]['tag'] === 'blockquote'
                || preg_match('/\A(?:x_)?(?:divrplyfwdmsg|mail-editor-reference-message-container|stopspelling)\z/i', $nodes[$i]['attrs']['id'] ?? '')
                || array_filter(['gmail_quote', 'yahoo_quoted', 'moz-cite-prefix'], static fn (string $class): bool => self::has($nodes[$i], $class)) !== []) {
                self::fail();
            }
        }
        $dispatch = self::one($nodes, static fn (array $n): bool => self::has($n, 'rt-v17-dispatch'));
        $card = self::one($nodes, static fn (array $n): bool => self::has($n, 'rt-v17-dispatch-block'));
        $actions = self::one($nodes, static fn (array $n): bool => self::has($n, 'rt-v17-dispatch-actions'));
        if (! self::within($nodes, $dispatch, $root) || ! self::within($nodes, $card, $dispatch)
            || ! self::within($nodes, $actions, $card) || $nodes[$actions]['tag'] !== 'table') {
            self::fail();
        }
        $table = $nodes[$actions];
        $owned = isset($table['attrs'][self::MARKER]);
        $markers = self::find($nodes, static fn (array $n): bool => isset($n['attrs'][self::MARKER]));
        if ($markers !== ($owned ? [$actions] : []) || ($owned && $table['attrs'][self::MARKER] !== 'v1')
            || ($table['attrs']['role'] ?? '') !== 'presentation'
            || ($table['attrs']['width'] ?? '') !== ($owned ? '215' : '100%')) {
            self::fail();
        }
        $row = self::direct($nodes, $actions);
        if (count($row) === 1 && $nodes[$row[0]]['tag'] === 'tbody') {
            $row = self::direct($nodes, $row[0]);
        }
        if (count($row) !== 1 || $nodes[$row[0]]['tag'] !== 'tr') {
            self::fail();
        }
        $cells = self::direct($nodes, $row[0]);
        if (count($cells) !== 3 || array_filter($cells, static fn (int $i): bool => $nodes[$i]['tag'] !== 'td') !== []) {
            self::fail();
        }
        $widths = array_map(static fn (int $i): ?string => $nodes[$i]['attrs']['width'] ?? null, $cells);
        if (! $owned && $widths !== ['100%', '32', null]) {
            self::fail();
        }
        $links = array_map(static fn (int $cell): array => self::find($nodes, static fn (array $n, int $i): bool => $n['tag'] === 'a' && self::within($nodes, $i, $cell)), $cells);
        if (array_map('count', $links) !== [2, 1, 1]) {
            self::fail();
        }
        [$mail, $whatsapp] = $links[0];
        $phone = $links[1][0];
        $number = $links[2][0];
        if ($owned) {
            self::assertSlots($html, $nodes, $cells[0], 'rt-system-dispatch-links', ['24', '8', '24'], [1]);
            self::assertSlots($html, $nodes, $cells[1], 'rt-system-dispatch-phone', ['2', '24', '6'], [0, 2]);
        } elseif (self::direct($nodes, $cells[0]) !== [$mail, $whatsapp] || self::direct($nodes, $cells[1]) !== [$phone]) {
            self::fail();
        }
        $href = $nodes[$phone]['attrs']['href'] ?? '';
        if (! preg_match('/\Amailto:[^\s<>?@]+@[^\s<>?@]+\z/', $nodes[$mail]['attrs']['href'] ?? '')
            || ($nodes[$whatsapp]['attrs']['href'] ?? '') !== 'https://wa.me/491601881848'
            || ! preg_match('/\Atel:(\+?[0-9]{3,15})\z/', $href, $phoneMatch)
            || ($nodes[$number]['attrs']['href'] ?? '') !== $href) {
            self::fail();
        }
        $icons = [];
        foreach ([[$mail, '/m/19125261c2.png', 'E-Mail an die Disposition'], [$whatsapp, '/m/f9d88b899d.png', 'WhatsApp'], [$phone, '/m/1111670ac8.png', 'Telefon']] as [$link, $path, $alt]) {
            $image = self::one($nodes, static fn (array $n, int $i): bool => $n['tag'] === 'img' && self::within($nodes, $i, $link));
            if (($nodes[$image]['attrs']['src'] ?? '') !== $baseUrl.$path
                || ($nodes[$image]['attrs']['width'] ?? '') !== '24' || ($nodes[$image]['attrs']['height'] ?? '') !== '24'
                || ($nodes[$image]['attrs']['alt'] ?? '') !== $alt) {
                self::fail();
            }
            $icons[] = $image;
        }
        $default = $phoneMatch[1] === self::DEFAULT_PHONE;
        $targetWidths = $default ? ['75', '32', '108'] : ['62', '32', '121'];
        if ($owned && $widths !== $targetWidths) {
            self::fail();
        }
        if (count(self::find($nodes, static fn (array $n, int $i): bool => $n['tag'] === 'img' && self::within($nodes, $i, $actions))) !== ($owned && $default ? 4 : 3)) {
            self::fail();
        }
        $numberChildren = self::direct($nodes, $number);
        if (count($numberChildren) !== 1 || $nodes[$numberChildren[0]]['tag'] !== ($owned && $default ? 'img' : 'span')) {
            self::fail();
        }
        $value = $numberChildren[0];
        if ($owned && $default) {
            if (($nodes[$value]['attrs']['src'] ?? '') !== $baseUrl.self::NUMBER_IMAGE
                || ($nodes[$value]['attrs']['width'] ?? '') !== '108' || ($nodes[$value]['attrs']['height'] ?? '') !== '20'
                || ($nodes[$value]['attrs']['alt'] ?? '') !== '+49 160 1881839'
                || ($nodes[$value]['attrs']['style'] ?? '') !== 'display:block;width:108px;height:20px;border:0;') {
                self::fail();
            }
        } else {
            $text = trim(CssSemantic::decodeHtmlEntitiesOnce(substr($html, $nodes[$value]['openEnd'], $nodes[$value]['close'] - $nodes[$value]['openEnd'])));
            if (self::direct($nodes, $value) !== [] || ! preg_match('/\A[+0-9()\p{Zs}.\-]{3,24}\z/u', $text)
                || preg_replace('/[()\p{Zs}.\-]/u', '', $text) !== $phoneMatch[1]) {
                self::fail();
            }
        }
        if ($owned) {
            foreach ([[$actions, 'width:215px!important;max-width:100%;table-layout:auto!important;'], [$cells[0], 'width:'.$targetWidths[0].'px!important;white-space:nowrap!important;'], [$cells[1], 'width:32px!important;padding:8px 0;vertical-align:middle;'], [$cells[2], 'width:'.$targetWidths[2].'px!important;white-space:nowrap!important;padding-left:0!important;text-align:left!important;'], [$whatsapp, 'margin-left:0;']] as [$i, $suffix]) {
                if (! str_ends_with($nodes[$i]['attrs']['style'] ?? '', $suffix)) {
                    self::fail();
                }
            }
            if (! $default && ! str_ends_with($nodes[$value]['attrs']['style'] ?? '', 'white-space:nowrap!important;overflow-wrap:normal!important;word-break:normal!important;')) {
                self::fail();
            }

            return $html;
        }
        foreach ([[$actions, 'width', '100%'], [$cells[0], 'width', '100%'], [$cells[0], 'padding', '8px 0'], [$cells[1], 'width', '32px'], [$cells[1], 'padding', '8px 8px 8px 0'], [$cells[2], 'padding', '8px 0'], [$whatsapp, 'margin-left', '14px']] as [$i, $name, $expected]) {
            $values = [];
            foreach (self::declarations($nodes[$i]['attrs']['style'] ?? '') as [$part, $property, $declarationValue]) {
                if ($property === $name) {
                    $values[] = strtolower(trim(preg_replace('/\s+/', ' ', $declarationValue)));
                }
            }
            if ($values !== [$expected]) {
                self::fail();
            }
        }
        $changes = [
            $actions => ['width' => '215', self::MARKER => 'v1', 'style' => self::style($table, ['width', 'max-width', 'table-layout'], 'width:215px!important;max-width:100%;table-layout:auto!important;')],
            $cells[0] => ['width' => $targetWidths[0], 'nowrap' => '', 'style' => self::style($nodes[$cells[0]], ['width', 'white-space'], 'width:'.$targetWidths[0].'px!important;white-space:nowrap!important;')],
            $cells[1] => ['width' => '32', 'style' => self::style($nodes[$cells[1]], ['width', 'padding', 'padding-left', 'padding-right', 'vertical-align', 'text-align'], 'width:32px!important;padding:8px 0;vertical-align:middle;')],
            $cells[2] => ['width' => $targetWidths[2], 'nowrap' => '', 'style' => self::style($nodes[$cells[2]], ['width', 'white-space', 'padding-left', 'text-align'], 'width:'.$targetWidths[2].'px!important;white-space:nowrap!important;padding-left:0!important;text-align:left!important;')],
        ];
        if (! $default) {
            $changes[$value] = ['style' => self::style($nodes[$value], ['white-space', 'overflow-wrap', 'word-break'], 'white-space:nowrap!important;overflow-wrap:normal!important;word-break:normal!important;')];
        }
        $edits = [];
        foreach ($changes as $i => $attributes) {
            $edits[] = [$nodes[$i]['start'], $nodes[$i]['openEnd'] - $nodes[$i]['start'], self::opening($nodes[$i], $attributes)];
        }
        $mailHtml = substr($html, $nodes[$cells[0]]['openEnd'], $nodes[$mail]['end'] - $nodes[$cells[0]]['openEnd']);
        $waHtml = substr($html, $nodes[$mail]['end'], $nodes[$whatsapp]['start'] - $nodes[$mail]['end'])
            .self::opening($nodes[$whatsapp], ['style' => self::style($nodes[$whatsapp], ['margin-left'], 'margin-left:0;')])
            .substr($html, $nodes[$whatsapp]['openEnd'], $nodes[$cells[0]]['close'] - $nodes[$whatsapp]['openEnd']);
        $edits[] = [$nodes[$cells[0]]['openEnd'], $nodes[$cells[0]]['close'] - $nodes[$cells[0]]['openEnd'], self::slots('rt-system-dispatch-links', [[24, $mailHtml], [8, '&nbsp;'], [24, $waHtml]])];
        $phoneHtml = substr($html, $nodes[$cells[1]]['openEnd'], $nodes[$cells[1]]['close'] - $nodes[$cells[1]]['openEnd']);
        $edits[] = [$nodes[$cells[1]]['openEnd'], $nodes[$cells[1]]['close'] - $nodes[$cells[1]]['openEnd'], self::slots('rt-system-dispatch-phone', [[2, '&nbsp;'], [24, $phoneHtml], [6, '&nbsp;']])];
        if ($default) {
            $edits[] = [$nodes[$value]['start'], $nodes[$value]['end'] - $nodes[$value]['start'], '<img src="'.htmlspecialchars($baseUrl.self::NUMBER_IMAGE, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" width="108" height="20" align="middle" border="0" alt="+49 160 1881839" style="display:block;width:108px;height:20px;border:0;">'];
        }
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $previous = strlen($html) + 1;
        foreach ($edits as [$start, $length, $replacement]) {
            if ($start + $length > $previous) {
                self::fail();
            }
            $html = substr_replace($html, $replacement, $start, $length);
            $previous = $start;
        }
        if (self::project($html, $profile, $baseUrl) !== $html) {
            self::fail();
        }

        return $html;
    }

    /** Physical spacer cells survive Word ignoring inline-block and margins. */
    private static function slots(string $class, array $slots): string
    {
        $width = array_sum(array_column($slots, 0));
        $html = '<table class="'.$class.'" role="presentation" width="'.$width.'" border="0" cellspacing="0" cellpadding="0" style="width:'.$width.'px;border-collapse:collapse;table-layout:fixed;"><tbody><tr>';
        foreach ($slots as [$slot, $content]) {
            $html .= '<td width="'.$slot.'" valign="middle" style="padding:0;font-size:0;line-height:0;">'.$content.'</td>';
        }

        return $html.'</tr></tbody></table>';
    }

    private static function assertSlots(string $html, array $nodes, int $parent, string $class, array $widths, array $spacers): void
    {
        $tables = self::direct($nodes, $parent);
        $width = (string) array_sum(array_map('intval', $widths));
        if (count($tables) !== 1 || $nodes[$tables[0]]['tag'] !== 'table' || ! self::has($nodes[$tables[0]], $class)
            || ($nodes[$tables[0]]['attrs']['width'] ?? '') !== $width
            || ($nodes[$tables[0]]['attrs']['role'] ?? '') !== 'presentation'
            || ($nodes[$tables[0]]['attrs']['style'] ?? '') !== 'width:'.$width.'px;border-collapse:collapse;table-layout:fixed;') {
            self::fail();
        }
        $children = self::direct($nodes, $tables[0]);
        foreach (['tbody', 'tr'] as $tag) {
            if (count($children) !== 1 || $nodes[$children[0]]['tag'] !== $tag) {
                self::fail();
            }
            $children = self::direct($nodes, $children[0]);
        }
        if (array_map(static fn (int $i): string => $nodes[$i]['attrs']['width'] ?? '', $children) !== $widths
            || array_filter($children, static fn (int $i): bool => $nodes[$i]['tag'] !== 'td' || ($nodes[$i]['attrs']['style'] ?? '') !== 'padding:0;font-size:0;line-height:0;') !== []) {
            self::fail();
        }
        foreach ($spacers as $position) {
            $cell = $nodes[$children[$position]];
            if (substr($html, $cell['openEnd'], $cell['close'] - $cell['openEnd']) !== '&nbsp;') {
                self::fail();
            }
        }
    }

    private static function style(array $node, array $remove, string $suffix): string
    {
        $parts = [];
        foreach (self::declarations($node['attrs']['style'] ?? '') as [$part, $property]) {
            if (! in_array($property, $remove, true)) {
                $parts[] = $part.';';
            }
        }

        return implode('', $parts).$suffix;
    }

    private static function declarations(string $style): array
    {
        $parts = [];
        $start = 0;
        $quote = null;
        $depth = 0;
        for ($i = 0; $i <= strlen($style); $i++) {
            $char = $style[$i] ?? ';';
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                if (--$depth < 0) {
                    self::fail();
                }
            } elseif ($char === ';' && $depth === 0) {
                $part = substr($style, $start, $i - $start);
                if (trim($part) !== '') {
                    if (! preg_match('/\A\s*([a-z-]+)\s*:(.*)\z/is', $part, $property)) {
                        self::fail();
                    }
                    $parts[] = [$part, strtolower($property[1]), $property[2]];
                }
                $start = $i + 1;
            }
        }
        if ($quote !== null || $depth !== 0 || str_contains($style, '/*')) {
            self::fail();
        }

        return $parts;
    }

    private static function opening(array $node, array $changes): string
    {
        $tag = $node['opening'];
        $edits = [];
        $added = '';
        foreach ($changes as $name => $value) {
            $attribute = ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            if (isset($node['ranges'][$name])) {
                $edits[] = [...$node['ranges'][$name], $attribute];
            } else {
                $added .= $attribute;
            }
        }
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $length, $attribute]) {
            $tag = substr_replace($tag, $attribute, $start, $length);
        }

        return substr($tag, 0, -1).$added.'>';
    }

    private static function find(array $nodes, callable $predicate): array
    {
        return array_keys(array_filter($nodes, $predicate, ARRAY_FILTER_USE_BOTH));
    }

    private static function one(array $nodes, callable $predicate): int
    {
        $found = self::find($nodes, $predicate);
        if (count($found) !== 1) {
            self::fail();
        }

        return $found[0];
    }

    private static function has(array $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($node['attrs']['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY), true);
    }

    private static function within(array $nodes, int $i, int $parent): bool
    {
        do {
            if ($i === $parent) {
                return true;
            }
            $i = $nodes[$i]['parent'];
        } while (is_int($i));

        return false;
    }

    private static function direct(array $nodes, int $parent): array
    {
        return self::find($nodes, static fn (array $n): bool => $n['parent'] === $parent);
    }

    /** Quote-safe offsets; comments, MSO helpers and STYLE bodies stay opaque. */
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
            $nodes[] = ['tag' => $tag, 'attrs' => $attrs, 'ranges' => $ranges, 'opening' => $token, 'parent' => $stack === [] ? null : $stack[array_key_last($stack)], 'start' => $offset, 'openEnd' => $cursor, 'close' => $void ? $cursor : null, 'end' => $void ? $cursor : null];
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
        throw new RuntimeException('Die Systemmail-Aktionszeile besitzt keinen eindeutigen Ausgabevertrag.');
    }
}
