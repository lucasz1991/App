<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignaturePersonalColumns;
use App\Support\Mail\SignatureTableOverlapDelivery;
use RuntimeException;

/** Final native desktop wire view. Canonical sources and mobile stay untouched. */
final class OutlookDesktopFlow
{
    public const REVISION = 'outlook-desktop-flow-v8';

    private const TWO_ROW_PREVIOUS_REVISION = 'outlook-desktop-flow-v7';

    private const ROW_PREVIOUS_REVISION = 'outlook-desktop-flow-v6';

    private const PREVIOUS_REVISION = 'outlook-desktop-flow-v5';

    private const LEGACY_REVISION = 'outlook-desktop-flow-v4';

    private const MARKER = 'data-rt-desktop-flow';

    private const STYLE = 'data-rt-desktop-flow-css';

    private const MSO_STYLE = 'data-rt-desktop-flow-mso-css';

    private const HOST = 'rt-dflow-host-v4';

    private const TOKENS = '~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    public static function projected(string $html): bool
    {
        return str_contains($html, self::MARKER.'="'.self::REVISION.'"')
            || str_contains($html, self::MARKER.'="'.self::TWO_ROW_PREVIOUS_REVISION.'"')
            || str_contains($html, self::MARKER.'="'.self::ROW_PREVIOUS_REVISION.'"')
            || str_contains($html, self::MARKER.'="'.self::PREVIOUS_REVISION.'"')
            || str_contains($html, self::MARKER.'="'.self::LEGACY_REVISION.'"');
    }

    public static function mobile(string $html): bool
    {
        if (! str_contains($html, 'rt-mobile-ledger') && ! str_contains($html, OutlookMobileCombinedComposeDocument::MODE)) {
            return false;
        }

        return self::mobileNodes(self::nodes($html));
    }

    public static function project(string $html): string
    {
        if (! str_contains($html, 'design-train-logo') && ! str_contains($html, self::MARKER) && ! str_contains($html, self::STYLE)) {
            return $html;
        }
        $nodes = self::nodes($html);
        if (self::mobileNodes($nodes)) {
            return $html;
        }
        if (SignaturePersonalColumns::applies($html)) {
            if (array_filter($nodes, static fn (array $node): bool => self::has($node, 'rt-outlook-signature')) === []) {
                return $html;
            }
            if (self::projected($html)) {
                self::assertPersonalProjected($html, $nodes);

                return $html;
            }

            return self::projectPersonalDesktop($html, $nodes);
        }
        if (str_contains($html, self::MARKER) || str_contains($html, self::STYLE)) {
            self::assertProjected($html, $nodes);

            return $html;
        }

        return self::projectDesktop($html, $nodes);
    }

    private static function mobileNodes(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (self::has($node, 'rt-mobile-ledger')
                || ($node['attrs']['data-rt-compose-document'] ?? '') === OutlookMobileCombinedComposeDocument::MODE) {
                return true;
            }
        }

        return false;
    }

    private static function projectDesktop(string $html, array $nodes): string
    {
        $ledgers = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'table'
            && self::has($node, 'rt-sign-ledger') && self::has($node, 'design-train-logo')));
        if ($ledgers === [] || ! SignatureTableOverlapDelivery::usesLogoTrain($html)) {
            return $html;
        }
        if (count($ledgers) !== 1) {
            self::fail();
        }
        if (array_filter($nodes, static fn (array $node): bool => self::has($node, 'rt-outlook-signature')) === []) {
            return $html;
        }
        // Even a generic authored TH/attribute/pseudo selector can observe the
        // old structure. Only the system profile without authored signature
        // rules opts in; preserving CSS text alone would not preserve meaning.
        foreach (self::styles($html) as $style) {
            if (isset($style['attrs']['data-rt-mail-document-css'])
                && trim(preg_replace('~/\*.*?\*/~s', '', $style['css'])) !== '') {
                return $html;
            }
        }
        SignatureTableOverlapDelivery::assertRuntime($html);
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        if (preg_match_all('/(?:^|\s)(rts[0-9a-f]{10})(?=\s|$)/', $nodes[$root]['attrs']['class'] ?? '', $scopes) !== 1) {
            self::fail();
        }
        $scope = $scopes[1][0];
        $scopeNodes = array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, $scope)));
        $allowedScopes = [$root];
        $signatureFrames = array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, OutlookCombinedComposeDocument::SIGNATURE_TABLE_CLASS)));
        if (count($signatureFrames) === 1 && $nodes[$signatureFrames[0]]['tag'] === 'table'
            && self::within($nodes, $signatureFrames[0], $root) && self::has($nodes[$signatureFrames[0]], $scope)) {
            $allowedScopes[] = $signatureFrames[0];
        }
        sort($allowedScopes);
        if ($scopeNodes !== $allowedScopes || ! self::within($nodes, $ledgers[0], $root)) {
            self::fail();
        }
        if (! self::knownContent($html, $nodes, $ledgers[0])) {
            return $html;
        }
        foreach ($nodes as $node) {
            foreach (preg_split('/\s+/', trim($node['attrs']['class'] ?? '')) as $class) {
                if (str_starts_with($class, 'rt-df')) {
                    self::fail();
                }
            }
        }
        $before = self::inventory($html);
        $content = self::one($nodes, 'rt-sign-ledger-content', 'td');
        $train = self::one($nodes, 'rt-delivery-train-cell', 'td');
        $edits = [
            $content => 'width:100%;direction:ltr;padding:16px 0 0;',
            $train => 'width:100%;padding:0;border:0;font-size:0;line-height:0;vertical-align:bottom;',
        ];
        foreach ($nodes as $index => $node) {
            if (! self::within($nodes, $index, $ledgers[0])) {
                continue;
            }
            if ($node['tag'] === 'td' && (self::has($node, 'rt-contact-text') || self::has($node, 'rt-contact-icon'))) {
                $edits[$index] = self::compact($node['attrs']['style'] ?? '', self::has($node, 'rt-contact-text'));
            } elseif ($node['tag'] === 'p' && self::has($node, 'rt-delivery-contact-value')) {
                $edits[$index] = self::compact($node['attrs']['style'] ?? '', true);
            }
        }
        [$html, $aliasChanges, $originalAliases] = self::inlineChanges($html, $nodes, $edits, $scope);
        $nodes = self::nodes($html);
        $ledger = self::one($nodes, 'rt-sign-ledger', 'table');
        $brand = self::one($nodes, 'rt-ledger-brand', 'th');
        $direct = self::one($nodes, 'rt-ledger-direct', 'td');
        $company = self::one($nodes, 'rt-ledger-company', 'td');
        $train = self::one($nodes, 'rt-delivery-train-cell', 'td');
        $gap = self::one($nodes, 'rt-delivery-train-gap', 'td');
        $content = self::one($nodes, 'rt-sign-ledger-content', 'td');
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        $contacts = self::flow(
            self::block('direct', 280, '0 11px 0 22px', self::inner($html, $nodes[$direct])),
            self::block('company', 280, '0 40px 0 22px', self::inner($html, $nodes[$company])),
        );
        $flow = '<div class="rt-dflow rt-sign-ledger design-train-logo" '.self::MARKER.'="'.self::REVISION.'" style="font-size:0;line-height:0;text-align:left;">'
            .self::block('brand', 280, '0 40px 0 0', self::inner($html, $nodes[$brand]), '40%', 220)
            .self::block('contacts', 600, '0', $contacts, '60%', 280)
            .'</div>';
        $trainBody = self::block('train', 400, '0 40px 0 0', self::inner($html, $nodes[$train]));
        $rootOpen = self::attribute($nodes[$root]['opening'], 'class', trim($nodes[$root]['attrs']['class'].' '.self::HOST.' '.self::host($scope)));
        $html = self::replace($html, [
            [$nodes[$ledger]['start'], $nodes[$ledger]['end'], $flow],
            [$nodes[$train]['openEnd'], $nodes[$train]['close'], $trainBody],
            [$nodes[$train]['start'], $nodes[$train]['openEnd'], self::attribute($nodes[$train]['opening'], 'width', '100%')],
            [$nodes[$gap]['start'], $nodes[$gap]['end'], ''],
            [$nodes[$content]['start'], $nodes[$content]['openEnd'], self::removeAttribute($nodes[$content]['opening'], 'colspan')],
            [$nodes[$root]['start'], $nodes[$root]['openEnd'], $rootOpen],
        ]);
        $html = self::stylesForFlow($html, $scope, $aliasChanges, $originalAliases);
        $nodes = self::nodes($html);
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        $html = substr_replace($html, '<style '.self::STYLE.'="1">'.self::css($scope).'</style>'.self::msoStyle($scope), $nodes[$root]['openEnd'], 0);
        self::assertProjected($html, self::nodes($html));
        if ($before !== self::inventory($html)) {
            self::fail();
        }

        return $html;
    }

    private static function projectPersonalDesktop(string $html, array $nodes): string
    {
        SignatureTableOverlapDelivery::assertRuntime($html);
        if (array_filter($nodes, static fn (array $node): bool => self::has($node, 'rt-train-window')) !== []) {
            self::fail();
        }
        foreach (self::styles($html) as $style) {
            if (isset($style['attrs']['data-rt-mail-document-css']) && trim($style['css']) !== '') {
                return $html;
            }
        }
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        if (preg_match('/(?:^|\s)(rts[0-9a-f]{10})(?=\s|$)/', $nodes[$root]['attrs']['class'] ?? '', $scopeMatch) !== 1) {
            self::fail();
        }
        $scope = $scopeMatch[1];
        $before = self::inventory($html);
        $content = self::one($nodes, 'rt-sign-content', 'td');
        $train = self::one($nodes, 'rt-delivery-train-cell', 'td');
        $image = self::one($nodes, 'rt-delivery-train', 'img');
        [$html, $changes, $aliases] = self::inlineChanges($html, $nodes, [
            $content => 'width:100%;direction:ltr;padding:6px 0 0;',
            $image => 'display:block;width:100%;max-width:100%;height:auto;margin:0;border:0;vertical-align:bottom;',
        ], $scope, personalFlow: true);
        $nodes = self::nodes($html);
        $content = self::one($nodes, 'rt-sign-content', 'td');
        $train = self::one($nodes, 'rt-delivery-train-cell', 'td');
        $gap = self::one($nodes, 'rt-delivery-train-gap', 'td');
        $flow = self::one($nodes, 'rt-personal-columns', 'div');
        $left = self::one($nodes, 'rt-personal-left', 'table');
        $right = self::one($nodes, 'rt-personal-right', 'table');
        $leftCell = self::one($nodes, 'rt-personal-cell-left', 'td');
        $rightCell = self::one($nodes, 'rt-personal-cell-right', 'td');
        $companyBlock = self::one($nodes, 'rt-personal-company-block', 'table');
        $logoFrame = self::one($nodes, 'rt-personal-logo-frame', 'table');
        $logo = self::one($nodes, 'rt-logo', 'img');
        $company = self::one($nodes, 'rt-personal-company-contacts', 'div');
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        $image = self::one($nodes, 'rt-delivery-train', 'img');
        $moving = self::inner($html, $nodes[$train]);
        $moving = str_replace($nodes[$image]['opening'], '<div class="rt-train-window">'.$nodes[$image]['opening'].'</div>', $moving, $wrapped);
        if ($wrapped !== 1) {
            self::fail();
        }
        $trainRow = $nodes[$train]['parent'];
        if ($trainRow === null || ! self::onlyChildren($html, $nodes, $trainRow, [$train, $gap])
            || ! self::onlyChildren($html, $nodes, $content, [$flow])) {
            self::fail();
        }
        $trainBlock = self::block('train', 460, '0', $moving);
        $trainBlock = preg_replace('~style="[^"]*"~', 'style="'.self::personalCurrentTrainStyle().'"', $trainBlock, 1);
        $trainBlock = preg_replace('~\A<table\b~', '<table dir="ltr"', $trainBlock, 1);
        $trainBlock = preg_replace_callback('~<td\b[^>]*>~', static fn (array $match): string => self::personalCellOpening($match[0], train: true), $trainBlock, 1);
        // Move the one existing logo carrier into the first flow group. Its
        // natural order remains usable when a receiver discards media rules.
        $logoHtml = substr($html, $nodes[$logoFrame]['start'], $nodes[$logoFrame]['end'] - $nodes[$logoFrame]['start']);
        $logoHtml = str_replace($nodes[$logoFrame]['opening'], self::attribute($nodes[$logoFrame]['opening'], 'style', self::boundedPersonalStyle($nodes[$logoFrame]['attrs']['style'] ?? '', 200)), $logoHtml);
        $logoHtml = str_replace($nodes[$logo]['opening'], self::attribute($nodes[$logo]['opening'], 'style', self::boundedPersonalStyle($nodes[$logo]['attrs']['style'] ?? '', 200, image: true)), $logoHtml);
        $logoBlock = '<table class="rt-personal-column rt-personal-logo-column" dir="ltr" role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="'.self::personalCurrentColumnStyle('logo').'"><tbody><tr><td class="rt-personal-cell-logo" dir="ltr" align="left" style="'.self::personalLogoCellStyle().'">'.$logoHtml.'</td></tr></tbody></table>';
        $flowOpening = self::attribute($nodes[$flow]['opening'], 'style', self::personalFlowStyle());
        $flowOpening = preg_replace('~\A<div\b~', '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"', $flowOpening, 1);
        $flowOpening = self::directionAttribute($flowOpening, 'ltr');
        $flowOpening = substr($flowOpening, 0, -1).' '.self::MARKER.'="'.self::REVISION.'" '.SignaturePersonalColumns::NATIVE_FLOW_ATTRIBUTE.'="v1">';
        // The canonical 14px alias is shared with personal-direct. Leave that
        // family intact there, and release only the company node from it.
        $companyClasses = array_values(array_filter(preg_split('/\s+/', trim($nodes[$company]['attrs']['class'] ?? '')), static fn (string $class): bool => preg_match('/\Aoi[0-9a-z]+\z/', $class) !== 1));
        $companyOpening = self::attribute(self::attribute($nodes[$company]['opening'], 'style', 'padding-top:0;'), 'class', implode(' ', $companyClasses));
        $personOpening = self::directionAttribute(self::attribute($nodes[$left]['opening'], 'style', self::personalCurrentColumnStyle('left')), 'ltr');
        $personOpening = substr($personOpening, 0, -1).' align="left" height="50">';
        $companyTableOpening = self::directionAttribute(self::attribute($nodes[$right]['opening'], 'style', self::personalCurrentColumnStyle('right')), 'ltr');
        $html = self::replace($html, [
            [$nodes[$trainRow]['start'], $nodes[$trainRow]['end'], ''],
            [$nodes[$flow]['close'], $nodes[$flow]['end'], '</td></tr><tr><td dir="ltr" align="left" width="100%" valign="bottom" style="'.self::personalRootCellStyle(train: true).'">'.$trainBlock.'</td></tr></tbody></table>'],
            [$nodes[$content]['start'], $nodes[$content]['openEnd'], self::removeAttribute($nodes[$content]['opening'], 'colspan')],
            [$nodes[$flow]['start'], $nodes[$flow]['openEnd'], $flowOpening.'<tbody><tr><td dir="ltr" align="left" width="100%" valign="top" style="'.self::personalRootCellStyle().'">'.$logoBlock],
            [$nodes[$left]['start'], $nodes[$left]['openEnd'], $personOpening],
            [$nodes[$right]['start'], $nodes[$right]['openEnd'], $companyTableOpening],
            [$nodes[$leftCell]['start'], $nodes[$leftCell]['openEnd'], self::personalCellOpening($nodes[$leftCell]['opening'])],
            [$nodes[$rightCell]['start'], $nodes[$rightCell]['openEnd'], self::personalCellOpening($nodes[$rightCell]['opening'])],
            [$nodes[$companyBlock]['start'], $nodes[$companyBlock]['openEnd'], self::attribute($nodes[$companyBlock]['opening'], 'style', self::boundedPersonalStyle($nodes[$companyBlock]['attrs']['style'] ?? '', 220))],
            [$nodes[$logoFrame]['start'], $nodes[$logoFrame]['end'], ''],
            [$nodes[$company]['start'], $nodes[$company]['openEnd'], $companyOpening],
            [$nodes[$root]['start'], $nodes[$root]['openEnd'], self::attribute($nodes[$root]['opening'], 'class', trim($nodes[$root]['attrs']['class'].' '.self::HOST.' '.self::personalCurrentHost($scope)))],
        ]);
        $html = self::stylesForFlow($html, $scope, $changes, $aliases, personalFlow: true);
        $nodes = self::nodes($html);
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        $html = substr_replace($html, '<style '.self::STYLE.'="1">'.self::personalCurrentCss($scope).'</style>', $nodes[$root]['openEnd'], 0);
        self::assertPersonalProjected($html, self::nodes($html));
        if ($before !== self::inventory($html)) {
            self::fail();
        }

        return $html;
    }

    /** Published v4-v7 factories below remain exact replay evidence. */
    private static function personalCss(string $scope, bool $legacy = false, bool $previous = false, bool $rowPrevious = false): string
    {
        if ($legacy || $previous) {
            return self::personalCssPrevious($scope, $legacy);
        }
        $p = '.'.self::personalHost($scope, rowPrevious: $rowPrevious).' ';
        $commonTableStyle = self::personalTableStyle();
        if (! $rowPrevious) {
            // Outlook otherwise converts the PERSON TABLE's legacy align=left
            // into text-align and loses the layout float on reply/forward.
            $commonTableStyle = str_replace('text-align:left;', '', $commonTableStyle);
        }

        return $p.'.rt-personal-columns{'.self::importantDeclarations(self::personalFlowStyle()).'}'
            .$p.'.rt-personal-column,'.$p.'.rt-df-train{'.self::importantDeclarations($commonTableStyle).'}'
            .$p.'.rt-personal-left{display:inline-table!important;min-height:50px!important;height:50px!important;}'
            .$p.'.rt-personal-right{border-top:4px solid transparent!important;}'
            .$p.'.rt-personal-logo-column{margin-bottom:10px!important;}'
            .$p.'.rt-personal-cell-logo{'.self::importantDeclarations(self::personalLogoCellStyle()).'}'
            .$p.'.rt-personal-cell-left,'.$p.'.rt-personal-cell-right{'.self::importantDeclarations(self::personalCellStyle()).'}'
            .$p.'.rt-personal-right .rt-personal-company-block{width:100%!important;max-width:220px!important;table-layout:fixed!important;}'
            .$p.'.rt-personal-logo-column table,'.$p.'.rt-personal-logo-column img{width:100%!important;max-width:200px!important;table-layout:fixed!important;height:auto!important;}'
            .$p.'.rt-df-train{display:inline-table!important;vertical-align:bottom!important;border-left:0!important;}'
            .$p.'.rt-dfc-train{'.self::importantDeclarations(self::personalTrainCellStyle()).'}'
            .$p.'.rt-personal-company-contacts{padding-top:0!important;}'
            .($rowPrevious ? $p.'.rt-train-window{overflow:hidden!important;padding-top:9.2%!important;}'
                .$p.'.rt-delivery-train{transform:scale(1.733333);transform-origin:100% 100%;}'
                : $p.'.rt-train-window{padding-top:0!important;}')
            .'@media(max-width:680px){'.$p.'.rt-personal-column,'.$p.'.rt-df-train{max-width:100%!important;}'
            .$p.'.rt-personal-column{border-left-width:18px!important;border-right-width:18px!important;}'
            .$p.'.rt-personal-logo-column table{max-width:175px!important;}'
            .$p.'.rt-df-train{border-right-width:18px!important;}}';
    }

    private static function personalCurrentCss(string $scope): string
    {
        $p = '.'.self::personalCurrentHost($scope).' ';
        $commonTableStyle = str_replace('text-align:left;', '', self::personalTableStyle());

        return $p.'.rt-personal-columns{'.self::importantDeclarations(self::personalFlowStyle()).'}'
            .$p.'.rt-personal-column,'.$p.'.rt-df-train{'.self::importantDeclarations($commonTableStyle).'}'
            .$p.'.rt-personal-left{display:inline-table!important;min-height:50px!important;height:50px!important;}'
            .$p.'.rt-personal-right{border-top:0 solid transparent!important;}'
            .$p.'.rt-personal-logo-column{margin-bottom:10px!important;}'
            .$p.'.rt-personal-cell-logo{'.self::importantDeclarations(self::personalLogoCellStyle()).'}'
            .$p.'.rt-personal-cell-left,'.$p.'.rt-personal-cell-right{'.self::importantDeclarations(self::personalCellStyle()).'}'
            .$p.'.rt-personal-right .rt-personal-company-block{width:100%!important;max-width:220px!important;table-layout:fixed!important;}'
            .$p.'.rt-personal-logo-column table,'.$p.'.rt-personal-logo-column img{width:100%!important;max-width:200px!important;table-layout:fixed!important;height:auto!important;}'
            .$p.'.rt-df-train{display:inline-table!important;vertical-align:bottom!important;border-left:0!important;}'
            .$p.'.rt-dfc-train{'.self::importantDeclarations(self::personalTrainCellStyle()).'}'
            .$p.'.rt-personal-company-contacts{padding-top:0!important;}'
            .$p.'.rt-train-window{padding-top:0!important;}'
            .'@media(max-width:680px){'.$p.'.rt-personal-column,'.$p.'.rt-df-train{max-width:100%!important;}'
            .$p.'.rt-personal-column{border-left-width:18px!important;border-right-width:18px!important;}'
            .$p.'.rt-personal-logo-column table{max-width:175px!important;}'
            .$p.'.rt-df-train{border-right-width:18px!important;}}';
    }

    private static function personalCurrentColumnStyle(string $side): string
    {
        return str_replace('border-top:4px solid transparent;', 'border-top:0 solid transparent;', self::personalColumnStyle($side));
    }

    private static function personalCurrentTrainStyle(): string
    {
        return self::personalTrainStyle();
    }

    /** Published v4/v5 drafts retain their exact acknowledged stylesheet. */
    private static function personalCssPrevious(string $scope, bool $legacy = false): string
    {
        $p = '.'.self::host($scope).' ';

        return $p.'.rt-personal-columns{'.self::importantDeclarations(self::personalFlowStyle($legacy)).'}'
            .$p.'.rt-personal-column,'.$p.'.rt-df-train{'.self::importantDeclarations(self::personalTableStyle($legacy)).'}'
            .($legacy ? $p.'.rt-personal-left{float:left!important;}'.$p.'.rt-personal-right{float:right!important;}'
                : $p.'.rt-personal-left{float:left!important;}'.$p.'.rt-personal-right{border-top:4px solid transparent!important;}'.$p.'.rt-personal-logo-column{margin-bottom:10px!important;}')
            .($legacy ? $p.'.rt-personal-column .rt-personal-cell-left,'.$p.'.rt-personal-column .rt-personal-cell-right' : $p.'.rt-personal-cell-logo,'.$p.'.rt-personal-cell-left,'.$p.'.rt-personal-cell-right').'{padding:'.($legacy ? '0 40px' : '0').'!important;'.($legacy ? '' : 'font-size:13px!important;line-height:18px!important;vertical-align:top!important;direction:ltr!important;text-align:left!important;').'}'
            .$p.'.rt-personal-right .rt-personal-company-block{width:100%!important;max-width:220px!important;table-layout:fixed!important;}'
            .($legacy ? $p.'.rt-personal-right .rt-personal-logo-frame{width:100%!important;max-width:200px!important;table-layout:fixed!important;height:auto!important;}'
                .$p.'.rt-personal-right .rt-personal-logo-frame img.rt-logo{width:100%!important;max-width:200px!important;height:auto!important;}'
                : $p.'.rt-personal-logo-column table,'.$p.'.rt-personal-logo-column img{width:100%!important;max-width:200px!important;table-layout:fixed!important;height:auto!important;}')
            .$p.'.rt-df-train{'.($legacy ? 'float:left!important;clear:left!important;position:sticky!important;top:100%!important;' : 'display:inline-table!important;vertical-align:bottom!important;border-left:0!important;float:left!important;clear:left!important;').'}'
            .$p.'.rt-dfc-train{padding:'.($legacy ? '0 40px 0 0' : '0').'!important;border:0!important;'.($legacy ? '' : 'font-size:0!important;line-height:0!important;vertical-align:bottom!important;direction:ltr!important;text-align:left!important;').'}'
            .($legacy ? $p.'.rt-personal-right .rt-personal-company-contacts{padding-top:14px!important;}' : $p.'.rt-personal-company-contacts{padding-top:0!important;}')
            .$p.'.rt-train-window{overflow:hidden!important;direction:rtl!important;max-width:420px!important;}'
            .($legacy ? '' : '@media(max-width:680px){'
                .$p.'.rt-personal-column,'.$p.'.rt-df-train{max-width:100%!important;}'
                .$p.'.rt-personal-column{border-left-width:18px!important;border-right-width:18px!important;}'
                .$p.'.rt-personal-logo-column{margin-bottom:10px!important;}'
                .$p.'.rt-personal-logo-column table{max-width:175px!important;}'
                .$p.'.rt-df-train{border-right-width:18px!important;}}');
    }

    private static function personalFlowStyle(bool $legacy = false): string
    {
        return 'font-size:0;line-height:0;text-align:left;width:100%;max-width:'.($legacy ? '920' : '680').'px;display:table;table-layout:fixed;border-collapse:collapse;overflow:hidden;'.($legacy ? '' : 'direction:ltr;margin:0 auto 0 0;');
    }

    private static function personalTableStyle(bool $legacy = false): string
    {
        return 'display:'.($legacy ? 'table' : 'inline-block').';vertical-align:top;width:'.($legacy ? '50%' : '100%').';min-width:0;max-width:'.($legacy ? '100%' : '340px').';table-layout:fixed;'
            .($legacy ? 'border-collapse:collapse;' : 'border-collapse:separate;border-spacing:0;box-sizing:border-box;border-left:40px solid transparent;border-right:40px solid transparent;direction:ltr;text-align:left;');
    }

    private static function personalCellStyle(bool $legacy = false): string
    {
        return 'padding:'.($legacy ? '0 40px' : '0').';font-size:13px;line-height:18px;vertical-align:top;'.($legacy ? '' : 'direction:ltr;text-align:left;');
    }

    private static function personalTrainCellStyle(): string
    {
        return 'padding:0;font-size:0;line-height:0;vertical-align:bottom;border:0;direction:ltr;text-align:left;';
    }

    private static function personalLogoCellStyle(): string
    {
        return 'padding:0;font-size:0;line-height:0;vertical-align:top;direction:ltr;text-align:left;';
    }

    private static function personalRootCellStyle(bool $train = false): string
    {
        return 'padding:0;width:100%;vertical-align:'.($train ? 'bottom' : 'top').';direction:ltr;text-align:left;font-size:0;line-height:0;';
    }

    private static function personalCellOpening(string $opening, bool $train = false): string
    {
        $opening = self::directionAttribute(self::attribute($opening, 'style', $train ? self::personalTrainCellStyle() : self::personalCellStyle()), 'ltr');

        return array_key_exists('align', self::attributes($opening))
            ? self::attribute($opening, 'align', 'left')
            : substr($opening, 0, -1).' align="left">';
    }

    private static function boundedPersonalStyle(string $style, int $maximum, bool $image = false): string
    {
        $properties = $image ? 'width|max-width|height|max-height' : 'width|max-width|table-layout';
        $parts = array_values(array_filter(self::declarations($style), static fn (string $part): bool => preg_match('/\A\s*(?:'.$properties.')\s*:/i', $part) !== 1));

        return implode(';', $parts).';width:100%;max-width:'.$maximum.'px;'.($image ? 'height:auto;' : 'table-layout:fixed;');
    }

    private static function personalColumnStyle(string $side, bool $legacy = false, bool $previous = false, bool $rowPrevious = false): string
    {
        $style = self::personalTableStyle($legacy);
        if (! $legacy && ! $previous && $side === 'left') {
            $style = str_replace('display:inline-block;', 'display:inline-table;', $style);
            if (! $rowPrevious) {
                $style = str_replace('text-align:left;', '', $style);
            }
        }

        return $style.($legacy ? 'float:'.$side.';' : ($side === 'left' ? ($previous ? 'float:left;' : 'min-height:50px;height:50px;') : '')).($side === 'right' && ! $legacy ? 'border-top:4px solid transparent;' : '')
            .($side === 'logo' && ! $legacy ? 'margin-bottom:10px;' : '');
    }

    private static function personalTrainStyle(bool $legacy = false, bool $previous = false): string
    {
        $table = self::personalTableStyle($legacy);
        // Keep the fixed TABLE formatting context around the 520px image;
        // inline-block creates an anonymous auto-layout table that can overflow.
        if (! $legacy) {
            $table = str_replace('display:inline-block;', 'display:inline-table;', $table);
        }

        return $table.($legacy ? 'float:left;clear:left;position:sticky;top:100%;' : 'vertical-align:bottom;border-left:0;'.($previous ? 'float:left;clear:left;' : ''));
    }

    private static function importantDeclarations(string $style): string
    {
        return implode(';', array_map(static fn (string $part): string => $part.'!important', self::declarations($style))).';';
    }

    private static function assertPersonalProjected(string $html, array $nodes): void
    {
        SignaturePersonalColumns::assertRuntime($html);
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        $flow = self::one($nodes, 'rt-personal-columns', 'table');
        $legacy = ($nodes[$flow]['attrs'][self::MARKER] ?? '') === self::LEGACY_REVISION;
        $previous = ($nodes[$flow]['attrs'][self::MARKER] ?? '') === self::PREVIOUS_REVISION;
        $rowPrevious = ($nodes[$flow]['attrs'][self::MARKER] ?? '') === self::ROW_PREVIOUS_REVISION;
        $current = ($nodes[$flow]['attrs'][self::MARKER] ?? '') === self::REVISION;
        $rowTrain = $current || $rowPrevious || ($nodes[$flow]['attrs'][self::MARKER] ?? '') === self::TWO_ROW_PREVIOUS_REVISION;
        $fluidTrain = $rowTrain || $current;
        $trainBlock = self::one($nodes, 'rt-df-train', 'table');
        $window = self::one($nodes, 'rt-train-window', 'div');
        $image = self::one($nodes, 'rt-delivery-train', 'img');
        $trainCell = self::one($nodes, 'rt-dfc-train', 'td');
        $company = self::one($nodes, 'rt-personal-company-contacts', 'div');
        $left = self::one($nodes, 'rt-personal-left', 'table');
        $right = self::one($nodes, 'rt-personal-right', 'table');
        $leftCell = self::one($nodes, 'rt-personal-cell-left', 'td');
        $rightCell = self::one($nodes, 'rt-personal-cell-right', 'td');
        $companyBlock = self::one($nodes, 'rt-personal-company-block', 'table');
        $logoFrame = self::one($nodes, 'rt-personal-logo-frame', 'table');
        $logo = self::one($nodes, 'rt-logo', 'img');
        $logoColumn = $legacy ? null : self::one($nodes, 'rt-personal-logo-column', 'table');
        if (! $legacy) {
            $logoCell = self::one($nodes, 'rt-personal-cell-logo', 'td');
            $logoRow = $nodes[$logoCell]['parent'];
            $logoBody = $logoRow === null ? null : $nodes[$logoRow]['parent'];
            if ($logoRow === null || $logoBody === null || $nodes[$logoRow]['tag'] !== 'tr' || $nodes[$logoBody]['tag'] !== 'tbody'
                || ! self::onlyChildren($html, $nodes, $logoColumn, [$logoBody])
                || ! self::onlyChildren($html, $nodes, $logoBody, [$logoRow])
                || ! self::onlyChildren($html, $nodes, $logoRow, [$logoCell])
                || ! self::onlyChildren($html, $nodes, $logoCell, [$logoFrame])
                || ($nodes[$logoCell]['attrs']['style'] ?? '') !== ($fluidTrain ? self::personalLogoCellStyle() : self::personalCellStyle())
                || ($nodes[$logoCell]['attrs']['dir'] ?? '') !== 'ltr' || ($nodes[$logoCell]['attrs']['align'] ?? '') !== 'left') {
                self::fail();
            }
        }
        $content = self::one($nodes, 'rt-sign-content', 'td');
        $frame = self::one($nodes, 'rt-sign-content-frame', 'table');
        $flowCell = $nodes[$left]['parent'];
        $children = array_keys(array_filter($nodes, static fn (array $node): bool => $node['parent'] === $flowCell));
        $flowRow = $nodes[$flowCell]['parent'];
        $flowBody = $nodes[$flowRow]['parent'];
        $trainFlowCell = $nodes[$trainBlock]['parent'];
        $trainFlowRow = $trainFlowCell === null ? null : $nodes[$trainFlowCell]['parent'];
        $rows = array_keys(array_filter($nodes, static fn (array $node, int $index): bool => $node['tag'] === 'tr' && self::within($nodes, $index, $frame)
            && ($node['parent'] === $frame || ($nodes[$node['parent']]['tag'] === 'tbody' && $nodes[$node['parent']]['parent'] === $frame)), ARRAY_FILTER_USE_BOTH));
        $personalMarkers = array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][SignaturePersonalColumns::NATIVE_FLOW_ATTRIBUTE])));
        $flowMarkers = array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][self::MARKER])));
        if ($children !== ($legacy ? [$left, $right, $trainBlock] : ($rowTrain ? [$logoColumn, $left, $right] : [$logoColumn, $left, $right, $trainBlock])) || $nodes[$flow]['parent'] !== $content || count($rows) !== 1
            || $nodes[$flowCell]['tag'] !== 'td' || $nodes[$flowRow]['tag'] !== 'tr' || $nodes[$flowBody]['tag'] !== 'tbody'
            || $nodes[$flowBody]['parent'] !== $flow
            || ! self::onlyChildren($html, $nodes, $flow, [$flowBody]) || ! self::onlyChildren($html, $nodes, $flowBody, $rowTrain ? [$flowRow, $trainFlowRow] : [$flowRow])
            || ! self::onlyChildren($html, $nodes, $flowRow, [$flowCell]) || ! self::onlyChildren($html, $nodes, $flowCell, $children)
            || ($nodes[$flowCell]['attrs']['style'] ?? '') !== ($fluidTrain ? self::personalRootCellStyle() : 'padding:0;width:100%;vertical-align:top;'.($legacy ? '' : 'direction:ltr;text-align:left;'))
            || ($rowTrain && ($trainFlowRow === null || $nodes[$trainFlowRow]['tag'] !== 'tr' || $nodes[$trainFlowCell]['tag'] !== 'td'
                || ! self::onlyChildren($html, $nodes, $trainFlowRow, [$trainFlowCell]) || ! self::onlyChildren($html, $nodes, $trainFlowCell, [$trainBlock])
                || ($nodes[$trainFlowCell]['attrs']['style'] ?? '') !== self::personalRootCellStyle(train: true)
                || ($nodes[$trainFlowCell]['attrs']['dir'] ?? '') !== 'ltr' || ($nodes[$trainFlowCell]['attrs']['align'] ?? '') !== 'left'
                || ($nodes[$left]['attrs']['align'] ?? '') !== 'left' || ($nodes[$left]['attrs']['height'] ?? '') !== '50'))
            || ($current && (isset($nodes[$logoColumn]['attrs']['align']) || isset($nodes[$right]['attrs']['align']) || isset($nodes[$trainBlock]['attrs']['align'])))
            || ($nodes[$flow]['attrs']['cellpadding'] ?? '') !== '0' || ($nodes[$flow]['attrs']['cellspacing'] ?? '') !== '0'
            || $personalMarkers !== [$flow] || $flowMarkers !== [$flow]
            || ! self::onlyChildren($html, $nodes, $rows[0], [$content])
            || ! self::onlyChildren($html, $nodes, $content, [$flow])
            || ($nodes[$flow]['attrs'][SignaturePersonalColumns::NATIVE_FLOW_ATTRIBUTE] ?? '') !== 'v1'
            || ($nodes[$flow]['attrs']['style'] ?? '') !== self::personalFlowStyle($legacy)
            || (! $legacy && (($nodes[$flow]['attrs']['dir'] ?? '') !== 'ltr' || ($nodes[$flowCell]['attrs']['dir'] ?? '') !== 'ltr'
                || ($nodes[$flowCell]['attrs']['align'] ?? '') !== 'left'
                || array_filter([$logoColumn, $left, $right, $trainBlock], static fn (int $index): bool => ($nodes[$index]['attrs']['dir'] ?? '') !== 'ltr') !== []
                || array_filter([$leftCell, $rightCell, $trainCell], static fn (int $index): bool => ($nodes[$index]['attrs']['dir'] ?? '') !== 'ltr' || ($nodes[$index]['attrs']['align'] ?? '') !== 'left') !== []
                || ($nodes[$trainCell]['attrs']['style'] ?? '') !== self::personalTrainCellStyle()))
            || ($nodes[$left]['attrs']['style'] ?? '') !== ($current ? self::personalCurrentColumnStyle('left') : self::personalColumnStyle('left', $legacy, $previous, $rowPrevious))
            || ($nodes[$right]['attrs']['style'] ?? '') !== ($current ? self::personalCurrentColumnStyle('right') : self::personalColumnStyle('right', $legacy, $previous))
            || (! $legacy && (($nodes[$logoColumn]['attrs']['style'] ?? '') !== ($current ? self::personalCurrentColumnStyle('logo') : self::personalColumnStyle('logo'))
                || ! self::within($nodes, $logoFrame, $logoColumn)))
            || ($nodes[$leftCell]['attrs']['style'] ?? '') !== self::personalCellStyle($legacy)
            || ($nodes[$rightCell]['attrs']['style'] ?? '') !== self::personalCellStyle($legacy)
            || ! str_ends_with($nodes[$companyBlock]['attrs']['style'] ?? '', ';width:100%;max-width:220px;table-layout:fixed;')
            || ! str_ends_with($nodes[$logoFrame]['attrs']['style'] ?? '', ';width:100%;max-width:200px;table-layout:fixed;')
            || ! str_ends_with($nodes[$logo]['attrs']['style'] ?? '', ';width:100%;max-width:200px;height:auto;')
            || ! self::within($nodes, $logo, $logoFrame) || ($legacy && ! self::within($nodes, $logoFrame, $companyBlock))
            || ($nodes[$trainBlock]['attrs']['style'] ?? '') !== ($current ? self::personalCurrentTrainStyle() : self::personalTrainStyle($legacy, $previous))
            || array_filter($nodes, static fn (array $node): bool => self::has($node, 'rt-delivery-train-cell') || self::has($node, 'rt-delivery-train-gap')) !== []) {
            self::fail();
        }
        if ($nodes[$image]['parent'] !== $window || $nodes[$window]['parent'] !== $trainCell || ! self::within($nodes, $trainCell, $trainBlock)
            || trim(self::inner($html, $nodes[$window])) !== $nodes[$image]['opening'] || ($nodes[$window]['attrs']['dir'] ?? '') !== ($fluidTrain ? '' : 'rtl')
            || ($nodes[$window]['attrs']['style'] ?? '') !== ($fluidTrain ? '' : 'overflow:hidden;direction:rtl;max-width:420px;')
            || ($nodes[$image]['attrs']['style'] ?? '') !== ($fluidTrain ? 'display:block;width:100%;max-width:100%;height:auto;margin:0;border:0;vertical-align:bottom;' : 'display:block;width:520px;max-width:none!important;height:auto;margin:0;border:0;vertical-align:bottom;')
            || ($nodes[$company]['attrs']['style'] ?? '') !== 'padding-top:'.($legacy ? '14px' : '0').';') {
            self::fail();
        }
        if (! in_array($nodes[$flow]['attrs'][self::MARKER] ?? '', [self::REVISION, self::TWO_ROW_PREVIOUS_REVISION, self::ROW_PREVIOUS_REVISION, self::PREVIOUS_REVISION, self::LEGACY_REVISION], true)
            || ! preg_match('/(?:^|\s)(rts[0-9a-f]{10})(?=\s|$)/', $nodes[$root]['attrs']['class'] ?? '', $scope)) {
            self::fail();
        }
        $style = '<style '.self::STYLE.'="1">'.($current ? self::personalCurrentCss($scope[1]) : self::personalCss($scope[1], $legacy, $previous, $rowPrevious)).'</style>';
        $host = $current ? self::personalCurrentHost($scope[1]) : self::personalHost($scope[1], previous: ! $rowTrain, rowPrevious: $rowPrevious);
        if (! self::has($nodes[$root], $host) || substr_count($html, $style) !== 1
            || substr($html, $nodes[$root]['openEnd'], strlen($style)) !== $style) {
            self::fail();
        }
    }

    private static function compact(string $style, bool $text): string
    {
        $parts = self::declarations($style);
        foreach ($parts as &$part) {
            if (preg_match('/\A\s*padding\s*:\s*(0\s+0\s+)8px(\s+9px)?\s*;?\z/i', $part, $match)) {
                $part = 'padding:'.$match[1].'4px'.($match[2] ?? '');
            }
            if ($text && preg_match('/\A\s*line-height\s*:\s*20px\s*\z/i', $part)) {
                $part = 'line-height:18px';
            }
        }
        unset($part);
        if ($text) {
            $parts = array_values(array_filter($parts, static fn (string $part): bool => preg_match('/\A\s*(?:word-break|overflow-wrap)\s*:/i', $part) !== 1));
            $parts[] = 'word-break:normal';
            $parts[] = 'overflow-wrap:anywhere';
        }

        return implode(';', $parts).';';
    }

    /** Update only aliases whose complete node family is intentionally changed. */
    private static function inlineChanges(string $html, array $nodes, array $edits, string $scope, bool $personalFlow = false): array
    {
        $frame = self::one($nodes, 'rt-sign-content-frame', 'table');
        $stage = self::one($nodes, 'rt-sign-stage', 'div');
        if (! self::within($nodes, $frame, $stage)) {
            self::fail();
        }
        $families = [];
        foreach ($nodes as $index => $node) {
            foreach (preg_split('/\s+/', trim($node['attrs']['class'] ?? '')) as $class) {
                if (preg_match('/\Aoi[0-9a-z]+\z/', $class)) {
                    $families[$class][] = $index;
                }
            }
        }
        $changes = [];
        $ranges = [];
        foreach ($edits as $index => $style) {
            $node = $nodes[$index];
            if (! self::within($nodes, $index, $frame)) {
                self::fail();
            }
            $aliases = array_values(array_filter(preg_split('/\s+/', $node['attrs']['class'] ?? ''), static fn (string $class): bool => preg_match('/\Aoi[0-9a-z]+\z/', $class) === 1));
            if (count($aliases) !== 1) {
                self::fail();
            }
            $alias = $aliases[0];
            foreach ($families[$alias] as $member) {
                if (! isset($edits[$member]) || $edits[$member] !== $style
                    || ($nodes[$member]['attrs']['style'] ?? '') !== ($node['attrs']['style'] ?? '')) {
                    self::fail();
                }
            }
            $old = '.'.$scope.'.'.$alias.',.'.$scope.' .'.$alias.'{'.trim($node['attrs']['style'], "; \t\r\n").';}';
            $new = '.'.($personalFlow ? self::personalCurrentHost($scope) : self::host($scope)).' .rt-sign-stage .rt-sign-content-frame '.$node['tag'].'.'.$alias.'{'.self::critical($style).'}';
            $changes[$alias] = [$old, $new];
            $ranges[] = [$node['start'], $node['openEnd'], self::attribute($node['opening'], 'style', $style)];
        }

        return [self::replace($html, $ranges), $changes, array_fill_keys(array_keys($families), true)];
    }

    private static function stylesForFlow(string $html, string $scope, array $changes, array $originalAliases, bool $personalFlow = false): string
    {
        $used = [];
        $protectedAliases = [];
        $nodes = self::nodes($html);
        foreach ($nodes as $node) {
            $parent = $node['parent'];
            // Stable scopes can move from the known DIV onto its outer TABLE.
            // Image pairs also retain their exact alias grammar for the later
            // single-image/MSO visibility compiler.
            $scopeOwner = self::has($node, $scope)
                || ($node['tag'] === 'table' && $parent !== null && self::has($nodes[$parent], 'rt-outlook-signature') && self::has($nodes[$parent], $scope));
            foreach (preg_split('/\s+/', trim($node['attrs']['class'] ?? '')) as $class) {
                $used[$class] = true;
                if ($scopeOwner || $node['tag'] === 'img') {
                    $protectedAliases[$class] = true;
                }
            }
        }
        $ranges = [];
        $inline = 0;
        $runtime = 0;
        foreach (self::styles($html) as $style) {
            $css = $style['css'];
            if (($style['attrs'][OutlookSignatureInlineStyle::ATTRIBUTE] ?? null) === '1') {
                $inline++;
                foreach ($changes as [$old, $new]) {
                    if (substr_count($css, $old) !== 1) {
                        self::fail();
                    }
                    $css = str_replace($old, $new, $css);
                }
                // These are exact generated alias selectors, not a DOM-driven
                // pruning of authored rules or MSO-only image declarations.
                $css = preg_replace_callback('~\.'.$scope.'\.(oi[0-9a-z]+),\.'.$scope.' \.\1\{[^{}]*\}~', static fn (array $match): string => ! isset($originalAliases[$match[1]]) || isset($used[$match[1]]) ? $match[0] : '', $css);
                if ($personalFlow) {
                    // A descendant-only family cannot match the generated
                    // self-scope selector. Keep its declarations byte-exact,
                    // and retain both forms for every present/future owner.
                    $css = preg_replace_callback('~\.'.$scope.'\.(oi[0-9a-z]+),\.'.$scope.' \.\1\{([^{}]*)\}~', static fn (array $match): string => ! isset($originalAliases[$match[1]]) || isset($protectedAliases[$match[1]])
                        ? $match[0] : '.'.$scope.' .'.$match[1].'{'.$match[2].'}', $css);
                }
            } elseif (($style['attrs']['data-rt-outlook-signature-css'] ?? null) === '1') {
                $runtime++;
                $css = self::pruneRuntime($css);
                if ($personalFlow) {
                    $css = self::prunePersonalRuntime($css, $scope);
                }
            } else {
                continue;
            }
            $ranges[] = [$style['contentStart'], $style['contentEnd'], $css];
        }
        if ($inline !== 1 || $runtime !== 1) {
            self::fail();
        }

        return self::replace($html, $ranges);
    }

    /** Replace only complete compiler-owned identities covered by the native flow. */
    private static function prunePersonalRuntime(string $css, string $scope): string
    {
        $p = '.'.$scope.' ';
        $known = [
            '.rt-personal-columns{font-size:0!important;line-height:0!important;text-align:left!important;width:100%!important;max-width:920px!important;}',
            '.rt-personal-column{display:inline-table!important;vertical-align:top!important;width:50%!important;min-width:280px!important;min-width:min(280px,100%)!important;table-layout:fixed!important;border-collapse:collapse!important;}',
            '.rt-personal-column{width:100%!important;min-width:0!important;}',
            '.rt-delivery-train-cell{width:50%!important;padding:0 40px 0 0!important;border-right:1px solid transparent!important;box-sizing:border-box!important;font-size:0!important;line-height:0!important;}',
            'table.rt-personal-company-block img.rt-logo{width:200px!important;max-width:100%!important;height:auto!important;}',
            'table.rt-personal-logo-frame{width:200px!important;max-width:100%!important;height:auto!important;}',
            '.rt-personal-cell-left{padding:0 40px!important;font-size:13px!important;line-height:18px!important;vertical-align:top!important;}',
            '.rt-personal-cell-right{padding:0 40px!important;font-size:13px!important;line-height:18px!important;vertical-align:top!important;}',
            '.rt-personal-cell-left{padding:0 22px!important;}',
            '.rt-personal-cell-right{padding:24px 22px 0!important;}',
            '.rt-sign-ledger img.'.(str_contains($css, $p.'.rt-sign-ledger img.rt-client-logo{') ? 'rt-client-logo' : 'rt-logo').'{width:100%!important;max-width:180px!important;height:auto!important;margin:0!important;}',
            '.rt-sign-ledger-content{padding:25px 0 0!important;}',
            '.rt-delivery-train{display:block!important;width:100%!important;max-width:100%!important;height:auto!important;margin:0!important;border:0!important;vertical-align:bottom!important;}',
            '.rt-contact-text{word-break:break-all!important;overflow-wrap:anywhere!important;}',
            // The retained personal-name identity is more specific and fixes
            // 22/26px in every viewport, including each older media branch.
            '.rt-sign-name{font-size:22px!important;line-height:26px!important;}',
            '.rt-sign-name{font-size:21px!important;line-height:25px!important;}',
            '.rt-sign-name{font-size:18px!important;line-height:22px!important;}',
            '.rt-sign-name{font-size:17px!important;line-height:21px!important;}',
            // The native logo identity already replaces these widths in both
            // desktop and narrow views; the separate margin rule is retained.
            'img.rt-logo{width:180px!important;}',
            'img.rt-logo{width:150px!important;}',
            'img.rt-logo{width:138px!important;}',
        ];
        foreach ($known as $rule) {
            if (substr_count($css, $p.$rule) !== 1) {
                self::fail();
            }
            $css = str_replace($p.$rule, '', $css);
        }
        $spacing = $p.'.rt-personal-direct,'.$p.'.rt-personal-company-contacts{padding-top:14px!important;}';
        if (substr_count($css, $spacing) !== 1) {
            self::fail();
        }
        $css = str_replace($spacing, $p.'.rt-personal-direct{padding-top:14px!important;}', $css);
        // This exact narrow rule duplicates the retained global identity.
        $duplicate = $p.'.rt-personal-company-block{float:none!important;margin:0!important;}';
        if (substr_count($css, $duplicate) !== 2) {
            self::fail();
        }
        $offset = strrpos($css, $duplicate);

        $css = substr_replace($css, '', $offset, strlen($duplicate));

        return str_replace('@media only screen and (max-width:560px){}', '', $css);
    }

    /** Only known compiler-owned layout identities removed by this projection. */
    private static function pruneRuntime(string $css): string
    {
        $out = '';
        $start = 0;
        $quote = null;
        $comment = false;
        for ($i = 0, $length = strlen($css); $i < $length; $i++) {
            $c = $css[$i];
            if ($comment) {
                if ($c === '*' && ($css[$i + 1] ?? '') === '/') {
                    $comment = false;
                    $i++;
                }

                continue;
            }
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $comment = true;
                $i++;
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '{') {
                $head = substr($css, $start, $i - $start);
                $depth = 1;
                $end = $i + 1;
                $innerQuote = null;
                $innerComment = false;
                for (; $end < $length && $depth > 0; $end++) {
                    $d = $css[$end];
                    if ($innerComment) {
                        if ($d === '*' && ($css[$end + 1] ?? '') === '/') {
                            $innerComment = false;
                            $end++;
                        }
                    } elseif ($innerQuote !== null) {
                        if ($d === '\\') {
                            $end++;
                        } elseif ($d === $innerQuote) {
                            $innerQuote = null;
                        }
                    } elseif ($d === '/' && ($css[$end + 1] ?? '') === '*') {
                        $innerComment = true;
                        $end++;
                    } elseif ($d === '"' || $d === "'") {
                        $innerQuote = $d;
                    } elseif ($d === '{') {
                        $depth++;
                    } elseif ($d === '}') {
                        $depth--;
                    }
                }
                if ($depth !== 0) {
                    self::fail();
                }
                $body = substr($css, $i + 1, $end - $i - 2);
                $plain = trim(preg_replace('~/\*.*?\*/~s', '', $head));
                if (str_starts_with($plain, '@')) {
                    $out .= $head.'{'.self::pruneRuntime($body).'}';
                } else {
                    $selectors = explode(',', $plain);
                    $removed = $selectors !== [];
                    foreach ($selectors as $selector) {
                        $removed = $removed && preg_match('/\.(?:rt-ledger-(?:brand|contacts|direct|company)|rt-delivery-(?:wide-column|group-cell))(?![\w-])/', $selector) === 1;
                    }
                    if (! $removed) {
                        $out .= $head.'{'.$body.'}';
                    }
                }
                $i = $end - 1;
                $start = $end;
            }
        }

        return $out.substr($css, $start);
    }

    private static function flow(string $leftContents, string $rightContents): string
    {
        return '<div class="rt-dflow" style="font-size:0;line-height:0;text-align:left;">'.$leftContents.$rightContents.'</div>';
    }

    private static function block(string $kind, int $max, string $padding, string $contents, string $width = '100%', ?int $minimum = null): string
    {
        $min = $minimum === null ? '' : 'min-width:'.$minimum.'px;min-width:min('.$minimum.'px,100%);';

        return '<table class="rt-dfb rt-df-'.$kind.'" role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="display:inline-table;vertical-align:top;width:'.$width.';'.$min.'max-width:'.$max.'px;table-layout:fixed;border-collapse:collapse;"><tbody><tr><td class="rt-dfc rt-dfc-'.$kind.'" style="padding:'.$padding.';vertical-align:top;font-weight:normal;font-size:13px;line-height:18px;">'.$contents.'</td></tr></tbody></table>';
    }

    private static function css(string $scope): string
    {
        $p = '.'.self::host($scope).' ';

        return $p.'.rt-dfb{display:inline-table!important;vertical-align:top!important;width:100%!important;table-layout:fixed!important;border-collapse:collapse!important;}'
            .$p.'.rt-df-brand{width:40%!important;min-width:220px!important;min-width:min(220px,100%)!important;max-width:280px!important;}'
            .$p.'.rt-df-train{max-width:400px!important;}'
            .$p.'.rt-df-contacts{width:60%!important;min-width:280px!important;min-width:min(280px,100%)!important;max-width:600px!important;}'
            .$p.'.rt-df-direct,'.$p.'.rt-df-company{max-width:280px!important;}'
            .$p.'.rt-dflow{font-size:0!important;line-height:0!important;text-align:left!important;}'
            .$p.'.rt-dfc{font-size:13px!important;line-height:18px!important;vertical-align:top!important;font-weight:normal!important;}'
            .$p.'.rt-dfc-brand,'.$p.'.rt-dfc-train{padding:0 40px 0 0!important;border:0!important;}'
            .$p.'.rt-dfc-contacts{padding:0!important;}'
            .$p.'.rt-dfc-direct{padding:0 11px 0 22px!important;}'
            .$p.'.rt-dfc-company{padding:0 40px 0 22px!important;}';
    }

    /** Older MSO receives ordinary full-width tables, without narrow columns. */
    private static function msoStyle(string $scope): string
    {
        return '<!--[if mso]><style '.self::MSO_STYLE.'="1">.'.self::host($scope).' .rt-dfb{display:table!important;width:100%!important;min-width:0!important;max-width:none!important;}</style><![endif]-->';
    }

    /** A single content-bound class survives Outlook's class-prefix rewriter. */
    private static function host(string $scope): string
    {
        return 'rt-df4'.substr($scope, 3);
    }

    private static function personalHost(string $scope, bool $previous = false, bool $rowPrevious = false): string
    {
        return $previous ? self::host($scope) : ($rowPrevious ? 'rt-df6' : 'rt-df7').substr($scope, 3);
    }

    private static function personalCurrentHost(string $scope): string
    {
        return 'rt-df8'.substr($scope, 3);
    }

    private static function critical(string $style): string
    {
        $parts = self::declarations($style);
        foreach ($parts as &$part) {
            if (preg_match('/\A\s*(?:width|padding(?:-[a-z]+)?|border(?:-[a-z]+)?|font-size|line-height|vertical-align|word-break|overflow-wrap)\s*:/i', $part)) {
                $part = preg_replace('/\s*!important\s*\z/i', '', $part).'!important';
            }
        }

        return implode(';', $parts).';';
    }

    private static function declarations(string $style): array
    {
        $parts = [];
        $start = 0;
        $quote = null;
        $depth = 0;
        for ($i = 0, $length = strlen($style); $i < $length; $i++) {
            $c = $style[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($c === ';' && $depth === 0) {
                if (trim(substr($style, $start, $i - $start)) !== '') {
                    $parts[] = trim(substr($style, $start, $i - $start));
                }
                $start = $i + 1;
            }
        }
        if ($quote !== null || $depth !== 0) {
            self::fail();
        }
        if (trim(substr($style, $start)) !== '') {
            $parts[] = trim(substr($style, $start));
        }

        return $parts;
    }

    private static function assertProjected(string $html, array $nodes): void
    {
        $flows = array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][self::MARKER])));
        $root = self::one($nodes, 'rt-outlook-signature', 'div');
        if (count($flows) !== 1 || ! in_array($nodes[$flows[0]]['attrs'][self::MARKER] ?? '', [self::REVISION, self::TWO_ROW_PREVIOUS_REVISION, self::ROW_PREVIOUS_REVISION, self::PREVIOUS_REVISION, self::LEGACY_REVISION], true)
            || ! self::has($nodes[$root], self::HOST) || ! self::within($nodes, $flows[0], $root)) {
            self::fail();
        }
        foreach (['brand', 'contacts', 'direct', 'company', 'train'] as $kind) {
            $block = self::one($nodes, 'rt-df-'.$kind, 'table');
            if (! self::within($nodes, $block, $root)) {
                self::fail();
            }
        }
        if (array_filter($nodes, static fn (array $node): bool => self::has($node, 'rt-delivery-train-gap') || self::has($node, 'rt-delivery-wide-column')) !== []) {
            self::fail();
        }
        preg_match('/(?:^|\s)(rts[0-9a-f]{10})(?=\s|$)/', $nodes[$root]['attrs']['class'] ?? '', $scope);
        $styles = array_values(array_filter(self::styles($html), static fn (array $style): bool => isset($style['attrs'][self::STYLE])));
        if (count($styles) !== 1 || ($styles[0]['attrs'][self::STYLE] ?? '') !== '1'
            || ! isset($scope[1]) || $styles[0]['css'] !== self::css($scope[1])
            || ! self::has($nodes[$root], self::host($scope[1]))
            || substr_count($html, self::msoStyle($scope[1])) !== 1
            || substr($html, $nodes[$root]['openEnd'], strlen('<style '.self::STYLE.'="1">'.self::css($scope[1]).'</style>'.self::msoStyle($scope[1]))) !== '<style '.self::STYLE.'="1">'.self::css($scope[1]).'</style>'.self::msoStyle($scope[1])) {
            self::fail();
        }
    }

    private static function inventory(string $html): array
    {
        $images = [];
        $links = [];
        $mso = [];
        foreach (self::nodes($html) as $node) {
            if ($node['tag'] === 'img') {
                $images[] = array_intersect_key($node['attrs'], array_flip(['src', 'width', 'height', 'alt']));
            } elseif ($node['tag'] === 'a') {
                $links[] = $node['attrs']['href'] ?? null;
            }
        }
        preg_match_all('~<!--\[if mso\]>(.*?)<!\[endif\]-->~s', $html, $comments);
        foreach ($comments[1] as $comment) {
            preg_match_all('~<img\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is', $comment, $tags);
            array_push($mso, ...$tags[0]);
        }
        sort($images);
        sort($links);
        sort($mso);

        $text = preg_replace('~<!--.*?-->|<style\b[^>]*>.*?</style\s*>~is', '', $html);
        $text = CssSemantic::decodeHtmlEntitiesOnce(strip_tags($text));
        $text = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00a0}", ' ', $text)));

        return [$images, $links, $mso, $text];
    }

    /** Unknown sibling content must retain the original layout and typography. */
    private static function knownContent(string $html, array $nodes, int $ledger): bool
    {
        $content = self::one($nodes, 'rt-sign-ledger-content', 'td');
        if (! self::onlyChildren($html, $nodes, $content, [$ledger])) {
            return false;
        }
        $brand = self::one($nodes, 'rt-ledger-brand', 'th');
        $heading = self::one($nodes, 'rt-delivery-brand-heading', 'td');
        $headingTable = $nodes[$nodes[$heading]['parent']]['parent'];
        if (($nodes[$headingTable]['tag'] ?? '') === 'tbody') {
            $headingTable = $nodes[$headingTable]['parent'];
        }
        if ($headingTable === null || ! self::onlyChildren($html, $nodes, $brand, [$headingTable])) {
            return false;
        }
        $contacts = self::one($nodes, 'rt-ledger-contacts', 'th');
        $contactTable = self::one($nodes, 'rt-delivery-contacts', 'table');
        if (! self::onlyChildren($html, $nodes, $contacts, [$contactTable])) {
            return false;
        }
        // Removed table/row scaffolding may contain whitespace, never authored
        // text or extra nodes. The two real data tables themselves stay opaque.
        foreach ([$ledger, $contactTable] as $table) {
            $container = $table;
            do {
                $children = array_keys(array_filter($nodes, static fn (array $node): bool => $node['parent'] === $container));
                if (! self::onlyChildren($html, $nodes, $container, $children) || $children === []) {
                    return false;
                }
                $container = count($children) === 1 && in_array($nodes[$children[0]]['tag'], ['tbody', 'tr'], true) ? $children[0] : null;
            } while ($container !== null);
        }
        foreach (['rt-ledger-direct', 'rt-ledger-company'] as $class) {
            $group = self::one($nodes, $class, 'td');
            $tables = array_keys(array_filter($nodes, static fn (array $node, int $index): bool => $node['tag'] === 'table' && self::has($node, 'rt-contact') && self::within($nodes, $index, $group), ARRAY_FILTER_USE_BOTH));
            if (count($tables) !== 1) {
                return false;
            }
            $child = $tables[0];
            while ($child !== $group) {
                $parent = $nodes[$child]['parent'];
                if ($parent === null || ! self::onlyChildren($html, $nodes, $parent, [$child])) {
                    return false;
                }
                $child = $parent;
            }
        }

        return true;
    }

    private static function onlyChildren(string $html, array $nodes, int $parent, array $children): bool
    {
        $cursor = $nodes[$parent]['openEnd'];
        foreach ($children as $child) {
            if ($nodes[$child]['parent'] !== $parent || trim(substr($html, $cursor, $nodes[$child]['start'] - $cursor)) !== '') {
                return false;
            }
            $cursor = $nodes[$child]['end'];
        }

        return trim(substr($html, $cursor, $nodes[$parent]['close'] - $cursor)) === '';
    }

    private static function styles(string $html): array
    {
        preg_match_all('~(<style\b[^>]*>)(.*?)(</style\s*>)~is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $match): array => ['attrs' => self::attributes($match[1][0]), 'css' => $match[2][0],
            'contentStart' => $match[2][1], 'contentEnd' => $match[2][1] + strlen($match[2][0])], $matches);
    }

    private static function one(array $nodes, string $class, string $tag): int
    {
        $matches = array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, $class)));
        if (count($matches) !== 1 || $nodes[$matches[0]]['tag'] !== $tag) {
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
        for ($current = $nodes[$child]['parent']; $current !== null; $current = $nodes[$current]['parent']) {
            if ($current === $parent) {
                return true;
            }
        }

        return false;
    }

    private static function inner(string $html, array $node): string
    {
        return substr($html, $node['openEnd'], $node['close'] - $node['openEnd']);
    }

    private static function attribute(string $tag, string $name, string $value): string
    {
        $replacement = ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        $output = preg_replace('~\s+'.preg_quote($name, '~').'\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i', addcslashes($replacement, '\\$'), $tag, -1, $count);

        return $count === 1 ? $output : self::fail();
    }

    private static function directionAttribute(string $tag, string $direction): string
    {
        return array_key_exists('dir', self::attributes($tag))
            ? self::attribute($tag, 'dir', $direction)
            : substr($tag, 0, -1).' dir="'.$direction.'">';
    }

    private static function removeAttribute(string $tag, string $name): string
    {
        $out = preg_replace('~\s+'.preg_quote($name, '~').'\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $tag, -1, $count);

        return $count === 1 ? $out : self::fail();
    }

    private static function attributes(string $tag): array
    {
        preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $tag, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attrs = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (array_key_exists($name, $attrs)) {
                self::fail();
            }
            $attrs[$name] = CssSemantic::decodeHtmlEntitiesOnce($match[2] ?? $match[3] ?? $match[4] ?? '');
        }

        return $attrs;
    }

    private static function nodes(string $html): array
    {
        preg_match_all(self::TOKENS, $html, $tokens, PREG_OFFSET_CAPTURE);
        $nodes = [];
        $stack = [];
        foreach ($tokens[0] as [$token, $offset]) {
            if (str_starts_with($token, '<!--') || preg_match('~\A<style\b~i', $token)) {
                continue;
            }
            preg_match('~\A<(/?)([a-z][a-z0-9:-]*)\b~i', $token, $name);
            $tag = strtolower($name[2]);
            if ($name[1] === '/') {
                $index = array_pop($stack);
                if ($index === null || $nodes[$index]['tag'] !== $tag) {
                    self::fail();
                }
                $nodes[$index]['close'] = $offset;
                $nodes[$index]['end'] = $offset + strlen($token);

                continue;
            }
            $void = in_array($tag, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true);
            $parent = $stack === [] ? null : $stack[array_key_last($stack)];
            $index = count($nodes);
            $nodes[] = ['tag' => $tag, 'attrs' => self::attributes($token), 'parent' => $parent, 'start' => $offset,
                'openEnd' => $offset + strlen($token), 'close' => $void ? $offset + strlen($token) : null,
                'end' => $void ? $offset + strlen($token) : null, 'opening' => $token];
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
        throw new RuntimeException('Die finale Desktop-Darstellung besitzt keinen eindeutigen Inhalts-/CSS-Vertrag.');
    }
}
