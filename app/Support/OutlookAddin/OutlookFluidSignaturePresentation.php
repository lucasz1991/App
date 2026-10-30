<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Portable V11 base: four legacy-aligned groups need no surviving responsive stylesheet. */
final class OutlookFluidSignaturePresentation
{
    public const FLOW = 'outlook-desktop-flow-v11';

    public const REVISION = 'outlook-fluid-signature-presentation-v1';

    private const TOKENS = '~<!doctype\b[^>]*>|<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    private const ASSETS = [
        '45f4109cf7dbeed8c31c4c8a56bfdd9065b947b4b576596ba4bd6e5373b73fb8' => ['zug-dampf-outlook-intrinsic-v1-light.gif', 'zug-dampf-outlook-brand-v1-light.gif', '47007453902679fbc96eda87bb8701db7f701b2c340d83e9561fa68c4dbd503d'],
        '78a1fd22c31977b21286a40b5aafe51b957b63b49f40948b6ed2b21ba0651d4a' => ['zug-dampf-outlook-intrinsic-v1-light.png', 'zug-dampf-outlook-brand-v1-light.png', '0b9b8d55f6a2343198a721915fe2fa2e61df6eb1e52f72bb91f32a4c4a2a5258'],
        '822dd45cf0529e41d8e1ed9a082904581d76be4a9f25c39240111e11fd3d3554' => ['zug-dampf-outlook-intrinsic-v1-dark.gif', 'zug-dampf-outlook-brand-v1-dark.gif', '243786c7e426e2f06165858c8a1883cfd5598b72df1247c7cc2b3ff6e41220a5'],
        'b1bc9d62a6dd026ffc9e6f0c181d1586084104223afa272766ee38371e4072dd' => ['zug-dampf-outlook-intrinsic-v1-dark.png', 'zug-dampf-outlook-brand-v1-dark.png', 'd8370fc0cc4d87ddad0a076cb5c2468e36ad1f5667966418cbfc2c519bd878b3'],
        '9db04d54004742501156413430e77ccf1b1ed8515018caa9c7d00caedaf63f17' => ['zug-dampf-outlook-intrinsic-v1-light-mirrored.gif', 'zug-dampf-outlook-brand-v1-light-mirrored.gif', '67d1600699cad2f1f2af69ea59a9d88a7085f26556966f083ab615401e430d39'],
        'dc6eb47779cca236195052802aac7c1e22dba1f8198fbfbadb5729b504df99d6' => ['zug-dampf-outlook-intrinsic-v1-light-mirrored.png', 'zug-dampf-outlook-brand-v1-light-mirrored.png', 'b6d051cacd77535d71366db071b9c077fdbd7d4d487eb369fed2a77fbe142792'],
        '18567efab2a728b8058195b8fef1651e97b2aff8c116261af9698afabdaad4d9' => ['zug-dampf-outlook-intrinsic-v1-dark-mirrored.gif', 'zug-dampf-outlook-brand-v1-dark-mirrored.gif', 'ed21ba08d9d32ede363a30d13d182aa802b426330fd0110c4b0f50b11c960768'],
        '4b6995aa470fb7730c0efa88d95528eb053bf95e3314a9567f2d36084370bd78' => ['zug-dampf-outlook-intrinsic-v1-dark-mirrored.png', 'zug-dampf-outlook-brand-v1-dark-mirrored.png', '917e8f055d74b6e4b2294df05fb74079cfb5e8526902cc87fda2e260b658861b'],
        '003919f6fe18e62a958217d165f9c3a93602f513af78bbd1470aaa6dc95a18ec' => ['outlook-hotline-label-outline-v1.png', 'outlook-hotline-label-inktop-v1.png', '37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a'],
    ];

    public static function payload(array $payload): array
    {
        foreach (['signature', 'template'] as $key) {
            if (isset($payload[$key]['html'])) {
                $payload[$key] = self::pair($payload[$key], 'html', 'media', $key === 'signature' ? 30000 : 99000);
            }
        }
        foreach ($payload['templates'] ?? [] as $index => $entry) {
            foreach (['html' => 'media', 'composeHtml' => 'composeMedia', 'combinedComposeHtml' => 'combinedComposeMedia', 'mobileComposeHtml' => 'mobileComposeMedia'] as $field => $media) {
                if (isset($entry[$field])) {
                    $entry = self::pair($entry, $field, $media, $field === 'mobileComposeHtml' ? 30000 : 99000);
                }
            }
            if (isset($entry['signature']['html'])) {
                $entry['signature'] = self::pair($entry['signature'], 'html', 'media', 30000);
            }
            $payload['templates'][$index] = $entry;
        }

        return $payload;
    }

    private static function pair(array $pair, string $field, string $media, int $limit): array
    {
        $before = $pair[$field];
        $after = self::document($before);
        if ($before === $after) {
            return $pair;
        }
        $nodes = self::nodes($after);
        $edits = [];
        $replaced = [];
        foreach ($nodes as $node) {
            if ($node['tag'] !== 'img' || (! self::has($node, 'rt-v11-train-image') && ! self::has($node, 'rt-v11-dispatch-label'))) {
                continue;
            }
            $src = $node['attrs']['src'] ?? '';
            if (! str_starts_with($src, 'cid:')) {
                continue;
            }
            $cid = substr($src, 4);
            $records = array_values(array_filter($pair[$media], static fn (array $asset): bool => ($asset['contentId'] ?? '') === $cid));
            if (count($records) !== 1 || ($binary = base64_decode($records[0]['base64'] ?? '', true)) === false) {
                self::fail();
            }
            $sha = hash('sha256', $binary);
            if (! isset(self::ASSETS[$sha])) {
                self::fail();
            }
            [, $filename, $expected] = self::ASSETS[$sha];
            $bytes = self::asset($filename, $expected);
            $name = 'railtime-'.substr($expected, 0, 20).'.'.pathinfo($filename, PATHINFO_EXTENSION);
            $attachment = ['name' => $name, 'contentId' => $name, 'base64' => base64_encode($bytes)];
            if (! array_filter($pair[$media], static fn (array $record): bool => $record === $attachment)) {
                $pair[$media][] = $attachment;
            }
            $replaced[] = $cid;
            $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['src' => 'cid:'.$name])];
        }
        $after = self::replace($after, $edits);
        $pair[$media] = array_values(array_filter($pair[$media], static fn (array $record): bool => ! in_array($record['contentId'] ?? '', $replaced, true) || str_contains($after, 'cid:'.$record['contentId'])));
        if (array_sum(array_map(static fn (array $record): int => strlen(base64_decode($record['base64'], true) ?: ''), $pair[$media])) > 2097152) {
            self::fail();
        }
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $after, $styles);
        if (intdiv(strlen(mb_convert_encoding($after, 'UTF-16LE', 'UTF-8')), 2) > $limit || strlen(implode('', $styles[1])) > ($limit === 30000 ? 12288 : 24576)) {
            self::fail();
        }
        $pair[$field] = $after;

        return $pair;
    }

    public static function document(string $html): string
    {
        preg_match_all('~<style\b[^>]*data-rt-mail-document-css=(["\'])signature\1[^>]*>(.*?)</style\s*>~is', $html, $authored);
        if (array_filter($authored[2], static fn (string $css): bool => trim($css) !== '') !== []) {
            return $html;
        }
        if (! str_contains($html, 'data-rt-desktop-flow="'.OutlookThreeCellPresentation::FLOW.'"') && ! str_contains($html, 'data-rt-desktop-flow="'.self::FLOW.'"')) {
            return str_contains($html, 'RT-TEMPLATE-MANAGED-V1') ? self::gutters($html, false) : $html;
        }
        $nodes = self::nodes($html);
        $roots = array_keys(array_filter($nodes, static fn (array $node): bool => in_array($node['attrs']['data-rt-desktop-flow'] ?? '', [self::FLOW, OutlookThreeCellPresentation::FLOW], true)));
        if (count($roots) !== 1) {
            self::fail();
        }
        $rootIndex = $roots[0];
        $root = $nodes[$rootIndex];
        if (($root['attrs']['data-rt-desktop-flow'] ?? '') === self::FLOW) {
            self::assertCurrent($html, $nodes, $rootIndex);

            return $html;
        }
        OutlookThreeCellPresentation::document($html);
        if (! preg_match('/(?:^|\s)rt-df10([a-f0-9]{10})(?:\s|$)/', $root['attrs']['class'] ?? '', $scopeMatch)) {
            self::fail();
        }
        $system = self::has($root, 'rt-system-columns');
        $oldScope = 'rt-df10'.$scopeMatch[1];
        $scope = 'rt-df11'.$scopeMatch[1];
        $body = self::singleChild($html, $nodes, $rootIndex, 'tbody');
        $rows = self::direct($nodes, $body);
        $outer = self::rowCells($html, $nodes, $rows[0], 3);
        $sourceColumns = [];
        foreach ($outer as $cell) {
            $table = self::singleChild($html, $nodes, $cell, 'table');
            $sourceColumns[] = self::singleChild($html, $nodes, self::singleRow($html, $nodes, $table), 'td');
        }
        $person = self::inner($html, $nodes[$sourceColumns[0]]);
        $personNodes = self::nodes($person);
        $direct = array_keys(array_filter($personNodes, static fn (array $node): bool => $node['tag'] === 'div' && self::has($node, 'rt-personal-direct')));
        if ((! $system && count($direct) !== 1) || ($system && count($direct) > 1)) {
            self::fail();
        }
        $directHtml = '';
        if ($direct !== []) {
            $directNode = $personNodes[$direct[0]];
            $directHtml = self::inner($person, $directNode);
            $person = substr_replace($person, '', $directNode['start'], $directNode['end'] - $directNode['start']);
        }
        $contents = [
            'identity' => [176, 16, 'rt-personal-left', $person],
            'company' => [172, 12, 'rt-personal-right', self::inner($html, $nodes[$sourceColumns[1]])],
            'dispatch' => [164, 4, 'rt-dispatch-column', self::inner($html, $nodes[$sourceColumns[2]])],
        ];
        if (! $system) {
            $contents = array_slice($contents, 0, 1, true) + ['direct' => [204, 0, 'rt-personal-direct', $directHtml]] + array_slice($contents, 1, null, true);
        }
        $columns = '';
        foreach ($contents as $role => [$width, $right, $class, $content]) {
            $content = self::contactSlots(self::nestedTables($content));
            if ($role === 'dispatch') {
                $labelNodes = self::nodes($content);
                $labels = array_keys(array_filter($labelNodes, static fn (array $node): bool => $node['tag'] === 'img' && ($node['attrs']['alt'] ?? '') === 'Dispo 24/7 erreichbar'));
                if (count($labels) !== 1) {
                    self::fail();
                }
                $label = $labelNodes[$labels[0]];
                $content = substr_replace($content, self::opening($label, ['class' => 'rt-v11-dispatch-label', 'height' => '22', 'src' => self::assetUrl($label['attrs']['src']), 'style' => 'display:block;width:160px;max-width:100%;height:auto;border:0;']), $label['start'], $label['openEnd'] - $label['start']);
            }
            $columns .= '<table class="'.$class.' rt-v11-column rt-v11-'.$role.'" role="presentation" width="'.$width.'" align="left" border="0" cellspacing="0" cellpadding="0" style="width:'.$width.'px;max-width:100%;border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0;"><tbody><tr><td class="rt-v11-'.$role.'-cell" valign="top" style="padding:0 '.$right.'px 0 0;vertical-align:top;font-family:Arial,sans-serif;font-size:13px;line-height:17px;">'.$content.'</td></tr></tbody></table>';
        }
        $trainCell = self::singleChild($html, $nodes, $rows[1], 'td');
        $trainIndex = self::singleChild($html, $nodes, $trainCell, 'table');
        $train = $nodes[$trainIndex];
        $imageNodes = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'img' && self::has($node, 'rt-v10-train-image')));
        if (count($imageNodes) !== 1 || ! self::within($nodes, $imageNodes[0], $trainIndex)) {
            self::fail();
        }
        $image = $nodes[$imageNodes[0]];
        $newImage = self::opening($image, ['class' => 'rt-delivery-train rt-v11-train-image', 'width' => '200', 'height' => '43', 'src' => self::assetUrl($image['attrs']['src']), 'style' => 'display:block;width:200px;max-width:100%;height:auto;margin:0;border:0;vertical-align:bottom;']);
        $max = $system ? 530 : 734;
        $rootOpen = self::opening($root, ['class' => str_replace(['rt-v10-columns', $oldScope], ['rt-v11-columns', $scope], $root['attrs']['class']), 'data-rt-desktop-flow' => self::FLOW, 'width' => '100%', 'style' => 'width:100%;max-width:'.$max.'px;border-collapse:collapse;']);
        $flow = $rootOpen.'<tbody><tr><td class="rt-v11-columns-cell" style="padding:10px 0 0 18px;font-size:0;line-height:0;vertical-align:top;">'.$columns.'<br clear="all" style="font-size:0;line-height:0;"></td></tr><tr><td class="rt-v11-train-host" style="padding:0;font-size:0;line-height:0;vertical-align:bottom;"><table class="rt-df-train rt-v11-train" role="presentation" width="218" border="0" cellspacing="0" cellpadding="0" style="width:218px;max-width:100%;border-collapse:collapse;"><tbody><tr><td class="rt-v11-train-cell" style="padding:0 18px 0 0;font-size:0;line-height:0;vertical-align:bottom;"><div class="rt-train-window">'.$newImage.'</div></td></tr></tbody></table></td></tr></tbody></table>';
        $html = substr_replace($html, $flow, $root['start'], $root['end'] - $root['start']);
        $html = preg_replace('~<style\b(?=[^>]*data-rt-three-cell-css="1")[^>]*>.*?</style\s*>~is', '', $html, 1, $removed);
        if ($removed !== 1) {
            self::fail();
        }
        $html = str_replace($oldScope, $scope, $html);
        $html = self::gutters($html, true);
        $nodes = self::nodes($html);
        $current = array_keys(array_filter($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-desktop-flow'] ?? '') === self::FLOW));
        self::assertCurrent($html, $nodes, $current[0]);
        $css = '@media(min-width:740px){.'.$scope.' .rt-v11-train{width:520px!important;max-width:100%!important;}.'.$scope.' .rt-v11-train-cell{padding-right:40px!important;}.'.$scope.' .rt-v11-train-image{width:100%!important;max-width:100%!important;height:auto!important;}}';
        $sheet = '<style data-rt-fluid-signature-css="1">'.$css.'</style>';
        if ($system) {
            $html = preg_replace('~</head\s*>~i', $sheet.'$0', $html, 1);
        } else {
            $line = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'td' && self::has($node, 'rt-v9-top-rule')));
            if (count($line) !== 1) {
                self::fail();
            }
            $html = substr_replace($html, $sheet, $nodes[$line[0]]['openEnd'], 0);
        }

        return $html;
    }

    private static function nestedTables(string $html): string
    {
        $edits = [];
        foreach (self::nodes($html) as $node) {
            if ($node['tag'] !== 'table') {
                continue;
            }
            $style = preg_replace('/(?:^|;)\s*(?:float|mso-table-anchor-[a-z-]+|mso-table-left)\s*:[^;]*/i', '', $node['attrs']['style'] ?? '');
            $changes = ['align' => null, 'style' => ltrim($style, ';')];
            if (! self::has($node, 'rt-personal-logo-frame')) {
                $style = preg_replace('/(?:^|;)\s*(?:width|max-width)\s*:[^;]*/i', '', $style);
                $changes['width'] = '100%';
                $changes['style'] = ltrim($style, ';').';width:100%!important;max-width:100%;';
            }
            $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, $changes)];
        }

        return self::replace($html, $edits);
    }

    private static function contactSlots(string $html): string
    {
        $nodes = self::nodes($html);
        $edits = [];
        foreach ($nodes as $index => $node) {
            if ($node['tag'] !== 'tr') {
                continue;
            }
            $cells = self::direct($nodes, $index);
            if (count($cells) !== 2 || $nodes[$cells[0]]['tag'] !== 'td' || $nodes[$cells[1]]['tag'] !== 'td' || ! self::has($nodes[$cells[0]], 'rt-contact-icon') || ! self::has($nodes[$cells[1]], 'rt-contact-text')) {
                continue;
            }
            $icon = $nodes[$cells[0]];
            $value = $nodes[$cells[1]];
            $iconStyle = preg_replace('/(?:^|;)\s*(?:padding(?:-[a-z]+)?|width|text-align)\s*:[^;]*/i', '', $icon['attrs']['style'] ?? '');
            $valueStyle = preg_replace('/(?:^|;)\s*padding(?:-[a-z]+)?\s*:[^;]*/i', '', $value['attrs']['style'] ?? '');
            $edits[] = [$icon['start'], $icon['openEnd'] - $icon['start'], self::opening($icon, ['width' => '27', 'align' => 'left', 'valign' => 'top', 'style' => ltrim($iconStyle, ';').';width:27px;padding:0!important;text-align:left;'])];
            $edits[] = [$value['start'], $value['openEnd'] - $value['start'], self::opening($value, ['valign' => 'top', 'style' => ltrim($valueStyle, ';').';padding:0!important;'])];
            foreach ($nodes as $imageIndex => $image) {
                if ($image['tag'] === 'img' && self::within($nodes, $imageIndex, $cells[0])) {
                    $edits[] = [$image['start'], $image['openEnd'] - $image['start'], self::opening($image, ['width' => '18', 'height' => '18', 'style' => 'display:block;width:18px;height:18px;margin:0;border:0;'])];
                }
            }
        }

        return self::replace($html, $edits);
    }

    private static function gutters(string $html, bool $signature): string
    {
        $nodes = self::nodes($html);
        $edits = [];
        foreach ($nodes as $index => $node) {
            if ($node['tag'] === 'td' && $signature && self::has($node, 'rt-v9-legal-cell')) {
                $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['class' => 'rt-v11-legal-cell', 'style' => 'padding:0 18px;'])];
            }
            if ($node['tag'] === 'td' && $signature && self::has($node, 'rt-sign-content')) {
                $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['style' => 'padding:0!important;vertical-align:top;'])];
            }
            if ($node['tag'] !== 'table' || ! self::has($node, 'rt-shell') || ! self::has($node, 'design-v27')) {
                continue;
            }
            for ($ancestor = $node['parent']; $ancestor !== null; $ancestor = $nodes[$ancestor]['parent']) {
                if ($nodes[$ancestor]['tag'] === 'table' && self::has($nodes[$ancestor], 'rt-shell')) {
                    continue 2;
                }
            }
            $style = preg_replace('/(?:^|;)\s*border-(?:left|right)\s*:[^;]*/i', '', $node['attrs']['style'] ?? '');
            $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['style' => ltrim($style, ';').';border-left:0!important;border-right:0!important;'])];
            $body = self::singleChild($html, $nodes, $index, 'tbody');
            foreach (self::direct($nodes, $body) as $row) {
                foreach (self::direct($nodes, $row) as $cellIndex) {
                    $cell = $nodes[$cellIndex];
                    if ($cell['tag'] !== 'td') {
                        self::fail();
                    }
                    $ownsSignature = array_filter(array_keys($nodes), static fn (int $child): bool => self::within($nodes, $child, $cellIndex)
                        && (self::has($nodes[$child], 'rt-v11-columns') || self::has($nodes[$child], 'rt-brand-legal-table')));
                    if ($ownsSignature !== []) {
                        continue;
                    }
                    [$top, $bottom] = self::verticalPadding($cell['attrs']['style'] ?? '');
                    $cellStyle = preg_replace('/(?:^|;)\s*padding(?:-[a-z]+)?\s*:[^;]*/i', '', $cell['attrs']['style'] ?? '');
                    $edits[] = [$cell['start'], $cell['openEnd'] - $cell['start'], self::opening($cell, ['class' => trim(($cell['attrs']['class'] ?? '').' rt-v11-template-pad'), 'style' => ltrim($cellStyle, ';').';padding:'.$top.' 18px '.$bottom.' 18px!important;'])];
                }
            }
        }

        return self::replace($html, $edits);
    }

    private static function verticalPadding(string $style): array
    {
        $edges = ['top' => ['0', false], 'right' => ['0', false], 'bottom' => ['0', false], 'left' => ['0', false]];
        preg_match_all('/(?:^|;)\s*padding(?:-(top|right|bottom|left))?\s*:\s*([^;]+)/i', $style, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $important = str_contains(strtolower($match[2]), '!important');
            $value = trim(preg_replace('/\s*!important\s*/i', '', $match[2]));
            $values = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
            if ($match[1] !== '') {
                $updates = [$match[1] => $value];
            } elseif (count($values) >= 1 && count($values) <= 4) {
                $updates = array_combine(['top', 'right', 'bottom', 'left'], match (count($values)) {
                    1 => array_fill(0, 4, $values[0]),
                    2 => [$values[0], $values[1], $values[0], $values[1]],
                    3 => [$values[0], $values[1], $values[2], $values[1]],
                    4 => $values,
                });
            } else {
                self::fail();
            }
            foreach ($updates as $edge => $padding) {
                if (! preg_match('/\A(?:0|[0-9]+(?:\.[0-9]+)?(?:px|pt|cm))\z/', $padding)) {
                    self::fail();
                }
                if ($important || ! $edges[$edge][1]) {
                    $edges[$edge] = [$padding, $important];
                }
            }
        }

        return [$edges['top'][0], $edges['bottom'][0]];
    }

    private static function assetUrl(string $source): string
    {
        if (str_starts_with($source, 'cid:')) {
            return $source;
        }
        $base = rtrim((string) config('app.url'), '/').'/mail-assets/';
        $source = preg_replace('/\?v=[0-9]+\z/', '', $source);
        foreach (self::ASSETS as [$old, $new, $expected]) {
            if ($source === $base.$old) {
                self::asset($new, $expected);

                return $base.$new;
            }
        }
        self::fail();
    }

    private static function asset(string $filename, string $sha): string
    {
        $binary = file_get_contents(public_path('mail-assets/'.$filename));
        if ($binary === false || ! hash_equals($sha, hash('sha256', $binary))) {
            self::fail();
        }

        return $binary;
    }

    private static function assertCurrent(string $html, array $nodes, int $root): void
    {
        if ($nodes[$root]['tag'] !== 'table' || ! self::has($nodes[$root], 'rt-v11-columns') || ($nodes[$root]['attrs']['width'] ?? '') !== '100%') {
            self::fail();
        }
        $body = self::singleChild($html, $nodes, $root, 'tbody');
        $rows = self::direct($nodes, $body);
        if (count($rows) !== 2) {
            self::fail();
        }
        $main = self::singleChild($html, $nodes, $rows[0], 'td');
        $groups = self::direct($nodes, $main);
        $widths = self::has($nodes[$root], 'rt-system-columns') ? [176, 172, 164] : [176, 204, 172, 164];
        if (count($groups) !== count($widths) + 1 || $nodes[$groups[array_key_last($groups)]]['tag'] !== 'br' || ($nodes[$groups[array_key_last($groups)]]['attrs']['clear'] ?? '') !== 'all') {
            self::fail();
        }
        foreach ($widths as $position => $width) {
            $column = $nodes[$groups[$position]];
            if ($column['tag'] !== 'table' || ($column['attrs']['width'] ?? '') !== (string) $width || ($column['attrs']['align'] ?? '') !== 'left') {
                self::fail();
            }
            self::singleChild($html, $nodes, self::singleRow($html, $nodes, $groups[$position]), 'td');
        }
        $host = self::singleChild($html, $nodes, $rows[1], 'td');
        $train = self::singleChild($html, $nodes, $host, 'table');
        if (($nodes[$train]['attrs']['width'] ?? '') !== '218') {
            self::fail();
        }
    }

    private static function opening(array $node, array $changes): string
    {
        $edits = [];
        $added = '';
        foreach ($changes as $name => $value) {
            $attribute = $value === null ? '' : ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            if (isset($node['ranges'][$name])) {
                [$offset, $length] = $node['ranges'][$name];
                $edits[] = [$offset, $length, $attribute];
            } else {
                $added .= $attribute;
            }
        }
        $opening = self::replace($node['opening'], $edits);

        return substr($opening, 0, -1).$added.'>';
    }

    private static function singleChild(string $html, array $nodes, int $parent, string $tag): int
    {
        $children = self::direct($nodes, $parent);
        if (count($children) !== 1 || $nodes[$children[0]]['tag'] !== $tag) {
            self::fail();
        }
        self::children($html, $nodes, $parent, $children);

        return $children[0];
    }

    private static function singleRow(string $html, array $nodes, int $table): int
    {
        $children = self::direct($nodes, $table);
        if (count($children) !== 1) {
            self::fail();
        }
        if ($nodes[$children[0]]['tag'] === 'tbody') {
            $tbody = $children[0];
            self::children($html, $nodes, $table, [$tbody]);

            return self::singleChild($html, $nodes, $tbody, 'tr');
        }

        return self::singleChild($html, $nodes, $table, 'tr');
    }

    private static function rowCells(string $html, array $nodes, int $row, int $count): array
    {
        $columns = self::direct($nodes, $row);
        if (count($columns) !== $count || array_filter($columns, static fn (int $index): bool => $nodes[$index]['tag'] !== 'td')) {
            self::fail();
        }
        self::children($html, $nodes, $row, $columns);

        return $columns;
    }

    private static function plainText(string $html, array $nodes, int $node, string $expected): void
    {
        $text = preg_replace('~<!--.*?-->|<style\b[^>]*>.*?</style\s*>~is', '', self::inner($html, $nodes[$node]));
        $text = trim(str_replace("\xc2\xa0", ' ', CssSemantic::decodeHtmlEntitiesOnce($text)));
        if (self::direct($nodes, $node) !== [] || $text !== $expected) {
            self::fail();
        }
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

    private static function within(array $nodes, int $child, int $parent): bool
    {
        for ($index = $child; $index !== null; $index = $nodes[$index]['parent']) {
            if ($index === $parent) {
                return true;
            }
        }

        return false;
    }

    private static function inner(string $html, array $node): string
    {
        return substr($html, $node['openEnd'], $node['close'] - $node['openEnd']);
    }

    private static function replace(string $html, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $last = strlen($html) + 1;
        foreach ($edits as [$offset, $length, $replacement]) {
            if ($offset < 0 || $offset + $length > $last) {
                self::fail();
            }
            $html = substr_replace($html, $replacement, $offset, $length);
            $last = $offset;
        }

        return $html;
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
            $attrs = [];
            $ranges = [];
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
        throw new RuntimeException('Die portable Outlook-Signatur besitzt keinen eindeutigen Ausgabe-Vertrag.');
    }
}
