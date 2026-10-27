<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Final physical-mobile V27 geometry; canonical source and media stay intact. */
final class OutlookMobileHybridDelivery
{
    public const MARKER = 'data-rt-mobile-hybrid';

    private const TOKENS = '~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    private const FLOW = 'font-size:0;line-height:0;text-align:left;width:100%;max-width:680px;table-layout:fixed;overflow:hidden;direction:ltr;margin:0 auto 0 0;';

    private const CELL = 'padding:0;';

    private const IMAGE = 'display:block;width:100%;max-width:100%;height:auto;margin:0;border:0;';

    public static function project(string $html): string
    {
        if (! str_contains($html, 'data-rt-personal-columns')
            || (! str_contains($html, 'rt-mobile-ledger') && ! str_contains($html, 'x10'))) {
            return $html;
        }
        $nodes = self::nodes($html);
        $roots = array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, 'rt-mobile-ledger', 'x10')));
        $flows = array_keys(array_filter($nodes, static fn (array $node): bool => array_key_exists('data-rt-personal-columns', $node['attrs'])));
        if ($roots === [] || $flows === []) {
            return $html;
        }
        if (count($roots) !== 1 || count($flows) !== 1) {
            self::fail();
        }
        $root = $roots[0];
        $flow = $flows[0];
        if ($nodes[$root]['tag'] !== 'div' || ! self::has($nodes[$root], 'rt-outlook-signature', 'xz')
            || ! self::has($nodes[$root], 'rtm', 'xy') || ! self::within($nodes, $flow, $root)
            || ($nodes[$flow]['attrs']['data-rt-personal-columns'] ?? '') !== 'v1') {
            self::fail();
        }
        $scope = self::scope($html);
        self::styles($html, $scope);
        $artifacts = array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs']['data-rt-artifact-version'])));
        if (count($artifacts) !== 1 || $nodes[$artifacts[0]]['tag'] !== 'tr'
            || $nodes[$artifacts[0]]['attrs']['data-rt-artifact-version'] !== 'v27'
            || ! self::within($nodes, $flow, $artifacts[0])) {
            self::fail();
        }
        $marked = array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][self::MARKER])));
        if ($marked !== (isset($nodes[$flow]['attrs'][self::MARKER]) ? [$flow] : [])) {
            self::fail();
        }
        if (isset($nodes[$flow]['attrs'][self::MARKER])) {
            self::assertProjected($html, $nodes, $flow);

            return $html;
        }
        if ($nodes[$flow]['tag'] !== 'div' || ! self::has($nodes[$flow], 'rt-personal-columns')) {
            self::fail();
        }
        self::styleIs($nodes[$flow], 'font-size:0;line-height:0;text-align:left;width:100%;max-width:920px;');
        $left = self::one($nodes, 'rt-personal-left', 'table');
        $right = self::one($nodes, 'rt-personal-right', 'table');
        self::children($html, $nodes, $flow, [$left, $right]);
        $leftCell = self::tableCell($html, $nodes, $left);
        $rightCell = self::tableCell($html, $nodes, $right);
        if (! self::has($nodes[$leftCell], 'rt-personal-cell-left') || ! self::has($nodes[$rightCell], 'rt-personal-cell-right')) {
            self::fail();
        }
        foreach ([$left, $right] as $column) {
            self::styleIs($nodes[$column], 'display:table;vertical-align:top;width:100%;min-width:0;max-width:none;table-layout:fixed;border-collapse:collapse;margin:0;');
            self::attributesOnly($nodes[$column], ['class', 'role', 'width', 'border', 'cellpadding', 'cellspacing', 'style', 'align']);
        }
        self::styleIs($nodes[$leftCell], 'padding:0 18px;font-size:13px;line-height:18px;vertical-align:top;text-align:left;');
        self::styleIs($nodes[$rightCell], 'padding:24px 18px 0;font-size:13px;line-height:18px;vertical-align:top;text-align:left;');
        foreach ([$leftCell, $rightCell] as $cell) {
            self::attributesOnly($nodes[$cell], ['class', 'style']);
        }
        self::attributesOnly($nodes[$flow], ['class', 'style', 'data-rt-personal-columns']);
        $logo = self::one($nodes, 'rt-personal-logo-frame', 'table');
        $name = self::one($nodes, 'rt-personal-name', 'div');
        $direct = self::one($nodes, 'rt-personal-direct', 'div');
        $company = self::one($nodes, 'rt-personal-company-block', 'table');
        $contacts = self::one($nodes, 'rt-personal-company-contacts', 'div');
        self::children($html, $nodes, $leftCell, [$logo, $name, $direct]);
        self::children($html, $nodes, $rightCell, [$company]);
        self::children($html, $nodes, self::tableCell($html, $nodes, $company), [$contacts]);
        self::styleIs($nodes[$contacts], 'padding-top:14px;');
        self::attributesOnly($nodes[$contacts], ['class', 'style']);
        $contactClasses = array_values(array_filter(preg_split('/\s+/', trim($nodes[$contacts]['attrs']['class'] ?? '')), static fn (string $class): bool => preg_match('/\A(?:oi|m|g)[0-9a-z]+\z/', $class) !== 1));
        if ($contactClasses !== ['rt-personal-company-contacts']) {
            self::fail();
        }
        $contactsOpening = self::attribute(self::attribute($nodes[$contacts]['opening'], 'class', 'rt-personal-company-contacts'), 'style', 'padding-top:4px!important;');
        $logoCell = self::tableCell($html, $nodes, $logo);
        $logoImages = self::direct($nodes, $logoCell);
        if (count($logoImages) !== 1 || $nodes[$logoImages[0]]['tag'] !== 'img' || ! self::has($nodes[$logoImages[0]], 'rt-logo')) {
            self::fail();
        }
        $train = self::one($nodes, 'rt-delivery-train-cell', 'td', 'xq');
        $image = self::one($nodes, 'rt-delivery-train', 'img', 'xr');
        self::children($html, $nodes, $train, [$image]);
        self::styleIs($nodes[$image], 'display:block;width:100%;max-width:100%;height:auto;margin:0;border:0;vertical-align:bottom;');
        if (($nodes[$image]['attrs']['width'] ?? '') !== '300' || ($nodes[$image]['attrs']['height'] ?? '') !== '38') {
            self::fail();
        }
        $content = $nodes[$flow]['parent'];
        $contentRow = $content === null ? null : $nodes[$content]['parent'];
        $trainRow = $nodes[$train]['parent'];
        if ($content === null || $nodes[$content]['tag'] !== 'td' || $contentRow === null || $nodes[$contentRow]['tag'] !== 'tr'
            || $trainRow === null || $nodes[$trainRow]['tag'] !== 'tr' || $nodes[$contentRow]['parent'] !== $nodes[$trainRow]['parent']
            || ! self::within($nodes, $train, $root)) {
            self::fail();
        }
        self::children($html, $nodes, $content, [$flow]);
        self::children($html, $nodes, $contentRow, [$content]);
        self::children($html, $nodes, $trainRow, [$train]);
        self::children($html, $nodes, $nodes[$contentRow]['parent'], [$contentRow, $trainRow]);
        $moving = self::inner($html, $nodes[$train]);
        $imageOpening = self::attribute(self::attribute($nodes[$image]['opening'], 'class', 'rt-mhy-image rt-i'.$scope), 'style', self::IMAGE);
        $moving = substr_replace($moving, '<div class=rt-w'.$scope.'>'.$imageOpening.'</div>',
            $nodes[$image]['start'] - $nodes[$train]['openEnd'], $nodes[$image]['openEnd'] - $nodes[$image]['start']);
        $logoBlock = self::block('logo', self::whole($html, $nodes[$logo]));
        $trainBlock = self::block('train', $moving);
        $before = self::inventory($html);
        $edits = [
            [$nodes[$flow]['start'], $nodes[$flow]['openEnd'], '<table class="rt-mobile-hybrid-v2" '.self::MARKER.'="v2" data-rt-personal-columns="v1" role=presentation dir=ltr width=100% cellpadding=0 cellspacing=0 style="'.self::FLOW.'"><tbody><tr><td dir=ltr align=left style="padding:0;">'.$logoBlock],
            [$nodes[$flow]['close'], $nodes[$flow]['end'], '</td></tr><tr><td dir=ltr align=left style="padding:0;">'.$trainBlock.'</td></tr></tbody></table>'],
            [$nodes[$left]['start'], $nodes[$left]['openEnd'], self::tableOpening('person')],
            [$nodes[$right]['start'], $nodes[$right]['openEnd'], self::tableOpening('company')],
            [$nodes[$leftCell]['start'], $nodes[$leftCell]['openEnd'], '<td dir=ltr align=left style="'.self::cellStyle('person').'">'],
            [$nodes[$rightCell]['start'], $nodes[$rightCell]['openEnd'], '<td dir=ltr align=left style="'.self::cellStyle('company').'">'],
            [$nodes[$contacts]['start'], $nodes[$contacts]['openEnd'], $contactsOpening],
            [$nodes[$logo]['start'], $nodes[$logo]['end'], ''],
            [$nodes[$trainRow]['start'], $nodes[$trainRow]['end'], ''],
        ];
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $output = $html;
        $edge = strlen($html);
        foreach ($edits as [$start, $end, $replacement]) {
            if ($end > $edge) {
                self::fail();
            }
            $output = substr_replace($output, $replacement, $start, $end - $start);
            $edge = $start;
        }
        $output = self::pruneUnusedMobileMirrors($output, $nodes[$root]);
        $count = 0;
        $output = preg_replace_callback('~(<style\s+data-rt-outlook-mobile-css=(["\'])1\2\s*>)(.*?)(</style\s*>)~is', static fn (array $style): string => $style[1].$style[3].self::enhancement($scope).$style[4], $output, -1, $count);
        if ($count !== 1) {
            self::fail();
        }
        if ($before !== self::inventory($output)) {
            self::fail();
        }
        $projected = self::nodes($output);
        $flow = self::one($projected, 'rt-mobile-hybrid-v2', 'table');
        self::assertProjected($output, $projected, $flow);

        return $output;
    }

    private static function tableStyle(string $kind): string
    {
        return 'display:'.(in_array($kind, ['person', 'train'], true) ? 'inline-table' : 'inline-block').';vertical-align:'.($kind === 'train' ? 'bottom' : 'top').';width:100%;max-width:340px;'.($kind === 'train' ? 'table-layout:fixed;' : '').'border-collapse:separate;box-sizing:border-box;border:0 solid transparent;border-width:0 18px;'.($kind === 'train' ? 'border-left:0;' : ($kind === 'person' ? 'float:left;' : ''));
    }

    private static function tableOpening(string $kind): string
    {
        return '<table class="rt-mhy-'.$kind.'" role=presentation dir=ltr'.($kind === 'person' ? ' align=left height=50' : '').' width=100% cellpadding=0 cellspacing=0 style="'.self::tableStyle($kind).'">';
    }

    private static function block(string $kind, string $content): string
    {
        return self::tableOpening($kind).'<tbody><tr><td dir=ltr align=left style="'.self::cellStyle($kind).'">'.$content.'</td></tr></tbody></table>';
    }

    private static function cellStyle(string $kind): string
    {
        return self::CELL.(in_array($kind, ['person', 'company'], true) ? 'font-size:13px;line-height:18px;text-align:left;' : '');
    }

    private static function assertProjected(string $html, array $nodes, int $flow): void
    {
        if (($nodes[$flow]['attrs'][self::MARKER] ?? '') !== 'v2' || $nodes[$flow]['tag'] !== 'table'
            || ! self::has($nodes[$flow], 'rt-mobile-hybrid-v2') || ($nodes[$flow]['attrs']['dir'] ?? '') !== 'ltr'
            || ($nodes[$flow]['attrs']['style'] ?? '') !== self::FLOW) {
            self::fail();
        }
        $bodies = self::direct($nodes, $flow);
        if (count($bodies) !== 1 || $nodes[$bodies[0]]['tag'] !== 'tbody') {
            self::fail();
        }
        self::children($html, $nodes, $flow, $bodies);
        $rows = self::direct($nodes, $bodies[0]);
        if (count($rows) !== 2 || $nodes[$rows[0]]['tag'] !== 'tr' || $nodes[$rows[1]]['tag'] !== 'tr') {
            self::fail();
        }
        self::children($html, $nodes, $bodies[0], $rows);
        $cells = [];
        foreach ($rows as $row) {
            $children = self::direct($nodes, $row);
            if (count($children) !== 1 || $nodes[$children[0]]['tag'] !== 'td') {
                self::fail();
            }
            self::children($html, $nodes, $row, $children);
            $cell = $children[0];
            if (($nodes[$cell]['attrs']['dir'] ?? '') !== 'ltr' || ($nodes[$cell]['attrs']['align'] ?? '') !== 'left'
                || ($nodes[$cell]['attrs']['style'] ?? '') !== 'padding:0;') {
                self::fail();
            }
            $cells[] = $cell;
        }
        $groups = [];
        foreach (['logo', 'person', 'company', 'train'] as $kind) {
            $group = self::one($nodes, 'rt-mhy-'.$kind, 'table');
            if (($nodes[$group]['attrs']['dir'] ?? '') !== 'ltr' || ($nodes[$group]['attrs']['style'] ?? '') !== self::tableStyle($kind)) {
                self::fail();
            }
            if ($kind === 'person' && (($nodes[$group]['attrs']['align'] ?? '') !== 'left' || ($nodes[$group]['attrs']['height'] ?? '') !== '50')) {
                self::fail();
            }
            $groups[] = $group;
            $groupCell = self::tableCell($html, $nodes, $group);
            if (($nodes[$groupCell]['attrs']['style'] ?? '') !== self::cellStyle($kind)
                || ($nodes[$groupCell]['attrs']['dir'] ?? '') !== 'ltr' || ($nodes[$groupCell]['attrs']['align'] ?? '') !== 'left') {
                self::fail();
            }
        }
        self::children($html, $nodes, $cells[0], array_slice($groups, 0, 3));
        self::children($html, $nodes, $cells[1], [$groups[3]]);
        self::children($html, $nodes, self::tableCell($html, $nodes, $groups[0]), [self::one($nodes, 'rt-personal-logo-frame', 'table')]);
        self::children($html, $nodes, self::tableCell($html, $nodes, $groups[1]), [self::one($nodes, 'rt-personal-name', 'div'), self::one($nodes, 'rt-personal-direct', 'div')]);
        self::children($html, $nodes, self::tableCell($html, $nodes, $groups[2]), [self::one($nodes, 'rt-personal-company-block', 'table')]);
        $contacts = self::one($nodes, 'rt-personal-company-contacts', 'div');
        if (($nodes[$contacts]['attrs']['class'] ?? '') !== 'rt-personal-company-contacts'
            || ($nodes[$contacts]['attrs']['style'] ?? '') !== 'padding-top:4px!important;') {
            self::fail();
        }
        $scope = self::scope($html);
        $image = self::one($nodes, 'rt-i'.$scope, 'img');
        $window = self::one($nodes, 'rt-w'.$scope, 'div');
        if (($nodes[$image]['attrs']['style'] ?? '') !== self::IMAGE
            || ($nodes[$image]['attrs']['width'] ?? '') !== '300' || ($nodes[$image]['attrs']['height'] ?? '') !== '38'
            || ($nodes[$image]['attrs']['class'] ?? '') !== 'rt-mhy-image rt-i'.$scope
            || ($nodes[$window]['attrs']['class'] ?? '') !== 'rt-w'.$scope || ! self::within($nodes, $window, $groups[3])) {
            self::fail();
        }
        self::attributesOnly($nodes[$window], ['class']);
        self::children($html, $nodes, $window, [$image]);
        self::children($html, $nodes, self::tableCell($html, $nodes, $groups[3]), [$window]);
    }

    private static function scope(string $html): string
    {
        return substr(hash('sha256', "outlook-mobile-hybrid-v65\0".serialize(self::inventory($html))), 0, 6);
    }

    private static function enhancement(string $scope): string
    {
        return '.rt-w'.$scope.'{padding-top:0}';
    }

    private static function styles(string $html, string $scope): void
    {
        preg_match_all('~<style\b([^>]*)>(.*?)</style\s*>~is', $html, $styles, PREG_SET_ORDER);
        $enhancements = 0;
        $enhancement = self::enhancement($scope);
        foreach ($styles as $style) {
            if (trim($style[2]) === '') {
                continue;
            }
            $css = $style[2];
            if (preg_match('/\A\s+data-rt-outlook-mobile-css\s*=\s*(["\'])1\1\s*\z/i', $style[1]) === 1
                && str_ends_with($css, $enhancement)) {
                $enhancements++;
                $css = substr($css, 0, -strlen($enhancement));
            }
            if (preg_match('/\A\s+data-rt-(?:outlook-(?:signature|mobile|marker|template)-css|combined-compose-css)\s*=\s*(["\'])1\1\s*\z/i', $style[1]) !== 1
                || str_contains($css, 'rt-mhy-') || str_contains($css, 'rt-mobile-hybrid-') || preg_match('/\.rt-(?:w|[wi][0-9a-f]{6})(?![-_a-z0-9])/i', $css) === 1) {
                self::fail();
            }
        }
        if ($enhancements !== (str_contains($html, self::MARKER.'="v2"') ? 1 : 0)) {
            self::fail();
        }
    }

    /** Release only generated aliases whose last real/conditional owner moved. */
    private static function pruneUnusedMobileMirrors(string $html, array $root): string
    {
        $scopes = array_values(array_filter(preg_split('/\s+/', trim($root['attrs']['class'] ?? '')), static fn (string $class): bool => preg_match('/\Am[0-9a-z]{3,}\z/', $class) === 1));
        if (count($scopes) !== 1) {
            self::fail();
        }
        $used = [];
        // Conditional IMG classes also count as owners; never remove their rule.
        preg_match_all('~\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $html, $classes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        foreach ($classes as $class) {
            foreach (preg_split('/\s+/', trim($class[1] ?? $class[2] ?? $class[3] ?? '')) as $name) {
                $used[$name] = true;
            }
        }
        $selector = '~\A\.'.preg_quote($scopes[0], '~').'\.(rtm|xy)(?:\.\1)? \.([mg][0-9a-z]+)\z~';

        return preg_replace_callback('~(<style\s+data-rt-outlook-mobile-css=(["\'])1\2\s*>)(.*?)(</style\s*>)~is', static function (array $style) use ($used, $selector): string {
            $css = $style[3];
            $rules = self::flatRules($css);
            foreach (array_reverse($rules) as [$start, $end, $head]) {
                if (preg_match($selector, $head, $alias) === 1 && ! isset($used[$alias[2]])) {
                    $css = substr_replace($css, '', $start, $end - $start);
                }
            }

            return $style[1].$css.$style[4];
        }, $html);
    }

    /** Mobile mirrors contain flat generated rules; strings/comments stay opaque. */
    private static function flatRules(string $css): array
    {
        $rules = [];
        $start = 0;
        $opening = null;
        $quote = null;
        for ($i = 0, $length = strlen($css); $i < $length; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif (substr($css, $i, 2) === '/*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    return [];
                }
                $i = $end + 1;
            } elseif ($char === '{') {
                if ($opening !== null) {
                    return [];
                }
                $opening = $i;
            } elseif ($char === '}') {
                if ($opening === null) {
                    return [];
                }
                $rules[] = [$start, $i + 1, substr($css, $start, $opening - $start)];
                $start = $i + 1;
                $opening = null;
            }
        }

        return $opening === null && $quote === null && trim(substr($css, $start)) === '' ? $rules : [];
    }

    private static function inventory(string $html): array
    {
        preg_match_all(self::TOKENS, $html, $tokens);
        $inventory = ['images' => [], 'links' => [], 'opaque' => [], 'text' => []];
        foreach ($tokens[0] as $tag) {
            if (preg_match('~\A<style\s+data-rt-outlook-mobile-css=(["\'])1\1\s*>~i', $tag)) {
                continue;
            }
            if (str_starts_with($tag, '<!--') || preg_match('~\A<style\b~i', $tag)) {
                $inventory['opaque'][] = $tag;
            } elseif (preg_match('~\A<img\b~i', $tag)) {
                if (preg_match('/\b(?:rt-delivery-train|xr|rt-mhy-image)\b/', $tag)) {
                    $tag = preg_replace('~\s(?:class|style)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $tag);
                }
                $inventory['images'][] = $tag;
            } elseif (preg_match('~\A<a\b~i', $tag)) {
                $inventory['links'][] = $tag;
            }
        }
        $text = preg_replace('~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>~is', '', $html);
        foreach (preg_split('~<[^>]*>~', $text) as $part) {
            if (trim($part) !== '') {
                $inventory['text'][] = $part;
            }
        }
        foreach ($inventory as &$items) {
            sort($items);
        }

        return $inventory;
    }

    private static function styleIs(array $node, string $expected): void
    {
        $normalize = static fn (string $value): string => preg_replace('/\s+/', '', rtrim($value, "; \t\r\n"));
        if ($normalize($node['attrs']['style'] ?? '') !== $normalize($expected)) {
            self::fail();
        }
    }

    private static function attributesOnly(array $node, array $allowed): void
    {
        if (array_diff(array_keys($node['attrs']), $allowed) !== []) {
            self::fail();
        }
    }

    private static function attribute(string $tag, string $name, string $value): string
    {
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pattern = '~\s'.preg_quote($name, '~').'\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i';
        $output = preg_replace($pattern, ' '.$name.'="'.$value.'"', $tag, -1, $count);
        if ($count > 1) {
            self::fail();
        }

        return $count === 1 ? $output : substr($tag, 0, -1).' '.$name.'="'.$value.'">';
    }

    private static function inner(string $html, array $node): string
    {
        return substr($html, $node['openEnd'], $node['close'] - $node['openEnd']);
    }

    private static function whole(string $html, array $node): string
    {
        return substr($html, $node['start'], $node['end'] - $node['start']);
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
            $table = $row;
            $children = self::direct($nodes, $row);
            if (count($children) !== 1) {
                self::fail();
            }
            $row = $children[0];
        }
        $cells = self::direct($nodes, $row);
        if ($nodes[$row]['tag'] !== 'tr' || count($cells) !== 1 || $nodes[$cells[0]]['tag'] !== 'td') {
            self::fail();
        }
        self::children($html, $nodes, $table, [$row]);
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

    private static function one(array $nodes, string $class, string $tag, ?string $alias = null): int
    {
        $matches = array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, $class, $alias)));
        if (count($matches) !== 1 || $nodes[$matches[0]]['tag'] !== $tag) {
            self::fail();
        }

        return $matches[0];
    }

    private static function has(array $node, string $class, ?string $alias = null): bool
    {
        return array_intersect(array_filter([$class, $alias]), preg_split('/\s+/', trim($node['attrs']['class'] ?? ''))) !== [];
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
                'start' => $offset, 'openEnd' => $cursor, 'close' => $void ? $cursor : null, 'end' => $void ? $cursor : null, 'opening' => $token];
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
        throw new RuntimeException('Die mobile V27-Ausgabe besitzt keinen eindeutigen Hybridvertrag.');
    }
}
