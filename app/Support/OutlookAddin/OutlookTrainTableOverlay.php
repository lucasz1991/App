<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignatureArtifactVersion;
use App\Support\Mail\SignatureTableOverlapDelivery;
use RuntimeException;

/**
 * Unwired native-output experiment: real same-row IMG, exact original Word flow.
 * No authoring, foreground mask, template inset or production entry point.
 */
final class OutlookTrainTableOverlay
{
    public const MARKER = 'table-img-v1';

    public const MOBILE_MARKER = 'table-mobile-img-v1';

    public const MAX_CHARACTERS = 30000;

    public const MAX_CSS_BYTES = 12288;

    private const ATTRIBUTE = 'data-rt-train-table-overlay';

    private const WORD_ATTRIBUTE = 'data-rt-train-word-shape';

    private const STAGE = 'tt';

    private const CARRIER = 'rt-tt';

    private const CONTENT = 'rt-tc';

    private const NORMAL_START = '<!--[if !mso]><!-->';

    private const NORMAL_END = '<!--<![endif]-->';

    private const MSO_START = '<!--[if mso]>';

    private const MSO_END = '<![endif]-->';

    private const IMAGE_STYLE = 'display:block;width:100%;max-width:600px;height:auto;margin:0;border:0;vertical-align:bottom;';

    private const CELL_STYLE = 'width:100%;padding:0;font-size:0;line-height:0;vertical-align:bottom;';

    private const MSO_IMAGE_STYLE = 'display:block;width:300px;max-width:300px;height:38px;margin:0;border:0;vertical-align:bottom;';

    private const SLOT = '<td class="rt-tt" width="1%" valign="bottom" style="width:1%;padding:0;font-size:0;line-height:0;vertical-align:bottom;">';

    public static function project(string $html): string
    {
        return self::projectVariant($html, false);
    }

    public static function projectMobile(string $html): string
    {
        return self::projectVariant($html, true);
    }

    private static function projectVariant(string $html, bool $mobile): string
    {
        if (str_contains($html, self::ATTRIBUTE)) {
            if (! str_contains($html, self::ATTRIBUTE.'="'.($mobile ? self::MOBILE_MARKER : self::MARKER).'"')) {
                self::fail();
            }
            self::assertRuntime($html);

            return $html;
        }
        // Existing cached intrinsic documents must pass their existing exact
        // inverse; never reinterpret them as a new table topology.
        $original = OutlookTrainBottomOverlay::restore($html);
        if (! SignatureTableOverlapDelivery::applies($original)) {
            if (str_contains($original, 'data-rt-train-delivery="'.SignatureTableOverlapDelivery::MARKER.'"')) {
                self::fail();
            }

            return $html;
        }
        // Mirrored historical versions have a different right-bottom axis.
        // This opt-in implementation does not silently change that geometry.
        if (SignatureArtifactVersion::detect('signature', $original) !== 'v27') {
            return $html;
        }
        self::validateBase($original, $mobile);
        $context = self::baseContext($original, $mobile);
        $nodes = $context['nodes'];
        $content = $nodes[$context['content']];
        $image = $nodes[$context['image']];
        $stage = $nodes[$context['stage']];
        $output = self::mirrors($original, $context, false);
        // CSS lengths changed; offsets must be recomputed before HTML cuts.
        $context = self::baseContext($output, $mobile);
        $nodes = $context['nodes'];
        $content = $nodes[$context['content']];
        $image = $nodes[$context['image']];
        $stage = $nodes[$context['stage']];
        $imageOpening = self::imageOpening($image['opening'], false);
        $prefix = self::NORMAL_START.self::SLOT.$imageOpening.'</td>'
            .self::contentOpening($content['opening'], false).self::NORMAL_END
            .self::MSO_START.$content['opening'].self::MSO_END;
        $flow = self::MSO_START.$context['rowOpening'].$context['cellOpening'].self::MSO_END
            .$context['mso'].self::MSO_START.'</td></tr>'.self::MSO_END;
        $output = self::replace($output, [
            [$content['start'], $content['openEnd'], $prefix],
            [$nodes[$context['flow']]['start'], $nodes[$context['flow']]['end'], $flow],
            [$stage['start'], $stage['openEnd'], self::stageOpening($stage['opening'], $mobile, false, $context['wordShape'])],
        ]);
        self::budget($output);
        if (self::restore($output) !== $original) {
            self::fail();
        }

        return $output;
    }

    public static function assertRuntime(string $html): void
    {
        if (! str_contains($html, self::ATTRIBUTE)) {
            self::fail();
        }
        $mobile = str_contains($html, self::ATTRIBUTE.'="'.self::MOBILE_MARKER.'"');
        $original = self::restore($html);
        if ($original === $html || self::projectVariant($original, $mobile) !== $html) {
            self::fail();
        }
    }

    /** Exact new inverse, or the existing cached intrinsic inverse. */
    public static function restore(string $html): string
    {
        if (! str_contains($html, self::ATTRIBUTE)) {
            return OutlookTrainBottomOverlay::restore($html);
        }
        self::budget($html);
        $nodes = self::nodes($html);
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        $stage = self::one($nodes, self::STAGE, 'div');
        $frame = self::one($nodes, 'rt-sign-content-frame', 'table');
        $content = self::one($nodes, 'rt-sign-content', 'td');
        $carrier = self::one($nodes, self::CARRIER, 'td');
        $image = self::one($nodes, 'rt-delivery-train', 'img');
        $mark = $nodes[$stage]['attrs'][self::ATTRIBUTE] ?? '';
        $wordShape = $nodes[$stage]['attrs'][self::WORD_ATTRIBUTE] ?? '';
        $mobile = $mark === self::MOBILE_MARKER;
        if (! in_array($mark, [self::MARKER, self::MOBILE_MARKER], true)
            || $nodes[$root]['parent'] !== null || ! self::hasClass($nodes[$stage], 'rt-sign-stage')
            || $nodes[$frame]['parent'] !== $stage || $nodes[$image]['parent'] !== $carrier
            || self::one($nodes, self::CONTENT, 'td') !== $content
            || count(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][self::ATTRIBUTE]))) !== 1
            || count(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][self::WORD_ATTRIBUTE]))) !== 1
            || preg_match('/\A[0-9a-f]{16}\z/', $wordShape) !== 1
            || count(array_filter($nodes, static fn (array $node): bool => $node['parent'] === null)) !== 1) {
            self::fail();
        }
        $rows = self::rows($nodes, $frame);
        if (count($rows) !== 1 || $nodes[$rows[0]]['children'] !== [$carrier, $content]
            || $nodes[$carrier]['opening'] !== self::SLOT || $nodes[$carrier]['children'] !== [$image]
            || substr($html, $nodes[$carrier]['close'], $nodes[$carrier]['end'] - $nodes[$carrier]['close']) !== '</td>') {
            self::fail();
        }
        $imageOpening = self::imageOpening($nodes[$image]['opening'], true);
        $afterOpening = substr($html, $nodes[$content]['openEnd']);
        $tag = '(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*';
        if (preg_match('~\A'.preg_quote(self::NORMAL_END.self::MSO_START, '~').'(<td\b'.$tag.'>)'.preg_quote(self::MSO_END, '~').'~is', $afterOpening, $originalCell) !== 1) {
            self::fail();
        }
        $originalOpening = $originalCell[1];
        if (self::contentOpening($originalOpening, false) !== $nodes[$content]['opening']) {
            self::fail();
        }
        $prefix = self::NORMAL_START.self::SLOT.$nodes[$image]['opening'].'</td>'
            .$nodes[$content]['opening'].$originalCell[0];
        $prefixStart = $nodes[$carrier]['start'] - strlen(self::NORMAL_START);
        if (substr($html, $prefixStart, strlen($prefix)) !== $prefix
            || $prefixStart < $nodes[$rows[0]]['openEnd']
            || trim(substr($html, $nodes[$rows[0]]['openEnd'], $prefixStart - $nodes[$rows[0]]['openEnd'])) !== '') {
            self::fail();
        }
        $flowPattern = '~'.preg_quote(self::MSO_START, '~').'(<tr\b'.$tag.'>)(<td\b'.$tag.'>)'.preg_quote(self::MSO_END, '~')
            .'('.preg_quote(self::MSO_START, '~').'<img\b'.$tag.'>'.preg_quote(self::MSO_END, '~').')'
            .preg_quote(self::MSO_START.'</td></tr>'.self::MSO_END, '~').'~is';
        if (preg_match_all($flowPattern, $html, $flowMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) !== 1) {
            self::fail();
        }
        $flow = $flowMatches[0];
        if (! in_array('rt-delivery-train-row', preg_split('/\s+/', self::attributes($flow[1][0])['class'] ?? ''), true)
            || ! in_array('rt-delivery-train-cell', preg_split('/\s+/', self::attributes($flow[2][0])['class'] ?? ''), true)
            || $flow[0][1] < $nodes[$rows[0]]['end']
            || trim(substr($html, $nodes[$rows[0]]['end'], $flow[0][1] - $nodes[$rows[0]]['end'])) !== '') {
            self::fail();
        }
        if (! hash_equals($wordShape, self::wordShape($originalOpening, $flow[1][0], $flow[2][0], $flow[3][0]))) {
            self::fail();
        }
        self::frameEdges($html, $nodes, $frame, $rows[0], $content, $flow[0][1], $flow[0][1] + strlen($flow[0][0]));
        $restoredFlow = $flow[1][0].$flow[2][0].self::NORMAL_START.$imageOpening.self::NORMAL_END.$flow[3][0].'</td></tr>';
        $wordBefore = self::wordView(substr($html, $nodes[$frame]['start'], $nodes[$frame]['end'] - $nodes[$frame]['start']));
        $restored = self::replace($html, [
            [$prefixStart, $prefixStart + strlen($prefix), $originalOpening],
            [$flow[0][1], $flow[0][1] + strlen($flow[0][0]), $restoredFlow],
            [$nodes[$stage]['start'], $nodes[$stage]['openEnd'], self::stageOpening($nodes[$stage]['opening'], $mobile, true, $wordShape)],
        ]);
        $context = self::baseContext($restored, $mobile);
        $frameNode = $context['nodes'][$context['frame']];
        if (self::wordView(substr($restored, $frameNode['start'], $frameNode['end'] - $frameNode['start'])) !== $wordBefore) {
            self::fail();
        }
        $restored = self::mirrors($restored, $context, true);
        self::validateBase($restored, $mobile);
        self::budget($restored);

        return $restored;
    }

    private static function validateBase(string $html, bool $mobile): void
    {
        if (str_contains($html, self::ATTRIBUTE) || str_contains($html, self::WORD_ATTRIBUTE)
            || preg_match('~\bclass="[^"]*\b(?:rt-tt|rt-tc)\b~', $html)
            || str_contains($html, 'data-rt-train-bottom-overlay=')) {
            self::fail();
        }
        self::assertIdentifiersAbsent($html, [self::STAGE, 'ft', 'fi']);
        // Reuse the old fail-closed shape/mirror contract without weakening it.
        $checked = $mobile ? OutlookTrainBottomOverlay::projectMobile($html) : OutlookTrainBottomOverlay::project($html);
        if ($checked === $html || OutlookTrainBottomOverlay::restore($checked) !== $html) {
            self::fail();
        }
    }

    private static function baseContext(string $html, bool $mobile): array
    {
        $nodes = self::nodes($html);
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        $stage = self::one($nodes, 'rt-sign-stage', 'div');
        $frame = self::one($nodes, 'rt-sign-content-frame', 'table');
        $content = self::one($nodes, 'rt-sign-content', 'td');
        $image = self::one($nodes, 'rt-delivery-train', 'img');
        $flow = self::one($nodes, 'rt-delivery-train-row', 'tr');
        $cell = self::one($nodes, 'rt-delivery-train-cell', 'td');
        $rows = self::rows($nodes, $frame);
        if (SignatureArtifactVersion::detect('signature', $html) !== 'v27' || count($rows) !== 2
            || $nodes[$rows[0]]['children'] !== [$content] || $rows[1] !== $flow
            || $nodes[$flow]['children'] !== [$cell] || $nodes[$image]['parent'] !== $cell
            || $nodes[$content]['attrs']['width'] !== '100%'
            || ($nodes[$image]['attrs']['style'] ?? '') !== self::IMAGE_STYLE
            || $nodes[$cell]['start'] !== $nodes[$flow]['openEnd']) {
            self::fail();
        }
        $normal = self::NORMAL_START.$nodes[$image]['opening'].self::NORMAL_END;
        $mso = substr($html, $nodes[$cell]['openEnd'] + strlen($normal), $nodes[$cell]['close'] - $nodes[$cell]['openEnd'] - strlen($normal));
        if (substr($html, $nodes[$cell]['openEnd'], strlen($normal)) !== $normal
            || preg_match('~\A<!--\[if mso\]><img class="rt-delivery-train-mso" src="[^"]+" width="300" height="38" alt="" style="[^"]+"><!\[endif\]-->\z~D', $mso) !== 1
            || substr($html, $nodes[$cell]['close'], $nodes[$flow]['end'] - $nodes[$cell]['close']) !== '</td></tr>') {
            self::fail();
        }
        self::frameEdges($html, $nodes, $frame, $rows[0], $content, $nodes[$flow]['start'], $nodes[$flow]['end']);
        preg_match_all('/(?:^|\s)(rts[0-9a-f]{10})(?=\s|$)/', $nodes[$root]['attrs']['class'] ?? '', $scope);
        if (count($scope[1]) !== 1) {
            self::fail();
        }
        $rootClasses = preg_split('/\s+/', $nodes[$root]['attrs']['class'] ?? '');
        $mobileScope = 'm'.base_convert(substr($scope[1][0], 3), 16, 36);
        if ($mobile !== in_array('rt-mobile-ledger', $rootClasses, true)
            || ($mobile && (! in_array('rtm', $rootClasses, true) || ! in_array($mobileScope, $rootClasses, true)))) {
            self::fail();
        }
        self::assertWordOpenings($nodes[$flow]['opening'], $nodes[$cell]['opening'], $mso, $mobile, $html, $mobile ? '.'.$mobileScope.'.rtm' : '.'.$scope[1][0]);

        return compact('nodes', 'root', 'stage', 'frame', 'content', 'image', 'flow', 'cell', 'mso', 'mobile', 'mobileScope')
            + ['scope' => $scope[1][0], 'rowOpening' => $nodes[$flow]['opening'], 'cellOpening' => $nodes[$cell]['opening'],
                'wordShape' => self::wordShape($nodes[$content]['opening'], $nodes[$flow]['opening'], $nodes[$cell]['opening'], $mso)];
    }

    /** Newly hidden compiler-owned Word geometry is never an opaque free channel. */
    private static function assertWordOpenings(string $row, string $cell, string $mso, bool $mobile, string $html, string $scope): void
    {
        $rowAttrs = self::attributes($row);
        $cellAttrs = self::attributes($cell);
        $cellClasses = preg_split('/\s+/', $cellAttrs['class'] ?? '');
        if ($rowAttrs !== ['class' => 'rt-delivery-train-row']
            || array_diff(array_keys($cellAttrs), ['class', 'width', 'align', 'valign', 'style']) !== []
            || count($cellAttrs) !== 5 || ($cellClasses[0] ?? '') !== 'rt-delivery-train-cell'
            || count($cellClasses) !== count(array_unique($cellClasses))
            || ($mobile ? count($cellClasses) < 3 || count($cellClasses) > 35 : count($cellClasses) !== 2)
            || preg_match('/\Aoi[0-9a-z]+\z/', $cellClasses[1] ?? '') !== 1
            || ($mobile && preg_match('/\Am[0-9a-z]+\z/', $cellClasses[2] ?? '') !== 1)
            || ($mobile && array_filter(array_slice($cellClasses, 3), static fn (string $class): bool => preg_match('/\Ag[1-9a-z][0-9a-z]*\z/', $class) !== 1) !== [])
            || ($cellAttrs['width'] ?? '') !== '100%' || ($cellAttrs['align'] ?? '') !== 'left'
            || ($cellAttrs['valign'] ?? '') !== 'bottom' || ($cellAttrs['style'] ?? '') !== self::CELL_STYLE
            || preg_match('~\A<!--\[if mso\]>(<img\b[^>]*>)<!\[endif\]-->\z~D', $mso, $image) !== 1) {
            self::fail();
        }
        self::assertCellMirrors($html, $scope, $cellClasses, $mobile);
        $imageAttrs = self::attributes($image[1]);
        if (array_diff(array_keys($imageAttrs), ['class', 'src', 'width', 'height', 'alt', 'style']) !== []
            || count($imageAttrs) !== 6 || ($imageAttrs['class'] ?? '') !== 'rt-delivery-train-mso'
            || ($imageAttrs['width'] ?? '') !== '300' || ($imageAttrs['height'] ?? '') !== '38'
            || ($imageAttrs['alt'] ?? '') !== '' || ($imageAttrs['style'] ?? '') !== self::MSO_IMAGE_STYLE) {
            self::fail();
        }
    }

    /** Reconstruct only the compiler-owned five independent cell properties. */
    private static function assertCellMirrors(string $html, string $scope, array $classes, bool $mobile): void
    {
        $attribute = $mobile ? 'data-rt-outlook-mobile-css' : OutlookSignatureInlineStyle::ATTRIBUTE;
        if (preg_match_all('~<style '.$attribute.'="1">(.*?)</style>~s', $html, $styles) !== 1) {
            self::fail();
        }
        $css = $styles[1][0];
        if (! $mobile) {
            $rule = $scope.'.'.$classes[1].','.$scope.' .'.$classes[1].'{'.self::CELL_STYLE.'}';
            if (substr_count($css, $rule) !== 1) {
                self::fail();
            }

            return;
        }
        $properties = [];
        foreach (array_slice($classes, 2) as $index => $alias) {
            $pattern = '~'.preg_quote($scope, '~').'(?:\.rtm)? \.'.preg_quote($alias, '~').'\{([^{}]*)\}~';
            $count = preg_match_all($pattern, $css, $rules);
            // An empty residual primary-m rule is removed by the established
            // family compiler; all of its properties must then come from g.
            if ($count > 1 || ($count === 0 && $index !== 0)) {
                self::fail();
            }
            foreach ($rules[1] as $body) {
                foreach (explode(';', rtrim($body, ';')) as $declaration) {
                    if (preg_match('/\A([a-z-]+):([^;]+)!important\z/', $declaration, $part) !== 1
                        || isset($properties[$part[1]])) {
                        self::fail();
                    }
                    $properties[$part[1]] = $part[2];
                }
            }
        }
        $expected = ['width' => '100%', 'padding' => '0', 'font-size' => '0', 'line-height' => '0', 'vertical-align' => 'bottom'];
        ksort($properties);
        ksort($expected);
        if ($properties !== $expected) {
            self::fail();
        }
    }

    /** Structural tamper checksum, not an authentication or permission token. */
    private static function wordShape(string $content, string $row, string $cell, string $mso): string
    {
        return substr(hash('sha256', $content.$row.$cell.$mso.'</td></tr>'), 0, 16);
    }

    /** No tree-invisible Word row/cell may surround the two owned row regions. */
    private static function frameEdges(string $html, array $nodes, int $frame, int $firstRow, int $content, int $flowStart, int $flowEnd): void
    {
        $container = $nodes[$firstRow]['parent'];
        if ($container !== $frame && ($nodes[$container]['tag'] !== 'tbody' || $nodes[$container]['parent'] !== $frame)) {
            self::fail();
        }
        $gaps = [
            [$nodes[$container]['openEnd'], $nodes[$firstRow]['start']],
            [$nodes[$content]['end'], $nodes[$firstRow]['close']],
            [$nodes[$firstRow]['end'], $flowStart],
            [$flowEnd, $nodes[$container]['close']],
        ];
        if ($container !== $frame) {
            $gaps[] = [$nodes[$frame]['openEnd'], $nodes[$container]['start']];
            $gaps[] = [$nodes[$container]['end'], $nodes[$frame]['close']];
        }
        foreach ($gaps as [$start, $end]) {
            if ($end < $start || trim(substr($html, $start, $end - $start)) !== '') {
                self::fail();
            }
        }
    }

    private static function mirrors(string $html, array $context, bool $restore): string
    {
        $attribute = $context['mobile'] ? 'data-rt-outlook-mobile-css' : OutlookSignatureInlineStyle::ATTRIBUTE;
        $opening = '<style '.$attribute.'="1">';
        if (substr_count($html, $opening) !== 1) {
            self::fail();
        }
        $start = strpos($html, $opening) + strlen($opening);
        $end = strpos($html, '</style>', $start);
        $css = substr($html, $start, $end - $start);
        $pattern = $context['mobile'] ? '/\Am[0-9a-z]+\z/' : '/\Aoi[0-9a-z]+\z/';
        $aliases = [];
        foreach (['image', 'content'] as $role) {
            $values = array_values(array_filter(preg_split('/\s+/', $context['nodes'][$context[$role]]['attrs']['class'] ?? ''), static fn (string $class): bool => preg_match($pattern, $class) === 1));
            if (count($values) !== 1) {
                self::fail();
            }
            $aliases[$role] = $values[0];
        }
        $scope = $context['mobile'] ? '.'.$context['mobileScope'].'.rtm' : '.'.$context['scope'];
        $originalSelector = $context['mobile'] ? $scope.' .'.$aliases['image'] : $scope.'.'.$aliases['image'].','.$scope.' .'.$aliases['image'];
        $normalSelector = $scope.' .'.self::STAGE.' .'.self::CARRIER.' .'.$aliases['image'];
        $contentRule = $scope.' .'.self::STAGE.' .'.self::CONTENT.'.'.$aliases['content'].'{width:99%!important;}';
        $originalStyle = $context['mobile'] ? self::important(self::IMAGE_STYLE) : self::IMAGE_STYLE;
        $normalStyle = $context['mobile']
            ? str_replace('width:100%!important;', 'width:10000%!important;', $originalStyle)
            : str_replace('width:100%;', 'width:10000%!important;', $originalStyle);
        $originalRule = $originalSelector.'{'.$originalStyle.'}';
        $normalRule = $normalSelector.'{'.$normalStyle.'}';
        $from = $restore ? $normalRule : $originalRule;
        $to = $restore ? $originalRule : $normalRule;
        if (substr_count($css, $from) !== 1 || str_contains($css, $to)
            || self::selectorCount($css, self::STAGE) !== ($restore ? 2 : 0)
            || substr_count($css, self::CARRIER) !== ($restore ? 1 : 0)
            || substr_count($css, self::CONTENT) !== ($restore ? 1 : 0)) {
            self::fail();
        }
        if ($restore) {
            if (! str_ends_with($css, $contentRule)) {
                self::fail();
            }
            $css = substr($css, 0, -strlen($contentRule));
        }
        $css = str_replace($from, $to, $css);
        if (! $restore) {
            $css .= $contentRule;
        }

        return substr($html, 0, $start).$css.substr($html, $end);
    }

    private static function contentOpening(string $opening, bool $restore): string
    {
        if ($restore) {
            self::fail();
        }
        $attrs = self::attributes($opening);
        $style = $attrs['style'] ?? '';
        $hasWidth = substr_count($style, 'width:100%;') === 1;
        if (($attrs['width'] ?? '') !== '100%' || (! $hasWidth && preg_match('/(?:^|;)\s*width\s*:/i', $style))
            || in_array(self::CONTENT, preg_split('/\s+/', $attrs['class'] ?? ''), true)) {
            self::fail();
        }
        $result = preg_replace('~\bclass="([^"]+)"~', 'class="$1 '.self::CONTENT.'"', $opening, 1, $classes);
        $result = str_replace('width="100%"', 'width="99%"', $result, $widths);
        if ($hasWidth) {
            $result = str_replace('width:100%;', 'width:99%!important;', $result, $styles);
        } else {
            $result = str_replace('style="'.$style.'"', 'style="'.$style.'width:99%!important;"', $result, $styles);
        }
        if ($classes !== 1 || $widths !== 1 || $styles !== 1) {
            self::fail();
        }

        return $result;
    }

    private static function imageOpening(string $opening, bool $restore): string
    {
        $attrs = self::attributes($opening);
        $projected = str_replace('width:100%;', 'width:10000%!important;', self::IMAGE_STYLE);
        if (($attrs['style'] ?? '') !== ($restore ? $projected : self::IMAGE_STYLE)) {
            self::fail();
        }

        return str_replace($restore ? $projected : self::IMAGE_STYLE, $restore ? self::IMAGE_STYLE : $projected, $opening);
    }

    private static function stageOpening(string $opening, bool $mobile, bool $restore, string $wordShape): string
    {
        if ($restore) {
            $attribute = ' '.self::ATTRIBUTE.'="'.($mobile ? self::MOBILE_MARKER : self::MARKER).'"';
            $wordAttribute = ' '.self::WORD_ATTRIBUTE.'="'.$wordShape.'"';
            if (substr_count($opening, $attribute) !== 1 || substr_count($opening, $wordAttribute) !== 1 || substr_count($opening, ' '.self::STAGE.'"') !== 1) {
                self::fail();
            }

            return str_replace([$attribute, $wordAttribute, ' '.self::STAGE.'"'], ['', '', '"'], $opening);
        }
        $result = preg_replace('~\bclass="([^"]+)"~', 'class="$1 '.self::STAGE.'"', $opening, 1, $changed);
        if ($changed !== 1) {
            self::fail();
        }

        return substr($result, 0, -1).' '.self::ATTRIBUTE.'="'.($mobile ? self::MOBILE_MARKER : self::MARKER).'" '.self::WORD_ATTRIBUTE.'="'.$wordShape.'">';
    }

    /** Only used on the proven frame; no general conditional HTML evaluator. */
    private static function wordView(string $html): string
    {
        $html = preg_replace('~<!--\[if !mso\]><!-->.*?<!--<!\[endif\]-->~s', '', $html);

        return preg_replace('~<!--\[if mso\]>(.*?)<!\[endif\]-->~s', '$1', $html);
    }

    private static function important(string $style): string
    {
        return str_replace(';', '!important;', rtrim($style, ';')).'!important;';
    }

    /** Only newly owned short identifiers; never rewrite authored selectors. */
    public static function assertForegroundIdentifiersAbsent(string $html): void
    {
        self::assertIdentifiersAbsent($html, ['ft', 'fi']);
    }

    private static function assertIdentifiersAbsent(string $html, array $names): void
    {
        preg_match_all('~\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))~i', $html, $classes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        foreach ($classes as $class) {
            $tokens = preg_split('/\s+/', CssSemantic::decodeHtmlEntitiesOnce($class[1] ?? $class[2] ?? $class[3] ?? ''));
            if (array_intersect($tokens, $names) !== []) {
                self::fail();
            }
        }
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        foreach ($styles[1] as $css) {
            foreach ($names as $name) {
                if (self::selectorCount($css, $name) !== 0) {
                    self::fail();
                }
            }
        }
    }

    /** Decoded selector tokens, not short substrings in text/URLs/declarations. */
    private static function selectorCount(string $css, string $name): int
    {
        $css = preg_replace('~/\*.*?\*/~s', '', CssSemantic::decodeHtmlEntitiesOnce($css));
        $css = preg_replace_callback('~\\\\([0-9a-fA-F]{1,6})(?:\r\n|[ \t\r\n\f])?|\\\\([^\r\n\f])~', static function (array $match): string {
            if (($match[1] ?? '') === '') {
                return $match[2];
            }
            $point = hexdec($match[1]);

            return $point > 0 && $point <= 0x10FFFF && ! ($point >= 0xD800 && $point <= 0xDFFF) ? mb_chr($point, 'UTF-8') : "\u{FFFD}";
        }, $css);
        preg_match_all('~([^{}]+)\{~', $css, $selectors);
        $count = 0;
        foreach ($selectors[1] as $selector) {
            $count += preg_match_all('~(?<![a-zA-Z0-9_-])'.preg_quote($name, '~').'(?![a-zA-Z0-9_-])~', $selector);
        }

        return $count;
    }

    private static function budget(string $html): void
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        if (array_sum(array_map('strlen', $styles[1])) >= self::MAX_CSS_BYTES
            || intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) > self::MAX_CHARACTERS) {
            throw new RuntimeException('Die experimentelle Tabellenprojektion ueberschreitet das unveraenderte native HTML-/CSS-Budget.');
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

    private static function attributes(string $tag): array
    {
        preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $tag, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attrs = [];
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            $raw = $match[2] ?? $match[3] ?? $match[4] ?? '';
            if (isset($attrs[$key]) || (in_array($key, ['class', 'style'], true) && $match[4] !== null)) {
                self::fail();
            }
            $attrs[$key] = CssSemantic::decodeHtmlEntitiesOnce($raw);
            if ($key === 'class' && $attrs[$key] !== $raw) {
                self::fail();
            }
        }

        return $attrs;
    }

    /** Offset tree only; canonical children, styles and comments stay opaque. */
    private static function nodes(string $html): array
    {
        $tag = '(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*';
        preg_match_all('~<!--.*?-->|<style\b'.$tag.'>.*?</style\s*>|</?[a-z][a-z0-9:-]*'.$tag.'>~is', $html, $tokens, PREG_OFFSET_CAPTURE);
        $nodes = $stack = [];
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
            if (! $void && preg_match('~/\s*>\z~', $token)) {
                self::fail();
            }
            $parent = $stack === [] ? null : $stack[array_key_last($stack)];
            $index = count($nodes);
            $nodes[] = ['tag' => $element, 'attrs' => self::attributes($token), 'parent' => $parent, 'children' => [], 'start' => $offset, 'openEnd' => $offset + strlen($token), 'opening' => $token, 'close' => $void ? $offset + strlen($token) : null, 'end' => $void ? $offset + strlen($token) : null];
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
        throw new RuntimeException('Die experimentelle Zug-Tabelle besitzt keinen eindeutigen reversiblen IMG-/MSO-/CSS-Vertrag.');
    }
}
