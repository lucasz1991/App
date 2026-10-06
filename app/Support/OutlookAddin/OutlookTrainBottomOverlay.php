<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignatureArtifactVersion;
use App\Support\Mail\SignatureTableOverlapDelivery;
use RuntimeException;

/** Exact native-output projection only: real GIF overlay, unchanged Word flow. */
final class OutlookTrainBottomOverlay
{
    public const MARKER = 'intrinsic-img-v1';

    private const ATTRIBUTE = 'data-rt-train-bottom-overlay';

    private const ADDED = [
        'rt-sign-stage' => 'position:relative;z-index:0;',
        'rt-sign-content-frame' => 'position:relative;z-index:1;',
        'rt-delivery-train' => 'position:absolute;bottom:0;z-index:0;',
    ];

    public static function project(string $html): string
    {
        if (str_contains($html, self::ATTRIBUTE)) {
            self::assertRuntime($html);

            return $html;
        }
        if (! SignatureTableOverlapDelivery::applies($html)) {
            if (str_contains($html, 'data-rt-train-delivery="'.SignatureTableOverlapDelivery::MARKER.'"')) {
                self::fail();
            }

            return $html;
        }
        [$nodes, $targets, $cell, $branch, $mirrored] = self::contract($html, false);
        $output = self::mirrors($html, $nodes, $targets, $mirrored, false);
        // Only stylesheet lengths changed; re-read offsets before HTML cuts.
        [$nodes, $targets, $cell, $branch] = self::contract($output, false);
        $stage = $targets['rt-sign-stage'];
        $image = $targets['rt-delivery-train'];
        $moving = substr($output, $branch[0], $branch[1] - $branch[0]);
        $moving = str_replace($nodes[$image]['opening'], self::opening($nodes[$image], 'rt-delivery-train', $mirrored, false), $moving, $changed);
        if ($changed !== 1) {
            self::fail();
        }
        $ranges = [
            [$branch[0], $branch[1], ''],
            [$nodes[$stage]['close'], $nodes[$stage]['close'], $moving],
        ];
        foreach (['rt-sign-stage', 'rt-sign-content-frame'] as $class) {
            $node = $nodes[$targets[$class]];
            $ranges[] = [$node['start'], $node['openEnd'], self::opening($node, $class, $mirrored, false)];
        }
        $output = self::replace($output, $ranges);
        self::budget($output);
        if (self::restore($output) !== $html) {
            self::fail();
        }

        return $output;
    }

    public static function assertRuntime(string $html): void
    {
        $original = self::restore($html);
        if ($original === $html || self::project($original) !== $html) {
            self::fail();
        }
    }

    /** Exact inverse, mandatory before existing mobile/legacy shape adapters. */
    public static function restore(string $html): string
    {
        if (! str_contains($html, self::ATTRIBUTE)) {
            return $html;
        }
        [$nodes, $targets, $cell, $branch, $mirrored] = self::contract($html, true);
        $image = $targets['rt-delivery-train'];
        $moving = substr($html, $branch[0], $branch[1] - $branch[0]);
        $moving = str_replace($nodes[$image]['opening'], self::opening($nodes[$image], 'rt-delivery-train', $mirrored, true), $moving, $changed);
        if ($changed !== 1) {
            self::fail();
        }
        $ranges = [
            [$branch[0], $branch[1], ''],
            [$nodes[$cell]['openEnd'], $nodes[$cell]['openEnd'], $moving],
        ];
        foreach (['rt-sign-stage', 'rt-sign-content-frame'] as $class) {
            $node = $nodes[$targets[$class]];
            $ranges[] = [$node['start'], $node['openEnd'], self::opening($node, $class, $mirrored, true)];
        }
        $restored = self::replace($html, $ranges);
        [$nodes, $targets, , , $mirrored] = self::contract($restored, false);
        $restored = self::mirrors($restored, $nodes, $targets, $mirrored, true);
        SignatureTableOverlapDelivery::assertRuntime($restored);
        self::budget($restored);

        return $restored;
    }

    private static function contract(string $html, bool $projected): array
    {
        $nodes = self::nodes($html);
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        if (array_keys(array_filter($nodes, static fn (array $node): bool => $node['parent'] === null)) !== [$root]
            || preg_match('/(?:^|\s)rts[0-9a-f]{10}(?:\s|$)/', $nodes[$root]['attrs']['class'] ?? '') !== 1
            || ! SignatureTableOverlapDelivery::applies($html)) {
            self::fail();
        }
        $version = SignatureArtifactVersion::detect('signature', $html);
        $mirrored = SignatureArtifactVersion::usesMirroredTrain($version);
        $targets = [];
        foreach (['rt-sign-stage' => 'div', 'rt-sign-content-frame' => 'table', 'rt-delivery-train' => 'img'] as $class => $tag) {
            $targets[$class] = self::one($nodes, $class, $tag);
            $expected = self::declarations($class, $mirrored).($projected ? self::added($class, $mirrored) : '');
            if (($nodes[$targets[$class]]['attrs']['style'] ?? '') !== $expected) {
                self::fail();
            }
        }
        $stage = $targets['rt-sign-stage'];
        $frame = $targets['rt-sign-content-frame'];
        $image = $targets['rt-delivery-train'];
        $content = self::one($nodes, 'rt-sign-content', 'td');
        $cell = self::one($nodes, 'rt-delivery-train-cell', 'td');
        $row = self::one($nodes, 'rt-delivery-train-row', 'tr');
        $signCell = self::one($nodes, 'rt-sign-cell', 'td');
        $rows = self::rows($nodes, $frame);
        $marks = array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][self::ATTRIBUTE])));
        if ($nodes[$stage]['parent'] !== $signCell || $nodes[$frame]['parent'] !== $stage
            || count($rows) !== 2 || $rows[1] !== $row || $nodes[$rows[0]]['children'] !== [$content]
            || $nodes[$row]['children'] !== [$cell] || $nodes[$cell]['attrs']['width'] !== '100%'
            || $nodes[$image]['attrs']['width'] !== '300' || $nodes[$image]['attrs']['height'] !== '38'
            || ($projected ? $marks !== [$stage] || $nodes[$stage]['attrs'][self::ATTRIBUTE] !== self::MARKER : $marks !== [])
            || $nodes[$stage]['children'] !== ($projected ? [$frame, $image] : [$frame])
            || $nodes[$image]['parent'] !== ($projected ? $stage : $cell)) {
            self::fail();
        }
        $branch = '<!--[if !mso]><!-->'.$nodes[$image]['opening'].'<!--<![endif]-->';
        if (substr_count($html, $branch) !== 1) {
            self::fail();
        }
        $start = strpos($html, $branch);
        $end = $start + strlen($branch);
        if ($projected ? $start !== $nodes[$frame]['end'] || $end !== $nodes[$stage]['close'] : $start !== $nodes[$cell]['openEnd']) {
            self::fail();
        }
        $mso = substr($html, $projected ? $nodes[$cell]['openEnd'] : $end, $nodes[$cell]['close'] - ($projected ? $nodes[$cell]['openEnd'] : $end));
        if (preg_match('~\A<!--\[if mso\]><img class="rt-delivery-train-mso" src="[^"]+" width="300" height="38" alt="" style="[^"]+"><!\[endif\]-->\z~D', $mso) !== 1) {
            self::fail();
        }
        if (! $projected) {
            SignatureTableOverlapDelivery::assertRuntime($html);
        }

        return [$nodes, $targets, $cell, [$start, $end], $mirrored];
    }

    private static function declarations(string $class, bool $mirrored): string
    {
        return match ($class) {
            'rt-sign-stage' => 'display:block;width:100%;overflow:visible;',
            'rt-sign-content-frame' => 'width:100%;table-layout:fixed;border-collapse:collapse;direction:ltr;',
            'rt-delivery-train' => 'display:block;width:100%;max-width:600px;height:auto;margin:'.($mirrored ? '0 0 0 auto' : '0').';border:0;vertical-align:bottom;',
        };
    }

    private static function added(string $class, bool $mirrored): string
    {
        return self::ADDED[$class].($class === 'rt-delivery-train' ? ($mirrored ? 'right:0;left:auto;' : 'left:0;right:auto;') : '');
    }

    private static function opening(array $node, string $class, bool $mirrored, bool $restore): string
    {
        $old = self::declarations($class, $mirrored).($restore ? self::added($class, $mirrored) : '');
        $new = self::declarations($class, $mirrored).($restore ? '' : self::added($class, $mirrored));
        $tag = str_replace(' style="'.$old.'"', ' style="'.$new.'"', $node['opening'], $count);
        if ($count !== 1) {
            self::fail();
        }
        if ($class === 'rt-sign-stage') {
            $marker = ' '.self::ATTRIBUTE.'="'.self::MARKER.'"';
            $tag = $restore ? str_replace($marker, '', $tag, $count) : substr($tag, 0, -1).$marker.'>';
            if ($restore && $count !== 1) {
                self::fail();
            }
        }

        return $tag;
    }

    /** Project the existing trusted inline STYLE, not an optional new override. */
    private static function mirrors(string $html, array $nodes, array $targets, bool $mirrored, bool $restore): string
    {
        $root = $nodes[self::one($nodes, 'rt-outlook-signature', 'div')];
        preg_match('/(?:^|\s)(rts[0-9a-f]{10})(?:\s|$)/', $root['attrs']['class'], $scope);
        $opening = '<style '.OutlookSignatureInlineStyle::ATTRIBUTE.'="1">';
        if (substr_count($html, $opening) !== 1) {
            self::fail();
        }
        $start = strpos($html, $opening) + strlen($opening);
        $end = strpos($html, '</style>', $start);
        if ($end === false) {
            self::fail();
        }
        $css = substr($html, $start, $end - $start);
        foreach ($targets as $class => $index) {
            $aliases = array_values(array_filter(preg_split('/\s+/', $nodes[$index]['attrs']['class'] ?? ''), static fn (string $alias): bool => preg_match('/\Aoi[0-9a-z]+\z/', $alias) === 1));
            if (count($aliases) !== 1 || count(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, $aliases[0]))) !== 1) {
                self::fail();
            }
            $selector = '.'.$scope[1].'.'.$aliases[0].',.'.$scope[1].' .'.$aliases[0];
            $original = self::declarations($class, $mirrored);
            $projected = $original.self::added($class, $mirrored);
            $old = $selector.'{'.($restore ? $projected : $original).'}';
            $new = $selector.'{'.($restore ? $original : $projected).'}';
            if (substr_count($css, $selector.'{') !== 1 || substr_count($css, $old) !== 1) {
                self::fail();
            }
            $css = str_replace($old, $new, $css);
        }

        return substr($html, 0, $start).$css.substr($html, $end);
    }

    private static function budget(string $html): void
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        if (array_sum(array_map('strlen', $styles[1])) >= OutlookSignatureInlineStyle::MAX_CSS_BYTES
            || intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) > 30000) {
            throw new RuntimeException('Die begrenzte Zugprojektion ueberschreitet das unveraenderte native HTML-/CSS-Budget.');
        }
    }

    private static function rows(array $nodes, int $table): array
    {
        $children = $nodes[$table]['children'];
        if (count($children) === 1 && $nodes[$children[0]]['tag'] === 'tbody') {
            $children = $nodes[$children[0]]['children'];
        }
        if (array_filter($children, static fn (int $child): bool => $nodes[$child]['tag'] !== 'tr') !== []) {
            self::fail();
        }

        return $children;
    }

    private static function one(array $nodes, string $class, string $tag): int
    {
        $found = array_keys(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, $class)));
        if (count($found) !== 1 || $nodes[$found[0]]['tag'] !== $tag) {
            self::fail();
        }

        return $found[0];
    }

    private static function hasClass(array $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', $node['attrs']['class'] ?? ''), true);
    }

    /** Offset tree only: never reserialize media, CSS, text or MSO comments. */
    private static function nodes(string $html): array
    {
        $tag = '(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*';
        preg_match_all('~<!--.*?-->|<style\b'.$tag.'>.*?</style\s*>|</?[a-z][a-z0-9:-]*'.$tag.'>~is', $html, $tokens, PREG_OFFSET_CAPTURE);
        $nodes = [];
        $stack = [];
        foreach ($tokens[0] as [$token, $offset]) {
            if (str_starts_with($token, '<!--') || preg_match('~\A<style\b~i', $token)) {
                continue;
            }
            preg_match('~\A<(/?)([a-z][a-z0-9:-]*)\b~i', $token, $name);
            $element = strtolower($name[2]);
            if ($name[1] === '/') {
                $index = array_pop($stack);
                if ($index === null || $nodes[$index]['tag'] !== $element || preg_match('~\A</[a-z][a-z0-9:-]*\s*>\z~i', $token) !== 1) {
                    self::fail();
                }
                $nodes[$index]['close'] = $offset;
                $nodes[$index]['end'] = $offset + strlen($token);

                continue;
            }
            $void = in_array($element, ['br', 'hr', 'img', 'meta', 'link'], true);
            $attrs = [];
            preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $token, $attributes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($attributes as $attribute) {
                $key = strtolower($attribute[1]);
                if (isset($attrs[$key])) {
                    self::fail();
                }
                $attrs[$key] = CssSemantic::decodeHtmlEntitiesOnce($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '');
            }
            $parent = $stack === [] ? null : $stack[array_key_last($stack)];
            $index = count($nodes);
            $nodes[] = ['tag' => $element, 'attrs' => $attrs, 'parent' => $parent, 'children' => [], 'start' => $offset, 'openEnd' => $offset + strlen($token), 'opening' => $token, 'close' => $void ? $offset + strlen($token) : null, 'end' => $void ? $offset + strlen($token) : null];
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

    private static function replace(string $html, array $ranges): string
    {
        usort($ranges, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $last = strlen($html) + 1;
        foreach ($ranges as [$start, $end, $replacement]) {
            if ($end > $last || $start > $end) {
                self::fail();
            }
            $html = substr_replace($html, $replacement, $start, $end - $start);
            $last = $start;
        }

        return $html;
    }

    private static function fail(): never
    {
        throw new RuntimeException('Die native Zugprojektion besitzt keinen eindeutigen begrenzten IMG-/CSS-Vertrag.');
    }
}
