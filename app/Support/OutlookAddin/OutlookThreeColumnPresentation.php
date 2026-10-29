<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\EmailTemplateBuilder;
use App\Support\Mail\CssSemantic;
use RuntimeException;

/** New, output-only three-column family after all canonical V27 inverses. */
final class OutlookThreeColumnPresentation
{
    public const FLOW = 'outlook-desktop-flow-v9';

    public const REVISION = 'outlook-three-column-presentation-v1';

    private const TOKENS = '~<!doctype\b[^>]*>|<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

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
            if (isset($entry['signature'])) {
                $entry['signature'] = self::pair($entry['signature'], 'html', 'media', 30000);
            }
            $payload['templates'][$index] = $entry;
        }

        return $payload;
    }

    /** A full system document is never passed to the fragment-only legacy compiler. */
    public static function systemDocument(string $html, string $baseUrl, string $authoredCss = ''): string
    {
        if (trim($authoredCss) !== '' || ! preg_match('~\bdata-rt-artifact-version\s*=\s*(?:"v27"|\'v27\'|v27(?=[\s>]))~i', $html)) {
            return $html;
        }
        if (str_contains($html, 'data-rt-desktop-flow="'.self::FLOW.'"')) {
            return self::document($html, true);
        }
        $nodes = self::nodes($html);
        $artifacts = self::find($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-artifact-version'] ?? '') === 'v27');
        if (count($artifacts) !== 1 || $nodes[$artifacts[0]]['tag'] !== 'tr') {
            return $html;
        }
        $artifact = $nodes[$artifacts[0]];
        $fragment = substr($html, $artifact['start'], $artifact['end'] - $artifact['start']);
        $fragment = OutlookBrandedSignature::outlinedPresentation(OutlookBrandedSignature::project($fragment, $baseUrl));
        $html = substr_replace($html, $fragment, $artifact['start'], $artifact['end'] - $artifact['start']);
        $nodes = self::nodes($html);
        $markEdits = [];
        foreach ($nodes as $index => $node) {
            if ($node['tag'] !== 'img' || ! self::has($node, 'rt-mark')) {
                continue;
            }
            $pad = self::ancestor($nodes, $index, static fn (array $candidate): bool => $candidate['tag'] === 'td' && self::has($candidate, 'rt-pad'));
            if ($pad !== null) {
                preg_match_all('~<!-- RT_TEMPLATE_MARK_(?:START|END) -->~', self::inner($html, $nodes[$pad]), $runtimeMarkers);
                $markEdits[] = [$nodes[$pad]['openEnd'], $nodes[$pad]['close'] - $nodes[$pad]['openEnd'], implode('', $runtimeMarkers[0])];
                $markEdits[] = [$nodes[$pad]['start'], $nodes[$pad]['openEnd'] - $nodes[$pad]['start'], self::opening($nodes[$pad], ['style' => 'padding:0 40px;font-size:0;line-height:0;'])];
            }
        }
        $html = self::replace($html, $markEdits);

        return self::document($html, true);
    }

    private static function pair(array $pair, string $field, string $media, int $limit): array
    {
        $before = $pair[$field];
        $prepared = $before;
        if (! str_contains($before, 'data-rt-brand-hotline') && str_contains($before, 'design-hotline-spaced') && ! self::authored($before)) {
            $nodes = self::nodes($before);
            $artifacts = self::find($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-artifact-version'] ?? '') === 'v27');
            if (count($artifacts) === 1 && $nodes[$artifacts[0]]['tag'] === 'tr') {
                $artifact = $nodes[$artifacts[0]];
                $fragment = substr($before, $artifact['start'], $artifact['end'] - $artifact['start']);
                $fragment = OutlookBrandedSignature::outlinedPresentation(OutlookBrandedSignature::project($fragment, rtrim((string) config('app.url'), '/')));
                $prepared = substr_replace($before, $fragment, $artifact['start'], $artifact['end'] - $artifact['start']);
            }
        }
        $after = self::document($prepared);
        if ($after === $before) {
            return $pair;
        }
        $assetHashes = OutlookBrandedSignature::assetHashes();
        $assetEdits = [];
        foreach (self::nodes($after) as $node) {
            if ($node['tag'] !== 'img') {
                continue;
            }
            foreach ($assetHashes as $path => $sha) {
                if (($node['attrs']['src'] ?? '') !== rtrim((string) config('app.url'), '/').$path) {
                    continue;
                }
                $binary = file_get_contents(public_path(ltrim($path, '/')));
                if ($binary === false || ! hash_equals($sha, hash('sha256', $binary))) {
                    self::fail();
                }
                $cid = 'railtime-'.substr($sha, 0, 20).'.png';
                $attachment = ['name' => $cid, 'contentId' => $cid, 'base64' => base64_encode($binary)];
                if (! array_filter($pair[$media], static fn (array $item): bool => $item === $attachment)) {
                    $pair[$media][] = $attachment;
                }
                $assetEdits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['src' => 'cid:'.$cid])];
            }
        }
        $after = self::replace($after, $assetEdits);
        $nodes = self::nodes($after);
        $images = self::find($nodes, static fn (array $node): bool => $node['tag'] === 'img' && self::has($node, 'rt-v9-train-image'));
        if (count($images) === 1 && str_starts_with($nodes[$images[0]]['attrs']['src'] ?? '', 'cid:')) {
            $image = $nodes[$images[0]];
            $cid = substr($image['attrs']['src'], 4);
            $matches = array_values(array_filter($pair[$media], static fn (array $attachment): bool => ($attachment['contentId'] ?? '') === $cid));
            if (count($matches) !== 1 || ($replacement = OutlookIntrinsicTrainDelivery::currentAttachment($matches[0])) === null) {
                self::fail();
            }
            $after = substr_replace($after, self::opening($image, ['src' => 'cid:'.$replacement['contentId']]), $image['start'], $image['openEnd'] - $image['start']);
            if (! array_filter($pair[$media], static fn (array $attachment): bool => $attachment['contentId'] === $replacement['contentId'])) {
                $pair[$media][] = $replacement;
            }
        }
        $removed = array_diff(self::sources($before), self::sources($after));
        $pair[$media] = array_values(array_filter($pair[$media], static fn (array $attachment): bool => ! in_array('cid:'.$attachment['contentId'], $removed, true) || str_contains($after, 'cid:'.$attachment['contentId'])));
        if (array_sum(array_map(static fn (array $attachment): int => strlen(base64_decode($attachment['base64'], true) ?: ''), $pair[$media])) > 2097152) {
            self::fail();
        }
        $pair[$field] = $after;
        self::budget($after, $limit);

        return $pair;
    }

    public static function document(string $html, bool $system = false): string
    {
        if (! str_contains($html, 'data-rt-brand-hotline') && ! str_contains($html, 'data-rt-desktop-flow="'.self::FLOW.'"')) {
            return $html;
        }
        if (self::authored($html)) {
            return $html;
        }
        $nodes = self::nodes($html);
        $current = self::find($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-desktop-flow'] ?? '') === self::FLOW);
        if ($current !== []) {
            if (count($current) !== 1) {
                self::fail();
            }
            self::assertCurrent($html, $nodes, $current[0]);

            return $html;
        }
        $artifacts = self::find($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-artifact-version'] ?? '') === 'v27');
        if (count($artifacts) !== 1) {
            return $html;
        }
        $artifact = $artifacts[0];
        $headers = self::find($nodes, static fn (array $node): bool => $node['tag'] === 'td' && ($node['attrs']['data-rt-brand-hotline'] ?? '') === 'v1');
        if (count($headers) !== 1 || ! self::within($nodes, $headers[0], $artifact)) {
            return $html;
        }
        $banner = self::ancestor($nodes, $headers[0], static fn (array $node): bool => $node['tag'] === 'table' && self::has($node, 'rt-hotline-banner'));
        if ($banner === null) {
            self::fail();
        }
        $dispatch = self::dispatch($html, $nodes, $headers[0]);
        $flows = self::find($nodes, static fn (array $node): bool => $node['tag'] === 'table' && (($node['attrs']['data-rt-personal-flow'] ?? '') === 'v1' && ($node['attrs']['data-rt-desktop-flow'] ?? '') === 'outlook-desktop-flow-v8' || ($node['attrs']['data-rt-mobile-hybrid'] ?? '') === 'v2'));
        $extraEdits = [];
        $externalTrain = false;
        if (count($flows) === 1) {
            $oldRoot = $flows[0];
            $body = self::singleChild($html, $nodes, $oldRoot, 'tbody');
            $rows = self::direct($nodes, $body);
            if (count($rows) !== 2) {
                self::fail();
            }
            $cell = self::singleChild($html, $nodes, $rows[0], 'td');
            $groups = self::direct($nodes, $cell);
            if (count($groups) !== 3 || array_filter($groups, static fn (int $index): bool => $nodes[$index]['tag'] !== 'table')) {
                self::fail();
            }
            $logoBox = self::cell($html, $nodes, $groups[0]);
            $person = self::inner($html, $nodes[self::cell($html, $nodes, $groups[1])]);
            $company = self::inner($html, $nodes[self::cell($html, $nodes, $groups[2])]);
            $trainBox = $rows[1];
        } else {
            $legacyPersonal = self::find($nodes, static fn (array $node): bool => in_array($node['tag'], ['table', 'div'], true) && ($node['attrs']['data-rt-personal-columns'] ?? '') === 'v1' && ! isset($node['attrs']['data-rt-desktop-flow']));
            $generic = self::find($nodes, static fn (array $node): bool => $node['tag'] === 'div' && ($node['attrs']['data-rt-desktop-flow'] ?? '') === 'outlook-desktop-flow-v8');
            $ledger = self::find($nodes, static fn (array $node): bool => $node['tag'] === 'table' && self::has($node, 'rt-sign-ledger'));
            if (count($legacyPersonal) === 1) {
                $oldRoot = $legacyPersonal[0];
                $left = self::oneWithin($nodes, $oldRoot, 'rt-personal-left');
                $right = self::oneWithin($nodes, $oldRoot, 'rt-personal-right');
                $logoBox = self::oneWithin($nodes, $right, 'rt-personal-logo-frame');
                $person = self::inner($html, $nodes[self::cell($html, $nodes, $left)]);
                $company = self::inner($html, $nodes[self::oneWithin($nodes, $right, 'rt-personal-company-contacts')]);
                $trainBox = $artifact;
                $externalTrain = true;
            } elseif (count($generic) === 1) {
                $oldRoot = $generic[0];
                $brand = self::oneWithin($nodes, $oldRoot, 'rt-df-brand');
                $direct = self::oneWithin($nodes, $oldRoot, 'rt-df-direct');
                $companyBox = self::oneWithin($nodes, $oldRoot, 'rt-df-company');
                $logoBox = self::cell($html, $nodes, $brand);
                $direct = self::cell($html, $nodes, $direct);
                $companyBox = self::cell($html, $nodes, $companyBox);
                $trainBox = self::oneWithin($nodes, $artifact, 'rt-df-train');
                $extraEdits[] = [$nodes[$trainBox]['start'], $nodes[$trainBox]['end'] - $nodes[$trainBox]['start'], ''];
            } elseif (count($ledger) === 1) {
                $oldRoot = $ledger[0];
                $logoBox = self::oneWithin($nodes, $oldRoot, 'rt-ledger-brand');
                $direct = self::oneWithin($nodes, $oldRoot, 'rt-ledger-direct');
                $companyBox = self::oneWithin($nodes, $oldRoot, 'rt-ledger-company');
                $trainBox = $artifact;
                $externalTrain = true;
            } else {
                return $html;
            }
            if (count($legacyPersonal) !== 1) {
                $head = self::find($nodes, static fn (array $node): bool => self::has($node, 'rt-person-kopf'));
                $head = array_values(array_filter($head, static fn (int $index): bool => self::within($nodes, $index, $logoBox)));
                $person = count($head) === 1 ? substr($html, $nodes[$head[0]]['start'], $nodes[$head[0]]['end'] - $nodes[$head[0]]['start']) : '';
                $company = self::company($html, $nodes, [$companyBox, $direct], $dispatch['phone']);
            }
        }
        $company = self::whiteText(self::address($company));
        $person = self::whiteText($person);
        $dispatch['html'] = self::whiteText($dispatch['html']);
        if (! self::within($nodes, $oldRoot, $artifact)) {
            self::fail();
        }
        $logos = self::find($nodes, static fn (array $node): bool => $node['tag'] === 'img');
        $logos = array_values(array_filter($logos, static fn (int $index): bool => self::within($nodes, $index, $logoBox)));
        if (count($logos) !== 1) {
            self::fail();
        }
        $logo = self::opening($nodes[$logos[0]], ['class' => 'rt-v9-logo', 'width' => '160', 'height' => '27', 'style' => 'display:block;width:160px;max-width:100%;height:auto;margin:0;border:0;']);
        $logo = '<table class="rt-personal-logo-frame" role="presentation" width="160" border="0" cellspacing="0" cellpadding="0" style="width:160px;max-width:100%;border-collapse:collapse;"><tbody><tr><td style="padding:0 0 10px;font-size:0;line-height:0;">'.$logo.'</td></tr></tbody></table>';
        $trains = self::find($nodes, static fn (array $node): bool => $node['tag'] === 'img' && (self::has($node, 'rt-delivery-train') || self::has($node, 'rt-mhy-image')));
        $trains = array_values(array_filter($trains, static fn (int $index): bool => self::within($nodes, $index, $trainBox)));
        if (count($trains) !== 1) {
            self::fail();
        }
        $train = $nodes[$trains[0]];
        $legacyTrainCell = self::ancestor($nodes, $trains[0], static fn (array $node): bool => $node['tag'] === 'td' && self::has($node, 'rt-delivery-train-cell'));
        if ($externalTrain && $legacyTrainCell !== null && ! self::within($nodes, $legacyTrainCell, $oldRoot)) {
            $legacyRow = $nodes[$legacyTrainCell]['parent'];
            if ($legacyRow === null || $nodes[$legacyRow]['tag'] !== 'tr' || $legacyRow === $artifact) {
                self::fail();
            }
            $extraEdits[] = [$nodes[$legacyRow]['start'], $nodes[$legacyRow]['end'] - $nodes[$legacyRow]['start'], ''];
        }
        $source = $train['attrs']['src'];
        if ($system) {
            $source = self::systemTrainSource($source);
        }
        $train = self::opening($train, ['class' => 'rt-delivery-train rt-v9-train-image', 'src' => $source, 'width' => '200', 'height' => '43', 'style' => 'display:block;width:200px;max-width:100%;height:43px;margin:0;border:0;vertical-align:bottom;']);
        preg_match_all('/(?:^|\s)rts([0-9a-f]{10})(?=\s|$)/', implode(' ', array_map(static fn (array $node): string => $node['attrs']['class'] ?? '', $nodes)), $oldScopes);
        $oldScopes = array_values(array_unique($oldScopes[1]));
        $scope = 'rt-df9'.(count($oldScopes) === 1 ? $oldScopes[0] : substr(hash('sha256', $html), 0, 10));
        $flow = '<table class="'.($system ? 'rt-system-columns' : 'rt-personal-columns').' rt-v9-columns '.$scope.'" data-rt-desktop-flow="'.self::FLOW.'" '.($system ? 'data-rt-system-flow="v1"' : 'data-rt-personal-flow="v1" data-rt-personal-columns="v1"').' role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:720px;table-layout:fixed;border-collapse:collapse;"><tbody><tr><td style="padding:0;font-size:0;line-height:0;vertical-align:top;">'
            .self::column('rt-personal-left', 'rt-v9-person-cell', $logo.$person)
            .self::column('rt-personal-right', 'rt-v9-company-cell', $company)
            .self::column('rt-dispatch-column', 'rt-v9-dispatch-cell', $dispatch['html'])
            .'<br clear="all" style="font-size:0;line-height:0;height:0;"></td></tr><tr><td style="padding:0;font-size:0;line-height:0;vertical-align:bottom;">'
            .'<table class="rt-df-train rt-train-enlarged-v1" role="presentation" width="240" align="left" border="0" cellspacing="0" cellpadding="0" style="width:240px;max-width:100%;table-layout:fixed;border-collapse:collapse;"><tbody><tr><td class="rt-v9-train-cell" style="padding:0 40px 0 0;font-size:0;line-height:0;vertical-align:bottom;"><div class="rt-train-window">'.$train.'</div></td></tr></tbody></table></td></tr></tbody></table>';
        $edits = array_merge($extraEdits, [[$nodes[$oldRoot]['start'], $nodes[$oldRoot]['end'] - $nodes[$oldRoot]['start'], $flow], [$nodes[$banner]['start'], $nodes[$banner]['end'] - $nodes[$banner]['start'], '']]);
        $mainCell = self::singleChild($html, $nodes, $artifact, 'td');
        $mainStyle = preg_replace('/(?:^|;)\s*border-top\s*:[^;]*/i', '', $nodes[$mainCell]['attrs']['style'] ?? '');
        $edits[] = [$nodes[$mainCell]['start'], $nodes[$mainCell]['openEnd'] - $nodes[$mainCell]['start'], self::opening($nodes[$mainCell], ['class' => trim(($nodes[$mainCell]['attrs']['class'] ?? '').' rt-v9-top-rule'), 'style' => rtrim($mainStyle, ';').';border-top:3px solid #e90032;'])];
        if ($system) {
            $edits[] = [$nodes[$artifact]['start'], $nodes[$artifact]['openEnd'] - $nodes[$artifact]['start'], self::opening($nodes[$artifact], ['class' => implode(' ', array_unique(array_merge(self::classes($nodes[$artifact]), ['rt-system-signature-v27'])))])];
        }
        foreach ($nodes as $index => $node) {
            if (! self::within($nodes, $index, $oldRoot) && preg_match('/(?:^|\s)rt-df8[0-9a-f]{10}(?=\s|$)/', $node['attrs']['class'] ?? '')) {
                $classes = preg_replace('/(?:^|\s)(?:rt-df8[0-9a-f]{10}|rt-dflow-host-v4)(?=\s|$)/', '', $node['attrs']['class']);
                $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['class' => trim($classes.' '.$scope)])];
            }
            if ($node['tag'] === 'table' && self::has($node, 'rt-brand-legal-table')) {
                $legal = self::cell($html, $nodes, $index);
                $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['style' => 'width:100%;border-collapse:collapse;'])];
                $edits[] = [$nodes[$legal]['start'], $nodes[$legal]['openEnd'] - $nodes[$legal]['start'], self::opening($nodes[$legal], ['class' => 'rt-v9-legal-cell', 'style' => 'padding:0 40px;'])];
            }
            if (preg_match('/(?:^|;)\s*border-left\s*:\s*6px\s+solid\s+#e90032/i', $node['attrs']['style'] ?? '') && array_filter(self::direct($nodes, $index), static fn (int $child): bool => self::has($nodes[$child], 'rt-shell'))) {
                $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['style' => preg_replace('/border-left\s*:\s*6px\s+solid\s+#e90032/i', 'border-left:3px solid #e90032', $node['attrs']['style'])])];
            }
        }
        $artifactParent = $nodes[$artifact]['parent'];
        if ($artifactParent !== null) {
            $host = $nodes[$artifactParent];
            $edits[] = [$host['start'], $host['openEnd'] - $host['start'], self::opening($host, ['class' => trim(($host['attrs']['class'] ?? '').' '.$scope)])];
            $siblings = self::direct($nodes, $artifactParent);
            $position = array_search($artifact, $siblings, true);
            $legalRow = $position === false ? null : ($siblings[$position + 1] ?? null);
            if ($legalRow !== null && $nodes[$legalRow]['tag'] === 'tr') {
                $legalCell = self::singleChild($html, $nodes, $legalRow, 'td');
                $hasFrame = self::find($nodes, static fn (array $node): bool => $node['tag'] === 'table' && self::has($node, 'rt-brand-legal-table'));
                if ($hasFrame === []) {
                    $content = self::inner($html, $nodes[$legalCell]);
                    $wrapped = '<table class="rt-brand-legal-table" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><tbody><tr><td class="rt-v9-legal-cell" style="padding:0 40px;">'.$content.'</td></tr></tbody></table>';
                    $style = preg_replace('/(?:^|;)\s*padding\s*:[^;]*/i', '', $nodes[$legalCell]['attrs']['style'] ?? '');
                    $edits[] = [$nodes[$legalCell]['start'], $nodes[$legalCell]['openEnd'] - $nodes[$legalCell]['start'], self::opening($nodes[$legalCell], ['style' => 'padding:8px 0;'.ltrim($style, ';')])];
                    $edits[] = [$nodes[$legalCell]['openEnd'], $nodes[$legalCell]['close'] - $nodes[$legalCell]['openEnd'], $wrapped];
                }
            }
        }
        $html = self::replace($html, $edits);
        $oldClasses = [];
        $liveClasses = [];
        foreach ($nodes as $node) {
            $oldClasses = array_merge($oldClasses, self::classes($node));
        }
        foreach (self::nodes($html) as $node) {
            $liveClasses = array_merge($liveClasses, self::classes($node));
        }
        $dead = array_values(array_diff(array_unique($oldClasses), $liveClasses));
        $html = preg_replace_callback('~<style\b[^>]*data-rt-outlook-(?:signature(?:-inline)?|mobile)-css=["\']1["\'][^>]*>.*?</style\s*>~is', static function (array $sheet) use ($dead): string {
            return preg_replace_callback('~([^{}]+)\{([^{}]*)\}~', static function (array $rule) use ($dead): string {
                if (preg_match('/\A[a-z0-9_.#\s,>+~-]+\z/i', $rule[1])
                    && array_filter($dead, static fn (string $class): bool => array_filter(explode(',', $rule[1]), static fn (string $selector): bool => preg_match('/\.'.preg_quote($class, '/').'(?![a-z0-9_-])/i', $selector) !== 1) === []) !== []) {
                    return '';
                }

                return $rule[0];
            }, $sheet[0]) ?? $sheet[0];
        }, $html) ?? $html;
        // Old reversible native-flow rules must not observe the new topology.
        $html = preg_replace('~<style\b[^>]*data-rt-desktop-flow-(?:css|mso-css)=["\']1["\'][^>]*>.*?</style\s*>~is', '', $html) ?? $html;
        $css = '.'.$scope.'.rt-v9-columns{width:100%!important;max-width:720px!important;direction:ltr!important;text-align:left!important;}'
            .'.'.$scope.'.rt-v9-columns .rt-v9-column{display:inline-table!important;float:left!important;width:240px!important;max-width:100%!important;min-width:0!important;border:0!important;vertical-align:top!important;}'
            .'.'.$scope.' .rt-v9-cell{padding:0 40px!important;}'
            .'.'.$scope.' .rt-df-train{width:100%!important;max-width:364px!important;border:0!important;}'
            .'.'.$scope.' .rt-v9-train-image{width:100%!important;max-width:100%!important;height:auto!important;}'
            .'.'.$scope.' .rt-personal-logo-frame{width:160px!important;max-width:100%!important;border:0!important;background-color:transparent!important;}'
            .'.'.$scope.' .rt-v9-address{word-break:normal!important;overflow-wrap:anywhere!important;}'
            .'@media(max-width:600px){.'.$scope.'.rt-v9-columns .rt-v9-column{width:100%!important;max-width:100%!important;float:none!important;display:table!important;}.'.$scope.' .rt-v9-cell,.'.$scope.' .rt-v9-legal-cell{padding-left:18px!important;padding-right:18px!important;}.'.$scope.' .rt-v9-train-cell{padding-right:18px!important;}}';
        $sheet = '<style data-rt-three-column-css="1">'.$css.'</style>';
        if ($system) {
            $html = preg_replace('~</head\s*>~i', $sheet.'</head>', $html, 1) ?? $html;
        } else {
            $finalNodes = self::nodes($html);
            $finalArtifacts = self::find($finalNodes, static fn (array $node): bool => ($node['attrs']['data-rt-artifact-version'] ?? '') === 'v27');
            $contentCell = self::singleChild($html, $finalNodes, $finalArtifacts[0], 'td');
            $html = substr_replace($html, $sheet, $finalNodes[$contentCell]['openEnd'], 0);
        }

        return $html;
    }

    private static function column(string $class, string $cell, string $content): string
    {
        return '<table class="rt-v9-column '.$class.'" role="presentation" width="240" align="left" border="0" cellspacing="0" cellpadding="0" style="display:inline-table;float:left;vertical-align:top;width:240px;max-width:100%;table-layout:fixed;border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;"><tbody><tr><td class="rt-v9-cell '.$cell.'" style="padding:0 40px;font-family:Arial,sans-serif;font-size:13px;line-height:16px;vertical-align:top;text-align:left;">'.$content.'</td></tr></tbody></table>';
    }

    private static function dispatch(string $html, array $nodes, int $header): array
    {
        $images = $links = [];
        foreach ($nodes as $index => $node) {
            if (! self::within($nodes, $index, $header)) {
                continue;
            }
            if ($node['tag'] === 'img') {
                $alt = $node['attrs']['alt'] ?? '';
                if ($alt === 'Dispo 24/7 erreichbar') {
                    $images['label'] = self::opening($node, ['width' => '160', 'height' => '30', 'style' => 'display:block;width:160px;max-width:100%;height:auto;border:0;']);
                } elseif (in_array($alt, ['E-Mail', 'Telefon'], true)) {
                    $images[$alt] = self::opening($node, ['width' => '28', 'height' => '23', 'style' => 'display:block;width:28px;height:23px;border:0;']);
                }
            } elseif ($node['tag'] === 'a') {
                $href = $node['attrs']['href'] ?? '';
                foreach (['mailto:' => 'mail', 'tel:' => 'phone'] as $scheme => $kind) {
                    if (str_starts_with(strtolower($href), $scheme)) {
                        $links[$kind] ??= $node;
                    }
                }
            }
        }
        if (count($images) !== 3 || count($links) !== 2) {
            self::fail();
        }
        $phone = substr($links['phone']['attrs']['href'], 4);
        if (! preg_match('/\A\+?[0-9().-]{3,32}\z/D', $phone)) {
            self::fail();
        }
        $mail = self::opening($links['mail'], ['style' => 'color:#e90032!important;text-decoration:none;']);
        $call = self::opening($links['phone'], ['style' => 'color:#e90032!important;text-decoration:none;font:700 13px Arial,sans-serif;line-height:16px;overflow-wrap:anywhere;']);
        $content = $images['label'].'<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;table-layout:fixed;"><tbody><tr><td width="28" valign="middle">'.$mail.$images['E-Mail'].'</a></td><td width="28" valign="middle">'.$call.$images['Telefon'].'</a></td><td valign="middle">'.$call.htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</a></td></tr></tbody></table>';

        return ['html' => $content, 'phone' => $links['phone']['attrs']['href']];
    }

    private static function company(string $html, array $nodes, array $boxes, string $emergency): string
    {
        $rows = [];
        foreach ($nodes as $index => $node) {
            if ($node['tag'] !== 'tr' || ! array_filter($boxes, static fn (int $box): bool => self::within($nodes, $index, $box))) {
                continue;
            }
            $cells = self::direct($nodes, $index);
            if (count($cells) !== 2 || array_filter($cells, static fn (int $cell): bool => ! in_array($nodes[$cell]['tag'], ['td', 'th'], true))) {
                continue;
            }
            $row = substr($html, $node['start'], $node['end'] - $node['start']);
            if (! preg_match('~<img\b~i', $row)) {
                continue;
            }
            if (str_contains($row, $emergency) && (str_contains($row, 'contact-mobile') || str_contains($row, '8f6e4932f895cad94c31'))) {
                continue;
            }
            $priority = str_contains($row, 'mailto:') ? 2 : (str_contains($row, 'tel:') ? 1 : (preg_match('~<a\b~i', $row) ? 3 : 0));
            $rows[] = [$priority, $row];
        }
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        if (count($rows) < 3 || count($rows) > 5) {
            self::fail();
        }

        return '<table class="rt-personal-company-block" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;table-layout:fixed;"><tbody>'.implode('', array_column($rows, 1)).'</tbody></table>';
    }

    private static function systemTrainSource(string $source): string
    {
        $path = parse_url($source, PHP_URL_PATH);
        if (! is_string($path) || ! preg_match('~/mail-assets/zug-dampf-(?:v27-delivery|outlook-intrinsic-v1)-(light|dark)(-mirrored)?\.(gif|png)\z~', $path, $match)) {
            self::fail();
        }

        return EmailTemplateBuilder::mailAssetUrl('zug-dampf-outlook-intrinsic-v1-'.$match[1].($match[2] ?? '').'.'.$match[3]);
    }

    /** The canonical location-icon row is the only address leaf adjusted here. */
    private static function address(string $html): string
    {
        $nodes = self::nodes($html);
        $edits = [];
        foreach ($nodes as $index => $node) {
            if ($node['tag'] !== 'img' || ! preg_match('~(?:contact-location\.png|railtime-097e33e85eb0425fa556\.png)~', $node['attrs']['src'] ?? '')) {
                continue;
            }
            $row = self::ancestor($nodes, $index, static fn (array $candidate): bool => $candidate['tag'] === 'tr');
            if ($row === null || count($cells = self::direct($nodes, $row)) !== 2) {
                continue;
            }
            $paragraphs = self::find($nodes, static fn (array $candidate): bool => $candidate['tag'] === 'p');
            $paragraphs = array_values(array_filter($paragraphs, static fn (int $paragraph): bool => self::within($nodes, $paragraph, $cells[1])));
            if (count($paragraphs) !== 1 || preg_match('~<a\b~i', self::inner($html, $nodes[$paragraphs[0]]))) {
                continue;
            }
            $paragraph = $paragraphs[0];
            $edits[] = [$nodes[$paragraph]['start'], $nodes[$paragraph]['openEnd'] - $nodes[$paragraph]['start'], self::opening($nodes[$paragraph], ['class' => 'rt-v9-address', 'style' => 'margin:0;font-family:Arial,sans-serif;font-size:13px;line-height:16px;color:#142231;word-break:normal!important;overflow-wrap:anywhere;'])];
            $edits[] = [$nodes[$cells[0]]['start'], $nodes[$cells[0]]['openEnd'] - $nodes[$cells[0]]['start'], self::opening($nodes[$cells[0]], ['valign' => 'top', 'style' => rtrim($nodes[$cells[0]]['attrs']['style'] ?? '', ';').';vertical-align:top;'])];
            foreach ($nodes as $child => $leaf) {
                if (! self::within($nodes, $child, $paragraph)) {
                    continue;
                }
                if ($leaf['tag'] === 'br' && self::has($leaf, 'rt-address-break')) {
                    $edits[] = [$leaf['start'], $leaf['openEnd'] - $leaf['start'], self::opening($leaf, ['class' => null, 'style' => 'display:block!important;'])];
                } elseif ($leaf['tag'] === 'span' && preg_match('/background(?:-color)?\s*:\s*(?:#fff(?:fff)?|white|rgb\(\s*255\s*,\s*255\s*,\s*255\s*\))/i', $leaf['attrs']['style'] ?? '')) {
                    $edits[] = [$leaf['start'], $leaf['openEnd'] - $leaf['start'], self::opening($leaf, ['class' => null, 'style' => 'background-color:transparent!important;color:inherit;'])];
                }
            }
        }

        return self::replace($html, $edits);
    }

    /** Only known white text highlighting; text/link/font semantics stay intact. */
    private static function whiteText(string $html): string
    {
        $edits = [];
        foreach (self::nodes($html) as $node) {
            if ($node['tag'] !== 'span' || ! preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*(?:#fff(?:fff)?|white|rgb\(\s*255\s*,\s*255\s*,\s*255\s*\))\s*(?:!important)?\s*(?=;|$)/i', $node['attrs']['style'] ?? '')) {
                continue;
            }
            $style = preg_replace('/(?:^|;)\s*background(?:-color)?\s*:\s*(?:#fff(?:fff)?|white|rgb\(\s*255\s*,\s*255\s*,\s*255\s*\))\s*(?:!important)?\s*(?=;|$)/i', '', $node['attrs']['style']);
            $style = ltrim($style, ';');
            if (trim($style) === '' && array_diff(array_keys($node['attrs']), ['class', 'style']) === []) {
                $edits[] = [$node['start'], $node['openEnd'] - $node['start'], ''];
                $edits[] = [$node['close'], $node['end'] - $node['close'], ''];
            } else {
                $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['class' => null, 'style' => rtrim($style, ';').';background-color:transparent!important;'])];
            }
        }

        return self::replace($html, $edits);
    }

    private static function cell(string $html, array $nodes, int $table): int
    {
        return self::singleChild($html, $nodes, self::singleRow($html, $nodes, $table), 'td');
    }

    private static function oneWithin(array $nodes, int $root, string $class): int
    {
        $items = self::find($nodes, static fn (array $node): bool => self::has($node, $class));
        $items = array_values(array_filter($items, static fn (int $index): bool => self::within($nodes, $index, $root)));
        if (count($items) !== 1) {
            self::fail();
        }

        return $items[0];
    }

    private static function ancestor(array $nodes, int $index, callable $matches): ?int
    {
        for ($parent = $nodes[$index]['parent']; $parent !== null; $parent = $nodes[$parent]['parent']) {
            if ($matches($nodes[$parent])) {
                return $parent;
            }
        }

        return null;
    }

    private static function find(array $nodes, callable $matches): array
    {
        return array_keys(array_filter($nodes, $matches));
    }

    private static function sources(string $html): array
    {
        preg_match_all('~<img\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is', $html, $images);
        $sources = [];
        foreach ($images[0] as $image) {
            if (preg_match('~\ssrc\s*=\s*(["\'])(.*?)\1~is', $image, $source)) {
                $sources[] = CssSemantic::decodeHtmlEntitiesOnce($source[2]);
            }
        }

        return array_values(array_unique($sources));
    }

    private static function budget(string $html, int $limit): void
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        if (intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) > $limit || array_sum(array_map('strlen', $styles[1])) >= ($limit === 30000 ? 12288 : 24576)) {
            throw new RuntimeException('Die dreispaltige Signatur ueberschreitet ihr unveraendertes Transportbudget.');
        }
    }

    private static function authored(string $html): bool
    {
        preg_match_all('~<style\b[^>]*data-rt-mail-document-css=(["\'])signature\1[^>]*>(.*?)</style\s*>~is', $html, $sheets);

        return array_filter($sheets[2], static fn (string $css): bool => trim($css) !== '') !== [];
    }

    private static function assertCurrent(string $html, array $nodes, int $root): void
    {
        if ($nodes[$root]['tag'] !== 'table' || ! self::has($nodes[$root], 'rt-v9-columns') || ($nodes[$root]['attrs']['width'] ?? '') !== '100%') {
            self::fail();
        }
        $body = self::singleChild($html, $nodes, $root, 'tbody');
        $rows = self::direct($nodes, $body);
        if (count($rows) !== 2 || array_filter($rows, static fn (int $row): bool => $nodes[$row]['tag'] !== 'tr')) {
            self::fail();
        }
        $cell = self::singleChild($html, $nodes, $rows[0], 'td');
        $groups = self::direct($nodes, $cell);
        if (count($groups) !== 4 || $nodes[$groups[3]]['tag'] !== 'br' || ($nodes[$groups[3]]['attrs']['clear'] ?? '') !== 'all') {
            self::fail();
        }
        foreach (['rt-personal-left', 'rt-personal-right', 'rt-dispatch-column'] as $position => $class) {
            $column = $nodes[$groups[$position]];
            if ($column['tag'] !== 'table' || ! self::has($column, $class) || ($column['attrs']['width'] ?? '') !== '240' || ($column['attrs']['align'] ?? '') !== 'left') {
                self::fail();
            }
            self::cell($html, $nodes, $groups[$position]);
        }
        $trainCell = self::singleChild($html, $nodes, $rows[1], 'td');
        $train = self::singleChild($html, $nodes, $trainCell, 'table');
        if (! self::has($nodes[$train], 'rt-df-train') || ($nodes[$train]['attrs']['width'] ?? '') !== '240' || ($nodes[$train]['attrs']['align'] ?? '') !== 'left') {
            self::fail();
        }
        $images = self::find($nodes, static fn (array $node): bool => $node['tag'] === 'img' && self::has($node, 'rt-v9-train-image'));
        if (count($images) !== 1 || ($nodes[$images[0]]['attrs']['width'] ?? '') !== '200' || ($nodes[$images[0]]['attrs']['height'] ?? '') !== '43' || ! self::within($nodes, $images[0], $train)) {
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
        throw new RuntimeException('Die neue dreispaltige Signatur besitzt keinen eindeutigen Ausgabe-Vertrag.');
    }
}
