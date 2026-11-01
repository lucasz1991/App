<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Output-only v30-c-1 design. Published sources and V11–V15 stay immutable. */
final class OutlookV30ReferencePresentation
{
    public const FLOW = 'outlook-desktop-flow-v16';

    public const REVISION = 'outlook-v30-c-1-presentation-v2';

    private const TOKENS = '~<!doctype\b[^>]*>|<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    private const COLUMNS = [
        'identity' => [200, 220, 16, 24],
        'direct' => [260, 280, 16, 24],
        'company' => [230, 230, 16, 16],
        'dispatch' => [247, 227, 20, 0],
    ];

    private const SHELL_SUFFIX = ';max-width:1040px!important;margin:0!important;';

    private const SYSTEM_LAYOUT_SUFFIX = ';table-layout:fixed!important;';

    /** The existing admitted linked-delivery adapter enforces final UTF16/CSS budgets after compaction. */
    public static function payload(array $payload): array
    {
        foreach (['signature', 'template'] as $key) {
            if (isset($payload[$key]['html'])) {
                $payload[$key]['html'] = self::document($payload[$key]['html']);
            }
        }
        foreach ($payload['templates'] ?? [] as $index => $entry) {
            foreach (['html', 'composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                if (isset($entry[$field])) {
                    $entry[$field] = self::document($entry[$field]);
                }
            }
            if (isset($entry['signature']['html'])) {
                $entry['signature']['html'] = self::document($entry['signature']['html']);
            }
            $payload['templates'][$index] = $entry;
        }

        return $payload;
    }

    public static function document(string $html): string
    {
        if (str_contains($html, 'data-rt-desktop-flow="'.self::FLOW.'"')) {
            self::canonicalizeToV15($html);

            return $html;
        }
        if (! str_contains($html, 'data-rt-desktop-flow="'.OutlookAnimatedSignaturePresentation::FLOW.'"')) {
            return self::templateOnly($html);
        }
        preg_match_all('~<style\b[^>]*data-rt-mail-document-css=(["\'])signature\1[^>]*>(.*?)</style\s*>~is', $html, $authored);
        if (array_filter($authored[2], static fn (string $css): bool => trim($css) !== '') !== []) {
            return $html;
        }
        OutlookAnimatedSignaturePresentation::document($html);
        $result = self::project($html, true);
        if (self::canonicalizeToV15($result) !== $html) {
            self::fail();
        }

        return $result;
    }

    /** Strict source inverse, never a repair or a received-message normalizer. */
    public static function canonicalizeToV15(string $html): string
    {
        $result = self::project($html, false);
        OutlookAnimatedSignaturePresentation::document($result);

        return $result;
    }

    /** The separately compiled template-only field owns no signature material. */
    private static function templateOnly(string $html): string
    {
        if (! str_contains($html, 'RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE')
            || str_contains($html, 'RT-SIGNATURE-MANAGED-V1')) {
            return $html;
        }
        $nodes = self::nodes($html);
        $wrappers = self::findClass($nodes, 'rt-outlook-template');
        if (count($wrappers) !== 1 || $nodes[$wrappers[0]]['tag'] !== 'div') {
            return $html;
        }
        // A native template is the only non-STYLE top-level document. A quote,
        // reply header or surrounding authored document is never its owner.
        foreach ($nodes as $index => $node) {
            if (! self::within($nodes, $index, $wrappers[0]) && $node['tag'] !== 'style') {
                return $html;
            }
        }
        $outside = substr($html, 0, $nodes[$wrappers[0]]['start']).substr($html, $nodes[$wrappers[0]]['end']);
        $outside = preg_replace(self::TOKENS, '', $outside);
        if (trim($outside) !== '') {
            return $html;
        }
        $frames = self::findClass($nodes, 'rt-native-compose-frame');
        $shells = self::findClass($nodes, 'rt-shell');
        $pads = self::findClass($nodes, 'rt-native-template-pad');
        if (count($frames) !== 1 || count($shells) !== 1 || count($pads) !== 2
            || ! self::within($nodes, $frames[0], $wrappers[0])
            || ! self::within($nodes, $shells[0], $frames[0])) {
            return $html;
        }
        $sheet = '<style data-rt-v30-template-css="1">@media(max-width:520px){.v30 .rt-v16-template-pad{padding-left:20px!important;padding-right:20px!important}}</style>';
        if (str_contains($html, 'data-rt-v30-template-css=')) {
            if (substr_count($html, $sheet) !== 1
                || ! str_contains($nodes[$frames[0]]['attrs']['style'] ?? '', 'border-left:0!important;')
                || array_filter($pads, static fn (int $index): bool => ! self::has($nodes[$index], 'rt-v16-template-pad')) !== []) {
                self::fail();
            }
            foreach ([$frames[0], $shells[0]] as $index) {
                if (! str_ends_with($nodes[$index]['attrs']['style'] ?? '', self::SHELL_SUFFIX)
                    || ! self::has($nodes[$index], 'rt-v16-shell')) {
                    self::fail();
                }
            }
            $border = [];
            preg_match_all('~(?:^|;)\s*border(?:-left(?:-width)?)?\s*:[^;]*(?=;|$)~i', $nodes[$frames[0]]['attrs']['style'] ?? '', $border);
            if (count($border[0]) !== 1 || trim($border[0][0], '; ') !== 'border-left:0!important') {
                self::fail();
            }
            foreach ($pads as $position => $index) {
                $padding = [];
                preg_match_all('~(?:^|;)\s*(padding(?:-[a-z]+)*)\s*:([^;]*)(?=;|$)~i', $nodes[$index]['attrs']['style'] ?? '', $padding, PREG_SET_ORDER);
                if (! self::within($nodes, $index, $shells[0]) || count($padding) !== 1
                    || $padding[0][1] !== 'padding'
                    || $padding[0][2] !== ($position === 0 ? '0 40px 0 40px' : '32px 40px 30px 40px')) {
                    self::fail();
                }
            }

            return $html;
        }
        if (($nodes[$shells[0]]['attrs']['data-rt-linked'] ?? '') !== 'animated-v1'
            || isset($nodes[$shells[0]]['attrs']['data-rt-static'])) {
            return $html;
        }
        $edits = [[0, 0, $sheet]];
        foreach ([$frames[0], $shells[0]] as $index) {
            $style = $nodes[$index]['attrs']['style'] ?? '';
            if ($index === $frames[0]) {
                if (! str_contains($style, 'border-left:3px solid #e90032!important;')) {
                    return $html;
                }
                $style = self::exactReplace($style, 'border-left:3px solid #e90032!important;', 'border-left:0!important;');
            }
            $edits[] = [$nodes[$index]['start'], $nodes[$index]['openEnd'] - $nodes[$index]['start'], self::opening($nodes[$index], [
                'class' => ($nodes[$index]['attrs']['class'] ?? '').' v30 rt-v16-shell', 'style' => $style.self::SHELL_SUFFIX,
            ])];
        }
        foreach ($pads as $index) {
            if (! self::within($nodes, $index, $shells[0])) {
                self::fail();
            }
            $style = $nodes[$index]['attrs']['style'] ?? '';
            if (str_contains($style, 'padding:0 18px 0 18px!important;')) {
                $style = self::exactReplace($style, 'padding:0 18px 0 18px!important;', 'padding:0 40px 0 40px;');
            } elseif (str_contains($style, 'padding:0 18px 26px 18px!important;')) {
                $style = self::exactReplace($style, 'padding:0 18px 26px 18px!important;', 'padding:32px 40px 30px 40px;');
            } else {
                return $html;
            }
            $edits[] = [$nodes[$index]['start'], $nodes[$index]['openEnd'] - $nodes[$index]['start'], self::opening($nodes[$index], [
                'class' => ($nodes[$index]['attrs']['class'] ?? '').' rt-v16-template-pad', 'style' => $style,
            ])];
        }

        return self::replace($html, $edits);
    }

    private static function project(string $html, bool $forward): string
    {
        $family = $forward ? 15 : 16;
        $nodes = self::nodes($html);
        $root = self::one($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-desktop-flow'] ?? '') === 'outlook-desktop-flow-v'.$family);
        $system = self::has($nodes[$root], 'rt-system-columns');
        if (! $forward && $system) {
            $html = self::systemFrames($html, false);
            $nodes = self::nodes($html);
            $root = self::one($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-desktop-flow'] ?? '') === 'outlook-desktop-flow-v'.$family);
        }
        $main = self::oneClass($nodes, 'rt-v'.$family.'-columns-cell');
        $legal = self::oneClass($nodes, 'rt-v'.$family.'-legal-cell');
        $legalTable = self::oneClass($nodes, 'rt-brand-legal-table');
        $legalHost = $nodes[$legalTable]['parent'];
        if (! is_int($legalHost) || $nodes[$legalHost]['tag'] !== 'td' || ! self::within($nodes, $main, $root)) {
            self::fail();
        }
        $edits = [];
        $changes = [];
        $set = static function (int $index, array $attributes) use (&$changes): void {
            $changes[$index] = array_replace($changes[$index] ?? [], $attributes);
        };
        $style = static function (int $index, string $before, string $after) use ($nodes, $forward, $set): void {
            $expected = $forward ? $before : $after;
            if (($nodes[$index]['attrs']['style'] ?? '') !== $expected) {
                self::fail();
            }
            $set($index, ['style' => $forward ? $after : $before]);
        };
        $style($main, 'padding:12px 0 0 18px;font-size:0;line-height:0;vertical-align:top;', 'padding:12px 40px 0 40px;font-size:0;line-height:0;vertical-align:top;');
        $style($legal, 'padding:0 18px;', 'padding:12px 40px 16px;');
        $rootClass = $nodes[$root]['attrs']['class'] ?? '';
        if ($forward) {
            if (str_contains($rootClass, 'rt-v16-single-dispatch')) {
                self::fail();
            }
            $set($root, ['class' => $rootClass.' rt-v16-single-dispatch']);
        } else {
            if (! str_ends_with($rootClass, ' rt-v16-single-dispatch')
                || count(self::findClass($nodes, 'rt-v16-single-dispatch')) !== 1) {
                self::fail();
            }
            $set($root, ['class' => substr($rootClass, 0, -strlen(' rt-v16-single-dispatch'))]);
        }

        $identity = null;
        foreach (self::COLUMNS as $role => [$oldWidth, $newWidth, $oldRight, $newRight]) {
            $matches = self::findClass($nodes, 'rt-v'.$family.'-'.$role);
            if ($role === 'direct' && $matches === [] && self::has($nodes[$root], 'rt-system-columns')) {
                continue;
            }
            if (count($matches) !== 1 || ! self::within($nodes, $matches[0], $root)) {
                self::fail();
            }
            $index = $matches[0];
            if ($role === 'identity') {
                $identity = $index;
            }
            $width = $forward ? $oldWidth : $newWidth;
            if (($nodes[$index]['attrs']['width'] ?? '') !== (string) $width) {
                self::fail();
            }
            $set($index, ['width' => (string) ($forward ? $newWidth : $oldWidth)]);
            $referenceWidth = $role === 'direct' ? 'clamp(248px,calc(100% - 220px),280px)' : $newWidth.'px';
            $style($index, 'width:'.$oldWidth.'px;max-width:100%;border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0;', 'width:'.$referenceWidth.';max-width:100%;border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0;');
            $cell = self::oneClass($nodes, 'rt-v'.$family.'-'.$role.'-cell');
            $style($cell, 'padding:0 '.$oldRight.'px 0 0;vertical-align:top;font-family:Arial,sans-serif;font-size:13px;line-height:17px;', 'padding:0 '.$newRight.'px 0 0;vertical-align:top;font-family:\'Trebuchet MS\',Arial,sans-serif;font-size:13px;line-height:19px;');
        }
        if ($identity === null) {
            self::fail();
        }

        foreach ($nodes as $index => $node) {
            if (self::has($node, 'rt-contact-icon') && self::within($nodes, $index, $root)) {
                $expected = $forward ? '27' : '26';
                if (($node['attrs']['width'] ?? '') !== $expected) {
                    self::fail();
                }
                $original = $node['attrs']['style'] ?? '';
                $old = 'width:27px!important;min-width:27px!important;';
                $new = 'width:26px!important;min-width:26px!important;';
                $set($index, ['width' => $forward ? '26' : '27', 'style' => self::exactReplace($original, $forward ? $old : $new, $forward ? $new : $old)]);
                foreach (self::direct($nodes, $index) as $image) {
                    if ($nodes[$image]['tag'] !== 'img') {
                        self::fail();
                    }
                    $expected = $forward ? '18' : '15';
                    if (($nodes[$image]['attrs']['width'] ?? '') !== $expected || ($nodes[$image]['attrs']['height'] ?? '') !== $expected) {
                        self::fail();
                    }
                    $value = $forward ? '15' : '18';
                    $set($image, ['width' => $value, 'height' => $value, 'style' => self::property(self::property($nodes[$image]['attrs']['style'] ?? '', 'width', $expected.'px'.($forward ? '' : '!important'), $value.'px'.($forward ? '!important' : '')), 'height', $expected.'px'.($forward ? '' : '!important'), $value.'px'.($forward ? '!important' : ''))]);
                }
            }
            if (self::has($node, 'rt-delivery-contact-value') && self::within($nodes, $index, $root)) {
                $value = self::property($node['attrs']['style'] ?? '', 'word-break', $forward ? 'break-all' : 'normal', $forward ? 'normal' : 'break-all');
                foreach ([['font-size:13px!important;', 'font-size:13px;'], ['line-height:16px!important;', 'line-height:19px;']] as [$old, $new]) {
                    if (str_contains($value, $forward ? $old : $new)) {
                        $value = self::exactReplace($value, $forward ? $old : $new, $forward ? $new : $old);
                    }
                }
                $set($index, ['style' => $value]);
            }
            if (self::has($node, 'rt-personal-company-contacts') && self::within($nodes, $index, $root)) {
                $value = $node['attrs']['style'] ?? '';
                $suffix = ';margin-top:0!important;';
                if (! $forward && ! str_ends_with($value, $suffix)) {
                    self::fail();
                }
                $set($index, ['style' => $forward ? $value.$suffix : substr($value, 0, -strlen($suffix))]);
            }
            if (! self::has($nodes[$root], 'rt-system-columns') && self::has($node, 'rt-sign-name') && self::within($nodes, $index, $identity)) {
                $set($index, ['style' => self::property($node['attrs']['style'] ?? '', 'line-height', $forward ? '26px' : '27px', $forward ? '27px' : '26px')]);
            }
            if (! self::has($nodes[$root], 'rt-system-columns') && self::has($node, 'rt-personal-role') && self::within($nodes, $index, $identity)) {
                $original = $node['attrs']['style'] ?? '';
                foreach ([['color', '#e4002b', '#e90032'], ['letter-spacing', '.8px', '1.6px'], ['line-height', '16px', '15px']] as [$property, $old, $new]) {
                    $original = self::property($original, $property, $forward ? $old : $new, $forward ? $new : $old);
                }
                $set($index, ['style' => $original]);
            }
            if (isset($node['attrs']['style']) && (($node['tag'] === 'div' && $node['parent'] === $legal)
                || ($node['tag'] === 'span' && is_int($node['parent']) && $nodes[$node['parent']]['tag'] === 'div' && $nodes[$node['parent']]['parent'] === $legal && ! self::has($node, 'rt-office-metadata')))) {
                $old = $node['tag'] === 'span' ? 'font-size:9px;line-height:14px;color:#718096;' : 'margin:0;line-height:14px;';
                $new = $node['tag'] === 'span' ? 'font-size:10.5px;line-height:16px;color:#5c6875;' : 'margin:0;line-height:16px;';
                if (in_array($node['tag'], ['div', 'span'], true)) {
                    $style($index, $old, $new);
                }
            }
        }

        $train = self::oneClass($nodes, 'rt-v'.$family.'-train');
        $trainImage = self::oneClass($nodes, 'rt-v'.$family.'-train-image');
        $caption = self::oneClass($nodes, 'rt-v'.$family.'-dispatch-caption');
        foreach ([[$train, 242, 547, null, null], [$trainImage, 224, 529, 49, 115], [$caption, 177, 199, 34, 38]] as [$index, $oldWidth, $newWidth, $oldHeight, $newHeight]) {
            if (($nodes[$index]['attrs']['width'] ?? '') !== (string) ($forward ? $oldWidth : $newWidth)
                || ($oldHeight !== null && ($nodes[$index]['attrs']['height'] ?? '') !== (string) ($forward ? $oldHeight : $newHeight))) {
                self::fail();
            }
            $referenceWidth = $index === $train ? 'min(547px,100%)' : $newWidth.'px';
            $attrs = ['width' => (string) ($forward ? $newWidth : $oldWidth), 'style' => self::exactReplace($nodes[$index]['attrs']['style'] ?? '', 'width:'.($forward ? $oldWidth.'px' : $referenceWidth).';', 'width:'.($forward ? $referenceWidth : $oldWidth.'px').';')];
            if ($oldHeight !== null) {
                $attrs['height'] = (string) ($forward ? $newHeight : $oldHeight);
            }
            $set($index, $attrs);
        }

        // Only proven current output frames acquire a bounded left-aligned shell.
        foreach ($nodes as $index => $node) {
            if (self::has($node, 'rt-managed-signature-v1') || self::has($node, 'rt-combined-compose-frame') || self::has($node, 'rt-shell')) {
                $current = $node['attrs']['style'] ?? '';
                $class = $node['attrs']['class'] ?? '';
                if ($forward) {
                    if (str_contains($class, 'rt-v16-shell')) {
                        self::fail();
                    }
                    $set($index, ['style' => $current.self::SHELL_SUFFIX, 'class' => $class.' rt-v16-shell v30']);
                } else {
                    if (! str_ends_with($current, self::SHELL_SUFFIX) || ! str_ends_with($class, ' rt-v16-shell v30')) {
                        self::fail();
                    }
                    $set($index, ['style' => substr($current, 0, -strlen(self::SHELL_SUFFIX)), 'class' => substr($class, 0, -strlen(' rt-v16-shell v30'))]);
                }
            }
            if (($node['tag'] === 'td' && (self::within($nodes, $root, $index) || $index === $legalHost)) && isset($node['attrs']['style'])) {
                $current = $node['attrs']['style'];
                $class = $node['attrs']['class'] ?? '';
                if ($forward && str_contains($current, 'border-left:3px solid #e90032;')) {
                    $set($index, ['style' => self::exactReplace($current, 'border-left:3px solid #e90032;', 'border-left:0!important;'), 'class' => $class.' rt-v16-no-left']);
                } elseif (! $forward && str_ends_with($class, ' rt-v16-no-left')) {
                    $set($index, ['style' => self::exactReplace($current, 'border-left:0!important;', 'border-left:3px solid #e90032;'), 'class' => substr($class, 0, -strlen(' rt-v16-no-left'))]);
                }
            }
            if (self::has($node, 'rt-v9-top-rule')) {
                $current = $changes[$index]['style'] ?? $node['attrs']['style'] ?? '';
                $set($index, ['style' => self::exactReplace($current, $forward ? 'border-top:3px solid #e90032;' : 'border-top:2px solid #e90032;', $forward ? 'border-top:2px solid #e90032;' : 'border-top:3px solid #e90032;')]);
            }
            if (self::has($node, 'rt-v'.$family.'-template-pad')) {
                $current = $node['attrs']['style'] ?? '';
                $old = '18px';
                $new = '40px';
                $count = 0;
                $pattern = $forward ? '~padding:([^;!]+)!important;~' : '~padding:([^;!]+);~';
                $current = preg_replace_callback($pattern, static function (array $match) use ($forward, $old, $new, &$count): string {
                    $parts = explode(' ', $match[1]);
                    if (count($parts) !== 4 || $parts[1] !== ($forward ? $old : $new) || $parts[3] !== ($forward ? $old : $new)) {
                        self::fail();
                    }
                    $parts[1] = $parts[3] = $forward ? $new : $old;
                    if ($forward && $parts[0] === '0' && $parts[2] === '26px') {
                        $parts[0] = '32px';
                        $parts[2] = '30px';
                    } elseif (! $forward && $parts[0] === '32px' && $parts[2] === '30px') {
                        $parts[0] = '0';
                        $parts[2] = '26px';
                    }
                    $count++;

                    return 'padding:'.implode(' ', $parts).($forward ? '' : '!important').';';
                }, $current);
                if ($count !== 1) {
                    self::fail();
                }
                $set($index, ['style' => $current]);
            }
        }
        // The outer legal host never adds a second horizontal gutter.
        $current = $changes[$legalHost]['style'] ?? $nodes[$legalHost]['attrs']['style'] ?? '';
        $set($legalHost, ['style' => self::exactReplace($current, $forward ? ';padding:8px 0!important;' : ';padding:0!important;', $forward ? ';padding:0!important;' : ';padding:8px 0!important;')]);

        $scope = self::scope($nodes[$root], $family);
        $css = self::css(str_replace('rt-df'.$family, 'rt-df16', $scope), $system);
        // One real card remains visible even when Office removes STYLE/media.
        // A second hidden copy cannot be proved safe after that transport.
        if (str_contains($html, 'rt-v16-mobile-dispatch')) {
            self::fail();
        }
        if ($forward) {
            if (str_contains($html, 'data-rt-v30-css=')) {
                self::fail();
            }
        } else {
            if (substr_count($html, $css) !== 1) {
                self::fail();
            }
            $edits[] = [strpos($html, $css), strlen($css), ''];
        }
        foreach ($changes as $index => $attributes) {
            $edits[] = [$nodes[$index]['start'], $nodes[$index]['openEnd'] - $nodes[$index]['start'], ($forward && $index === $root ? $css : '').self::opening($nodes[$index], $attributes)];
        }
        $result = self::replace($html, $edits);

        $result = self::family($result, $forward ? 15 : 16, $forward ? 16 : 15);

        return $forward && $system ? self::systemFrames($result, true) : $result;
    }

    private static function css(string $scope, bool $system = false): string
    {
        $s = '.v30';

        $css = '<style data-rt-v30-css="v30-c-1">'
            .$s.' .rt-delivery-contact-value{line-height:19px!important;word-break:normal!important}'
            .$s.' .rt-company-contact{margin-top:0!important}'
            .'@media(max-width:520px){'
            .$s.' .rt-v16-columns-cell{padding-left:20px!important;padding-right:20px!important}'
            .$s.' .rt-v16-identity{width:100%!important}'
            .$s.' .rt-personal-logo-frame{float:right!important;width:104px!important;margin:5px 0 0 12px!important}'
            .$s.' .rt-personal-logo-frame td{padding:0!important}'
            .$s.' .rt-v9-logo{width:104px!important;height:auto!important}'
            .$s.' .rt-v16-identity-cell{padding:0 0 14px!important}'
            .$s.' .rt-v16-direct{width:52%!important}'
            .$s.' .rt-v16-company{width:48%!important}'
            .$s.' .rt-v16-direct-cell{padding-right:4px!important}'
            .$s.' .rt-v16-company-cell{padding:0 0 0 4px!important}'
            .$s.' .rt-contact td,'.$s.' .rt-delivery-contact-value{font-size:12px!important;line-height:18px!important}'
            .$s.' .rt-v16-legal-cell{padding-left:20px!important;padding-right:20px!important}'
            .$s.' .rt-v16-template-pad{padding-left:20px!important;padding-right:20px!important}'
            .'}</style>';
        if (! $system) {
            return $css;
        }

        // The installed system inliner recognizes media queries only with a
        // space after @media. Keep employee wire bytes unchanged here.
        return str_replace([
            '@media(max-width:520px)',
            '.v30 .rt-personal-logo-frame{float:right!important;width:104px!important;margin:5px 0 0 12px!important}',
            '.v30 .rt-personal-logo-frame td{padding:0!important}',
            '.v30 .rt-v9-logo{width:104px!important;height:auto!important}',
            '.v30 .rt-v16-direct{width:52%!important}',
            '.v30 .rt-v16-company{width:48%!important}',
            '.v30 .rt-v16-direct-cell{padding-right:4px!important}',
            '.v30 .rt-v16-company-cell{padding:0 0 0 4px!important}',
        ], [
            '@media (max-width:520px)',
            '.v30 .rt-personal-logo-frame{float:none!important;width:184px!important;margin:0!important}',
            '',
            '.v30 .rt-v9-logo{width:184px!important;height:auto!important}',
            '',
            '.v30 .rt-v16-company{width:100%!important}',
            '',
            '.v30 .rt-v16-company-cell{padding:0!important}',
        ], $css);
    }

    /** Exactly the canonical system factory ancestors, never employee frames. */
    private static function systemFrames(string $html, bool $forward): string
    {
        $nodes = self::nodes($html);
        $root = self::one($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-desktop-flow'] ?? '') === self::FLOW);
        if (! self::has($nodes[$root], 'rt-system-columns')) {
            self::fail();
        }
        $train = self::oneClass($nodes, 'rt-v16-train');
        if (! self::within($nodes, $train, $root)) {
            self::fail();
        }
        $shell = self::oneClass($nodes, 'rt-shell');
        $wrapper = $nodes[$shell]['parent'];
        if (! self::within($nodes, $root, $shell) || ! is_int($wrapper) || $nodes[$wrapper]['tag'] !== 'div') {
            self::fail();
        }
        $cell = $nodes[$wrapper]['parent'];
        $row = is_int($cell) ? $nodes[$cell]['parent'] : null;
        $body = is_int($row) ? $nodes[$row]['parent'] : null;
        $outer = is_int($body) ? $nodes[$body]['parent'] : null;
        if (! is_int($cell) || $nodes[$cell]['tag'] !== 'td' || ! self::has($nodes[$cell], 'design-page-pad')
            || ! is_int($row) || $nodes[$row]['tag'] !== 'tr' || ! is_int($body) || $nodes[$body]['tag'] !== 'tbody'
            || ! is_int($outer) || $nodes[$outer]['tag'] !== 'table' || ($nodes[$outer]['attrs']['width'] ?? '') !== '100%'
            || ($nodes[$outer]['attrs']['class'] ?? '') !== '' || ($nodes[$wrapper]['attrs']['class'] ?? '') !== '') {
            self::fail();
        }
        $base = 'background-color:#ffffff!important;width:100%;';
        $wrapperBefore = $base.'box-sizing:border-box;border-left:3px solid #e90032;overflow:hidden;';
        $wrapperAfter = $base.'box-sizing:border-box;border-left:0!important;overflow:hidden;';
        $shellStyle = $nodes[$shell]['attrs']['style'] ?? '';
        $rootStyle = 'width:100%;table-layout:fixed;border-collapse:collapse;direction:ltr;';
        $trainStyle = 'width:min(547px,100%);max-width:100%!important;border-collapse:collapse;';
        if (! str_ends_with($shellStyle, $forward ? self::SHELL_SUFFIX : self::SHELL_SUFFIX.self::SYSTEM_LAYOUT_SUFFIX)
            || ($nodes[$outer]['attrs']['style'] ?? '') !== $base.($forward ? '' : self::SYSTEM_LAYOUT_SUFFIX)
            || ($nodes[$wrapper]['attrs']['style'] ?? '') !== ($forward ? $wrapperBefore : $wrapperAfter)
            || ($nodes[$root]['attrs']['style'] ?? '') !== $rootStyle.($forward ? '' : self::SYSTEM_LAYOUT_SUFFIX)
            || ($nodes[$train]['attrs']['style'] ?? '') !== $trainStyle.($forward ? '' : self::SYSTEM_LAYOUT_SUFFIX)) {
            self::fail();
        }

        return self::replace($html, [
            // Legacy responsive system CSS sets table-layout:auto!important.
            // Authored inline important keeps these owned tables fixed even
            // when that media rule is retained after system inlining.
            [$nodes[$root]['start'], $nodes[$root]['openEnd'] - $nodes[$root]['start'], self::opening($nodes[$root], ['style' => $rootStyle.($forward ? self::SYSTEM_LAYOUT_SUFFIX : '')])],
            [$nodes[$train]['start'], $nodes[$train]['openEnd'] - $nodes[$train]['start'], self::opening($nodes[$train], ['style' => $trainStyle.($forward ? self::SYSTEM_LAYOUT_SUFFIX : '')])],
            [$nodes[$wrapper]['start'], $nodes[$wrapper]['openEnd'] - $nodes[$wrapper]['start'], self::opening($nodes[$wrapper], ['style' => $forward ? $wrapperAfter : $wrapperBefore])],
            [$nodes[$outer]['start'], $nodes[$outer]['openEnd'] - $nodes[$outer]['start'], self::opening($nodes[$outer], ['style' => $base.($forward ? self::SYSTEM_LAYOUT_SUFFIX : '')])],
            [$nodes[$shell]['start'], $nodes[$shell]['openEnd'] - $nodes[$shell]['start'], self::opening($nodes[$shell], ['style' => $forward ? $shellStyle.self::SYSTEM_LAYOUT_SUFFIX : substr($shellStyle, 0, -strlen(self::SYSTEM_LAYOUT_SUFFIX))])],
        ]);
    }

    private static function scope(array $root, int $family): string
    {
        $found = array_values(array_filter(self::classes($root), static fn (string $class): bool => preg_match('/\Art-df'.$family.'[a-z0-9]{10}\z/', $class) === 1));
        if (count($found) !== 1) {
            self::fail();
        }

        return $found[0];
    }

    private static function family(string $html, int $from, int $to): string
    {
        return preg_replace_callback(self::TOKENS, static function (array $match) use ($from, $to): string {
            $token = $match[0];
            if (preg_match('~\A<style\b~i', $token)) {
                return str_replace(['rt-df'.$from, '.rt-v'.$from.'-'], ['rt-df'.$to, '.rt-v'.$to.'-'], $token);
            }
            if (! preg_match('~\A<[a-z]~i', $token)) {
                return $token;
            }
            $token = preg_replace_callback('~\bclass=("|\')([^"\']*)\1~i', static fn (array $c): string => 'class='.$c[1].str_replace(['rt-df'.$from, 'rt-v'.$from.'-'], ['rt-df'.$to, 'rt-v'.$to.'-'], $c[2]).$c[1], $token);

            return str_replace(['data-rt-desktop-flow="outlook-desktop-flow-v'.$from.'"', 'data-rt-dispatch-card="v'.$from.'"'], ['data-rt-desktop-flow="outlook-desktop-flow-v'.$to.'"', 'data-rt-dispatch-card="v'.$to.'"'], $token);
        }, $html);
    }

    private static function property(string $style, string $name, string $before, string $after): string
    {
        $count = 0;
        $result = preg_replace_callback('~((?:^|;)\s*'.preg_quote($name, '~').'\s*:\s*)'.preg_quote($before, '~').'(?=\s*(?:!important)?\s*(?:;|$))~i', static function (array $match) use ($after, &$count): string {
            $count++;

            return $match[1].$after;
        }, $style);
        if ($count !== 1) {
            self::fail();
        }

        return $result;
    }

    private static function exactReplace(string $value, string $before, string $after): string
    {
        if (substr_count($value, $before) !== 1) {
            self::fail();
        }

        return str_replace($before, $after, $value);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function findClass(array $nodes, string $class): array
    {
        return array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, $class)));
    }

    private static function oneClass(array $nodes, string $class): int
    {
        return self::one($nodes, static fn (array $node): bool => self::has($node, $class));
    }

    private static function one(array $nodes, callable $predicate): int
    {
        $found = array_keys(array_filter($nodes, $predicate));
        if (count($found) !== 1) {
            self::fail();
        }

        return $found[0];
    }

    private static function within(array $nodes, int $index, int $parent): bool
    {
        do {
            if ($index === $parent) {
                return true;
            }
            $index = $nodes[$index]['parent'];
        } while (is_int($index));

        return false;
    }

    private static function replace(string $html, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $last = strlen($html) + 1;
        foreach ($edits as [$offset, $length, $replacement]) {
            if ($offset + $length > $last) {
                self::fail();
            }
            $html = substr_replace($html, $replacement, $offset, $length);
            $last = $offset;
        }

        return $html;
    }

    private static function classes(array $node): array
    {
        return preg_split('/\s+/', trim($node['attrs']['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    }

    private static function has(array $node, string $class): bool
    {
        return in_array($class, self::classes($node), true);
    }

    private static function direct(array $nodes, int $parent): array
    {
        return array_keys(array_filter($nodes, static fn (array $node): bool => $node['parent'] === $parent));
    }

    private static function opening(array $node, array $changes): string
    {
        $tag = $node['opening'];
        $edits = [];
        $new = '';
        foreach ($changes as $key => $value) {
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
        throw new RuntimeException('Die Outlook-v30-c-1-Ausgabe besitzt keinen eindeutigen Referenzvertrag.');
    }
}
