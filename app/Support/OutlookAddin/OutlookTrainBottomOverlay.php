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

    public const MOBILE_MARKER = 'intrinsic-mobile-img-v1';

    private const ATTRIBUTE = 'data-rt-train-bottom-overlay';

    private const STAGE_CLASS = 'rt-native-train-overlay';

    private const STAGE_WHITE_PREFIX = 'background-color:#ffffff!important;';

    private const ADDED = [
        'rt-sign-stage' => 'position:relative;z-index:0;',
        'rt-sign-content-frame' => 'position:relative;z-index:1;',
        'rt-delivery-train' => 'position:absolute;bottom:0;z-index:0;',
    ];

    public static function project(string $html): string
    {
        return self::projectVariant($html, false);
    }

    public static function projectMobile(string $html): string
    {
        if (str_contains($html, self::ATTRIBUTE)) {
            if (! str_contains($html, self::ATTRIBUTE.'="'.self::MOBILE_MARKER.'"')) {
                self::fail();
            }
            self::assertRuntime($html);

            return $html;
        }
        if (! str_contains($html, 'data-rt-outlook-mobile-css') && ! str_contains($html, 'rt-mobile-ledger')) {
            return $html;
        }

        return self::projectVariant($html, true);
    }

    private static function projectVariant(string $html, bool $mobile): string
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
        [$nodes, $targets, $cell, $branch, $mirrored] = self::contract($html, false, $mobile);
        $output = self::mirrors($html, $nodes, $targets, $mirrored, false, $mobile);
        // Only stylesheet lengths changed; re-read offsets before HTML cuts.
        [$nodes, $targets, $cell, $branch] = self::contract($output, false, $mobile);
        $stage = $targets['rt-sign-stage'];
        $image = $targets['rt-delivery-train'];
        $moving = substr($output, $branch[0], $branch[1] - $branch[0]);
        $moving = str_replace($nodes[$image]['opening'], self::opening($nodes[$image], 'rt-delivery-train', $mirrored, false, $mobile), $moving, $changed);
        if ($changed !== 1) {
            self::fail();
        }
        $ranges = [
            [$branch[0], $branch[1], ''],
            [$nodes[$stage]['close'], $nodes[$stage]['close'], $moving],
        ];
        foreach (['rt-sign-stage', 'rt-sign-content-frame'] as $class) {
            $node = $nodes[$targets[$class]];
            $ranges[] = [$node['start'], $node['openEnd'], self::opening($node, $class, $mirrored, false, $mobile)];
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
        $mobile = str_contains($html, self::ATTRIBUTE.'="'.self::MOBILE_MARKER.'"');
        if ($original === $html || self::projectVariant($original, $mobile) !== $html) {
            self::fail();
        }
    }

    /** Exact inverse, mandatory before existing mobile/legacy shape adapters. */
    public static function restore(string $html): string
    {
        if (! str_contains($html, self::ATTRIBUTE)) {
            return $html;
        }
        $mobile = str_contains($html, self::ATTRIBUTE.'="'.self::MOBILE_MARKER.'"');
        [$nodes, $targets, $cell, $branch, $mirrored] = self::contract($html, true, $mobile);
        $image = $targets['rt-delivery-train'];
        $moving = substr($html, $branch[0], $branch[1] - $branch[0]);
        $moving = str_replace($nodes[$image]['opening'], self::opening($nodes[$image], 'rt-delivery-train', $mirrored, true, $mobile), $moving, $changed);
        if ($changed !== 1) {
            self::fail();
        }
        $ranges = [
            [$branch[0], $branch[1], ''],
            [$nodes[$cell]['openEnd'], $nodes[$cell]['openEnd'], $moving],
        ];
        foreach (['rt-sign-stage', 'rt-sign-content-frame'] as $class) {
            $node = $nodes[$targets[$class]];
            $ranges[] = [$node['start'], $node['openEnd'], self::opening($node, $class, $mirrored, true, $mobile)];
        }
        $restored = self::replace($html, $ranges);
        [$nodes, $targets, , , $mirrored] = self::contract($restored, false, $mobile);
        $restored = self::mirrors($restored, $nodes, $targets, $mirrored, true, $mobile);
        SignatureTableOverlapDelivery::assertRuntime($restored);
        self::budget($restored);

        return $restored;
    }

    private static function contract(string $html, bool $projected, bool $mobile = false): array
    {
        $nodes = self::nodes($html);
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        if (array_keys(array_filter($nodes, static fn (array $node): bool => $node['parent'] === null)) !== [$root]
            || preg_match_all('/(?:^|\s)(rts[0-9a-f]{10})(?=\s|$)/', $nodes[$root]['attrs']['class'] ?? '', $scopes) !== 1
            || ! SignatureTableOverlapDelivery::applies($html)) {
            self::fail();
        }
        // The projected stage is always a proper descendant, never the rts
        // root itself or a second node carrying that root's scope class.
        if (self::one($nodes, $scopes[1][0], 'div') !== $root) {
            self::fail();
        }
        $version = SignatureArtifactVersion::detect('signature', $html);
        if ($mobile) {
            self::mobileScope($html, $nodes, $root);
        }
        $mirrored = SignatureArtifactVersion::usesMirroredTrain($version);
        $targets = [];
        foreach (['rt-sign-stage' => 'div', 'rt-sign-content-frame' => 'table', 'rt-delivery-train' => 'img'] as $class => $tag) {
            $targets[$class] = self::one($nodes, $class, $tag);
            $expected = self::declarations($class, $mirrored).($projected ? self::added($class, $mirrored) : '');
            if ($class === 'rt-sign-stage') {
                $expected = self::stagePrefix($nodes[$targets[$class]]).$expected;
            }
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
        $overlays = array_keys(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, self::STAGE_CLASS)));
        $stageChildren = [$frame];
        $hotlines = array_keys(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, 'rt-hotline-banner')));
        if ($hotlines !== []) {
            if (count($hotlines) !== 1 || $nodes[$hotlines[0]]['tag'] !== 'table' || $nodes[$hotlines[0]]['parent'] !== $stage) {
                self::fail();
            }
            array_unshift($stageChildren, $hotlines[0]);
        }
        if ($projected) {
            $stageChildren[] = $image;
        }
        if ($nodes[$stage]['parent'] !== $signCell || $nodes[$frame]['parent'] !== $stage
            || count($rows) !== 2 || $rows[1] !== $row || $nodes[$rows[0]]['children'] !== [$content]
            || $nodes[$row]['children'] !== [$cell] || $nodes[$cell]['attrs']['width'] !== '100%'
            || $nodes[$image]['attrs']['width'] !== '300' || $nodes[$image]['attrs']['height'] !== '38'
            || ($projected ? $marks !== [$stage] || $nodes[$stage]['attrs'][self::ATTRIBUTE] !== ($mobile ? self::MOBILE_MARKER : self::MARKER) : $marks !== [])
            || $overlays !== ($projected ? [$stage] : [])
            || $nodes[$stage]['children'] !== $stageChildren
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

    private static function opening(array $node, string $class, bool $mirrored, bool $restore, bool $mobile = false): string
    {
        $prefix = $class === 'rt-sign-stage' ? self::stagePrefix($node) : '';
        $old = $prefix.self::declarations($class, $mirrored).($restore ? self::added($class, $mirrored) : '');
        $new = $prefix.self::declarations($class, $mirrored).($restore ? '' : self::added($class, $mirrored));
        $tag = str_replace(' style="'.$old.'"', ' style="'.$new.'"', $node['opening'], $count);
        if ($count !== 1) {
            self::fail();
        }
        if ($class === 'rt-sign-stage') {
            $marker = ' '.self::ATTRIBUTE.'="'.($mobile ? self::MOBILE_MARKER : self::MARKER).'"';
            $tag = $restore ? str_replace($marker, '', $tag, $count) : substr($tag, 0, -1).$marker.'>';
            if ($restore && $count !== 1) {
                self::fail();
            }
            $classes = $node['attrs']['class'] ?? '';
            if ($restore) {
                $replacement = str_replace(' '.self::STAGE_CLASS, '', $classes, $count);
                if ($count !== 1 || ! str_ends_with($classes, ' '.self::STAGE_CLASS)) {
                    self::fail();
                }
            } else {
                $replacement = $classes.' '.self::STAGE_CLASS;
            }
            $tag = str_replace(' class="'.$classes.'"', ' class="'.$replacement.'"', $tag, $count);
            if ($count !== 1) {
                self::fail();
            }
        }

        return $tag;
    }

    /** Only the proved published white carrier prefix, never generic CSS. */
    private static function stagePrefix(array $node): string
    {
        return str_starts_with($node['attrs']['style'] ?? '', self::STAGE_WHITE_PREFIX) ? self::STAGE_WHITE_PREFIX : '';
    }

    /** Project the existing trusted inline STYLE, not an optional new override. */
    private static function mirrors(string $html, array $nodes, array $targets, bool $mirrored, bool $restore, bool $mobile = false): string
    {
        if ($mobile) {
            return self::mobileMirrors($html, $nodes, $targets, $restore);
        }
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
            // Identical source scopes can occur in quoted older signatures.
            // The output-only stage class keeps these new declarations local
            // even if Office drops data attributes but preserves classes/CSS.
            // contract() proves that the root rts class occurs only on the
            // outer root, while STAGE_CLASS occurs only on its child stage.
            // A root.rts.STAGE_CLASS branch can therefore never match.
            $projectedSelector = $class === 'rt-sign-stage'
                ? '.'.$scope[1].' .'.self::STAGE_CLASS.'.'.$aliases[0]
                : '.'.$scope[1].' .'.self::STAGE_CLASS.' .'.$aliases[0];
            $original = self::declarations($class, $mirrored);
            if ($class === 'rt-sign-stage' && self::stagePrefix($nodes[$index]) !== '') {
                $currentSelector = $restore ? $projectedSelector : $selector;
                $currentSuffix = $original.($restore ? self::added($class, $mirrored) : '');
                // Desktop mirrors may retain importance or contain only its
                // exact normalized white declaration. Preserve the present
                // form byte-for-byte through the projection and inverse.
                $whiteRule = $currentSelector.'{'.self::STAGE_WHITE_PREFIX.$currentSuffix.'}';
                $normalizedRule = $currentSelector.'{background-color:#ffffff;'.$currentSuffix.'}';
                if (substr_count($css, $whiteRule) === 1) {
                    $original = self::STAGE_WHITE_PREFIX.$original;
                } elseif (substr_count($css, $normalizedRule) === 1) {
                    $original = 'background-color:#ffffff;'.$original;
                } else {
                    self::fail();
                }
            }
            $projected = $original.self::added($class, $mirrored);
            $old = ($restore ? $projectedSelector : $selector).'{'.($restore ? $projected : $original).'}';
            $new = ($restore ? $selector : $projectedSelector).'{'.($restore ? $original : $projected).'}';
            if (substr_count($css, ($restore ? $projectedSelector : $selector).'{') !== 1 || substr_count($css, $old) !== 1
                || substr_count($css, ($restore ? $selector : $projectedSelector).'{') !== 0) {
                self::fail();
            }
            $css = str_replace($old, $new, $css);
        }

        return substr($html, 0, $start).$css.substr($html, $end);
    }

    /** The established mobile compiler skips the stage/frame but mirrors IMG. */
    private static function mobileMirrors(string $html, array $nodes, array $targets, bool $restore): string
    {
        $scope = self::mobileScope($html, $nodes, self::one($nodes, 'rt-outlook-signature', 'div'));
        $opening = '<style data-rt-outlook-mobile-css="1">';
        if (substr_count($html, $opening) !== 1 || str_contains($html, OutlookSignatureInlineStyle::ATTRIBUTE)) {
            self::fail();
        }
        $start = strpos($html, $opening) + strlen($opening);
        $end = strpos($html, '</style>', $start);
        if ($end === false) {
            self::fail();
        }
        $css = substr($html, $start, $end - $start);
        $image = $targets['rt-delivery-train'];
        $aliases = array_values(array_filter(preg_split('/\s+/', $nodes[$image]['attrs']['class'] ?? ''), static fn (string $alias): bool => preg_match('/\Am[0-9a-z]+\z/', $alias) === 1));
        if (count($aliases) !== 1 || count(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, $aliases[0]))) !== 1) {
            self::fail();
        }
        $selector = '.'.$scope.'.rtm .'.$aliases[0];
        $projectedSelector = '.'.$scope.'.rtm .'.self::STAGE_CLASS.' .'.$aliases[0];
        $original = self::declarations('rt-delivery-train', false);
        $projected = $original.self::added('rt-delivery-train', false);
        $old = ($restore ? $projectedSelector : $selector).'{'.self::important($restore ? $projected : $original).'}';
        $new = ($restore ? $selector : $projectedSelector).'{'.self::important($restore ? $original : $projected).'}';
        if (substr_count($css, ($restore ? $projectedSelector : $selector).'{') !== 1 || substr_count($css, $old) !== 1
            || substr_count($css, ($restore ? $selector : $projectedSelector).'{') !== 0) {
            self::fail();
        }
        $css = str_replace($old, $new, $css);
        $critical = '';
        foreach (['rt-sign-stage', 'rt-sign-content-frame'] as $class) {
            if (str_contains($css, '.'.$scope.'.rtm .'.$class.'{')) {
                self::fail();
            }
            $roleSelector = '.'.$scope.'.rtm .'.self::STAGE_CLASS.($class === 'rt-sign-stage' ? '' : ' .'.$class);
            $rule = $roleSelector.'{'.self::important(self::added($class, false)).'}';
            if (substr_count($css, $roleSelector.'{') !== ($restore ? 1 : 0)) {
                self::fail();
            }
            $critical .= $rule;
        }
        if ($restore) {
            if (! str_ends_with($css, $critical)) {
                self::fail();
            }
            $css = substr($css, 0, -strlen($critical));
        } else {
            $css .= $critical;
        }

        return substr($html, 0, $start).$css.substr($html, $end);
    }

    /** The canonical physical mobile rows and content-derived 40-bit scope. */
    private static function mobileScope(string $html, array $nodes, int $root): string
    {
        $classes = preg_split('/\s+/', $nodes[$root]['attrs']['class'] ?? '');
        preg_match('/(?:^|\s)(rts[0-9a-f]{10})(?:\s|$)/', $nodes[$root]['attrs']['class'] ?? '', $signatureScope);
        $scope = isset($signatureScope[1]) ? 'm'.base_convert(substr($signatureScope[1], 3), 16, 36) : '';
        $scopes = array_values(array_filter($classes, static fn (string $class): bool => preg_match('/\Am[0-9a-z]+\z/', $class) === 1));
        $ledger = self::one($nodes, 'rt-sign-ledger', 'table');
        $rows = self::rows($nodes, $ledger);
        if ($scopes !== [$scope] || ! in_array('rt-mobile-ledger', $classes, true) || ! in_array('rtm', $classes, true)
            || SignatureArtifactVersion::detect('signature', $html) !== 'v27' || count($rows) !== 2) {
            self::fail();
        }
        foreach (['rt-ledger-brand', 'rt-ledger-contacts'] as $index => $class) {
            $cell = self::one($nodes, $class, 'td');
            if ($nodes[$rows[$index]]['children'] !== [$cell] || ($nodes[$cell]['attrs']['width'] ?? '') !== '100%') {
                self::fail();
            }
        }

        return $scope;
    }

    private static function important(string $declarations): string
    {
        return str_replace(';', '!important;', rtrim($declarations, ';')).'!important;';
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
