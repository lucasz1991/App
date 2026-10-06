<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignatureArtifactVersion;
use App\Support\Mail\SignatureTableOverlapDelivery;
use RuntimeException;

/** Reversible native output only; published rows and conditional bytes stay intact. */
final class OutlookNativePersonalSignature
{
    public const MARKER = 'identity-aligned-v2';

    public const LEGACY_MARKER = 'identity-left-v1';

    public const STYLE_ATTRIBUTE = 'data-rt-personal-column-css';

    private const ATTRIBUTE = 'data-rt-personal-layout';

    private const ALIGNMENT_CLASS = 'rt-pv2';

    private const IDENTITY_SLOT = '<!-- RT-PERSONAL-IDENTITY-SLOT-V2 -->';

    private const COMPANY_SLOT = '<!-- RT-PERSONAL-COMPANY-SLOT-V2 -->';

    public static function project(string $html): string
    {
        if (str_contains($html, self::ATTRIBUTE)) {
            self::restore($html);

            return $html;
        }
        $legacy = self::projectLegacy($html);
        if ($legacy === $html) {
            return $html;
        }
        foreach ([self::ALIGNMENT_CLASS, 'rt-phl', 'rt-phr', 'rt-pnw', 'rt-personal-logo-table', 'data-rt-personal-header', 'data-rt-personal-contacts', self::IDENTITY_SLOT, self::COMPANY_SLOT] as $reserved) {
            if (str_contains($html, $reserved)) {
                self::fail();
            }
        }

        return self::align($legacy);
    }

    public static function restore(string $html): string
    {
        if (str_contains($html, self::ATTRIBUTE.'="'.self::MARKER.'"')) {
            $html = self::unalign($html);
        }

        return self::restoreLegacy($html);
    }

    private static function projectLegacy(string $html): string
    {
        if (str_contains($html, self::ATTRIBUTE)) {
            self::restore($html);

            return $html;
        }
        if (! SignatureTableOverlapDelivery::applies($html)
            || SignatureArtifactVersion::detect('signature', $html) !== 'v27'
            || ! str_contains($html, 'rt-person-kopf')) {
            return $html;
        }
        $nodes = self::nodes($html);
        if (array_filter($nodes, static fn (array $node): bool => self::hasClass($node, 'rt-sign-ledger')) === []) {
            return $html;
        }
        $people = array_keys(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, 'rt-person-kopf')));
        if ($people === []) {
            return $html;
        }
        $person = self::oneClass($nodes, 'rt-person-kopf', 'div');
        $name = self::oneClass($nodes, 'rt-sign-name', 'p');
        if (trim(CssSemantic::decodeHtmlEntitiesOnce(strip_tags(self::inner($html, $nodes[$name])))) === '') {
            return $html;
        }
        [$ledger, $brand, $contacts, $table, $rows] = self::ledger($nodes);
        $wrapper = $person;
        while ($nodes[$wrapper]['parent'] !== $brand) {
            $wrapper = $nodes[$wrapper]['parent'] ?? self::fail();
        }
        $logo = self::oneClass($nodes, 'rt-logo', 'img');
        if ($nodes[$wrapper]['tag'] !== 'div' || ! self::within($nodes, $name, $person)
            || ! self::within($nodes, $logo, $brand) || $nodes[$logo]['end'] > $nodes[$wrapper]['start']
            || trim(substr($html, $nodes[$wrapper]['end'], $nodes[$brand]['close'] - $nodes[$wrapper]['end'])) !== '') {
            self::fail();
        }
        $direct = self::cell($nodes, $rows[0], 'rt-ledger-direct');
        self::cell($nodes, $rows[1], 'rt-ledger-company');
        $logoBytes = substr($html, $nodes[$brand]['openEnd'], $nodes[$wrapper]['start'] - $nodes[$brand]['openEnd']);
        $personBytes = substr($html, $nodes[$wrapper]['start'], $nodes[$brand]['close'] - $nodes[$wrapper]['start']);
        $directBytes = self::whole($html, $nodes[$rows[0]]);

        // Clone already mirrored presentation geometry, rather than adding CSS
        // or assigning a personal contact class to the relocated logo group.
        $tableOpen = self::opening($html, $nodes[$table]);
        $tableOpen = self::replaceClass($tableOpen, 'rt-delivery-contacts', 'rt-personal-direct-table');
        $tableOpen = self::attribute($tableOpen, 'data-rt-personal-direct', '1');
        $directFrame = $tableOpen.'<tbody>'.$directBytes.'</tbody></table>';
        $logoCell = self::replaceClass(self::opening($html, $nodes[$direct]), 'rt-ledger-direct', 'rt-personal-logo');
        $logoRow = self::attribute(self::opening($html, $nodes[$rows[0]]), 'data-rt-personal-logo', '1')
            .$logoCell.$logoBytes.'</td></tr>';

        return self::replace($html, [
            [$nodes[$brand]['start'], $nodes[$brand]['openEnd'], self::column(self::opening($html, $nodes[$brand]), '32%', '48%')],
            [$nodes[$contacts]['start'], $nodes[$contacts]['openEnd'], self::column(self::opening($html, $nodes[$contacts]), '68%', '52%')],
            [$nodes[$brand]['openEnd'], $nodes[$brand]['close'], $personBytes.$directFrame],
            [$nodes[$rows[0]]['start'], $nodes[$rows[0]]['end'], $logoRow],
            [$nodes[$ledger]['start'], $nodes[$ledger]['openEnd'], self::attribute(self::opening($html, $nodes[$ledger]), self::ATTRIBUTE, self::LEGACY_MARKER)],
            [$nodes[self::oneClass($nodes, 'rt-outlook-signature', 'div')]['start'], $nodes[self::oneClass($nodes, 'rt-outlook-signature', 'div')]['start'], self::columnStyle($nodes)],
        ]);
    }

    /** Restore only our exact projection before the existing physical mobile adapter. */
    private static function restoreLegacy(string $html): string
    {
        if (! str_contains($html, self::ATTRIBUTE)) {
            return $html;
        }
        if (! SignatureTableOverlapDelivery::applies($html)
            || SignatureArtifactVersion::detect('signature', $html) !== 'v27') {
            self::fail();
        }
        $nodes = self::nodes($html);
        $columns = self::columnStyleRange($html, $nodes);
        [$ledger, $brand, $contacts, $table, $rows] = self::ledger($nodes, $columns !== null);
        $markers = array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][self::ATTRIBUTE])));
        if ($markers !== [$ledger] || ($nodes[$ledger]['attrs'][self::ATTRIBUTE] ?? '') !== self::LEGACY_MARKER) {
            self::fail();
        }
        $frame = self::oneClass($nodes, 'rt-personal-direct-table', 'table');
        $person = self::oneClass($nodes, 'rt-person-kopf', 'div');
        $logo = self::oneClass($nodes, 'rt-logo', 'img');
        $logoCell = self::cell($nodes, $rows[0], 'rt-personal-logo');
        self::cell($nodes, $rows[1], 'rt-ledger-company');
        $directRows = self::rows($nodes, $frame);
        if ($nodes[$frame]['parent'] !== $brand || ($nodes[$frame]['attrs']['data-rt-personal-direct'] ?? '') !== '1'
            || count($directRows) !== 1 || ($nodes[$rows[0]]['attrs']['data-rt-personal-logo'] ?? '') !== '1'
            || ! self::within($nodes, $person, $brand) || ! self::within($nodes, $logo, $logoCell)
            || $nodes[$person]['end'] > $nodes[$frame]['start']
            || trim(substr($html, $nodes[$frame]['end'], $nodes[$brand]['close'] - $nodes[$frame]['end'])) !== '') {
            self::fail();
        }
        $direct = self::cell($nodes, $directRows[0], 'rt-ledger-direct');
        foreach (['data-rt-personal-direct' => $frame, 'data-rt-personal-logo' => $rows[0]] as $attribute => $target) {
            if (array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][$attribute]))) !== [$target]) {
                self::fail();
            }
        }
        $expectedFrame = self::attribute(self::replaceClass(self::opening($html, $nodes[$table]), 'rt-delivery-contacts', 'rt-personal-direct-table'), 'data-rt-personal-direct', '1');
        $expectedRow = self::attribute(self::opening($html, $nodes[$directRows[0]]), 'data-rt-personal-logo', '1');
        $expectedCell = self::replaceClass(self::opening($html, $nodes[$direct]), 'rt-ledger-direct', 'rt-personal-logo');
        if (self::opening($html, $nodes[$frame]) !== $expectedFrame
            || self::opening($html, $nodes[$rows[0]]) !== $expectedRow
            || self::opening($html, $nodes[$logoCell]) !== $expectedCell) {
            self::fail();
        }
        $logoBytes = self::inner($html, $nodes[$logoCell]);
        $personBytes = substr($html, $nodes[$brand]['openEnd'], $nodes[$frame]['start'] - $nodes[$brand]['openEnd']);
        $ledgerOpen = self::opening($html, $nodes[$ledger]);
        if (substr_count($ledgerOpen, ' '.self::ATTRIBUTE.'="'.self::LEGACY_MARKER.'"') !== 1) {
            self::fail();
        }

        $ranges = [
            [$nodes[$brand]['openEnd'], $nodes[$brand]['close'], $logoBytes.$personBytes],
            [$nodes[$rows[0]]['start'], $nodes[$rows[0]]['end'], self::whole($html, $nodes[$directRows[0]])],
            [$nodes[$ledger]['start'], $nodes[$ledger]['openEnd'], str_replace(' '.self::ATTRIBUTE.'="'.self::LEGACY_MARKER.'"', '', $ledgerOpen)],
        ];
        if ($columns !== null) {
            $ranges[] = [$nodes[$brand]['start'], $nodes[$brand]['openEnd'], self::column(self::opening($html, $nodes[$brand]), '48%', '32%')];
            $ranges[] = [$nodes[$contacts]['start'], $nodes[$contacts]['openEnd'], self::column(self::opening($html, $nodes[$contacts]), '52%', '68%')];
            $ranges[] = [$columns[0], $columns[1], ''];
        }

        return self::replace($html, $ranges);
    }

    /** Two real shared rows; intrinsic header height aligns both contact starts. */
    private static function align(string $html): string
    {
        self::restoreLegacy($html);
        $nodes = self::nodes($html);
        [$ledger, $brand, $contacts, $table, $rows] = self::ledger($nodes, true);
        $outerRow = self::rows($nodes, $ledger)[0];
        $directTable = self::oneClass($nodes, 'rt-personal-direct-table', 'table');
        $direct = self::oneClass($nodes, 'rt-ledger-direct', 'td');
        $person = self::oneClass($nodes, 'rt-person-kopf', 'div');
        $wrapper = $nodes[$person]['parent'] ?? self::fail();
        if ($nodes[$wrapper]['parent'] !== $brand || $nodes[$brand]['children'] !== [$wrapper, $directTable]
            || isset($nodes[$brand]['attrs']['id']) || isset($nodes[$contacts]['attrs']['id'])) {
            self::fail();
        }
        $columns = self::columnStyleRange($html, $nodes) ?? self::fail();
        $personBytes = self::whole($html, $nodes[$wrapper]);
        $personOpen = self::padding(self::opening($html, $nodes[$wrapper]), 'padding-top:13px;', 'padding-top:0;');
        $personOpen = self::addClass($personOpen, 'rt-pnw');
        $headerPerson = $personOpen.substr($personBytes, $nodes[$wrapper]['openEnd'] - $nodes[$wrapper]['start']);
        $companyRow = self::whole($html, $nodes[$rows[1]]);
        $logoTable = self::replace(self::whole($html, $nodes[$table]), [
            [$nodes[$rows[1]]['start'] - $nodes[$table]['start'], $nodes[$rows[1]]['end'] - $nodes[$table]['start'], self::COMPANY_SLOT],
            [0, $nodes[$table]['openEnd'] - $nodes[$table]['start'], self::replaceClass(self::opening($html, $nodes[$table]), 'rt-delivery-contacts', 'rt-personal-logo-table')],
        ]);
        $header = '<tr data-rt-personal-header="1">'
            .self::replaceClass(self::opening($html, $nodes[$brand]), 'rt-ledger-brand', 'rt-phl').$headerPerson.'</th>'
            .self::replaceClass(self::opening($html, $nodes[$contacts]), 'rt-ledger-contacts', 'rt-phr').$logoTable.'</th></tr>';
        $companyTable = self::opening($html, $nodes[$table]).'<tbody>'.$companyRow.'</tbody></table>';
        $output = self::replace($html, [
            [$nodes[$wrapper]['start'], $nodes[$wrapper]['end'], self::IDENTITY_SLOT],
            [$nodes[$direct]['start'], $nodes[$direct]['openEnd'], self::padding(self::opening($html, $nodes[$direct]), 'padding:0;', 'padding:14px 0 0!important;')],
            [$nodes[$table]['start'], $nodes[$table]['end'], $companyTable],
            [$nodes[$outerRow]['start'], $nodes[$outerRow]['openEnd'], $header.self::attribute(self::opening($html, $nodes[$outerRow]), 'data-rt-personal-contacts', '1')],
            [$nodes[$ledger]['start'], $nodes[$ledger]['openEnd'], self::addClass(str_replace(self::ATTRIBUTE.'="'.self::LEGACY_MARKER.'"', self::ATTRIBUTE.'="'.self::MARKER.'"', self::opening($html, $nodes[$ledger])), self::ALIGNMENT_CLASS)],
            [$columns[0], $columns[1], ''],
        ]);
        $output = self::alignmentMirrors($output, false);
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $output, $styles);
        if (array_sum(array_map('strlen', $styles[1])) >= OutlookSignatureInlineStyle::MAX_CSS_BYTES
            || intdiv(strlen(mb_convert_encoding($output, 'UTF-16LE', 'UTF-8')), 2) > 30000) {
            throw new RuntimeException('Die ausgerichtete persoenliche Signatur ueberschreitet das unveraenderte native HTML-/CSS-Budget.');
        }
        if (self::unalign($output) !== $html) {
            self::fail();
        }

        return $output;
    }

    /** Exact inverse to cached v1, followed by its unchanged ledger inverse. */
    private static function unalign(string $html): string
    {
        $nodes = self::nodes($html);
        $ledger = self::oneClass($nodes, 'rt-sign-ledger', 'table');
        $brand = self::oneClass($nodes, 'rt-ledger-brand', 'th');
        $contacts = self::oneClass($nodes, 'rt-ledger-contacts', 'th');
        $leftHeader = self::oneClass($nodes, 'rt-phl', 'th');
        $rightHeader = self::oneClass($nodes, 'rt-phr', 'th');
        $wrapper = self::oneClass($nodes, 'rt-pnw', 'div');
        $logoTable = self::oneClass($nodes, 'rt-personal-logo-table', 'table');
        $table = self::oneClass($nodes, 'rt-delivery-contacts', 'table');
        $direct = self::oneClass($nodes, 'rt-ledger-direct', 'td');
        $rows = self::rows($nodes, $ledger);
        $companyRows = self::rows($nodes, $table);
        $markers = array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][self::ATTRIBUTE])));
        if ($markers !== [$ledger] || ($nodes[$ledger]['attrs'][self::ATTRIBUTE] ?? '') !== self::MARKER
            || array_keys(array_filter($nodes, static fn (array $node): bool => self::hasClass($node, self::ALIGNMENT_CLASS))) !== [$ledger]
            || count($rows) !== 2 || $nodes[$rows[0]]['children'] !== [$leftHeader, $rightHeader]
            || $nodes[$rows[1]]['children'] !== [$brand, $contacts]
            || $nodes[$leftHeader]['children'] !== [$wrapper] || $nodes[$rightHeader]['children'] !== [$logoTable]
            || $nodes[$contacts]['children'] !== [$table] || count($companyRows) !== 1
            || self::opening($html, $nodes[$rows[0]]) !== '<tr data-rt-personal-header="1">'
            || ($nodes[$rows[1]]['attrs']['data-rt-personal-contacts'] ?? '') !== '1'
            || substr_count($html, self::IDENTITY_SLOT) !== 1 || substr_count($html, self::COMPANY_SLOT) !== 1
            || ! str_contains(self::inner($html, $nodes[$brand]), self::IDENTITY_SLOT)
            || ! str_contains(self::inner($html, $nodes[$logoTable]), self::COMPANY_SLOT)
            || self::whole($html, $nodes[$rows[0]]) !== '<tr data-rt-personal-header="1">'.self::whole($html, $nodes[$leftHeader]).self::whole($html, $nodes[$rightHeader]).'</tr>'
            || self::inner($html, $nodes[$leftHeader]) !== self::whole($html, $nodes[$wrapper])
            || self::inner($html, $nodes[$rightHeader]) !== self::whole($html, $nodes[$logoTable])) {
            self::fail();
        }
        foreach (['data-rt-personal-header' => $rows[0], 'data-rt-personal-contacts' => $rows[1]] as $attribute => $target) {
            if (array_keys(array_filter($nodes, static fn (array $node): bool => isset($node['attrs'][$attribute]))) !== [$target]) {
                self::fail();
            }
        }
        foreach ([$leftHeader => [$brand, 'rt-ledger-brand', 'rt-phl'], $rightHeader => [$contacts, 'rt-ledger-contacts', 'rt-phr']] as $header => [$original, $from, $to]) {
            if (self::opening($html, $nodes[$header]) !== self::replaceClass(self::opening($html, $nodes[$original]), $from, $to)) {
                self::fail();
            }
        }
        $company = self::cell($nodes, $companyRows[0], 'rt-ledger-company');
        $companyBytes = self::whole($html, $nodes[$companyRows[0]]);
        $tableOpen = self::replaceClass(self::opening($html, $nodes[$logoTable]), 'rt-personal-logo-table', 'rt-delivery-contacts');
        if (self::whole($html, $nodes[$table]) !== $tableOpen.'<tbody>'.$companyBytes.'</tbody></table>') {
            self::fail();
        }
        $personBytes = self::whole($html, $nodes[$wrapper]);
        $originalPersonOpen = self::padding(self::removeClass(self::opening($html, $nodes[$wrapper]), 'rt-pnw'), 'padding-top:0;', 'padding-top:13px;');
        $personBytes = $originalPersonOpen.substr($personBytes, $nodes[$wrapper]['openEnd'] - $nodes[$wrapper]['start']);
        $brandBytes = str_replace(self::IDENTITY_SLOT, $personBytes, self::inner($html, $nodes[$brand]));
        $originalLogoTable = self::replace(self::whole($html, $nodes[$logoTable]), [
            [0, $nodes[$logoTable]['openEnd'] - $nodes[$logoTable]['start'], $tableOpen],
        ]);
        $originalLogoTable = str_replace(self::COMPANY_SLOT, $companyBytes, $originalLogoTable);
        $rowOpen = self::opening($html, $nodes[$rows[1]]);
        if (substr_count($rowOpen, ' data-rt-personal-contacts="1"') !== 1 || isset($nodes[$company]['attrs']['data-rt-personal-header'])) {
            self::fail();
        }
        $output = self::replace($html, [
            [$nodes[$brand]['openEnd'], $nodes[$brand]['close'], $brandBytes],
            [$nodes[$table]['start'], $nodes[$table]['end'], $originalLogoTable],
            [$nodes[$rows[0]]['start'], $nodes[$rows[0]]['end'], ''],
            [$nodes[$rows[1]]['start'], $nodes[$rows[1]]['openEnd'], str_replace(' data-rt-personal-contacts="1"', '', $rowOpen)],
            [$nodes[$ledger]['start'], $nodes[$ledger]['openEnd'], self::removeClass(str_replace(self::ATTRIBUTE.'="'.self::MARKER.'"', self::ATTRIBUTE.'="'.self::LEGACY_MARKER.'"', self::opening($html, $nodes[$ledger])), self::ALIGNMENT_CLASS)],
        ]);
        // The direct cell was inside the replaced brand inner range: inverse
        // it on the reconstructed offsets, never as an overlapping edit.
        $restoredNodes = self::nodes($output);
        $direct = self::oneClass($restoredNodes, 'rt-ledger-direct', 'td');
        $root = self::oneClass($restoredNodes, 'rt-outlook-signature', 'div');
        // Only these two generated v2 spellings are reversible: the cached
        // plain value and the new inline priority over retained padding:0.
        $directPadding = str_contains($restoredNodes[$direct]['attrs']['style'] ?? '', 'padding:14px 0 0!important;')
            ? 'padding:14px 0 0!important;'
            : 'padding:14px 0 0;';
        $output = self::replace($output, [
            [$restoredNodes[$direct]['start'], $restoredNodes[$direct]['openEnd'], self::padding(self::opening($output, $restoredNodes[$direct]), $directPadding, 'padding:0;')],
            [$restoredNodes[$root]['start'], $restoredNodes[$root]['start'], self::columnStyle($restoredNodes)],
        ]);
        $output = self::alignmentMirrors($output, true);
        self::restoreLegacy($output);

        return $output;
    }

    /** Critical geometry lives in retained generated STYLEs as well as inline. */
    private static function alignmentMirrors(string $html, bool $inverse): string
    {
        $nodes = self::nodes($html);
        $scope = self::scope($nodes);
        $tag = '(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*';
        preg_match_all('~<style\b'.$tag.'>.*?</style\s*>~is', $html, $styles, PREG_OFFSET_CAPTURE);
        $ranges = [];
        foreach (['data-rt-outlook-signature-css', OutlookSignatureInlineStyle::ATTRIBUTE] as $attribute) {
            $matches = array_values(array_filter($styles[0], static fn (array $style): bool => isset(self::attributes(substr($style[0], 0, strpos($style[0], '>') + 1))[$attribute])));
            if (count($matches) !== 1 || self::attributes(substr($matches[0][0], 0, strpos($matches[0][0], '>') + 1))[$attribute] !== '1') {
                self::fail();
            }
            [$style, $offset] = $matches[0];
            if ($attribute === OutlookSignatureInlineStyle::ATTRIBUTE) {
                $rules = self::alignmentCss($scope);
                if ($inverse) {
                    if (substr_count($style, $rules) !== 1 || ! str_ends_with($style, $rules.'</style>')) {
                        self::fail();
                    }
                    $changed = substr($style, 0, -strlen($rules.'</style>')).'</style>';
                } else {
                    if (! str_ends_with($style, '</style>') || str_contains($style, '.rt-phl') || str_contains($style, '.rt-phr') || str_contains($style, '.rt-pnw')) {
                        self::fail();
                    }
                    $changed = substr($style, 0, -8).$rules.'</style>';
                }
            } else {
                // The shared canonical rts scope is also present on old quoted
                // signatures. Preserve its rules; only new scoped copies may
                // change geometry inside the uniquely marked v2 ledger.
                foreach (['brand' => '32', 'contacts' => '68'] as $class => $original) {
                    $prefix = '.'.$scope.' .rt-ledger-'.$class.'.rt-delivery-wide-column{width:';
                    if (substr_count($style, $prefix.$original.'%!important;') !== 1) {
                        self::fail();
                    }
                }
                $changed = $style;
            }
            $ranges[] = [$offset, $offset + strlen($style), $changed];
        }

        return self::replace($html, $ranges);
    }

    private static function alignmentCss(string $scope): string
    {
        // No current rule may match a legacy quoted ledger sharing rts/oi.
        // Four classes outrank the later retained three-class column rules.
        $prefix = '.'.$scope.' .'.self::ALIGNMENT_CLASS.' ';
        $left = $prefix.'.rt-phl.rt-delivery-wide-column';
        $right = $prefix.'.rt-phr.rt-delivery-wide-column';

        return $left.'{width:48%!important;}'.$right.'{width:52%!important;}'
            .$prefix.'.rt-ledger-brand.rt-delivery-wide-column{width:48%!important;}'
            .$prefix.'.rt-ledger-contacts.rt-delivery-wide-column{width:52%!important;}'
            .$prefix.'.rt-pnw{padding-top:0!important;}'
            .$prefix.'.rt-ledger-direct.rt-delivery-group-cell{padding:14px 0 0!important;}'
            .'@media(max-width:860px){.'.$scope.' .rt-sign-ledger.'.self::ALIGNMENT_CLASS.' .rt-delivery-wide-column{display:block!important;width:100%!important;padding:0!important;border:0!important;}}';
    }

    private static function scope(array $nodes): string
    {
        $root = self::oneClass($nodes, 'rt-outlook-signature', 'div');
        if (preg_match('/(?:^|\s)(rts[0-9a-f]{10})(?:\s|$)/', $nodes[$root]['attrs']['class'] ?? '', $scope) !== 1) {
            self::fail();
        }

        return $scope[1];
    }

    private static function padding(string $tag, string $old, string $new): string
    {
        $count = 0;
        $output = preg_replace_callback('~\bstyle="([^"]*)"~', static function (array $match) use ($old, $new, &$count): string {
            if (substr_count($match[1], $old) !== 1
                || preg_match_all('~(?:^|;)\s*padding(?:-(?:top|right|bottom|left))?\s*:~i', $match[1]) !== 1) {
                self::fail();
            }
            $count++;

            return 'style="'.str_replace($old, $new, $match[1]).'"';
        }, $tag);
        if ($count !== 1) {
            self::fail();
        }

        return $output;
    }

    private static function addClass(string $tag, string $class): string
    {
        if (in_array($class, preg_split('/\s+/', self::attributes($tag)['class'] ?? ''), true)) {
            self::fail();
        }
        if (! isset(self::attributes($tag)['class'])) {
            return self::attribute($tag, 'class', $class);
        }

        return preg_replace_callback('~\bclass="([^"]*)"~', static fn (array $match): string => 'class="'.$match[1].' '.$class.'"', $tag, 1);
    }

    private static function removeClass(string $tag, string $class): string
    {
        $classes = preg_split('/\s+/', self::attributes($tag)['class'] ?? '');
        if (count(array_keys($classes, $class, true)) !== 1) {
            self::fail();
        }

        return preg_replace_callback('~\sclass="([^"]*)"~', static function (array $match) use ($class): string {
            if ($match[1] === $class) {
                return '';
            }
            if (! str_ends_with($match[1], ' '.$class)) {
                self::fail();
            }

            return ' class="'.substr($match[1], 0, -strlen(' '.$class)).'"';
        }, $tag, 1);
    }

    /** @return array{int,int,int,int,list<int>} */
    private static function ledger(array $nodes, bool $balanced = false): array
    {
        $root = self::oneClass($nodes, 'rt-outlook-signature', 'div');
        $ledger = self::oneClass($nodes, 'rt-sign-ledger', 'table');
        $brand = self::oneClass($nodes, 'rt-ledger-brand', 'th');
        $contacts = self::oneClass($nodes, 'rt-ledger-contacts', 'th');
        $rows = self::rows($nodes, $ledger);
        if ($nodes[$root]['parent'] !== null || ! self::within($nodes, $ledger, $root) || count($rows) !== 1
            || $nodes[$rows[0]]['children'] !== [$brand, $contacts]) {
            self::fail();
        }
        foreach ([$brand => $balanced ? '48%' : '32%', $contacts => $balanced ? '52%' : '68%'] as $cell => $width) {
            if (($nodes[$cell]['attrs']['width'] ?? '') !== $width
                || ($nodes[$cell]['attrs']['role'] ?? '') !== 'presentation'
                || ! self::hasClass($nodes[$cell], 'rt-delivery-wide-column')) {
                throw new RuntimeException('Die persoenliche native Layoutspalte besitzt fremde Kopfzellattribute.');
            }
        }
        if (count($nodes[$contacts]['children']) !== 1 || $nodes[$nodes[$contacts]['children'][0]]['tag'] !== 'table') {
            self::fail();
        }
        $table = $nodes[$contacts]['children'][0];
        $contactRows = self::rows($nodes, $table);
        if (($nodes[$table]['attrs']['width'] ?? '') !== '100%' || ! self::hasClass($nodes[$table], 'rt-delivery-contacts')
            || count($contactRows) !== 2) {
            self::fail();
        }

        return [$ledger, $brand, $contacts, $table, $contactRows];
    }

    /** Existing styles are opaque; only this exact removable output block is new. */
    private static function columnStyle(array $nodes): string
    {
        $root = self::oneClass($nodes, 'rt-outlook-signature', 'div');
        if (preg_match('/(?:^|\s)(rts[0-9a-f]{10})(?:\s|$)/', $nodes[$root]['attrs']['class'] ?? '', $scope) !== 1) {
            self::fail();
        }
        $left = '.'.$scope[1].' .rt-ledger-brand.rt-delivery-wide-column';
        $right = '.'.$scope[1].' .rt-ledger-contacts.rt-delivery-wide-column';

        return '<style '.self::STYLE_ATTRIBUTE.'="1">'
            .$left.'{width:48%!important;}'.$right.'{width:52%!important;}'
            .'@media(max-width:860px){'.$left.','.$right.'{width:100%!important;}}'
            .'</style>';
    }

    /** @return ?array{int,int} */
    private static function columnStyleRange(string $html, array $nodes): ?array
    {
        if (! str_contains($html, self::STYLE_ATTRIBUTE)) {
            return null; // Already cached 32/68 personal output remains reversible.
        }
        $tag = '(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*';
        preg_match_all('~<style\b'.$tag.'>.*?</style\s*>~is', $html, $styles, PREG_OFFSET_CAPTURE);
        $matches = array_values(array_filter($styles[0], static fn (array $style): bool => str_contains($style[0], self::STYLE_ATTRIBUTE)));
        if (count($matches) !== 1 || $matches[0][0] !== self::columnStyle($nodes)) {
            self::fail();
        }

        return [$matches[0][1], $matches[0][1] + strlen($matches[0][0])];
    }

    /** Change one generated percentage only, with an exact inverse for Mobile. */
    private static function column(string $tag, string $old, string $new): string
    {
        if ((self::attributes($tag)['width'] ?? '') !== $old) {
            self::fail();
        }
        $tag = str_replace(' width="'.$old.'"', ' width="'.$new.'"', $tag, $widthCount);
        $styleCount = 0;
        $tag = preg_replace_callback('~\bstyle="([^"]*)"~', static function (array $match) use ($old, $new, &$styleCount): string {
            $style = preg_replace('~(^|;)width:'.preg_quote($old, '~').';~', '$1width:'.$new.';', $match[1], -1, $styleCount);

            return 'style="'.$style.'"';
        }, $tag);
        if ($widthCount !== 1 || $styleCount !== 1) {
            self::fail();
        }

        return $tag;
    }

    private static function cell(array $nodes, int $row, string $class): int
    {
        $cell = self::oneClass($nodes, $class, 'td');
        if ($nodes[$row]['children'] !== [$cell] || ($nodes[$cell]['attrs']['width'] ?? '') !== '100%') {
            self::fail();
        }

        return $cell;
    }

    /** A strict offset tree; comments and style blocks are opaque and never serialized. */
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
            $parent = $stack === [] ? null : $stack[array_key_last($stack)];
            $index = count($nodes);
            $end = $offset + strlen($token);
            $nodes[] = ['tag' => $element, 'attrs' => self::attributes($token), 'parent' => $parent, 'children' => [], 'start' => $offset, 'openEnd' => $end, 'close' => $void ? $end : null, 'end' => $void ? $end : null];
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

    private static function attributes(string $tag): array
    {
        preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $tag, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $result = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (array_key_exists($name, $result)) {
                self::fail();
            }
            $result[$name] = CssSemantic::decodeHtmlEntitiesOnce($match[2] ?? $match[3] ?? $match[4] ?? '');
        }

        return $result;
    }

    private static function oneClass(array $nodes, string $class, string $tag): int
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

    private static function rows(array $nodes, int $table): array
    {
        $children = $nodes[$table]['children'];
        if (count($children) === 1 && $nodes[$children[0]]['tag'] === 'tbody') {
            $children = $nodes[$children[0]]['children'];
        }
        foreach ($children as $row) {
            if ($nodes[$row]['tag'] !== 'tr') {
                self::fail();
            }
        }

        return $children;
    }

    private static function within(array $nodes, int $child, int $parent): bool
    {
        for ($ancestor = $nodes[$child]['parent']; $ancestor !== null; $ancestor = $nodes[$ancestor]['parent']) {
            if ($ancestor === $parent) {
                return true;
            }
        }

        return false;
    }

    private static function opening(string $html, array $node): string
    {
        return substr($html, $node['start'], $node['openEnd'] - $node['start']);
    }

    private static function whole(string $html, array $node): string
    {
        return substr($html, $node['start'], $node['end'] - $node['start']);
    }

    private static function inner(string $html, array $node): string
    {
        return substr($html, $node['openEnd'], $node['close'] - $node['openEnd']);
    }

    private static function replaceClass(string $tag, string $old, string $new): string
    {
        $count = 0;
        $result = preg_replace_callback('~\bclass="([^"]*)"~', static function (array $match) use ($old, $new, &$count): string {
            $classes = preg_split('/\s+/', trim($match[1]));
            if (count(array_keys($classes, $old, true)) !== 1) {
                self::fail();
            }
            $count++;

            return 'class="'.implode(' ', array_map(static fn (string $class): string => $class === $old ? $new : $class, $classes)).'"';
        }, $tag);
        if ($count !== 1) {
            self::fail();
        }

        return $result;
    }

    private static function attribute(string $tag, string $name, string $value): string
    {
        if (isset(self::attributes($tag)[$name])) {
            self::fail();
        }

        return substr($tag, 0, -1).' '.$name.'="'.$value.'">';
    }

    private static function replace(string $html, array $ranges): string
    {
        usort($ranges, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
        foreach ($ranges as [$start, $end, $replacement]) {
            $html = substr($html, 0, $start).$replacement.substr($html, $end);
        }

        return $html;
    }

    private static function fail(): never
    {
        throw new RuntimeException('Die persoenliche native Signatur besitzt keinen eindeutigen freigegebenen Spaltenvertrag.');
    }
}
