<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** The red reference card is a separate late projection; V13 stays immutable. */
final class OutlookDispatchCardPresentation
{
    public const FLOW = 'outlook-desktop-flow-v14';

    public const REVISION = 'outlook-dispatch-card-presentation-v1';

    private const TOKENS = '~<!doctype\b[^>]*>|<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    private const ASSETS = [
        'outlook-dispo-card-caption-v1.png' => '464054a84f32ad05d4c6e65a67105c6500ed070a818a40935d3b9f390bec7ab7',
        'outlook-hotline-mail-v1.png' => '36ecdeda3135132910ead299dec2fdef8786ed05a39d7fadb3ee4156e5d1696d',
        'outlook-hotline-phone-v1.png' => 'a3564d9bc8fafc2fc9dcd577361b882dda579f539049876e589fd6d12b090dc5',
        'outlook-hotline-fill-v1.png' => '3ee5033b5b112852a0255cf62d99542391117ba462408510c460c799a5ad6583',
    ];

    private const REMOVED = [
        '37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a',
        'dcf209bd170e4581760ec5c8183830d445cd4fc2e46277bc5ff265bacbc85e9d',
        'e24cb8b0ac6770bd9b2a453c50b7c7157531f5929e96713f9aa03d42647f3ea1',
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
        $cards = array_keys(array_filter($nodes, static fn (array $n): bool => self::has($n, 'rt-v14-dispatch-block')));
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
        if (! str_contains($html, 'data-rt-desktop-flow="'.OutlookReferenceCardPresentation::FLOW.'"') && ! str_contains($html, 'data-rt-desktop-flow="'.self::FLOW.'"')) {
            return $html;
        }
        $nodes = self::nodes($html);
        $roots = array_keys(array_filter($nodes, static fn (array $node): bool => in_array($node['attrs']['data-rt-desktop-flow'] ?? '', [self::FLOW, OutlookReferenceCardPresentation::FLOW], true)));
        if (count($roots) !== 1) {
            self::fail();
        }
        $root = $roots[0];
        if (($nodes[$root]['attrs']['data-rt-desktop-flow'] ?? '') === self::FLOW) {
            self::assertCurrent($html, $nodes, $root);

            return $html;
        }
        // The previous projector owns all geometry/contact/media contracts.
        // An authored/custom layout never reaches this owned V13 shape.
        OutlookReferenceCardPresentation::document($html);
        $card = self::cardIndex($nodes, $root, 'rt-v13-dispatch-block');
        $old = substr($html, $nodes[$card]['start'], $nodes[$card]['end'] - $nodes[$card]['start']);
        [$mail, $phone, $number] = self::contacts($old);
        if (self::normalizeSources($old) !== self::transparent($mail, $phone, $number)) {
            self::fail();
        }
        $trains = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'img' && self::has($node, 'rt-v13-train-image')));
        if (count($trains) !== 1 || ! self::within($nodes, $trains[0], $root)) {
            self::fail();
        }
        $train = $nodes[$trains[0]];
        $stages = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'table' && self::has($node, 'rt-v13-train')));
        if (count($stages) !== 1 || ! self::within($nodes, $trains[0], $stages[0])) {
            self::fail();
        }
        $stage = $nodes[$stages[0]];
        $edits = [
            [$stage['start'], $stage['openEnd'] - $stage['start'], self::opening($stage, ['width' => '242', 'style' => 'width:min(547px,100%)!important;max-width:100%!important;border-collapse:collapse;'])],
            [$nodes[$card]['start'], $nodes[$card]['end'] - $nodes[$card]['start'], self::card($mail, $phone, $number)],
            [$train['start'], $train['openEnd'] - $train['start'], self::opening($train, ['width' => '224', 'height' => '49', 'style' => 'display:block;width:min(529px,100%)!important;max-width:100%!important;height:auto!important;margin:0;border:0;vertical-align:bottom;'])],
        ];
        foreach ($nodes as $index => $node) {
            if (! self::contactIcon($nodes, $index, $root, 13)) {
                continue;
            }
            // Generated historical CSS still assigns17px!important to the
            // old aliases; this owned V14 slot must keep its real27px width.
            $style = preg_replace('/(?:^|;)\s*(?:width|min-width)\s*:[^;]*/i', '', $node['attrs']['style'] ?? '');
            $edits[] = [$node['start'], $node['openEnd'] - $node['start'], self::opening($node, ['style' => trim($style, ';').';width:27px!important;min-width:27px!important;'])];
        }
        $legal = self::legalHost($nodes, 13);
        $style = preg_replace('/(?:^|;)\s*padding(?:-[a-z]+)?\s*:[^;]*/i', '', $nodes[$legal]['attrs']['style'] ?? '');
        $edits[] = [$nodes[$legal]['start'], $nodes[$legal]['openEnd'] - $nodes[$legal]['start'], self::opening($nodes[$legal], ['style' => trim($style, ';').';padding:8px 0!important;padding-left:0!important;padding-right:0!important;'])];
        $html = self::replace($html, $edits);
        $html = self::family($html, 13, 14);
        $nodes = self::nodes($html);
        $root = array_keys(array_filter($nodes, static fn (array $node): bool => ($node['attrs']['data-rt-desktop-flow'] ?? '') === self::FLOW));
        if (count($root) !== 1) {
            self::fail();
        }
        self::assertCurrent($html, $nodes, $root[0]);

        return $html;
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

            return str_replace('data-rt-desktop-flow="outlook-desktop-flow-v'.$from.'"', 'data-rt-desktop-flow="outlook-desktop-flow-v'.$to.'"', $token);
        }, $html);
    }

    private static function cardIndex(array $nodes, int $root, string $class): int
    {
        $cards = array_keys(array_filter($nodes, static fn (array $node): bool => self::has($node, $class)));
        if (count($cards) !== 1 || ! self::within($nodes, $cards[0], $root)) {
            self::fail();
        }

        return $cards[0];
    }

    private static function contacts(string $html): array
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
            } else {
                self::fail();
            }
        }
        if (count($mail) !== 1 || count($phones) !== 2) {
            self::fail();
        }
        $phone = $nodes[$phones[1]];
        $href = $phone['attrs']['href'];
        $number = trim(CssSemantic::decodeHtmlEntitiesOnce(strip_tags(self::inner($html, $phone))));
        if (($nodes[$phones[0]]['attrs']['href'] ?? '') !== $href || ! preg_match('/\A\+?[0-9][0-9 ()-]*\z/', $number)
            || preg_replace('/\D/', '', $number) !== preg_replace('/\D/', '', substr($href, 4))) {
            self::fail();
        }

        return [$mail[0], $href, $number];
    }

    private static function contactIcon(array $nodes, int $index, int $root, int $family): bool
    {
        if ($nodes[$index]['tag'] !== 'td' || ! self::has($nodes[$index], 'rt-contact-icon') || ! self::within($nodes, $index, $root)) {
            return false;
        }
        for ($parent = $nodes[$index]['parent']; $parent !== null && $parent !== $root; $parent = $nodes[$parent]['parent']) {
            if (self::has($nodes[$parent], 'rt-v'.$family.'-direct') || self::has($nodes[$parent], 'rt-v'.$family.'-company')) {
                return true;
            }
        }

        return false;
    }

    private static function legalHost(array $nodes, int $family): int
    {
        $cells = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'td' && self::has($node, 'rt-v'.$family.'-legal-cell')));
        if (count($cells) !== 1) {
            self::fail();
        }
        $parent = $nodes[$cells[0]]['parent'];
        foreach (['tr', 'tbody', 'table', 'td'] as $tag) {
            if ($parent === null || $nodes[$parent]['tag'] !== $tag
                || ($tag === 'table' && ! self::has($nodes[$parent], 'rt-brand-legal-table'))) {
                self::fail();
            }
            if ($tag === 'td') {
                return $parent;
            }
            $parent = $nodes[$parent]['parent'];
        }
        self::fail();
    }

    private static function normalizeSources(string $html): string
    {
        $replacements = [];
        foreach (self::ASSETS + [
            'outlook-hotline-label-inktop-v1.png' => self::REMOVED[0],
            'outlook-hotline-mail-outline-v1.png' => self::REMOVED[1],
            'outlook-hotline-phone-outline-v1.png' => self::REMOVED[2],
        ] as $filename => $sha) {
            $replacements['cid:railtime-'.substr($sha, 0, 20).'.png'] = self::url($filename);
        }

        return strtr($html, $replacements);
    }

    private static function assertCurrent(string $html, array $nodes, int $root): void
    {
        // Reuse, without changing, the previous factory's outer geometry proof.
        $trains = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'img' && self::has($node, 'rt-v14-train-image')));
        if (count($trains) !== 1 || ! self::within($nodes, $trains[0], $root)) {
            self::fail();
        }
        $train = $nodes[$trains[0]];
        if (($train['attrs']['width'] ?? '') !== '224' || ($train['attrs']['height'] ?? '') !== '49'
            || ($train['attrs']['style'] ?? '') !== 'display:block;width:min(529px,100%)!important;max-width:100%!important;height:auto!important;margin:0;border:0;vertical-align:bottom;') {
            self::fail();
        }
        // Recreate only the proven historical train attributes for that
        // immutable validator. The emitted source keeps the small fallback.
        $stages = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'table' && self::has($node, 'rt-v14-train')));
        if (count($stages) !== 1 || ! self::within($nodes, $trains[0], $stages[0])) {
            self::fail();
        }
        $stage = $nodes[$stages[0]];
        if (($stage['attrs']['width'] ?? '') !== '242'
            || ($stage['attrs']['style'] ?? '') !== 'width:min(547px,100%)!important;max-width:100%!important;border-collapse:collapse;') {
            self::fail();
        }
        $historical = self::replace($html, [
            [$stage['start'], $stage['openEnd'] - $stage['start'], self::opening($stage, ['width' => '547', 'style' => 'width:547px;max-width:100%!important;border-collapse:collapse;'])],
            [$train['start'], $train['openEnd'] - $train['start'], self::opening($train, ['width' => '529', 'height' => '115', 'style' => 'display:block;width:529px;max-width:100%!important;height:auto;margin:0;border:0;vertical-align:bottom;'])],
        ]);
        OutlookReferenceCardPresentation::document(self::family($historical, 14, 13));
        foreach ($nodes as $index => $node) {
            if (self::contactIcon($nodes, $index, $root, 14)
                && (($node['attrs']['width'] ?? '') !== '27'
                    || ! str_contains($node['attrs']['style'] ?? '', 'width:27px!important;min-width:27px!important;'))) {
                self::fail();
            }
        }
        $legal = self::legalHost($nodes, 14);
        preg_match_all('/(?:^|;)\s*(padding(?:-[a-z]+)?)\s*:\s*([^;]*)/i', $nodes[$legal]['attrs']['style'] ?? '', $padding, PREG_SET_ORDER);
        if (array_map(static fn (array $match): string => strtolower($match[1]).':'.trim($match[2]), $padding)
            !== ['padding:8px 0!important', 'padding-left:0!important', 'padding-right:0!important']) {
            self::fail();
        }
        $card = self::cardIndex($nodes, $root, 'rt-v14-dispatch-block');
        $markup = substr($html, $nodes[$card]['start'], $nodes[$card]['end'] - $nodes[$card]['start']);
        [$mail, $phone, $number] = self::contacts($markup);
        if (self::normalizeSources($markup) !== self::card($mail, $phone, $number)) {
            self::fail();
        }
    }

    private static function card(string $mail, string $phone, string $number): string
    {
        $safe = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fill = $safe(self::url('outlook-hotline-fill-v1.png'));
        $paint = 'background-color:#e90032;background-image:url('.$fill.');background-repeat:repeat;border-radius:8px;';

        return '<table class="rt-v14-dispatch-block" data-rt-dispatch-card="v14" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#e90032" background="'.$fill.'" style="width:100%;max-width:227px;border-collapse:separate;border-spacing:0;'.$paint.'"><tbody><tr><td class="rt-v14-dispatch-block-cell" data-rt-dispatch-card="v14" bgcolor="#e90032" background="'.$fill.'" style="padding:8px 14px 5px;'.$paint.'">'
            .'<img class="rt-v14-dispatch-caption" src="'.$safe(self::url('outlook-dispo-card-caption-v1.png')).'" width="177" height="34" alt="Dispo 24/7 erreichbar" style="display:block;width:min(199px,100%)!important;max-width:100%!important;height:auto!important;border:0;">'
            .'<table class="rt-v14-dispatch-actions" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;border-top:1px solid #ff6b8b;"><tbody><tr>'
            .'<td width="39" valign="middle" style="width:39px;padding:10px 14px 10px 0;vertical-align:middle;"><a href="'.$safe($mail).'" style="color:#ffffff;text-decoration:none;"><img src="'.$safe(self::url('outlook-hotline-mail-v1.png')).'" width="25" height="25" alt="E-Mail an die Disposition" style="display:block;width:25px;height:25px;border:0;"></a></td>'
            .'<td width="25" valign="middle" style="width:25px;padding:10px 0;vertical-align:middle;"><a href="'.$safe($phone).'" style="color:#ffffff;text-decoration:none;"><img src="'.$safe(self::url('outlook-hotline-phone-v1.png')).'" width="25" height="25" alt="Telefon" style="display:block;width:25px;height:25px;border:0;"></a></td>'
            .'<td valign="middle" style="padding:10px 0 10px 5px;vertical-align:middle;"><a href="'.$safe($phone).'" style="color:#ffffff;text-decoration:none;"><span style="font-family:Arial,sans-serif;font-size:12px;line-height:25px;font-weight:bold;color:#ffffff;overflow-wrap:anywhere;word-break:break-word;">'.$safe($number).'</span></a></td>'
            .'</tr></tbody></table></td></tr></tbody></table>';
    }

    private static function transparent(string $mail, string $phone, string $number): string
    {
        $safe = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<table class="rt-v13-dispatch-block" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:227px;border-collapse:collapse;"><tbody><tr><td class="rt-v13-dispatch-block-cell" style="padding:0;">'
            .'<img class="rt-v13-dispatch-caption" src="'.$safe(self::url('outlook-hotline-label-inktop-v1.png')).'" width="199" height="28" alt="Dispo 24/7 erreichbar" style="display:block;width:199px;max-width:100%!important;height:auto;border:0;">'
            .'<table class="rt-v13-dispatch-actions" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><tbody><tr>'
            .'<td width="39" valign="middle" style="width:39px;padding:10px 14px 10px 0;vertical-align:middle;"><a href="'.$safe($mail).'" style="color:#e90032;text-decoration:none;"><img src="'.$safe(self::url('outlook-hotline-mail-outline-v1.png')).'" width="25" height="25" alt="E-Mail an die Disposition" style="display:block;width:25px;height:25px;border:0;"></a></td>'
            .'<td width="25" valign="middle" style="width:25px;padding:10px 0;vertical-align:middle;"><a href="'.$safe($phone).'" style="color:#e90032;text-decoration:none;"><img src="'.$safe(self::url('outlook-hotline-phone-outline-v1.png')).'" width="25" height="25" alt="Telefon" style="display:block;width:25px;height:25px;border:0;"></a></td>'
            .'<td valign="middle" style="padding:10px 0 10px 5px;vertical-align:middle;"><a href="'.$safe($phone).'" style="color:#e90032;text-decoration:none;"><span style="font-family:Arial,sans-serif;font-size:13px;line-height:25px;font-weight:bold;color:#e90032;overflow-wrap:anywhere;word-break:break-word;">'.$safe($number).'</span></a></td>'
            .'</tr></tbody></table></td></tr></tbody></table>';
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
