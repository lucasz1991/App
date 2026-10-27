<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignaturePersonalColumns;
use App\Support\Mail\SignatureTableOverlapDelivery;
use RuntimeException;

/** Body-owned compose projection only; never a native signature or saved source. */
final class OutlookCombinedComposeDocument
{
    public const MODE = 'combined-v1';

    public const MARKER = 'RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT';

    public const MAX_CHARACTERS = 99000;

    public const MAX_CSS_BYTES = 24576;

    public const EDITABLE_CLASS = 'rt-compose-editable';

    public const EDITABLE_ATTRIBUTE = 'data-rt-compose-editable';

    public const EDITABLE_VERSION = 'v1';

    public const EDITABLE_START = 'RT-COMPOSE-EDITABLE-START-V1';

    public const EDITABLE_END = 'RT-COMPOSE-EDITABLE-END-V1';

    public const EDITABLE_TABLE_CLASS = 'rt-compose-editable-v1';

    public const DOCUMENT_TABLE_CLASS = 'rt-managed-combined-v1';

    public const TEMPLATE_TABLE_CLASS = 'rt-managed-template-v1';

    public const SIGNATURE_TABLE_CLASS = 'rt-managed-signature-v1';

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
            || str_contains($signatureHtml, 'RT-TEMPLATE-MANAGED-V1')
            || str_contains($signatureHtml, self::EDITABLE_START) || str_contains($signatureHtml, self::EDITABLE_END)) {
            self::fail();
        }

        if (SignatureTableOverlapDelivery::usesLogoTrain($signatureHtml)) {
            if (str_contains($signatureHtml, 'data-rt-train-bottom-overlay=')) {
                self::fail();
            }
            SignatureTableOverlapDelivery::assertRuntime($signatureHtml);
        } elseif (str_contains($signatureHtml, 'data-rt-train-bottom-overlay=')) {
            OutlookTrainBottomOverlay::assertRuntime($signatureHtml);
        } else {
            SignatureTableOverlapDelivery::assertRuntime($signatureHtml);
        }
        $templateHtml = self::markEditableRegion($templateHtml);
        [$templateNodes, $templateFrame] = self::fragment($templateHtml, 'rt-outlook-template', 'rtt', 12);
        [$signatureNodes, $signatureFrame] = self::fragment($signatureHtml, 'rt-outlook-signature', 'rts', 10);
        if (! self::hasClass($templateNodes[$templateFrame], 'rt-native-compose-frame')
            || count(array_filter($templateNodes, static fn (array $node): bool => self::hasClass($node, 'rt-native-compose-frame'))) !== 1
            || array_filter($templateNodes, static fn (array $node): bool => self::hasClass($node, 'rt-outlook-signature')) !== []
            || array_filter($signatureNodes, static fn (array $node): bool => self::hasClass($node, 'rt-native-compose-frame') || self::hasClass($node, 'rt-outlook-template') || self::editableMarker($node)) !== []) {
            self::fail();
        }
        self::assertTemplateMetadata($templateHtml, $templateNodes);
        // Project the two exact generated frame declarations and their existing
        // CSS mirrors. Outlook may remove a new override STYLE while retaining
        // these older compiler-owned blocks; neither must restore an inner red
        // border. Authored rules, cells, media and comments stay opaque.
        $template = self::withoutBorder($templateHtml, $templateNodes[$templateFrame], self::TEMPLATE_TABLE_CLASS);
        $signature = self::withoutBorder($signatureHtml, $signatureNodes[$signatureFrame], 'rt-combined-signature-frame '.self::SIGNATURE_TABLE_CLASS);
        $template = self::projectFrameMirror($template, $templateNodes, $templateFrame, template: true);
        $signature = self::projectFrameMirror($signature, $signatureNodes, $signatureFrame, template: false);
        $template = str_replace(self::NATIVE_MARKER, self::MARKER, $template);
        $template = str_replace('data-rt-template-signature-mode="native"', 'data-rt-template-signature-mode="combined-v1"', $template);
        $scope = 'rtc'.substr(hash('sha256', $templateHtml."\0".$signatureHtml), 0, 12);
        $frameStyle = 'width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;box-sizing:border-box;background-color:#ffffff;'
            .self::BORDER.'mso-table-lspace:0pt;mso-table-rspace:0pt;';
        // This optional new block mirrors only the common carrier. Inner-border
        // correctness no longer depends on Outlook retaining this STYLE.
        $css = 'table.'.$scope.'{width:100%!important;border-collapse:separate!important;border-spacing:0!important;'
            .'table-layout:fixed!important;box-sizing:border-box!important;background-color:#ffffff!important;border-left:6px solid #e90032!important;}'
            .'.'.$scope.' .rt-combined-compose-cell{padding:0!important;}';
        $output = '<div class="rt-combined-compose-document '.$scope.'" data-rt-compose-document="'.self::MODE.'" style="display:block;width:100%;">'
            .'<table class="rt-combined-compose-frame '.self::DOCUMENT_TABLE_CLASS.' '.$scope.'" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="'.$frameStyle.'">'
            .'<tbody><tr><td class="rt-combined-compose-cell" width="100%" valign="top" style="width:100%;padding:0;vertical-align:top;">'
            .'<style data-rt-combined-compose-css="1">'.$css.'</style>'.$template.$signature.'</td></tr></tbody></table></div>';
        $output = self::stabilizeTableScopes($output);
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $output, $styles);
        if (array_sum(array_map('strlen', $styles[1])) >= self::MAX_CSS_BYTES
            || intdiv(strlen(mb_convert_encoding($output, 'UTF-16LE', 'UTF-8')), 2) > self::MAX_CHARACTERS) {
            throw new RuntimeException('Das gemeinsame Outlook-Dokument ueberschreitet sein HTML-/CSS-Transportbudget.');
        }

        return $output;
    }

    /** Office typing retains TABLE classes while discarding DIV/P/SPAN ownership. */
    public static function stabilizeTableScopes(string $html): string
    {
        if (! str_contains($html, self::DOCUMENT_TABLE_CLASS)) {
            return $html;
        }
        $nodes = self::nodes($html);
        $edits = [];
        $frames = [];
        foreach ([self::DOCUMENT_TABLE_CLASS => ['rt-combined-compose-document', 'rtc', 12],
            self::TEMPLATE_TABLE_CLASS => ['rt-outlook-template', 'rtt', 12],
            self::SIGNATURE_TABLE_CLASS => ['rt-outlook-signature', 'rts', 10]] as $marker => [$rootClass, $prefix, $length]) {
            $tables = array_keys(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, $marker)));
            $roots = array_keys(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, $rootClass)));
            if (count($tables) !== 1 || count($roots) !== 1 || $nodes[$tables[0]]['tag'] !== 'table' || $nodes[$roots[0]]['tag'] !== 'div'
                || $nodes[$tables[0]]['start'] <= $nodes[$roots[0]]['start'] || $nodes[$tables[0]]['end'] >= $nodes[$roots[0]]['end']) {
                self::fail();
            }
            $frame = $nodes[$tables[0]];
            $classes = preg_split('/\s+/', trim($nodes[$roots[0]]['attrs']['class'] ?? ''));
            $scopes = array_values(array_filter($classes, static fn (string $class): bool => preg_match('/\A'.$prefix.'[0-9a-f]{'.$length.'}\z/', $class) === 1));
            if (count($scopes) !== 1) {
                self::fail();
            }
            if ($marker === self::SIGNATURE_TABLE_CLASS) {
                $scopes = array_merge($scopes, array_values(array_filter($classes, static fn (string $class): bool => preg_match('/\Art-(?:df[0-9]+[0-9a-f]{10}|dflow-host-v[0-9]+)\z/', $class) === 1)));
            }
            $frameClasses = preg_split('/\s+/', trim($frame['attrs']['class'] ?? ''));
            foreach ($scopes as $class) {
                if (! in_array($class, $frameClasses, true)) {
                    $frameClasses[] = $class;
                }
            }
            $opening = preg_replace_callback('~\sclass\s*=\s*(["\'])(.*?)\1~is', static fn (array $match): string => ' class='.$match[1].implode(' ', $frameClasses).$match[1], $frame['opening'], -1, $count);
            if ($count !== 1) {
                self::fail();
            }
            $edits[] = [$frame['start'], strlen($frame['opening']), $opening];
            $frames[] = $frame;
        }
        if ($frames[1]['start'] <= $frames[0]['start'] || $frames[2]['end'] >= $frames[0]['end'] || $frames[1]['end'] >= $frames[2]['start']) {
            self::fail();
        }
        usort($edits, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
        foreach ($edits as [$start, $length, $opening]) {
            $html = substr_replace($html, $opening, $start, $length);
        }

        return $html;
    }

    /** Give only a canonical standalone desktop signature the same incoming TABLE owner. */
    public static function stabilizeStandaloneSignature(string $html): string
    {
        if (! str_contains($html, 'rt-outlook-signature') || ! str_contains($html, 'RT-SIGNATURE-MANAGED-V1')
            || ! str_contains($html, 'data-rt-train-delivery="'.SignatureTableOverlapDelivery::MARKER.'"')
            || str_contains($html, self::DOCUMENT_TABLE_CLASS) || str_contains($html, 'RT-TEMPLATE-MANAGED-V1')
            || OutlookDesktopFlow::mobile($html)) {
            return $html;
        }
        // A legacy fragment can legitimately carry ordinary content outside its
        // signature. Do not claim ownership of that wider document.
        $candidates = self::nodes($html);
        if (count(array_filter($candidates, static fn (array $node): bool => $node['parent'] === null)) !== 1) {
            return $html;
        }
        [$nodes, $frameIndex] = self::fragment($html, 'rt-outlook-signature', 'rts', 10);
        $frame = $nodes[$frameIndex];
        $root = $nodes[$frame['parent']];
        $body = $frame['children'];
        if (count($body) !== 1 || $nodes[$body[0]]['tag'] !== 'tbody') {
            self::fail();
        }
        $rows = $nodes[$body[0]]['children'];
        if (count($rows) !== 2 || $nodes[$rows[0]]['tag'] !== 'tr' || $nodes[$rows[1]]['tag'] !== 'tr') {
            self::fail();
        }
        foreach ($rows as $row) {
            if (count($nodes[$row]['children']) !== 1 || $nodes[$nodes[$row]['children'][0]]['tag'] !== 'td') {
                self::fail();
            }
        }
        $main = $nodes[$nodes[$rows[0]]['children'][0]];
        $legal = $nodes[$nodes[$rows[1]]['children'][0]];
        if (! self::hasClass($main, 'rt-sign-cell') || ! self::hasClass($legal, 'rt-pad')
            || ($main['attrs']['width'] ?? '') !== '100%' || ($legal['attrs']['width'] ?? '') !== '100%') {
            self::fail();
        }
        $metadata = [];
        foreach ($nodes as $index => $node) {
            if (self::hasClass($node, self::SIGNATURE_TABLE_CLASS) && $index !== $frameIndex) {
                self::fail();
            }
            if (self::hasClass($node, self::TEMPLATE_TABLE_CLASS) || self::editableMarker($node)) {
                self::fail();
            }
            if (! self::hasClass($node, 'rt-office-metadata')) {
                continue;
            }
            if ($node['tag'] !== 'span' || $node['parent'] !== $nodes[$rows[1]]['children'][0] || $node['children'] !== []
                || ! array_key_exists('hidden', $node['attrs']) || ($node['attrs']['aria-hidden'] ?? '') !== 'true') {
                self::fail();
            }
            $metadata[] = substr($html, $node['start'] + strlen($node['opening']), $node['close'] - $node['start'] - strlen($node['opening']));
        }
        if (count($metadata) !== 2 || count(array_filter($metadata, static fn (string $value): bool => $value === 'RT-SIGNATURE-MANAGED-V1')) !== 1
            || count(array_filter($metadata, static fn (string $value): bool => preg_match('/\ART-SIGNATURE-VERSION:[0-9a-f]{16}\z/', $value) === 1)) !== 1) {
            self::fail();
        }
        foreach ($metadata as $value) {
            if (substr_count($html, $value) !== 2 || substr_count($html, '<!-- '.$value.' -->') !== 1) {
                self::fail();
            }
        }
        $scopes = array_values(array_filter(preg_split('/\s+/', trim($root['attrs']['class'] ?? '')), static fn (string $class): bool => preg_match('/\A(?:rts[0-9a-f]{10}|rt-df[0-9]+[0-9a-f]{10}|rt-dflow-host-v[0-9]+)\z/', $class) === 1));
        $classes = preg_split('/\s+/', trim($frame['attrs']['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($classes as $class) {
            if (preg_match('/\A(?:rts|rt-df|rt-dflow-host)/', $class) === 1 && ! in_array($class, $scopes, true)) {
                self::fail();
            }
        }
        $classes = array_values(array_unique(array_merge($classes, [self::SIGNATURE_TABLE_CLASS], $scopes)));
        $opening = preg_replace_callback('~\sclass\s*=\s*(["\'])(.*?)\1~is', static fn (array $match): string => ' class='.$match[1].implode(' ', $classes).$match[1], $frame['opening'], -1, $count);
        if ($count === 0 && ! array_key_exists('class', $frame['attrs'])) {
            $opening = substr($opening, 0, -1).' class="'.implode(' ', $classes).'">';
        } elseif ($count !== 1) {
            self::fail();
        }

        $padding = self::standalonePersonalPadding($nodes, $main);
        if ($padding !== null) {
            [$cell, $cellOpening] = $padding;
            $html = substr_replace($html, $cellOpening, $cell['start'], strlen($cell['opening']));
        }

        return substr_replace($html, $opening, $frame['start'], strlen($frame['opening']));
    }

    /** Preserve the verified 16px native inset against the older 25px runtime rule. */
    private static function standalonePersonalPadding(array $nodes, array $main): ?array
    {
        $flows = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'table'
            && self::hasClass($node, 'rt-personal-columns')
            && ($node['attrs'][SignaturePersonalColumns::NATIVE_FLOW_ATTRIBUTE] ?? '') === SignaturePersonalColumns::MARKER
            && ($node['attrs']['data-rt-desktop-flow'] ?? '') === OutlookDesktopFlow::REVISION));
        if (count($flows) !== 1) {
            return null;
        }
        $content = $nodes[$flows[0]]['parent'];
        $row = $content === null ? null : $nodes[$content]['parent'];
        $body = $row === null ? null : $nodes[$row]['parent'];
        $frame = $body === null ? null : $nodes[$body]['parent'];
        if ($content === null || $row === null || $body === null || $frame === null
            || $nodes[$content]['tag'] !== 'td' || ! self::hasClass($nodes[$content], 'rt-sign-content')
            || $nodes[$row]['tag'] !== 'tr' || $nodes[$body]['tag'] !== 'tbody'
            || $nodes[$frame]['tag'] !== 'table' || ! self::hasClass($nodes[$frame], 'rt-sign-content-frame')
            || $nodes[$frame]['start'] <= $main['start'] || $nodes[$frame]['end'] >= $main['end']
            || $nodes[$frame]['children'] !== [$body] || $nodes[$body]['children'] !== [$row]
            || $nodes[$row]['children'] !== [$content] || $nodes[$content]['children'] !== $flows) {
            return null;
        }
        $style = $nodes[$content]['attrs']['style'] ?? '';
        if (preg_match_all('/(?:\A|;)\s*padding(?:-[a-z]+)?\s*:/i', $style) !== 1
            || preg_match('/(?:\A|;)padding:16px 0 0(?=;|\z)/', $style) !== 1) {
            return null;
        }
        $opening = preg_replace_callback('~(\sstyle\s*=\s*)(["\'])(.*?)\2~is', static fn (array $match): string => $match[1].$match[2]
            .preg_replace('/(^|;)padding:16px 0 0(?=;|\z)/', '$1padding:16px 0 0!important', $match[3]).$match[2], $nodes[$content]['opening'], -1, $count);

        return $count === 1 ? [$nodes[$content], $opening] : null;
    }

    /** Own only the known empty writing paragraph, never authored greetings or quoted content. */
    public static function markEditableRegion(string $html): string
    {
        [$nodes, $frame] = self::fragment($html, 'rt-outlook-template', 'rtt', 12);
        $marked = array_keys(array_filter($nodes, self::editableMarker(...)));
        if (($marked === [] && (str_contains($html, self::EDITABLE_START) || str_contains($html, self::EDITABLE_END)))
            || ($marked !== [] && (substr_count($html, self::EDITABLE_START) !== 1 || substr_count($html, self::EDITABLE_END) !== 1))) {
            self::fail();
        }
        preg_match_all('~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>~is', $html, $opaque, PREG_OFFSET_CAPTURE);
        $bounds = [];
        foreach ($opaque[0] as [$token, $offset]) {
            foreach (['START', 'END'] as $edge) {
                if (preg_match('~\A<!--\s*RT_APPLICATION_CONTENT_'.$edge.'\s*-->\z~', $token)) {
                    $bounds[$edge][] = [$offset, $offset + strlen($token)];
                }
            }
        }
        if ($bounds === [] && $marked === []) {
            return $html;
        }
        if (count($bounds['START'] ?? []) !== 1 || count($bounds['END'] ?? []) !== 1) {
            self::fail();
        }
        $start = $bounds['START'][0][1];
        $end = $bounds['END'][0][0];
        if ($start >= $end || $start <= $nodes[$frame]['start'] || $end >= $nodes[$frame]['close']) {
            self::fail();
        }
        $paragraphs = array_keys(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, 'design-spacer')
            && $node['start'] >= $start && $node['end'] <= $end));
        if (count($marked) > 1 || ($marked !== [] && count($paragraphs) !== 1)) {
            self::fail();
        }
        if (count($paragraphs) !== 1) {
            return $html;
        }
        $paragraph = $nodes[$paragraphs[0]];
        $parent = $paragraph['parent'];
        $wrapper = $marked === [] ? null : $nodes[$marked[0]];
        if ($wrapper !== null) {
            $cell = $paragraph['parent'];
            $row = $cell === null ? null : $nodes[$cell]['parent'];
            $body = $row === null ? null : $nodes[$row]['parent'];
            if ($wrapper['tag'] !== 'table' || ($wrapper['attrs']['class'] ?? '') !== self::EDITABLE_CLASS.' '.self::EDITABLE_TABLE_CLASS
                || ($wrapper['attrs'][self::EDITABLE_ATTRIBUTE] ?? '') !== self::EDITABLE_VERSION
                || $cell === null || $row === null || $body === null || $nodes[$body]['tag'] !== 'tbody' || $nodes[$row]['tag'] !== 'tr' || $nodes[$cell]['tag'] !== 'td'
                || $wrapper['children'] !== [$body] || $nodes[$body]['children'] !== [$row] || $nodes[$row]['children'] !== [$cell]
                || count($nodes[$cell]['children']) !== 3 || $nodes[$cell]['children'][1] !== $paragraphs[0]
                || $nodes[$body]['parent'] !== $marked[0] || $wrapper['opening'] !== self::editableOpening()
                || $wrapper['start'] < $start || $wrapper['end'] > $end
                || substr($html, $wrapper['start'], $wrapper['end'] - $wrapper['start']) !== self::editableOpening().'<tbody><tr><td style="padding:0;">'
                    .self::editableBoundary(self::EDITABLE_START).substr($html, $paragraph['start'], $paragraph['end'] - $paragraph['start']).self::editableBoundary(self::EDITABLE_END).'</td></tr></tbody></table>'
                || trim(substr($html, $nodes[$cell]['start'] + strlen($nodes[$cell]['opening']), $nodes[$cell]['close'] - $nodes[$cell]['start'] - strlen($nodes[$cell]['opening'])))
                    !== self::editableBoundary(self::EDITABLE_START).substr($html, $paragraph['start'], $paragraph['end'] - $paragraph['start']).self::editableBoundary(self::EDITABLE_END)) {
                self::fail();
            }
            $parent = $wrapper['parent'];
        }
        $eligible = $paragraph['tag'] === 'p' && $parent !== null && $nodes[$parent]['tag'] === 'td';
        for ($ancestor = $paragraphs[0]; $ancestor !== null; $ancestor = $nodes[$ancestor]['parent']) {
            $node = $nodes[$ancestor];
            if (in_array($node['tag'], ['blockquote', 'q'], true) || array_key_exists('hidden', $node['attrs'])
                || ($node['attrs']['aria-hidden'] ?? '') === 'true' || self::hasClass($node, 'rt-outlook-signature')) {
                $eligible = false;
            }
        }
        $inner = substr($html, $paragraph['start'] + strlen($paragraph['opening']), $paragraph['close'] - $paragraph['start'] - strlen($paragraph['opening']));
        $blank = trim(str_replace("\u{00a0}", '', CssSemantic::decodeHtmlEntitiesOnce(preg_replace('~<br\s*/?>~i', '', $inner)))) === '';
        if (! $eligible || ! $blank) {
            if ($wrapper !== null) {
                self::fail();
            }

            return $html;
        }
        if ($wrapper !== null) {
            return $html;
        }
        $opening = self::editableOpening().'<tbody><tr><td style="padding:0;">'.self::editableBoundary(self::EDITABLE_START);

        return substr($html, 0, $paragraph['start']).$opening
            .substr($html, $paragraph['start'], $paragraph['end'] - $paragraph['start']).self::editableBoundary(self::EDITABLE_END).'</td></tr></tbody></table>'.substr($html, $paragraph['end']);
    }

    private static function editableOpening(): string
    {
        return '<table class="'.self::EDITABLE_CLASS.' '.self::EDITABLE_TABLE_CLASS.'" '.self::EDITABLE_ATTRIBUTE.'="'.self::EDITABLE_VERSION.'" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">';
    }

    private static function editableBoundary(string $marker): string
    {
        return '<span hidden aria-hidden="true" class="rt-office-metadata" style="display:none!important;mso-hide:all;font-size:0;line-height:0;max-height:0;overflow:hidden;">'.$marker.'</span>';
    }

    /** Only the two exact owned boundaries in the derived mobile document. */
    public static function compactMobileEditableBounds(string $html): string
    {
        if (! str_contains($html, 'data-rt-compose-document="'.OutlookMobileCombinedComposeDocument::MODE.'"')) {
            return $html;
        }
        preg_match_all('~<style\b[^>]*data-rt-mail-document-css\b[^>]*>(.*?)</style\s*>~is', $html, $authored);
        if (array_filter($authored[1], static fn (string $css): bool => trim($css) !== '') !== []) {
            // Authored span selectors can observe the removed metadata class.
            return $html;
        }
        $nodes = self::nodes($html);
        $roots = array_keys(array_filter($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-compose-document'] ?? '') === OutlookMobileCombinedComposeDocument::MODE));
        if (count($roots) !== 1) {
            self::fail();
        }
        $tokens = [self::EDITABLE_START, self::EDITABLE_END];
        if (! str_contains($html, $tokens[0]) && ! str_contains($html, $tokens[1])) {
            return $html;
        }
        $parent = null;
        $replacements = [];
        foreach ($tokens as $position => $token) {
            $boundaries = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'span' && substr($html, $node['start'], $node['end'] - $node['start']) === self::editableBoundary($token)));
            $compact = '<span hidden style="display:none;mso-hide:all;font-size:0;line-height:0">'.$token.'</span>';
            $compacted = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'span' && substr($html, $node['start'], $node['end'] - $node['start']) === $compact));
            $matches = array_merge($boundaries, $compacted);
            if (count($matches) !== 1 || substr_count($html, $token) !== 1) {
                self::fail();
            }
            $index = $matches[0];
            $cell = $nodes[$index]['parent'];
            if ($cell === null || $nodes[$cell]['tag'] !== 'td' || ($parent !== null && $parent !== $cell)
                || $nodes[$index]['start'] <= $nodes[$roots[0]]['start'] || $nodes[$index]['end'] >= $nodes[$roots[0]]['end']
                || ($position === 0 ? $nodes[$cell]['children'][0] : $nodes[$cell]['children'][array_key_last($nodes[$cell]['children'])]) !== $index) {
                self::fail();
            }
            $parent = $cell;
            $replacements[self::editableBoundary($token)] = $compact;
        }
        $row = $nodes[$parent]['parent'];
        $body = $row === null ? null : $nodes[$row]['parent'];
        $table = $body === null ? null : $nodes[$body]['parent'];
        if ($row === null || $body === null || $table === null || $nodes[$row]['tag'] !== 'tr' || $nodes[$body]['tag'] !== 'tbody'
            || $nodes[$table]['tag'] !== 'table' || ! self::hasClass($nodes[$table], self::EDITABLE_TABLE_CLASS)
            || $nodes[$table]['children'] !== [$body] || $nodes[$body]['children'] !== [$row] || $nodes[$row]['children'] !== [$parent]) {
            self::fail();
        }

        return strtr($html, $replacements);
    }

    private static function editableMarker(array $node): bool
    {
        return self::hasClass($node, self::EDITABLE_CLASS) || self::hasClass($node, self::EDITABLE_TABLE_CLASS) || array_key_exists(self::EDITABLE_ATTRIBUTE, $node['attrs']);
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
