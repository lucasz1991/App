<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Reference card and contact order, after the immutable percentage-frame proof. */
final class OutlookReferenceCardPresentation
{
    public const FLOW = 'outlook-desktop-flow-v13';

    public const REVISION = 'outlook-reference-card-presentation-v1';

    private const TOKENS = '~<!doctype\b[^>]*>|<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    private const ASSETS = [
        'outlook-hotline-label-inktop-v1.png' => '37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a',
        'outlook-hotline-mail-outline-v1.png' => 'dcf209bd170e4581760ec5c8183830d445cd4fc2e46277bc5ff265bacbc85e9d',
        'outlook-hotline-phone-outline-v1.png' => 'e24cb8b0ac6770bd9b2a453c50b7c7157531f5929e96713f9aa03d42647f3ea1',
    ];

    private const REMOVED = [
        '37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a',
        'dcf209bd170e4581760ec5c8183830d445cd4fc2e46277bc5ff265bacbc85e9d',
        'e24cb8b0ac6770bd9b2a453c50b7c7157531f5929e96713f9aa03d42647f3ea1',
        '741a9d43f5105e22e383167d582bc3a0dac1e25fde14b946e3b7c693e1d2fc3a',
        'cfa8c6821180635369af1759ab5719f694aaa663e9fdefdc2dafcceac439f2dc',
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
        preg_match_all('~cid:([a-z0-9._-]+)~i', $before, $beforeRefs);
        $nodes = self::nodes($after);
        $cards = array_keys(array_filter($nodes, static fn (array $n): bool => self::has($n, 'rt-v13-dispatch-block')));
        if (count($cards) !== 1) {
            self::fail();
        }
        $urls = [];
        foreach (self::ASSETS as $filename => $sha) {
            $bytes = self::asset($filename, $sha);
            $cid = 'railtime-'.substr($sha, 0, 20).'.png';
            $record = ['name' => $cid, 'contentId' => $cid, 'base64' => base64_encode($bytes)];
            $existing = array_values(array_filter($pair[$media], static fn (array $m): bool => ($m['contentId'] ?? '') === $cid));
            if ($existing !== [] && $existing !== [$record]) {
                self::fail();
            }
            if ($existing === []) {
                $pair[$media][] = $record;
            }
            $urls[self::url($filename)] = 'cid:'.$cid;
        }
        $edits = [];
        foreach ($nodes as $index => $node) {
            if (! self::within($nodes, $index, $cards[0])) {
                continue;
            }
            $changes = [];
            foreach (['src', 'background'] as $attribute) {
                if (isset($urls[$node['attrs'][$attribute] ?? ''])) {
                    $changes[$attribute] = $urls[$node['attrs'][$attribute]];
                }
            }
            if (isset($node['attrs']['style'])) {
                $style = strtr($node['attrs']['style'], $urls);
                if ($style !== $node['attrs']['style']) {
                    $changes['style'] = $style;
                }
            }
            if ($changes !== []) {
                $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, $changes)];
            }
        }
        $after = self::replace($after, $edits);
        $lost = array_filter(array_unique($beforeRefs[1]), static fn (string $cid): bool => ! str_contains($after, 'cid:'.$cid));
        $pair[$media] = array_values(array_filter($pair[$media], static function (array $record) use ($lost): bool {
            if (! in_array($record['contentId'] ?? '', $lost, true)) {
                return true;
            }
            $bytes = base64_decode($record['base64'] ?? '', true);
            if ($bytes === false || ! in_array(hash('sha256', $bytes), self::REMOVED, true)) {
                self::fail();
            }

            return false;
        }));
        if (array_sum(array_map(static fn (array $m): int => strlen(base64_decode($m['base64'], true) ?: ''), $pair[$media])) > 2097152) {
            self::fail();
        }
        self::budget($after, $limit);
        $pair[$field] = $after;

        return $pair;
    }

    public static function document(string $html): string
    {
        preg_match_all('~<style\b[^>]*data-rt-mail-document-css=(["\'])signature\1[^>]*>(.*?)</style\s*>~is', $html, $authored);
        if (array_filter($authored[2], static fn (string $css): bool => trim($css) !== '') !== []) {
            return $html;
        }
        if (! str_contains($html, 'data-rt-desktop-flow="'.OutlookContentFramePresentation::FLOW.'"') && ! str_contains($html, 'data-rt-desktop-flow="'.self::FLOW.'"')) {
            return $html;
        }
        $nodes = self::nodes($html);
        $roots = array_keys(array_filter($nodes, static fn (array $n): bool => in_array($n['attrs']['data-rt-desktop-flow'] ?? '', [self::FLOW, OutlookContentFramePresentation::FLOW], true)));
        if (count($roots) !== 1) {
            self::fail();
        }$root = $roots[0];
        if (($nodes[$root]['attrs']['data-rt-desktop-flow'] ?? '') === self::FLOW) {
            self::assertCurrent($html, $nodes, $root);

            return $html;
        }
        OutlookContentFramePresentation::document($html);
        $body = self::only($html, $nodes, $root, 'tbody');
        $rows = self::direct($nodes, $body);
        $main = self::only($html, $nodes, $rows[0], 'td');
        $columns = self::direct($nodes, $main);
        array_pop($columns);
        $system = self::has($nodes[$root], 'rt-system-columns');
        $widths = $system ? [200, 230, 247] : [200, 260, 230, 247];
        $rights = $system ? [16, 16, 20] : [16, 16, 16, 20];
        $edits = [];
        foreach ($columns as $position => $index) {
            $column = $nodes[$index];
            $cell = self::only($html, $nodes, self::only($html, $nodes, self::only($html, $nodes, $index, 'tbody'), 'tr'), 'td');
            $content = self::inner($html, $nodes[$cell]);
            if (self::has($column, 'rt-v12-identity')) {
                $content = self::identity($content);
            } elseif (self::has($column, 'rt-v12-direct')) {
                $content = self::contacts($content, false);
            } elseif (self::has($column, 'rt-v12-company')) {
                $content = self::contacts($content, true);
            } elseif (self::has($column, 'rt-v12-dispatch')) {
                $content = self::card($content);
            } else {
                self::fail();
            }
            $cellOpen = self::opening($nodes[$cell], ['style' => 'padding:0 '.$rights[$position].'px 0 0;vertical-align:top;font-family:Arial,sans-serif;font-size:13px;line-height:17px;']);
            $columnOpen = self::opening($column, ['width' => (string) $widths[$position], 'style' => 'width:'.$widths[$position].'px;max-width:100%;border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0;']);
            $edits[] = [$column['start'], $column['end'] - $column['start'], $columnOpen.'<tbody><tr>'.$cellOpen.$content.'</td></tr></tbody></table>'];
        }
        $edits[] = [$nodes[$main]['start'], $nodes[$main]['openEnd'] - $nodes[$main]['start'], self::opening($nodes[$main], ['style' => 'padding:12px 0 0 18px;font-size:0;line-height:0;vertical-align:top;'])];
        foreach ($nodes as $node) {
            if (self::has($node, 'rt-v12-train')) {
                $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['width' => '547', 'style' => 'width:547px;max-width:100%!important;border-collapse:collapse;'])];
            }
            if (self::has($node, 'rt-v12-train-image')) {
                $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['width' => '529', 'height' => '115', 'style' => 'display:block;width:529px;max-width:100%!important;height:auto;margin:0;border:0;vertical-align:bottom;'])];
            }
        }
        $html = self::replace($html, $edits);
        $html = preg_replace_callback(self::TOKENS, static function (array $m): string {
            $token = $m[0];
            if (preg_match('~\A<style\b~i', $token)) {
                // The new intrinsic train is already the enhanced size; old media cannot shrink it.
                if (str_contains($token, 'data-rt-fluid-signature-css="1"')) {
                    return '';
                }

                return str_replace(['rt-df12', '.rt-v12-'], ['rt-df13', '.rt-v13-'], $token);
            }
            if (! preg_match('~\A<[a-z]~i', $token)) {
                return $token;
            }
            $token = preg_replace_callback('~\bclass=("|\')([^"\']*)\1~i', static fn (array $c): string => 'class='.$c[1].str_replace(['rt-df12', 'rt-v12-'], ['rt-df13', 'rt-v13-'], $c[2]).$c[1], $token);

            return str_replace('data-rt-desktop-flow="'.OutlookContentFramePresentation::FLOW.'"', 'data-rt-desktop-flow="'.self::FLOW.'"', $token);
        }, $html);
        $nodes = self::nodes($html);
        $roots = array_keys(array_filter($nodes, static fn (array $n): bool => ($n['attrs']['data-rt-desktop-flow'] ?? '') === self::FLOW));
        if (count($roots) !== 1) {
            self::fail();
        }self::assertCurrent($html, $nodes, $roots[0]);

        return $html;
    }

    private static function identity(string $html): string
    {
        $nodes = self::nodes($html);
        $edits = [];
        foreach ($nodes as $index => $node) {
            if ($node['tag'] === 'table' && self::has($node, 'rt-personal-logo-frame')) {
                $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['width' => '184', 'style' => 'width:184px;max-width:100%;border-collapse:collapse;'])];
                $cell = self::only($html, $nodes, self::only($html, $nodes, self::only($html, $nodes, $index, 'tbody'), 'tr'), 'td');
                $edits[] = [$nodes[$cell]['start'], $nodes[$cell]['openEnd'] - $nodes[$cell]['start'], self::opening($nodes[$cell], ['style' => 'padding:0 0 13px;'])];
                foreach ($nodes as $i => $img) {
                    if ($img['tag'] === 'img' && self::within($nodes, $i, $index)) {
                        $edits[] = [$img['start'], $img['openEnd'] - $img['start'], self::opening($img, ['width' => '184', 'height' => '32', 'style' => 'display:block;width:184px;max-width:100%;height:auto;border:0;'])];
                    }
                }
            }
        }

        return self::replace($html, $edits);
    }

    private static function contacts(string $html, bool $company): string
    {
        $nodes = self::nodes($html);
        $contactRows = [];
        foreach ($nodes as $index => $node) {
            if ($node['tag'] !== 'tr') {
                continue;
            }$cells = self::direct($nodes, $index);
            if (count($cells) === 2 && in_array($nodes[$cells[0]]['attrs']['width'] ?? '', ['17', '27'], true)) {
                $contactRows[] = $index;
            }
        }
        if ($contactRows === []) {
            self::fail();
        }$body = $nodes[$contactRows[0]]['parent'];
        self::children($html, $nodes, $body, $contactRows);
        $mail = [];
        $phone = [];
        $web = [];
        $address = [];
        foreach ($contactRows as $index) {
            $row = self::contactRow(substr($html, $nodes[$index]['start'], $nodes[$index]['end'] - $nodes[$index]['start']));
            if (str_contains($row, 'href="mailto:')) {
                $mail[] = $row;
            } elseif (str_contains($row, 'href="tel:')) {
                $phone[] = $row;
            } elseif (preg_match('~href="https?://~', $row)) {
                $web[] = $row;
            } elseif (str_contains($row, 'rt-v9-address')) {
                $address[] = $row;
            } else {
                self::fail();
            }
        }
        if ($company) {
            if (count($web) !== 1 || count($address) !== 1) {
                self::fail();
            }$rows = array_merge($web, $address);
        } else {
            if (count($mail) !== 1 || $web !== [] || $address !== []) {
                self::fail();
            }$rows = array_merge($mail, $phone);
        }

        return substr_replace($html, implode('', $rows), $nodes[$body]['openEnd'], $nodes[$body]['close'] - $nodes[$body]['openEnd']);
    }

    private static function contactRow(string $html): string
    {
        $nodes = self::nodes($html);
        $rows = array_keys(array_filter($nodes, static fn (array $n): bool => $n['tag'] === 'tr' && $n['parent'] === null));
        if (count($rows) !== 1) {
            self::fail();
        }$cells = self::direct($nodes, $rows[0]);
        if (count($cells) !== 2) {
            self::fail();
        }$icon = $cells[0];
        $value = $cells[1];
        $images = array_keys(array_filter($nodes, static fn (array $n): bool => $n['tag'] === 'img'));
        if (count($images) !== 1 || ! self::within($nodes, $images[0], $icon)) {
            self::fail();
        }
        $edits = [];
        foreach ([$icon => 'rt-contact-icon', $value => 'rt-contact-text'] as $i => $class) {
            $node = $nodes[$i];
            $style = preg_replace('/(?:^|;)\s*padding(?:-[a-z]+)?\s*:[^;]*/i', '', $node['attrs']['style'] ?? '');
            $changes = ['class' => trim(($node['attrs']['class'] ?? '').' '.$class), 'valign' => 'top', 'style' => ltrim($style, ';').';padding:0!important;vertical-align:top;'];
            if ($i === $icon) {
                $changes['width'] = '27';
                $changes['style'] = preg_replace('/(?:^|;)\s*width\s*:[^;]*/i', '', $changes['style']).';width:27px;';
            }
            $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, $changes)];
        }
        $img = $nodes[$images[0]];
        $edits[] = [$img['start'], $img['openEnd'] - $img['start'], self::opening($img, ['width' => '18', 'height' => '18', 'style' => 'display:block;width:18px;height:18px;margin:0;border:0;'])];

        return self::replace($html, $edits);
    }

    private static function card(string $html): string
    {
        $nodes = self::nodes($html);
        $mail = $phones = [];
        foreach ($nodes as $index => $node) {
            if ($node['tag'] !== 'a') {
                continue;
            }
            if (str_starts_with($node['attrs']['href'] ?? '', 'mailto:')) {
                $mail[] = $node['attrs']['href'];
            } elseif (str_starts_with($node['attrs']['href'] ?? '', 'tel:')) {
                $phones[] = $index;
            }
        }
        if (count($mail) !== 1 || count($phones) !== 2) {
            self::fail();
        }
        $phone = $nodes[$phones[1]];
        $href = $phone['attrs']['href'];
        if (($nodes[$phones[0]]['attrs']['href'] ?? '') !== $href) {
            self::fail();
        }
        $number = trim(CssSemantic::decodeHtmlEntitiesOnce(strip_tags(self::inner($html, $phone))));
        if (! preg_match('/\A\+?[0-9][0-9 ()-]*\z/', $number)) {
            self::fail();
        }
        $safe = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<table class="rt-v13-dispatch-block" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:227px;border-collapse:collapse;"><tbody><tr><td class="rt-v13-dispatch-block-cell" style="padding:0;">'
            .'<img class="rt-v13-dispatch-caption" src="'.$safe(self::url('outlook-hotline-label-inktop-v1.png')).'" width="199" height="28" alt="Dispo 24/7 erreichbar" style="display:block;width:199px;max-width:100%!important;height:auto;border:0;">'
            .'<table class="rt-v13-dispatch-actions" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><tbody><tr>'
            .'<td width="39" valign="middle" style="width:39px;padding:10px 14px 10px 0;vertical-align:middle;"><a href="'.$safe($mail[0]).'" style="color:#e90032;text-decoration:none;"><img src="'.$safe(self::url('outlook-hotline-mail-outline-v1.png')).'" width="25" height="25" alt="E-Mail an die Disposition" style="display:block;width:25px;height:25px;border:0;"></a></td>'
            .'<td width="25" valign="middle" style="width:25px;padding:10px 0;vertical-align:middle;"><a href="'.$safe($href).'" style="color:#e90032;text-decoration:none;"><img src="'.$safe(self::url('outlook-hotline-phone-outline-v1.png')).'" width="25" height="25" alt="Telefon" style="display:block;width:25px;height:25px;border:0;"></a></td>'
            .'<td valign="middle" style="padding:10px 0 10px 5px;vertical-align:middle;"><a href="'.$safe($href).'" style="color:#e90032;text-decoration:none;"><span style="font-family:Arial,sans-serif;font-size:13px;line-height:25px;font-weight:bold;color:#e90032;overflow-wrap:anywhere;word-break:break-word;">'.$safe($number).'</span></a></td>'
            .'</tr></tbody></table></td></tr></tbody></table>';
    }

    private static function assertCurrent(string $html, array $nodes, int $root): void
    {
        $r = $nodes[$root];
        if (! self::has($r, 'rt-sign-content-frame') || ! self::has($r, 'rt-v13-columns') || ($r['attrs']['width'] ?? '') !== '100%') {
            self::fail();
        }
        $body = self::only($html, $nodes, $root, 'tbody');
        $rows = self::direct($nodes, $body);
        if (count($rows) !== 2) {
            self::fail();
        }$main = self::only($html, $nodes, $rows[0], 'td');
        if (($nodes[$main]['attrs']['style'] ?? '') !== 'padding:12px 0 0 18px;font-size:0;line-height:0;vertical-align:top;' || ($nodes[$main]['attrs']['width'] ?? '') !== '100%') {
            self::fail();
        }
        $widths = self::has($r, 'rt-system-columns') ? [200, 230, 247] : [200, 260, 230, 247];
        $columns = self::direct($nodes, $main);
        $clear = array_pop($columns);
        if (count($columns) !== count($widths) || $nodes[$clear]['tag'] !== 'br' || ($nodes[$clear]['attrs']['clear'] ?? '') !== 'all') {
            self::fail();
        }
        foreach ($columns as $i => $c) {
            if ($nodes[$c]['tag'] !== 'table' || ($nodes[$c]['attrs']['width'] ?? '') !== (string) $widths[$i] || ($nodes[$c]['attrs']['align'] ?? '') !== 'left') {
                self::fail();
            }
        }
        $cards = array_filter($nodes, static fn (array $n): bool => self::has($n, 'rt-v13-dispatch-block'));
        if (count($cards) !== 1 || (reset($cards)['attrs']['width'] ?? '') !== '100%'
            || ! str_contains(reset($cards)['attrs']['style'] ?? '', 'width:100%;max-width:227px;')) {
            self::fail();
        }
        $train = self::only($html, $nodes, self::only($html, $nodes, $rows[1], 'td'), 'table');
        if (($nodes[$train]['attrs']['width'] ?? '') !== '547') {
            self::fail();
        }
        $images = array_filter($nodes, static fn (array $n): bool => $n['tag'] === 'img' && self::has($n, 'rt-v13-train-image'));
        if (count($images) !== 1 || ($img = reset($images))['attrs']['width'] !== '529' || ($img['attrs']['height'] ?? '') !== '115') {
            self::fail();
        }
    }

    private static function url(string $filename): string
    {
        return rtrim((string) config('app.url'), '/').'/mail-assets/'.$filename;
    }

    private static function asset(string $filename, string $expected): string
    {
        $path = public_path('mail-assets/'.$filename);
        if (! is_file($path) || is_link($path) || ($bytes = file_get_contents($path)) === false || ! hash_equals($expected, hash('sha256', $bytes))) {
            self::fail();
        }

        return $bytes;
    }

    private static function budget(string $html, int $limit): void
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        if (intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) > $limit || strlen(implode('', $styles[1])) > ($limit === 30000 ? 12288 : 24576)) {
            self::fail();
        }
    }

    private static function within(array $nodes, int $child, int $parent): bool
    {
        for ($i = $child; $i !== null; $i = $nodes[$i]['parent']) {
            if ($i === $parent) {
                return true;
            }
        }

        return false;
    }

    private static function replace(string $html, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $last = strlen($html) + 1;
        foreach ($edits as [$o,$l,$v]) {
            if ($o + $l > $last) {
                self::fail();
            }$html = substr_replace($html, $v, $o, $l);
            $last = $o;
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

    private static function only(string $html, array $nodes, int $parent, string $tag): int
    {
        $children = self::direct($nodes, $parent);
        if (count($children) !== 1 || $nodes[$children[0]]['tag'] !== $tag) {
            self::fail();
        }
        self::children($html, $nodes, $parent, $children);

        return $children[0];
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

    private static function trivia(string $value): void
    {
        if (trim(preg_replace('~<!--.*?-->|<style\b[^>]*>.*?</style\s*>~is', '', $value)) !== '') {
            self::fail();
        }
    }

    private static function inner(string $html, array $node): string
    {
        return substr($html, $node['openEnd'], $node['close'] - $node['openEnd']);
    }

    private static function opening(array $node, array $changes): string
    {
        $tag = $node['opening'];
        $edits = [];
        $new = '';
        foreach ($changes as $key => $value) {
            $attribute = $value === null ? '' : ' '.$key.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
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
        throw new RuntimeException('Die Outlook-Referenzsignatur besitzt keinen eindeutigen Ausgabe-Vertrag.');
    }
}
