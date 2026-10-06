<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Native-compose projection only; never persisted into the authoring document. */
final class OutlookComposeFrame
{
    /** @return array{html: string, css: string} */
    public static function apply(string $html, string $scope): array
    {
        if (! preg_match('~\bclass\s*=\s*(["\'])[^"\']*\bdesign-v27\b[^"\']*\1~i', $html)) {
            return ['html' => $html, 'css' => ''];
        }
        if (preg_match('/\Artt[0-9a-f]{12}\z/', $scope) !== 1
            || str_contains($html, 'rt-native-compose-frame')) {
            throw new RuntimeException('Der native Vorlagenrahmen besitzt keinen eindeutigen Scope.');
        }
        [$nodes, $frame, $canvas, $inner, $pads, $mark] = self::targets($html, $scope);
        $replacements = [
            $frame => self::style(self::className($nodes[$frame]['opening'], 'rt-native-compose-frame'), 'width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;box-sizing:border-box;background-color:#ffffff;border-left:6px solid #e90032;mso-table-lspace:0pt;mso-table-rspace:0pt;'),
            $canvas => self::style($nodes[$canvas]['opening'], 'background-color:#ffffff;padding:0;'),
            $inner => self::style(self::className($nodes[$inner]['opening'], 'rt-native-compose-inner'), 'display:block;width:auto;background-color:#ffffff;'),
            $mark => self::padding(self::className($nodes[$mark]['opening'], 'rt-native-template-mark'), left: 0),
        ];
        foreach ($pads as $pad) {
            $replacements[$pad] = self::padding(self::className($nodes[$pad]['opening'], 'rt-native-template-pad'), left: 40, right: 40);
        }
        // Offsets are from the unmodified input. Only selected opening tags
        // change; normal IMG, opaque MSO comments and all other bytes survive.
        krsort($replacements);
        $output = $html;
        foreach ($replacements as $index => $tag) {
            $node = $nodes[$index];
            $output = substr($output, 0, $node['start']).$tag.substr($output, $node['start'] + strlen($node['opening']));
        }

        return ['html' => $output, 'css' => '.'.$scope.' .rt-native-compose-frame{width:100%!important;border-collapse:separate!important;border-spacing:0!important;table-layout:fixed!important;box-sizing:border-box!important;background-color:#ffffff!important;border-left:6px solid #e90032!important;}'
            .'.'.$scope.' .design-page-pad{padding:0!important;}'
            .'.'.$scope.' .rt-native-compose-inner{display:block!important;width:auto!important;border:0!important;background-color:#ffffff!important;}'
            .'.'.$scope.' .rt-native-template-pad{padding-left:40px!important;padding-right:40px!important;}'
            .'.'.$scope.' .rt-native-template-mark{padding-left:0!important;}'
            .'@media(max-width:860px){.'.$scope.' .rt-native-template-pad{padding-left:22px!important;padding-right:22px!important;}}'];
    }

    private static function targets(string $html, string $scope): array
    {
        $nodes = self::nodes($html);
        $root = self::one($nodes, 'rt-outlook-template', 'div');
        $canvas = self::one($nodes, 'design-page-pad', 'td');
        $shell = self::one($nodes, 'design-v27', 'table');
        $markImage = self::one($nodes, 'rt-mark', 'img');
        $tables = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'table' && $node['parent'] === $root));
        if ($nodes[$root]['parent'] !== null || ! self::hasClass($nodes[$root], $scope) || count($tables) !== 1
            || ! self::within($nodes, $canvas, $tables[0])) {
            self::fail();
        }
        $frame = $tables[0];
        $inner = $nodes[$shell]['parent'];
        if ($inner === null || $nodes[$inner]['tag'] !== 'div' || $nodes[$inner]['parent'] !== $canvas
            || preg_match('/(?:^|;)\s*border-left\s*:\s*6px\s+solid\s+#e90032\s*(?:;|$)/i', $nodes[$inner]['attrs']['style'] ?? '') !== 1
            || ! self::hasClass($nodes[$shell], 'rt-shell')) {
            self::fail();
        }
        $accents = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'div'
            && preg_match('/(?:^|;)\s*border-left\s*:\s*6px\s+solid\s+#e90032\s*(?:;|$)/i', $node['attrs']['style'] ?? '') === 1));
        if ($accents !== [$inner]) {
            self::fail();
        }
        foreach ($nodes as $node) {
            if (isset($node['attrs']['data-rt-artifact-version']) || self::hasClass($node, 'rt-sign-cell') || self::hasClass($node, 'rt-outlook-signature')) {
                self::fail();
            }
        }
        $pads = [];
        foreach ($nodes as $index => $node) {
            $row = $node['parent'];
            $parent = $row === null ? null : $nodes[$row]['parent'];
            if ($node['tag'] === 'td' && self::hasClass($node, 'rt-pad') && $row !== null && $nodes[$row]['tag'] === 'tr'
                && ($parent === $shell || ($parent !== null && $nodes[$parent]['tag'] === 'tbody' && $nodes[$parent]['parent'] === $shell))) {
                $pads[] = $index;
            }
        }
        $mark = $nodes[$markImage]['parent'];
        if ($pads === [] || $mark === null || $nodes[$mark]['tag'] !== 'td' || ($nodes[$mark]['attrs']['width'] ?? '') !== '44'
            || ($nodes[$markImage]['attrs']['width'] ?? '') !== '44' || ($nodes[$markImage]['attrs']['height'] ?? '') !== '44') {
            self::fail();
        }
        foreach (['font-size', 'line-height'] as $property) {
            if (preg_match('/(?:^|;)\s*'.$property.'\s*:\s*0(?:px|pt)?\s*(?:!important\s*)?(?:;|$)/i', $nodes[$mark]['attrs']['style'] ?? '') !== 1) {
                self::fail();
            }
        }
        if (! self::within($nodes, $mark, $pads[0])) {
            self::fail();
        }
        for ($ancestor = $nodes[$mark]['parent']; $ancestor !== $pads[0]; $ancestor = $nodes[$ancestor]['parent']) {
            if ($ancestor === null || $nodes[$ancestor]['tag'] === 'blockquote'
                || in_array(strtolower($nodes[$ancestor]['attrs']['id'] ?? ''), ['divrplyfwdmsg', 'x_divrplyfwdmsg', 'mail-editor-reference-message-container'], true)) {
                self::fail();
            }
        }
        preg_match_all('/<!--\s*RT_TEMPLATE_MARK_START\s*-->/', $html, $starts, PREG_OFFSET_CAPTURE);
        preg_match_all('/<!--\s*RT_TEMPLATE_MARK_END\s*-->/', $html, $ends, PREG_OFFSET_CAPTURE);
        if (count($starts[0]) !== 1 || count($ends[0]) !== 1 || $starts[0][0][1] >= $nodes[$markImage]['start']
            || $ends[0][0][1] <= $nodes[$markImage]['start']) {
            self::fail();
        }
        $region = substr($html, $starts[0][0][1], $ends[0][0][1] - $starts[0][0][1]);
        preg_match_all('~<!--\[if mso\]>(.*?)<!\[endif\]-->~is', $region, $fallbacks);
        if (count($fallbacks[1]) !== 1) {
            self::fail();
        }
        $fallback = self::nodes($fallbacks[1][0]);
        if (count($fallback) !== 1 || $fallback[0]['tag'] !== 'img' || ! self::hasClass($fallback[0], 'rt-mark')
            || ($fallback[0]['attrs']['width'] ?? '') !== '44' || ($fallback[0]['attrs']['height'] ?? '') !== '44') {
            self::fail();
        }

        return [$nodes, $frame, $canvas, $inner, $pads, $mark];
    }

    /** Offset-only tree: quoted attributes, complete comments and styles are opaque. */
    private static function nodes(string $html): array
    {
        $quoted = '(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*';
        preg_match_all('~<!--.*?-->|<style\b'.$quoted.'>.*?</style\s*>|</?[a-z][a-z0-9:-]*'.$quoted.'>~is', $html, $tokens, PREG_OFFSET_CAPTURE);
        $nodes = [];
        $stack = [];
        foreach ($tokens[0] as [$tag, $offset]) {
            if (str_starts_with($tag, '<!--') || preg_match('~\A<style\b~i', $tag)) {
                continue;
            }
            preg_match('~\A<(/?)([a-z][a-z0-9:-]*)\b~i', $tag, $name);
            $element = strtolower($name[2]);
            if ($name[1] === '/') {
                $index = array_pop($stack);
                if ($index === null || $nodes[$index]['tag'] !== $element || preg_match('~\A</[a-z][a-z0-9:-]*\s*>\z~i', $tag) !== 1) {
                    self::fail();
                }

                continue;
            }
            $attrs = [];
            preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $tag, $attributes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($attributes as $attribute) {
                $key = strtolower($attribute[1]);
                if (isset($attrs[$key])) {
                    self::fail();
                }
                $attrs[$key] = CssSemantic::decodeHtmlEntitiesOnce($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '');
            }
            $parent = $stack === [] ? null : $stack[array_key_last($stack)];
            $index = count($nodes);
            $void = in_array($element, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true);
            if (! $void && preg_match('~/\s*>\z~', $tag)) {
                self::fail();
            }
            $nodes[] = ['tag' => $element, 'attrs' => $attrs, 'parent' => $parent, 'start' => $offset, 'opening' => $tag];
            if (! $void) {
                $stack[] = $index;
            }
        }
        if ($stack !== []) {
            self::fail();
        }

        return $nodes;
    }

    private static function one(array $nodes, string $class, string $tag): int
    {
        $matches = array_keys(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, $class)));
        if (count($matches) !== 1 || $nodes[$matches[0]]['tag'] !== $tag) {
            self::fail();
        }

        return $matches[0];
    }

    private static function hasClass(array $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($node['attrs']['class'] ?? '')), true);
    }

    private static function within(array $nodes, int $child, int $ancestor): bool
    {
        for ($parent = $nodes[$child]['parent']; $parent !== null; $parent = $nodes[$parent]['parent']) {
            if ($parent === $ancestor) {
                return true;
            }
        }

        return false;
    }

    private static function padding(string $tag, int $left, ?int $right = null): string
    {
        preg_match('~\sstyle\s*=\s*(["\'])(.*?)\1~is', $tag, $match);
        $old = $match[2] ?? '';
        // Generated canonical TD styles use ordinary declaration boundaries.
        // Leave quoted values intact; only real horizontal declarations change.
        $parts = preg_split('~;(?=(?:[^\'\"]|\'[^\']*\'|"[^"]*")*$)~s', $old);
        $parts = array_filter($parts, static fn (string $part): bool => preg_match('/\A\s*padding-(?:left'.($right === null ? '' : '|right').')\s*:/i', $part) !== 1);
        $style = implode(';', $parts);
        $style = preg_replace('/(^|;)(\s*padding\s*:[^;]*?)\s*!important\s*(?=;|$)/i', '$1$2', $style);
        $style = rtrim($style, "; \t\r\n").';padding-left:'.$left.'px;';
        if ($right !== null) {
            $style .= 'padding-right:'.$right.'px;';
        }

        return self::style($tag, $style);
    }

    private static function fail(): never
    {
        throw new RuntimeException('Die native V27-Vorlage besitzt keinen eindeutigen Canvas-/Akzent-/Markenvertrag.');
    }

    private static function style(string $tag, string $value): string
    {
        $count = 0;
        $tag = preg_replace_callback('~\sstyle\s*=\s*(["\'])(.*?)\1~is', static fn (array $match): string => ' style='.$match[1].$value.$match[1], $tag, -1, $count);
        if ($count > 1) {
            throw new RuntimeException('Der native Vorlagenrahmen besitzt doppelte Stilattribute.');
        }

        return $count === 1 ? $tag : preg_replace('~\s*/?>\z~', ' style="'.$value.'"$0', $tag);
    }

    private static function className(string $tag, string $class): string
    {
        $count = 0;
        $tag = preg_replace_callback('~\sclass\s*=\s*(["\'])(.*?)\1~is', static fn (array $m): string => ' class='.$m[1].$m[2].' '.$class.$m[1], $tag, -1, $count);
        if ($count > 1) {
            throw new RuntimeException('Der native Vorlagenrahmen besitzt doppelte Klassenattribute.');
        }

        return $count === 1 ? $tag : preg_replace('~\s*/?>\z~', ' class="'.$class.'"$0', $tag);
    }
}
