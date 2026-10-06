<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignatureTableOverlapDelivery;
use RuntimeException;

/** Body-owned compose projection only; never a native signature or saved source. */
final class OutlookCombinedComposeDocument
{
    public const MODE = 'combined-v1';

    public const MARKER = 'RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT';

    public const MAX_CHARACTERS = 99000;

    public const MAX_CSS_BYTES = 24576;

    private const NATIVE_MARKER = 'RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE';

    private const BORDER = 'border-left:6px solid #e90032;';

    public static function build(string $templateHtml, string $signatureHtml): string
    {
        foreach ([$templateHtml, $signatureHtml] as $fragment) {
            if ($fragment === '' || str_contains($fragment, 'data-rt-compose-document')
                || str_contains($fragment, self::MARKER) || str_contains($fragment, 'rt-combined-compose-frame')
                || str_contains($fragment, 'rt-combined-signature-frame')
                || preg_match('~<(?:html|head|body|script|iframe)\b~i', $fragment)) {
                self::fail();
            }
        }
        if (! SignatureTableOverlapDelivery::applies($signatureHtml)
            || substr_count($templateHtml, self::NATIVE_MARKER) !== 2
            || substr_count($templateHtml, 'data-rt-template-signature-mode="native"') !== 1
            || str_contains($templateHtml, 'RT-SIGNATURE-MANAGED-V1')
            || ! str_contains($signatureHtml, 'RT-SIGNATURE-MANAGED-V1')
            || str_contains($signatureHtml, 'RT-TEMPLATE-MANAGED-V1')) {
            self::fail();
        }

        SignatureTableOverlapDelivery::assertRuntime($signatureHtml);
        [$templateNodes, $templateFrame] = self::fragment($templateHtml, 'rt-outlook-template', 'rtt', 12);
        [$signatureNodes, $signatureFrame] = self::fragment($signatureHtml, 'rt-outlook-signature', 'rts', 10);
        if (! self::hasClass($templateNodes[$templateFrame], 'rt-native-compose-frame')
            || count(array_filter($templateNodes, static fn (array $node): bool => self::hasClass($node, 'rt-native-compose-frame'))) !== 1
            || array_filter($templateNodes, static fn (array $node): bool => self::hasClass($node, 'rt-outlook-signature')) !== []
            || array_filter($signatureNodes, static fn (array $node): bool => self::hasClass($node, 'rt-native-compose-frame') || self::hasClass($node, 'rt-outlook-template')) !== []) {
            self::fail();
        }
        self::assertTemplateMetadata($templateHtml, $templateNodes);
        // Project the two exact generated frame declarations and their existing
        // CSS mirrors. Outlook may remove a new override STYLE while retaining
        // these older compiler-owned blocks; neither must restore an inner red
        // border. Authored rules, cells, media and comments stay opaque.
        $template = self::withoutBorder($templateHtml, $templateNodes[$templateFrame]);
        $signature = self::withoutBorder($signatureHtml, $signatureNodes[$signatureFrame], 'rt-combined-signature-frame');
        $template = self::projectFrameMirror($template, $templateNodes, $templateFrame, template: true);
        $signature = self::projectFrameMirror($signature, $signatureNodes, $signatureFrame, template: false);
        $template = str_replace(self::NATIVE_MARKER, self::MARKER, $template);
        $template = str_replace('data-rt-template-signature-mode="native"', 'data-rt-template-signature-mode="combined-v1"', $template);
        $scope = 'rtc'.substr(hash('sha256', $templateHtml."\0".$signatureHtml), 0, 12);
        $frameStyle = 'width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;box-sizing:border-box;background-color:#ffffff;'
            .self::BORDER.'mso-table-lspace:0pt;mso-table-rspace:0pt;';
        // This optional new block mirrors only the common carrier. Inner-border
        // correctness no longer depends on Outlook retaining this STYLE.
        $css = '.'.$scope.' .rt-combined-compose-frame{width:100%!important;border-collapse:separate!important;border-spacing:0!important;'
            .'table-layout:fixed!important;box-sizing:border-box!important;background-color:#ffffff!important;border-left:6px solid #e90032!important;}'
            .'.'.$scope.' .rt-combined-compose-cell{padding:0!important;}';
        $output = '<style data-rt-combined-compose-css="1">'.$css.'</style>'
            .'<div class="rt-combined-compose-document '.$scope.'" data-rt-compose-document="'.self::MODE.'" style="display:block;width:100%;">'
            .'<table class="rt-combined-compose-frame" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="'.$frameStyle.'">'
            .'<tbody><tr><td class="rt-combined-compose-cell" width="100%" valign="top" style="width:100%;padding:0;vertical-align:top;">'
            .$template.$signature.'</td></tr></tbody></table></div>';
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $output, $styles);
        if (array_sum(array_map('strlen', $styles[1])) >= self::MAX_CSS_BYTES
            || intdiv(strlen(mb_convert_encoding($output, 'UTF-16LE', 'UTF-8')), 2) > self::MAX_CHARACTERS) {
            throw new RuntimeException('Das gemeinsame Outlook-Dokument ueberschreitet sein HTML-/CSS-Transportbudget.');
        }

        return $output;
    }

    /** @return array{array,int} */
    private static function fragment(string $html, string $rootClass, string $scopePrefix, int $scopeLength): array
    {
        $nodes = self::nodes($html);
        $roots = array_keys(array_filter($nodes, static fn (array $node): bool => $node['parent'] === null));
        if (count($roots) !== 1 || $nodes[$roots[0]]['tag'] !== 'div' || ! self::hasClass($nodes[$roots[0]], $rootClass)) {
            self::fail();
        }
        $root = $roots[0];
        $classes = preg_split('/\s+/', trim($nodes[$root]['attrs']['class'] ?? ''));
        if (count(array_filter($classes, static fn (string $class): bool => preg_match('/\A'.$scopePrefix.'[0-9a-f]{'.$scopeLength.'}\z/', $class) === 1)) !== 1
            || count(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, $rootClass))) !== 1) {
            self::fail();
        }
        $tables = array_values(array_filter($nodes[$root]['children'], static fn (int $index): bool => $nodes[$index]['tag'] === 'table'));
        if (count($tables) !== 1) {
            self::fail();
        }
        $frame = $tables[0];
        foreach (['role' => 'presentation', 'width' => '100%', 'cellpadding' => '0', 'cellspacing' => '0'] as $attribute => $value) {
            if (($nodes[$frame]['attrs'][$attribute] ?? '') !== $value) {
                self::fail();
            }
        }
        $outside = substr($html, 0, $nodes[$root]['start']).substr($html, $nodes[$root]['end']);
        $outside = preg_replace('~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>~is', '', $outside);
        if (trim($outside) !== '') {
            self::fail();
        }

        return [$nodes, $frame];
    }

    private static function withoutBorder(string $html, array $node, ?string $extraClass = null): string
    {
        $tag = $node['opening'];
        $count = 0;
        $tag = preg_replace_callback('~\sstyle\s*=\s*(["\'])(.*?)\1~is', static function (array $match): string {
            $style = $match[2];
            $parts = preg_split('~;(?=(?:[^\'\"]|\'[^\']*\'|"[^"]*")*$)~s', $style);
            $borders = array_values(array_filter($parts, static fn (string $part): bool => preg_match('/\A\s*border-left\s*:/i', $part) === 1));
            if ($borders !== ['border-left:6px solid #e90032']) {
                self::fail();
            }
            $style = str_replace(self::BORDER, 'border-left:0;', $style, $removed);
            if ($removed !== 1) {
                self::fail();
            }

            return ' style='.$match[1].$style.$match[1];
        }, $tag, -1, $count);
        if ($count !== 1) {
            self::fail();
        }
        if ($extraClass !== null) {
            $classCount = 0;
            $tag = preg_replace_callback('~\sclass\s*=\s*(["\'])(.*?)\1~is', static fn (array $match): string => ' class='.$match[1].$match[2].' '.$extraClass.$match[1], $tag, -1, $classCount);
            if ($classCount > 1) {
                self::fail();
            }
            if ($classCount === 0) {
                $tag = substr($tag, 0, -1).' class="'.$extraClass.'">';
            }
        }

        return substr($html, 0, $node['start']).$tag.substr($html, $node['start'] + strlen($node['opening']));
    }

    private static function projectFrameMirror(string $html, array $nodes, int $frame, bool $template): string
    {
        $roots = array_values(array_filter($nodes, static fn (array $node): bool => $node['parent'] === null));
        $scopePattern = $template ? '/(?:^|\s)(rtt[0-9a-f]{12})(?:\s|$)/' : '/(?:^|\s)(rts[0-9a-f]{10})(?:\s|$)/';
        if (count($roots) !== 1 || preg_match($scopePattern, $roots[0]['attrs']['class'] ?? '', $scope) !== 1) {
            self::fail();
        }
        if ($template) {
            $attribute = 'data-rt-outlook-template-css';
            $selector = '.'.$scope[1].' .rt-native-compose-frame';
            $declarations = 'width:100%!important;border-collapse:separate!important;border-spacing:0!important;table-layout:fixed!important;'
                .'box-sizing:border-box!important;background-color:#ffffff!important;border-left:6px solid #e90032!important;';
            $border = 'border-left:6px solid #e90032!important;';
            $replacement = 'border-left:0!important;';
        } else {
            $attribute = OutlookSignatureInlineStyle::ATTRIBUTE;
            $aliases = array_values(array_filter(preg_split('/\s+/', trim($nodes[$frame]['attrs']['class'] ?? '')), static fn (string $class): bool => preg_match('/\Aoi[0-9a-z]+\z/', $class) === 1));
            if (count($aliases) !== 1 || count(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, $aliases[0]))) !== 1) {
                self::fail();
            }
            $selector = '.'.$scope[1].'.'.$aliases[0].',.'.$scope[1].' .'.$aliases[0];
            // The generated alias compiler mirrors this exact opening-tag
            // declaration string. Reject reuse or a changed mirror instead of
            // applying an output border projection to an authored/quoted cell.
            $declarations = trim($nodes[$frame]['attrs']['style'] ?? '', "; \t\r\n").';';
            $border = self::BORDER;
            $replacement = 'border-left:0;';
        }
        $opening = '<style '.$attribute.'="1">';
        if (substr_count($html, $opening) !== 1) {
            self::fail();
        }
        $start = strpos($html, $opening) + strlen($opening);
        $end = strpos($html, '</style>', $start);
        if ($end === false) {
            self::fail();
        }
        $css = substr($html, $start, $end - $start);
        $rule = $selector.'{'.$declarations.'}';
        if (substr_count($css, $selector.'{') !== 1 || substr_count($css, $rule) !== 1 || substr_count($declarations, $border) !== 1) {
            self::fail();
        }
        $projectedRule = str_replace($border, $replacement, $rule);
        $css = str_replace($rule, $projectedRule, $css);

        return substr($html, 0, $start).$css.substr($html, $end);
    }

    private static function assertTemplateMetadata(string $html, array $nodes): void
    {
        $markers = array_values(array_filter($nodes, static fn (array $node): bool => array_key_exists('data-rt-template-signature-mode', $node['attrs'])));
        if (count($markers) !== 1) {
            self::fail();
        }
        $marker = $markers[0];
        $parent = $marker['parent'];
        if ($marker['tag'] !== 'span' || ($marker['attrs']['data-rt-template-signature-mode'] ?? '') !== 'native'
            || ! self::hasClass($marker, 'rt-office-metadata') || ! array_key_exists('hidden', $marker['attrs'])
            || ($marker['attrs']['aria-hidden'] ?? '') !== 'true' || $parent === null || $nodes[$parent]['tag'] !== 'td'
            || ! self::hasClass($nodes[$parent], 'rt-native-template-mark') || ($nodes[$parent]['attrs']['width'] ?? '') !== '44'
            || $marker['children'] !== [] || substr_count($html, '<!-- '.self::NATIVE_MARKER.' -->') !== 1
            || substr($html, $marker['start'] + strlen($marker['opening']), $marker['close'] - $marker['start'] - strlen($marker['opening'])) !== self::NATIVE_MARKER) {
            self::fail();
        }
    }

    /** Strict offset tree: complete comments, styles and quoted values are opaque. */
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
            $void = in_array($element, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true);
            if (! $void && preg_match('~/\s*>\z~', $token)) {
                self::fail();
            }
            $attrs = [];
            preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $token, $attributes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($attributes as $attribute) {
                $key = strtolower($attribute[1]);
                if (array_key_exists($key, $attrs)) {
                    self::fail();
                }
                $attrs[$key] = CssSemantic::decodeHtmlEntitiesOnce($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '');
            }
            $parent = $stack === [] ? null : $stack[array_key_last($stack)];
            $index = count($nodes);
            $nodes[] = ['tag' => $element, 'attrs' => $attrs, 'parent' => $parent, 'children' => [], 'start' => $offset, 'opening' => $token, 'close' => $void ? $offset + strlen($token) : null, 'end' => $void ? $offset + strlen($token) : null];
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

    private static function hasClass(array $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($node['attrs']['class'] ?? '')), true);
    }

    private static function fail(): never
    {
        throw new RuntimeException('Die gemeinsame Outlook-Vorlage/Signatur besitzt keinen eindeutigen freigegebenen Rahmenvertrag.');
    }
}
