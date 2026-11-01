<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Verified linked V16 output only; historical layouts and published sources stay immutable. */
final class OutlookCompactReferencePresentation
{
    public const FLOW = 'outlook-desktop-flow-v17';

    public const REVISION = 'outlook-compact-reference-v1';

    private const TOKENS = '~<!doctype\b[^>]*>|<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    private const CONTACT_SPACE = ';padding-bottom:3px!important;';

    private const TRAIN_OFFSET = ';margin-top:clamp(-8px,calc((500px - 100%)/10),0px);';

    // Additive immutable assets, never replace an already published hash.
    private const MEDIA = [
        '/m/097e33e85e.png' => '/m/ef78563be5.png',
        '/m/741a9d43f5.png' => '/m/ad1ce232f6.png',
        '/m/8f6e4932f8.png' => '/m/237a6ef4b9.png',
        '/m/cfa8c68211.png' => '/m/74e44dae98.png',
        '/m/09d2f890b7.png' => '/m/aee5605787.png',
        '/m/464054a84f.png' => '/m/4a55285090.png',
        '/m/36ecdeda31.png' => '/m/19125261c2.png',
        '/m/a3564d9bc8.png' => '/m/1111670ac8.png',
        '/mail-assets/zug-dampf-outlook-brand-v1-light.gif' => '/mail-assets/zug-dampf-outlook-brand-v2-light.gif',
        '/mail-assets/zug-dampf-outlook-brand-v1-dark.gif' => '/mail-assets/zug-dampf-outlook-brand-v2-dark.gif',
        '/mail-assets/zug-dampf-outlook-brand-v1-light-mirrored.gif' => '/mail-assets/zug-dampf-outlook-brand-v2-light-mirrored.gif',
        '/mail-assets/zug-dampf-outlook-brand-v1-dark-mirrored.gif' => '/mail-assets/zug-dampf-outlook-brand-v2-dark-mirrored.gif',
    ];

    private const NATIVE_MEDIA = [
        'contact-location.png' => 'contact-location-brand-v2.png',
        'contact-phone.png' => 'contact-phone-brand-v2.png',
        'contact-mobile.png' => 'contact-mobile-brand-v2.png',
        'contact-email.png' => 'contact-email-brand-v2.png',
        'contact-web.png' => 'contact-web-brand-v2.png',
        'outlook-dispo-card-caption-v1.png' => 'outlook-dispo-card-caption-alpha-v3.png',
        'outlook-hotline-mail-v1.png' => 'outlook-dispo-mail-kraeftig-v3.png',
        'outlook-hotline-phone-v1.png' => 'outlook-dispo-telefon-kraeftig-v3.png',
    ];

    public static function document(string $html): string
    {
        if (str_contains($html, 'data-rt-desktop-flow="'.self::FLOW.'"')) {
            $previous = self::canonicalizeToV16($html);
            if (self::project($previous, true) !== $html) {
                self::fail();
            }

            return $html;
        }
        if (! str_contains($html, 'data-rt-desktop-flow="'.OutlookV30ReferencePresentation::FLOW.'"')) {
            return $html;
        }
        // The inverse below also proves that no arbitrary authored layout is projected.
        self::assertV16($html);
        $result = self::project($html, true);
        if (self::canonicalizeToV16($result) !== $html) {
            self::fail();
        }

        return $result;
    }

    public static function canonicalizeToV16(string $html): string
    {
        $result = self::project($html, false);
        self::assertV16($result);

        return $result;
    }

    private static function project(string $html, bool $forward): string
    {
        if (! $forward) {
            $html = OutlookCompactMobilePresentation::canonicalize($html);
        }
        $family = $forward ? 16 : 17;
        $nodes = self::nodes($html);
        $root = self::one($nodes, static fn (array $n): bool => ($n['attrs']['data-rt-desktop-flow'] ?? '') === 'outlook-desktop-flow-v'.$family);
        $system = self::has($nodes[$root], 'rt-system-columns');
        for ($ancestor = $nodes[$root]['parent']; is_int($ancestor); $ancestor = $nodes[$ancestor]['parent']) {
            if ($nodes[$ancestor]['tag'] === 'blockquote') {
                self::fail();
            }
        }
        $main = self::oneClass($nodes, 'rt-v'.$family.'-columns-cell');
        $legal = self::oneClass($nodes, 'rt-v'.$family.'-legal-cell');
        $legalTable = self::oneClass($nodes, 'rt-brand-legal-table');
        $host = $nodes[$legalTable]['parent'];
        $train = self::oneClass($nodes, 'rt-v'.$family.'-train');
        $changes = [];
        $set = static function (int $i, array $a) use (&$changes): void {
            $changes[$i] = array_replace($changes[$i] ?? [], $a);
        };
        $mapStyle = static function (int $i, string $old, string $new) use ($nodes, $forward, $set): void {
            $s = $nodes[$i]['attrs']['style'] ?? '';
            $set($i, ['style' => self::exactReplace($s, $forward ? $old : $new, $forward ? $new : $old)]);
        };
        $rootClass = $nodes[$root]['attrs']['class'] ?? '';
        $suffix = ' rt-v17-compact';
        $raised = self::raised($html, $nodes, $root);
        $offset = $raised ? self::TRAIN_OFFSET : ';margin-top:0;';
        if (! $forward && (! str_ends_with($rootClass, ($raised ? ' rt-v17-raised' : '').$suffix) || self::has($nodes[$root], 'rt-v17-raised') !== $raised)) {
            self::fail();
        }
        $set($root, ['class' => $forward ? $rootClass.($raised ? ' rt-v17-raised' : '').$suffix : substr($rootClass, 0, -strlen($suffix.($raised ? ' rt-v17-raised' : '')))]);
        $mapStyle($legal, 'padding:12px 40px 16px;', 'padding:8px 40px 8px;');
        if (! is_int($host)) {
            self::fail();
        }
        $mapStyle($host, '#f3f5f7', '#dde1e6');
        if (($nodes[$host]['attrs']['bgcolor'] ?? '') === ($forward ? '#f3f5f7' : '#dde1e6')) {
            $set($host, ['bgcolor' => $forward ? '#dde1e6' : '#f3f5f7']);
        }
        $ts = $nodes[$train]['attrs']['style'] ?? '';
        if (! $forward && ! str_ends_with($ts, $offset)) {
            self::fail();
        }
        $fixed = $system ? '' : ';table-layout:fixed!important;';
        if (! $forward && ! str_ends_with($ts, $fixed.$offset)) {
            self::fail();
        }
        $set($train, ['style' => $forward ? $ts.$fixed.$offset : substr($ts, 0, -strlen($fixed.$offset))]);
        foreach (['identity' => ['220px', 'clamp(220px,calc((500px - 100%)*1000),100%)'], 'direct' => ['clamp(248px,calc(100% - 220px),280px)', 'min(280px,52%)'], 'company' => ['230px', $system ? '210px' : 'min(210px,48%)'], 'dispatch' => ['227px', '247px']] as $role => [$before,$after]) {
            if ($system && $role === 'direct') {
                continue;
            }
            $index = self::oneClass($nodes, 'rt-v'.$family.'-'.$role);
            $mapStyle($index, 'width:'.$before.';', 'width:'.$after.';');
            if (in_array($role, ['company', 'dispatch'], true)) {
                $set($index, ['width' => $forward ? ($role === 'company' ? '210' : '247') : ($role === 'company' ? '230' : '227')]);
            }
        }
        $actions = self::oneClass($nodes, 'rt-v'.$family.'-dispatch-actions');
        $cells = array_keys(array_filter($nodes, static fn (array $n, int $i): bool => $n['tag'] === 'td' && self::within($nodes, $i, $actions), ARRAY_FILTER_USE_BOTH));
        if (count($cells) !== 3) {
            self::fail();
        }
        $first = $cells[0];
        $mailLinks = array_values(array_filter(self::direct($nodes, $first), static fn (int $i): bool => $nodes[$i]['tag'] === 'a' && ! self::has($nodes[$i], 'rt-v17-dispatch-whatsapp')));
        if (count($mailLinks) !== 1) {
            self::fail();
        }
        $mailLink = $mailLinks[0];
        $mailStyle = $nodes[$mailLink]['attrs']['style'] ?? '';
        $mailSuffix = ';display:inline-block;vertical-align:middle;';
        if (! $forward && ! str_ends_with($mailStyle, $mailSuffix)) {
            self::fail();
        }
        $set($mailLink, ['style' => $forward ? $mailStyle.$mailSuffix : substr($mailStyle, 0, -strlen($mailSuffix))]);
        $wa = self::whatsapp(self::fillUrl($html));
        $extraEdits = [];
        if ($forward) {
            if (self::findClass($nodes, 'rt-v17-dispatch-whatsapp') !== []) {
                self::fail();
            }
            $extraEdits[] = [$nodes[$first]['close'], 0, $wa];
        } else {
            $waIndex = self::oneClass($nodes, 'rt-v17-dispatch-whatsapp');
            if ($nodes[$waIndex]['parent'] !== $first || substr($html, $nodes[$waIndex]['start'], $nodes[$waIndex]['end'] - $nodes[$waIndex]['start']) !== $wa) {
                self::fail();
            }
            $extraEdits[] = [$nodes[$waIndex]['start'], $nodes[$waIndex]['end'] - $nodes[$waIndex]['start'], ''];
        }
        foreach ($nodes as $i => $n) {
            if (! self::within($nodes, $i, $root) && ! self::within($nodes, $i, $legal)) {
                continue;
            }
            if (! $forward && self::within($nodes, $i, $waIndex)) {
                continue;
            }
            if ($n['tag'] === 'td' && is_int($n['parent']) && $nodes[$n['parent']]['tag'] === 'tr') {
                $siblings = self::direct($nodes, $n['parent']);
                if (array_filter($siblings, static fn (int $j): bool => self::has($nodes[$j], 'rt-contact-icon')) !== []) {
                    $s = $n['attrs']['style'] ?? '';
                    if (! $forward && ! str_ends_with($s, self::CONTACT_SPACE)) {
                        self::fail();
                    }
                    $set($i, ['style' => $forward ? $s.self::CONTACT_SPACE : substr($s, 0, -strlen(self::CONTACT_SPACE))]);
                }
            }
            if ($system && $n['tag'] === 'p' && isset($n['attrs']['style']) && str_contains($n['attrs']['style'], $forward ? 'color:#e4002b;' : 'color:#e90032;')) {
                $identity = self::oneClass($nodes, 'rt-v'.$family.'-identity-cell');
                if (self::within($nodes, $i, $identity)) {
                    $mapStyle($i, 'color:#e4002b;', 'color:#e90032;');
                }
            }
            if (isset($n['attrs']['style']) && (($n['tag'] === 'div' && $n['parent'] === $legal) || ($n['tag'] === 'span' && is_int($n['parent']) && $nodes[$n['parent']]['tag'] === 'div' && $nodes[$n['parent']]['parent'] === $legal && ! self::has($n, 'rt-office-metadata')))) {
                $before = $n['tag'] === 'span' ? 'font-size:10.5px;line-height:16px;color:#5c6875;' : 'margin:0;line-height:16px;';
                $after = $n['tag'] === 'span' ? 'font-size:10.5px;line-height:15px;color:#47515e;' : 'margin:0;line-height:15px;';
                $mapStyle($i, $before, $after);
            }
            if (self::has($n, 'rt-v'.$family.'-dispatch-block') || self::has($n, 'rt-v'.$family.'-dispatch-block-cell')) {
                $s = $n['attrs']['style'] ?? '';
                $fill = self::fillUrl($html);
                $background = 'background-image:url('.$fill.');background-repeat:repeat;';
                if ($forward) {
                    if (($n['attrs']['background'] ?? '') !== $fill) {
                        self::fail();
                    }
                    $set($i, ['style' => self::exactReplace($s, $background, ''), 'background' => null]);
                } else {
                    if (isset($n['attrs']['background']) || str_contains($s, 'background-image')) {
                        self::fail();
                    }
                    $set($i, ['style' => self::exactReplace($s, 'background-color:#e90032;', 'background-color:#e90032;'.$background), 'background' => $fill]);
                }
            }
            if (self::has($n, 'rt-v'.$family.'-dispatch-block')) {
                $current = $changes[$i]['style'] ?? $n['attrs']['style'] ?? '';
                $set($i, ['style' => self::exactReplace($current, $forward ? 'max-width:227px;' : 'max-width:247px;', $forward ? 'max-width:247px;' : 'max-width:227px;')]);
            }
            if (self::has($n, 'rt-v'.$family.'-dispatch-block-cell')) {
                $current = $changes[$i]['style'] ?? $n['attrs']['style'] ?? '';
                $set($i, ['style' => self::exactReplace($current, $forward ? 'padding:8px 14px 5px;' : 'padding:8px 16px 5px;', $forward ? 'padding:8px 16px 5px;' : 'padding:8px 14px 5px;')]);
            }
            if (self::has($n, 'rt-v'.$family.'-dispatch-caption')) {
                if (($n['attrs']['width'] ?? '') !== ($forward ? '199' : '215') || ($n['attrs']['height'] ?? '') !== ($forward ? '38' : '50')) {
                    self::fail();
                }
                $set($i, ['width' => $forward ? '215' : '199', 'height' => $forward ? '50' : '38']);
                $mapStyle($i, 'width:199px;', 'width:215px;');
            }
            if (self::has($n, 'rt-v'.$family.'-dispatch-actions')) {
                $mapStyle($i, 'border-top:1px solid #ff6b8b;', 'border-top:1px solid #ffffff;');
            }
            $actions = self::findClass($nodes, 'rt-v'.$family.'-dispatch-actions');
            if (count($actions) !== 1) {
                self::fail();
            }
            if (self::within($nodes, $i, $actions[0])) {
                if ($n['tag'] === 'img') {
                    $size = $forward ? '25' : '24';
                    if (($n['attrs']['width'] ?? '') !== $size || ($n['attrs']['height'] ?? '') !== $size) {
                        self::fail();
                    }
                    $newSize = $forward ? '24' : '25';
                    $set($i, ['width' => $newSize, 'height' => $newSize, 'style' => self::exactReplace(self::exactReplace($n['attrs']['style'] ?? '', 'width:'.$size.'px;', 'width:'.$newSize.'px;'), 'height:'.$size.'px;', 'height:'.$newSize.'px;')]);
                }
                if ($n['tag'] === 'td') {
                    $cells = self::direct($nodes, $n['parent']);
                    $position = array_search($i, $cells, true);
                    $old = ['width:39px;padding:10px 14px 10px 0;vertical-align:middle;', 'width:25px;padding:10px 0;vertical-align:middle;', 'padding:10px 0 10px 5px;vertical-align:middle;'];
                    $new = ['width:100%;padding:8px 0;vertical-align:middle;white-space:nowrap;', 'width:32px;padding:8px 8px 8px 0;vertical-align:middle;', 'padding:8px 0;vertical-align:middle;text-align:right;white-space:nowrap;'];
                    if (! is_int($position) || $position > 2) {
                        self::fail();
                    }
                    $mapStyle($i, $old[$position], $new[$position]);
                    if ($position < 2) {
                        $set($i, ['width' => $forward ? ($position === 0 ? '100%' : '32') : ($position === 0 ? '39' : '25')]);
                    }
                }
                if ($n['tag'] === 'span') {
                    $value = self::exactReplace($n['attrs']['style'] ?? '', $forward ? 'line-height:25px;' : 'line-height:24px;', $forward ? 'line-height:24px;' : 'line-height:25px;');
                    $set($i, ['style' => self::exactReplace($value, $forward ? 'font-size:12px;' : 'font-size:13px;', $forward ? 'font-size:13px;' : 'font-size:12px;')]);
                }
            }
            if ($n['tag'] === 'img' && isset($n['attrs']['src'])) {
                $mapped = self::imageSource($n['attrs']['src'], $system, $forward);
                $bound = self::has($n, 'rt-v'.$family.'-train-image') || self::has($n, 'rt-v'.$family.'-dispatch-caption') || self::within($nodes, $i, $actions[0]) || (is_int($n['parent']) && self::has($nodes[$n['parent']], 'rt-contact-icon'));
                if ($bound && $mapped === $n['attrs']['src']) {
                    self::fail();
                }
                $set($i, ['src' => $mapped]);
            }
        }
        $css = self::css($system);
        $edits = $extraEdits;
        if ($forward) {

        } else {
            if (substr_count($html, $css) !== 1) {
                self::fail();
            }
            $edits[] = [strpos($html, $css), strlen($css), ''];
        }
        foreach ($changes as $i => $a) {
            $edits[] = [$nodes[$i]['start'], $nodes[$i]['openEnd'] - $nodes[$i]['start'], ($forward && $i === $root ? $css : '').self::orderedOpening($nodes[$i], $a)];
        }
        $result = self::replace($html, $edits);
        $result = self::family($result, $forward ? 16 : 17, $forward ? 17 : 16);

        return $forward ? OutlookCompactMobilePresentation::document($result) : $result;
    }

    private static function whatsapp(string $fill): string
    {
        $native = str_contains($fill, '/mail-assets/');
        $origin = substr($fill, 0, -strlen($native ? '/mail-assets/outlook-hotline-fill-v1.png' : '/m/3ee5033b5b.png'));
        $source = $origin.($native ? '/mail-assets/outlook-dispo-whatsapp-weiss-v3.png' : '/m/f9d88b899d.png');

        return '<a class="rt-v17-dispatch-whatsapp" href="https://wa.me/491601881848" style="color:#ffffff;text-decoration:none;display:inline-block;vertical-align:middle;margin-left:14px;"><img src="'.$source.'" width="24" height="24" alt="WhatsApp" style="display:block;width:24px;height:24px;border:0;"></a>';
    }

    private static function imageSource(string $source, bool $system, bool $forward): string
    {
        $origin = rtrim((string) config('app.url'), '/');
        if ($system) {
            foreach (self::NATIVE_MEDIA as $old => $new) {
                $original = $origin.'/mail-assets/'.$old.(str_starts_with($old, 'contact-') ? '?v='.filemtime(public_path('mail-assets/'.$old)) : '');
                $replacement = $origin.'/mail-assets/'.$new;
                if ($source === ($forward ? $original : $replacement)) {
                    return $forward ? $replacement : $original;
                }
            }
        }
        foreach (self::MEDIA as $old => $new) {
            if ($source === $origin.($forward ? $old : $new)) {
                return $origin.($forward ? $new : $old);
            }
        }

        return $source;
    }

    /** Only short, single-line ASCII factory labels can use the progressive overlap. */
    private static function raised(string $html, array $nodes, int $root): bool
    {
        if (self::has($nodes[$root], 'rt-system-columns')) {
            return false;
        }
        $name = self::findClass($nodes, 'rt-sign-name');
        $role = self::findClass($nodes, 'rt-personal-role');
        if (count($name) !== 1 || count($role) !== 1) {
            return false;
        }
        $text = static fn (int $i): string => trim(CssSemantic::decodeHtmlEntitiesOnce(strip_tags(substr($html, $nodes[$i]['openEnd'], $nodes[$i]['close'] - $nodes[$i]['openEnd']))));
        if (! self::fits($text($name[0]), 22, 184, true) || ! self::fits(strtoupper($text($role[0])), 10, 174 - (strlen($text($role[0])) * 1.6), true)) {
            return false;
        }
        $direct = self::findClass($nodes, 'rt-v16-direct');
        if ($direct === []) {
            $direct = self::findClass($nodes, 'rt-v17-direct');
        }
        if (count($direct) !== 1) {
            return false;
        }
        $values = array_filter(self::findClass($nodes, 'rt-delivery-contact-value'), static fn (int $i): bool => self::within($nodes, $i, $direct[0]));
        if (count($values) > 2) {
            return false;
        }
        foreach ($values as $i) {
            if (! self::fits($text($i), 13, 222, false)) {
                return false;
            }
        }

        return true;
    }

    private static function fits(string $text, int $size, float $limit, bool $bold): bool
    {
        if ($text === '' || preg_match('/[^\x20-\x7e]/', $text)) {
            return false;
        }
        $advances = $bold ? [301, 367, 474, 586, 586, 889, 722, 238, 367, 367, 432, 586, 367, 367, 367, 390, 586, 586, 586, 586, 586, 586, 586, 586, 586, 586, 367, 367, 586, 586, 586, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 612, 722, 667, 944, 667, 667, 611, 402, 355, 402, 586, 586, 586, 556, 611, 556, 611, 575, 370, 611, 611, 298, 367, 556, 295, 889, 611, 611, 611, 611, 427, 556, 396, 611, 556, 784, 556, 556, 528, 434, 586, 434, 586] : [301, 367, 355, 556, 556, 889, 706, 191, 367, 367, 389, 584, 367, 367, 367, 524, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 367, 367, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 367, 355, 367, 524, 556, 524, 556, 557, 500, 557, 556, 370, 556, 556, 285, 367, 504, 295, 833, 556, 556, 557, 557, 389, 500, 396, 556, 500, 744, 501, 500, 500, 367, 524, 367, 584];
        $width = 0;
        foreach (str_split($text) as $c) {
            $width += $advances[ord($c) - 32] * $size / 1000;
        }

        return $width <= $limit;
    }

    private static function orderedOpening(array $node, array $changes): string
    {
        $opening = self::opening($node, $changes);
        if (isset($changes['background']) && ! isset($node['attrs']['background'])) {
            $attribute = ' background="'.self::escape($changes['background']).'"';
            if (! str_ends_with($opening, $attribute.'>')) {
                self::fail();
            }
            $opening = substr($opening, 0, -strlen($attribute.'>')).'>';
            $opening = self::exactReplace($opening, ' style=', $attribute.' style=');
        }
        if (isset($changes['bgcolor'], $node['ranges']['bgcolor'])) {
            [$offset, $length] = $node['ranges']['bgcolor'];
            $original = substr($node['opening'], $offset, $length);
            if (! str_contains($original, '"') && ! str_contains($original, "'")) {
                $opening = self::exactReplace($opening, ' bgcolor="'.$changes['bgcolor'].'"', ' bgcolor='.$changes['bgcolor']);
            }
        }

        return $opening;
    }

    private static function assertV16(string $html): void
    {
        $old = [
            '/m/464054a84f.png' => '/mail-assets/outlook-dispo-card-caption-v1.png',
            '/m/36ecdeda31.png' => '/mail-assets/outlook-hotline-mail-v1.png',
            '/m/a3564d9bc8.png' => '/mail-assets/outlook-hotline-phone-v1.png',
            '/m/3ee5033b5b.png' => '/mail-assets/outlook-hotline-fill-v1.png',
        ];
        // A finite virtual validation view only; never write it to the message.
        OutlookV30ReferencePresentation::canonicalizeToV15(strtr($html, $old));
    }

    private static function fillUrl(string $html): string
    {
        $nodes = self::nodes($html);
        $captions = array_keys(array_filter($nodes, static fn (array $n): bool => self::has($n, 'rt-v16-dispatch-caption') || self::has($n, 'rt-v17-dispatch-caption')));
        if (count($captions) !== 1) {
            self::fail();
        }
        $src = $nodes[$captions[0]]['attrs']['src'] ?? '';
        if (preg_match('~\A((?:https://[a-z0-9.:-]+)?)/m/[a-f0-9]{10}\.png\z~i', $src, $m)) {
            return $m[1].'/m/3ee5033b5b.png';
        }
        if (preg_match('~\A(https://[a-z0-9.:-]+)/mail-assets/outlook-dispo-card-caption-(?:v1|alpha-v3)\.png\z~i', $src, $m)) {
            return $m[1].'/mail-assets/outlook-hotline-fill-v1.png';
        }
        self::fail();
    }

    private static function css(bool $system): string
    {
        return '<style data-rt-compact-css="1">'
            .'@media (max-width:520px){.v30 .rt-v17-train{margin-top:0!important}.v30 .rt-v17-dispatch{width:100%!important}.v30 .rt-v17-dispatch-cell{padding:14px 0 0!important}.v30 .rt-v17-legal-cell{padding:8px 20px!important}}'
            .'</style>';
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
        throw new RuntimeException('Die kompakte Outlook-Ausgabe besitzt keinen eindeutigen Referenzvertrag.');
    }
}
