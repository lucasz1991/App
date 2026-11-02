<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** One reversible fluid inline layer for every canonical V17 recipient document. */
final class OutlookCompactMobilePresentation
{
    public const MARKER = 'data-rt-compact-mobile';

    public const REVISION = 'outlook-recipient-fluid-v17-v3';

    private const CLASS_NAME = 'rt-v17-mobile-inline';

    private const GUTTER_SUFFIX = ';padding-left:20px!important;padding-right:20px!important;';

    private const LOGO_WIDTH = '184px!important';

    private const LOGO_SUFFIX = ';float:none!important;margin:0!important;';

    private const COMPACT_CSS = '<style data-rt-compact-css="1">@media (max-width:520px){.v30 .rt-v17-train{margin-top:0!important}.v30 .rt-v17-dispatch{width:100%!important}.v30 .rt-v17-dispatch-cell{padding:14px 0 0!important}.v30 .rt-v17-legal-cell{padding:8px 20px!important}}</style>';

    private const TOKENS = '~<!doctype\b[^>]*>|<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    public static function document(string $html): string
    {
        if (! self::candidate($html)) {
            return $html;
        }
        $nodes = self::nodes($html);
        $marked = array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][self::MARKER]));
        if ($marked !== []) {
            $source = self::canonicalize($html);
            if (self::project($source, true) !== $html) {
                self::fail();
            }

            return $html;
        }
        $source = self::project($html, true);
        if (self::canonicalize($source) !== $html) {
            self::fail();
        }

        return $source;
    }

    /** Restores exact pre-fluid V17 bytes before the existing V17 inverse. */
    public static function canonicalize(string $html): string
    {
        if (! self::candidate($html) || ! str_contains($html, self::MARKER)) {
            return $html;
        }
        if (array_filter(self::nodes($html), static fn (array $node): bool => isset($node['attrs'][self::MARKER])) === []) {
            return $html;
        }

        return self::project($html, false);
    }

    private static function candidate(string $html): bool
    {
        return str_contains($html, self::MARKER) || str_contains($html, 'outlook-desktop-flow-v17');
    }

    private static function project(string $html, bool $forward): string
    {
        $nodes = self::nodes($html);
        $flows = array_keys(array_filter($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-desktop-flow'] ?? '') === 'outlook-desktop-flow-v17'));
        $markers = array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][self::MARKER])));
        if ($flows === [] && $markers === []) {
            return $html;
        }
        if (count($flows) !== 1 || $nodes[$flows[0]]['tag'] !== 'table' || ! self::has($nodes[$flows[0]], 'rt-v17-columns')) {
            self::fail();
        }
        $flow = $flows[0];
        $artifacts = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'tr' && ($node['attrs']['data-rt-artifact-version'] ?? '') === 'v27'));
        if (count($artifacts) !== 1 || ! self::within($nodes, $flow, $artifacts[0])) {
            self::fail();
        }
        $artifact = $artifacts[0];
        $signature = $nodes[$artifact]['parent'];
        if (is_int($signature) && $nodes[$signature]['tag'] === 'tbody') {
            $signature = $nodes[$signature]['parent'];
        }
        if (! is_int($signature) || $nodes[$signature]['tag'] !== 'table') {
            self::fail();
        }
        $ownedClasses = array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, self::CLASS_NAME)));
        $class = $nodes[$flow]['attrs']['class'] ?? '';
        if ($forward ? ($markers !== [] || $ownedClasses !== [])
            : ($markers !== [$flow] || $ownedClasses !== [$flow] || ($nodes[$flow]['attrs'][self::MARKER] ?? '') !== 'v17' || ! str_ends_with($class, ' '.self::CLASS_NAME))) {
            self::fail();
        }
        $system = self::has($nodes[$flow], 'rt-system-columns');
        $dispatchWidth = $nodes[self::one($nodes, 'rt-v17-dispatch')]['attrs']['width'] ?? '';
        $companyWidth = $nodes[self::one($nodes, 'rt-v17-company')]['attrs']['width'] ?? '';
        if (! in_array([$dispatchWidth, $companyWidth], [['227', '230'], ['247', '210']], true)) {
            self::fail();
        }
        $legacy = self::legacyCss($system);
        $sheets = [];
        preg_match_all(self::TOKENS, $html, $tokens, PREG_OFFSET_CAPTURE);
        foreach ($tokens[0] as $token) {
            if (preg_match('~\A<style\b~i', $token[0]) === 1 && preg_match('~\sdata-rt-(?:compact-css|v30-css)\s*=~i', $token[0]) === 1) {
                $sheets[] = $token;
            }
        }
        $prefix = $legacy.self::COMPACT_CSS;
        if ($forward ? (count($sheets) !== 2 || $sheets[0][0] !== $legacy || $sheets[1][0] !== self::COMPACT_CSS
            || $sheets[0][1] + strlen($legacy) !== $sheets[1][1] || $sheets[1][1] + strlen(self::COMPACT_CSS) !== $nodes[$flow]['start']) : $sheets !== []) {
            self::fail();
        }
        $changes = [];
        $set = static function (int $index, array $attrs) use (&$changes): void {
            $changes[$index] = array_replace($changes[$index] ?? [], $attrs);
        };
        $styleOf = static function (int $index) use (&$changes, $nodes): string {
            return $changes[$index]['style'] ?? $nodes[$index]['attrs']['style'] ?? '';
        };
        $suffix = static function (int $index, string $tail) use ($styleOf, $set, $forward): void {
            $value = $styleOf($index);
            if ($forward ? str_ends_with($value, $tail) : ! str_ends_with($value, $tail)) {
                self::fail();
            }
            $set($index, ['style' => $forward ? $value.$tail : substr($value, 0, -strlen($tail))]);
        };
        $set($flow, [self::MARKER => $forward ? 'v17' : null, 'class' => $forward ? $class.' '.self::CLASS_NAME : substr($class, 0, -strlen(' '.self::CLASS_NAME))]);
        $suffix($flow, ';max-width:1040px!important;');
        $identityWidth = 'clamp(220px,calc((500px - 100%)*1000),100%)';
        $companyBase = $system ? $companyWidth.'px' : 'min('.$companyWidth.'px,48%)';
        $rules = [
            'rt-v17-identity' => ['width' => [$identityWidth, '220px!important']],
            'rt-v17-direct' => ['width' => ['min(280px,52%)', '280px!important']],
            'rt-v17-company' => ['width' => [$companyBase, $companyWidth.'px!important']],
            'rt-v17-dispatch' => ['width' => [$dispatchWidth.'px', $dispatchWidth.'px!important']],
            'rt-v17-dispatch-cell' => ['padding' => ['0 0px 0 0', '0!important']],
            'rt-v17-dispatch-block' => ['max-width' => [$dispatchWidth.'px', '100%!important']],
            'rt-v17-train' => ['width' => ['min(547px,100%)', '100%!important'], 'max-width' => ['100%!important', '547px!important']],
            'rt-v17-train-image' => ['width' => ['529px', '529px!important'], 'height' => ['auto', 'auto!important']],
        ];
        foreach ($rules as $className => $properties) {
            if ($system && $className === 'rt-v17-direct') {
                continue;
            }
            $index = self::one($nodes, $className);
            if (! self::within($nodes, $index, $artifact)) {
                self::fail();
            }
            $style = $styleOf($index);
            foreach ($properties as $property => [$old, $new]) {
                $style = self::property($style, $property, $forward ? $old : $new, $forward ? $new : $old);
            }
            $set($index, ['style' => $style]);
        }
        foreach (['rt-v17-columns-cell', 'rt-v17-legal-cell'] as $className) {
            $suffix(self::one($nodes, $className), self::GUTTER_SUFFIX);
        }
        // Alias x6 is the mobile compiler's existing native template pad. The
        // canonical source inverse, not an arbitrary class name, owns it.
        $nativeCombined = array_filter($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-compose-document'] ?? '') === 'combined-native-v1') !== [];
        $templateRoots = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'div'
            && isset($node['attrs']['data-rt-theme']) && (self::has($node, 'rt-outlook-template') || self::has($node, 'x8'))));
        foreach ($nodes as $index => $node) {
            $aliasedTemplatePad = $nativeCombined && self::has($node, 'x6')
                && array_filter($templateRoots, static fn (int $root): bool => self::within($nodes, $index, $root)) !== [];
            if (self::has($node, 'rt-v17-template-pad') || self::has($node, 'rt-native-template-pad') || $aliasedTemplatePad) {
                if ($node['tag'] !== 'td' || self::within($nodes, $index, $artifact)) {
                    self::fail();
                }
                $suffix($index, self::GUTTER_SUFFIX);
            }
            if (self::within($nodes, $index, $flow) && (self::has($node, 'rt-delivery-contact-value') || self::has($node, 'xt'))) {
                if ($node['tag'] !== 'p') {
                    self::fail();
                }
                $suffix($index, ';line-height:19px!important;word-break:normal!important;');
            }
        }
        $train = self::one($nodes, 'rt-v17-train');
        if (($nodes[$train]['attrs']['width'] ?? '') !== ($forward ? '547' : '100%')) {
            self::fail();
        }
        $set($train, ['width' => $forward ? '100%' : '547']);
        $suffix($train, ';table-layout:fixed;');
        $raised = self::has($nodes[$flow], 'rt-v17-raised');
        $offset = $raised ? 'clamp(-8px,calc((500px - 100%)/10),0px)' : '0';
        $numericOffset = '0!important';
        $set($train, ['style' => self::property($styleOf($train), 'margin-top', $forward ? $offset : $numericOffset, $forward ? $numericOffset : $offset)]);
        if (! $system) {
            $logo = self::one($nodes, 'rt-personal-logo-frame');
            $image = self::one($nodes, 'rt-v9-logo');
            foreach ([$logo, $image] as $index) {
                if (! self::within($nodes, $index, $flow) || ($nodes[$index]['attrs']['width'] ?? '') !== '184') {
                    self::fail();
                }
                $style = $styleOf($index);
                if ($index === $logo && ! $forward) {
                    if (! str_ends_with($style, self::LOGO_SUFFIX)) {
                        self::fail();
                    }
                    $style = substr($style, 0, -strlen(self::LOGO_SUFFIX));
                }
                $value = $index === $logo ? self::LOGO_WIDTH : '100%!important';
                $style = self::property($style, 'width', $forward ? '184px' : $value, $forward ? $value : '184px');
                if ($index === $image) {
                    $style = self::property($style, 'height', $forward ? 'auto' : 'auto!important', $forward ? 'auto!important' : 'auto');
                } else {
                    $style .= $forward ? self::LOGO_SUFFIX : '';
                }
                $set($index, ['style' => $style]);
            }
            $role = self::one($nodes, 'rt-personal-role');
            $roleStyle = $styleOf($role);
            foreach (['color' => '#e90032', 'line-height' => '15px', 'letter-spacing' => '1.6px', 'font-size' => '10px'] as $property => $value) {
                $roleStyle = self::property($roleStyle, $property, $forward ? $value : $value.'!important', $forward ? $value.'!important' : $value);
            }
            $set($role, ['style' => $roleStyle]);
        }
        $legalTable = self::one($nodes, 'rt-brand-legal-table');
        $legalHost = $nodes[$legalTable]['parent'];
        if (! is_int($legalHost) || $nodes[$legalHost]['tag'] !== 'td' || ! self::within($nodes, $legalHost, $signature)) {
            self::fail();
        }
        $set($legalHost, ['style' => self::property($styleOf($legalHost), 'background', $forward ? '#dde1e6' : '#dde1e6!important', $forward ? '#dde1e6!important' : '#dde1e6')]);
        $edits = $forward ? [[$sheets[0][1], strlen($prefix), '']] : [];
        foreach ($changes as $index => $attrs) {
            $edits[] = [$nodes[$index]['start'], $nodes[$index]['openEnd'] - $nodes[$index]['start'], (! $forward && $index === $flow ? $prefix : '').self::opening($nodes[$index], $attrs)];
        }

        $result = self::replace($html, $edits);
        if ($forward) {
            foreach (self::nodes($result) as $node) {
                if (preg_match('~\b(?:min|max|clamp|calc)\s*\(~i', $node['attrs']['style'] ?? '') === 1) {
                    self::fail();
                }
            }
        }

        return $result;
    }

    private static function legacyCss(bool $system): string
    {
        $css = '<style data-rt-v30-css="v30-c-1">.v30 .rt-delivery-contact-value{line-height:19px!important;word-break:normal!important}.v30 .rt-company-contact{margin-top:0!important}@media(max-width:520px){.v30 .rt-v17-columns-cell{padding-left:20px!important;padding-right:20px!important}.v30 .rt-v17-identity{width:100%!important}.v30 .rt-personal-logo-frame{float:right!important;width:104px!important;margin:5px 0 0 12px!important}.v30 .rt-personal-logo-frame td{padding:0!important}.v30 .rt-v9-logo{width:104px!important;height:auto!important}.v30 .rt-v17-identity-cell{padding:0 0 14px!important}.v30 .rt-v17-direct{width:52%!important}.v30 .rt-v17-company{width:48%!important}.v30 .rt-v17-direct-cell{padding-right:4px!important}.v30 .rt-v17-company-cell{padding:0 0 0 4px!important}.v30 .rt-contact td,.v30 .rt-delivery-contact-value{font-size:12px!important;line-height:18px!important}.v30 .rt-v17-legal-cell{padding-left:20px!important;padding-right:20px!important}.v30 .rt-v17-template-pad{padding-left:20px!important;padding-right:20px!important}}</style>';
        if (! $system) {
            return $css;
        }

        return str_replace([
            '@media(max-width:520px)',
            '.v30 .rt-personal-logo-frame{float:right!important;width:104px!important;margin:5px 0 0 12px!important}',
            '.v30 .rt-personal-logo-frame td{padding:0!important}',
            '.v30 .rt-v9-logo{width:104px!important;height:auto!important}',
            '.v30 .rt-v17-direct{width:52%!important}',
            '.v30 .rt-v17-company{width:48%!important}',
            '.v30 .rt-v17-direct-cell{padding-right:4px!important}',
            '.v30 .rt-v17-company-cell{padding:0 0 0 4px!important}',
        ], [
            '@media (max-width:520px)',
            '.v30 .rt-personal-logo-frame{float:none!important;width:184px!important;margin:0!important}',
            '',
            '.v30 .rt-v9-logo{width:184px!important;height:auto!important}',
            '',
            '.v30 .rt-v17-company{width:100%!important}',
            '',
            '.v30 .rt-v17-company-cell{padding:0!important}',
        ], $css);
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
            if ($key === 'width' && isset($node['ranges'][$key])) {
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
        throw new RuntimeException('Die kompakte mobile Outlook-Ausgabe besitzt keinen eindeutigen Inline-Vertrag.');
    }
}
