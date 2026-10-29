<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use RuntimeException;

/** Immutable raster projection after the canonical compilers; never an editor image type. */
final class OutlookIntrinsicTrainDelivery
{
    private const MAX_MEDIA_BYTES = 2097152;

    private const TAG = '(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*';

    /** source SHA256 => [variant, extension, immutable SHA256, bytes] */
    private const ASSETS = [
        'e0f8327a1041e23bdbbb2a478978e81521efe4e021767e40ad30034ed61dbe53' => ['light', 'gif', '45f4109cf7dbeed8c31c4c8a56bfdd9065b947b4b576596ba4bd6e5373b73fb8', 142199],
        'e9f40cff8a9a047bba5842cf0695e346ac229c7d1dfa4b78393c60e02b814e8e' => ['light', 'png', '78a1fd22c31977b21286a40b5aafe51b957b63b49f40948b6ed2b21ba0651d4a', 5967],
        '380b43ab43da0c724c492abf8f095917ad06408e7de07aaf33134bf82afb50e9' => ['dark', 'gif', '822dd45cf0529e41d8e1ed9a082904581d76be4a9f25c39240111e11fd3d3554', 98641],
        '2c170c402e17b74e45dc450494fa8f796f172b1fcb8cdfd424b361ceb9bd78bb' => ['dark', 'png', 'b1bc9d62a6dd026ffc9e6f0c181d1586084104223afa272766ee38371e4072dd', 2918],
        '52c80c7a8a193472d50346a14a2876a857cce4f1dc98b944dfe98a7fbc94a840' => ['light-mirrored', 'gif', '9db04d54004742501156413430e77ccf1b1ed8515018caa9c7d00caedaf63f17', 142477],
        'b3f813d3f405153f0dfc75b23118d98057170d38b4d35fba0797b5649f474b51' => ['light-mirrored', 'png', 'dc6eb47779cca236195052802aac7c1e22dba1f8198fbfbadb5729b504df99d6', 5983],
        'adb9dbd54e0945c985a96f939867448b787a4990550d8cfefb16fa4c757a20e9' => ['dark-mirrored', 'gif', '18567efab2a728b8058195b8fef1651e97b2aff8c116261af9698afabdaad4d9', 98776],
        '5784b3d25f89394f4196edfaf032f6db33bbf1b85880de8eff8a50573e17281c' => ['dark-mirrored', 'png', '4b6995aa470fb7730c0efa88d95528eb053bf95e3314a9567f2d36084370bd78', 2884],
    ];

    /** @return array<string, string> All eight pinned assets participate in the cache/version key. */
    public static function fingerprint(): array
    {
        $hashes = [];
        foreach (self::assets() as $asset) {
            $hashes['/mail-assets/'.$asset['file']] = $asset['sha256'];
        }
        ksort($hashes);

        return $hashes;
    }

    /** Exact known train remapping for the independent current three-column view. */
    public static function currentAttachment(array $attachment): ?array
    {
        if (! is_string($attachment['contentId'] ?? null) || ($attachment['name'] ?? null) !== $attachment['contentId']
            || ! is_string($attachment['base64'] ?? null) || strlen($attachment['base64']) > 4 * (int) ceil(self::MAX_MEDIA_BYTES / 3)
            || ($binary = base64_decode($attachment['base64'], true)) === false || base64_encode($binary) !== $attachment['base64']) {
            return null;
        }
        $sha = hash('sha256', $binary);
        foreach (self::assets() as $source => $asset) {
            if ($attachment['contentId'] === 'railtime-'.substr($sha, 0, 20).'.'.$asset['extension']) {
                if ($source === $sha) {
                    return $asset['attachment'];
                }
                if ($asset['sha256'] === $sha && $asset['attachment'] === $attachment) {
                    return $attachment;
                }
            }
        }

        return null;
    }

    /** Versions and signature-free composeHtml/composeMedia remain byte-exact. */
    public static function payload(array $payload): array
    {
        $assets = null;
        if (isset($payload['signature'])) {
            $payload['signature'] = self::pair($payload['signature'], 'html', 'media', 30000, 12288, $assets);
        }
        if (isset($payload['template'])) {
            $payload['template'] = self::pair($payload['template'], 'html', 'media', 99000, 24576, $assets);
        }
        foreach ($payload['templates'] ?? [] as $index => $template) {
            foreach (['html' => 'media', 'combinedComposeHtml' => 'combinedComposeMedia', 'mobileComposeHtml' => 'mobileComposeMedia'] as $html => $media) {
                $mobile = $html === 'mobileComposeHtml';
                $template = self::pair($template, $html, $media, $mobile ? 30000 : 99000, $mobile ? 12288 : 24576, $assets);
            }
            if (isset($template['signature'])) {
                $template['signature'] = self::pair($template['signature'], 'html', 'media', 30000, 12288, $assets);
            }
            $payload['templates'][$index] = $template;
        }

        return $payload;
    }

    private static function pair(array $pair, string $htmlKey, string $mediaKey, int $maxCharacters, int $maxCss, ?array &$assets): array
    {
        if (! isset($pair[$htmlKey])) {
            return $pair;
        }
        if (! is_string($pair[$htmlKey])) {
            self::fail();
        }
        $html = $pair[$htmlKey];
        $target = self::target($html);
        if ($target === null) {
            return $pair;
        }
        $enlarged = $target['enlarged'];
        $width = $enlarged ? '324' : '300';
        $height = $enlarged ? '70' : '65';
        if (! is_array($pair[$mediaKey] ?? null) || ! array_is_list($pair[$mediaKey])) {
            self::fail();
        }
        $byCid = [];
        $total = 0;
        foreach ($pair[$mediaKey] as $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['contentId'] ?? null)
                || ($attachment['name'] ?? null) !== $attachment['contentId']
                || ! is_string($attachment['base64'] ?? null)
                || strlen($attachment['base64']) > 4 * (int) ceil(self::MAX_MEDIA_BYTES / 3)
                || preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/D', $attachment['base64']) !== 1
                || strlen($attachment['base64']) % 4 !== 0) {
                self::fail();
            }
            self::cid($attachment['contentId']);
            $key = strtolower($attachment['contentId']);
            $binary = base64_decode($attachment['base64'], true);
            if (! is_string($binary) || $binary === '' || base64_encode($binary) !== $attachment['base64'] || isset($byCid[$key])) {
                self::fail();
            }
            $total += strlen($binary);
            $byCid[$key] = ['attachment' => $attachment, 'binary' => $binary];
        }
        if ($total > self::MAX_MEDIA_BYTES) {
            self::fail();
        }
        $source = $target['attrs']['src'] ?? '';
        if (! str_starts_with($source, 'cid:')) {
            self::fail();
        }
        $oldCid = substr($source, 4);
        self::cid($oldCid);
        $record = $byCid[strtolower($oldCid)] ?? null;
        if ($record === null || $record['attachment']['contentId'] !== $oldCid) {
            self::fail();
        }
        $sha = hash('sha256', $record['binary']);
        $assets ??= self::assets();
        $asset = $assets[$sha] ?? null;
        $already = false;
        if ($asset === null) {
            foreach ($assets as $candidate) {
                if ($candidate['sha256'] === $sha) {
                    $asset = $candidate;
                    $already = true;
                    break;
                }
            }
        }
        if ($asset === null || $oldCid !== 'railtime-'.substr($sha, 0, 20).'.'.$asset['extension']
            || ($target['attrs']['width'] ?? '') !== ($already ? $width : '300') || ($target['attrs']['height'] ?? '') !== ($already ? $height : '38')) {
            self::fail();
        }
        if (preg_match('/(?:\A|;)\s*height\s*:\s*(38|65|70)px/i', $target['attrs']['style'] ?? '', $fixedHeight)
            && $fixedHeight[1] !== ($already ? $height : '38')) {
            self::fail();
        }
        $image = @getimagesizefromstring($record['binary']);
        if ($image === false || ($image['mime'] ?? '') !== 'image/'.$asset['extension']
            || ($already && [$image[0], $image[1]] !== [695, 151])) {
            self::fail();
        }
        if ($already) {
            if ($oldCid !== $asset['attachment']['contentId']) {
                self::fail();
            }
            self::budget($html, $pair[$mediaKey], $maxCharacters, $maxCss);

            return $pair;
        }
        $opening = self::attribute(self::attribute(self::attribute($target['opening'], 'src', 'cid:'.$asset['attachment']['contentId']), 'width', $width), 'height', $height);
        if (preg_match('/(?:\A|;)\s*height\s*:\s*38px\s*(?:!important\s*)?(?=;|\z)/i', $target['attrs']['style'] ?? '')) {
            $style = preg_replace('/((?:\A|;)\s*height\s*:\s*)38px(?=\s*(?:!important\s*)?(?:;|\z))/i', '${1}'.$height.'px', $target['attrs']['style']);
            if ($enlarged) {
                $style = preg_replace('/((?:\A|;)\s*(?:max-)?width\s*:\s*)300px(?=\s*(?:!important\s*)?(?:;|\z))/i', '${1}324px', $style);
            }
            $opening = self::attribute($opening, 'style', $style);
        }
        $html = substr_replace($html, $opening, $target['start'], strlen($target['opening']));
        if ($enlarged && ! $target['mobile']) {
            $stage = $target['stage'];
            $stageStyle = preg_replace('/((?:\A|;)\s*max-width\s*:\s*)340px(?=\s*(?:!important\s*)?(?:;|\z))/i', '${1}364px!important', $stage['attrs']['style'] ?? '', 1, $changed);
            if ($changed !== 1 || self::has($stage, 'rt-train-enlarged-v1')) {
                self::fail();
            }
            $stageOpening = self::attribute(self::attribute($stage['opening'], 'class', trim(($stage['attrs']['class'] ?? '').' rt-train-enlarged-v1')), 'style', $stageStyle);
            $html = substr_replace($html, $stageOpening, $stage['start'], strlen($stage['opening']));
        }
        $newKey = strtolower($asset['attachment']['contentId']);
        if (isset($byCid[$newKey]) && $byCid[$newKey]['attachment'] !== $asset['attachment']) {
            self::fail();
        }
        $media = [];
        foreach ($pair[$mediaKey] as $attachment) {
            // Conditional Word fallbacks, CSS and quoted/unowned images may
            // still need this source. Prune only the replaced, unreferenced CID.
            if ($attachment['contentId'] !== $oldCid || self::references($html, $oldCid)) {
                $media[] = $attachment;
            }
        }
        if (! isset($byCid[$newKey])) {
            $media[] = $asset['attachment'];
        }
        self::budget($html, $media, $maxCharacters, $maxCss);
        $pair[$htmlKey] = $html;
        $pair[$mediaKey] = $media;

        return $pair;
    }

    /** Resolve one unquoted personal train through its versioned owned structure. */
    private static function target(string $html): ?array
    {
        if (! str_contains($html, 'outlook-desktop-flow-v8') && ! str_contains($html, 'outlook-desktop-flow-v7') && ! str_contains($html, 'rt-mobile-hybrid-v2')) {
            return null;
        }
        preg_match_all('~<style\b[^>]*data-rt-mail-document-css=(["\'])signature\1[^>]*>(.*?)</style\s*>~is', $html, $authored, PREG_SET_ORDER);
        foreach ($authored as $style) {
            if (trim($style[2]) !== '') {
                return null;
            }
        }
        $nodes = self::nodes($html);
        $flows = [];
        foreach ($nodes as $index => $node) {
            if ($node['quoted']) {
                continue;
            }
            // Generic company signatures share the flow revision but do not
            // own the personal TABLE protocol or its intrinsic train viewport.
            $nativeVersion = $node['attrs']['data-rt-desktop-flow'] ?? '';
            $native = in_array($nativeVersion, ['outlook-desktop-flow-v7', 'outlook-desktop-flow-v8'], true)
                && (array_key_exists('data-rt-personal-flow', $node['attrs'])
                    || array_key_exists('data-rt-personal-columns', $node['attrs']) || self::has($node, 'rt-personal-columns'));
            $mobile = ($node['attrs']['data-rt-mobile-hybrid'] ?? '') === 'v2' && self::has($node, 'rt-mobile-hybrid-v2');
            if (! $native && ! $mobile) {
                continue;
            }
            if ($node['tag'] !== 'table' || ($node['attrs']['data-rt-personal-columns'] ?? '') !== 'v1'
                || ($native && (($node['attrs']['data-rt-personal-flow'] ?? '') !== 'v1' || ! self::has($node, 'rt-personal-columns')))) {
                self::fail();
            }
            $owner = null;
            $artifact = false;
            for ($parent = $node['parent']; $parent !== null; $parent = $nodes[$parent]['parent']) {
                $ancestor = $nodes[$parent];
                $artifact = $artifact || ($ancestor['tag'] === 'tr' && ($ancestor['attrs']['data-rt-artifact-version'] ?? '') === 'v27');
                if ($ancestor['tag'] === 'div' && (self::has($ancestor, 'rt-outlook-signature') || self::has($ancestor, 'xz'))) {
                    $owner = $ancestor;
                    break;
                }
            }
            $host = $nativeVersion === 'outlook-desktop-flow-v8' ? 'rt-df8' : 'rt-df7';
            if (! $artifact || $owner === null || ($native && preg_match('/(?:^|\s)'.$host.'[0-9a-f]{10}(?=\s|$)/', $owner['attrs']['class'] ?? '') !== 1)
                || ($mobile && ! self::has($owner, 'rt-mobile-ledger') && ! self::has($owner, 'x10'))) {
                self::fail();
            }
            $flows[] = [$index, $mobile];
        }
        if ($flows === []) {
            return null;
        }
        if (count($flows) !== 1) {
            self::fail();
        }
        [$flow, $mobile] = $flows[0];
        $body = self::child($nodes, $flow, 'tbody');
        $rows = self::children($nodes, $body);
        if (count($rows) !== 2 || $nodes[$rows[0]]['tag'] !== 'tr' || $nodes[$rows[1]]['tag'] !== 'tr') {
            self::fail();
        }
        $firstCell = self::child($nodes, $rows[0], 'td');
        $groups = self::children($nodes, $firstCell);
        $expected = $mobile ? ['rt-mhy-logo', 'rt-mhy-person', 'rt-mhy-company'] : ['rt-personal-logo-column', 'rt-personal-left', 'rt-personal-right'];
        if (count($groups) !== 3) {
            self::fail();
        }
        foreach ($expected as $position => $class) {
            if ($nodes[$groups[$position]]['tag'] !== 'table' || ! self::has($nodes[$groups[$position]], $class)) {
                self::fail();
            }
        }
        $train = self::child($nodes, self::child($nodes, $rows[1], 'td'), 'table');
        if (! self::has($nodes[$train], $mobile ? 'rt-mhy-train' : 'rt-df-train')) {
            self::fail();
        }
        $trainCell = self::child($nodes, self::child($nodes, self::child($nodes, $train, 'tbody'), 'tr'), 'td');
        $window = self::child($nodes, $trainCell, 'div');
        $image = self::child($nodes, $window, 'img');
        if ($nodes[$window]['quoted'] || $nodes[$image]['quoted'] || ($nodes[$window]['attrs']['style'] ?? '') !== '' || ($nodes[$image]['attrs']['alt'] ?? '') !== ''
            || isset($nodes[$image]['attrs']['srcset']) || isset($nodes[$image]['attrs']['sizes']) || isset($nodes[$image]['attrs']['usemap'])) {
            self::fail();
        }
        $style = strtolower(preg_replace('/\s+/', '', $nodes[$image]['attrs']['style'] ?? ''));
        if (preg_match('/\Adisplay:block;width:100%;max-width:100%;height:(?:auto|(?:38|65|70)px)(?:!important)?;margin:0;border:0;(?:vertical-align:bottom;)?\z/', rtrim($style, ';').';') !== 1) {
            self::fail();
        }
        if ($mobile) {
            if (preg_match('/\Art-mhy-image rt-i([0-9a-f]{6})\z/', $nodes[$image]['attrs']['class'] ?? '', $scope) !== 1
                || ($nodes[$window]['attrs']['class'] ?? '') !== 'rt-w'.$scope[1]) {
                self::fail();
            }
        } elseif (! self::has($nodes[$image], 'rt-delivery-train') || ($nodes[$window]['attrs']['class'] ?? '') !== 'rt-train-window') {
            self::fail();
        }

        $enlarged = self::has($nodes[$train], 'rt-train-enlarged-v1') || array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'table' && self::has($node, 'rt-brand-outline-v1')) !== [];

        return $nodes[$image] + ['stage' => $nodes[$train], 'mobile' => $mobile, 'enlarged' => $enlarged];
    }

    private static function assets(): array
    {
        $root = realpath(dirname(__DIR__, 3).'/public/mail-assets');
        if ($root === false) {
            self::fail();
        }
        $assets = [];
        foreach (self::ASSETS as $source => [$variant, $extension, $sha, $bytes]) {
            $file = 'zug-dampf-outlook-intrinsic-v1-'.$variant.'.'.$extension;
            $path = $root.DIRECTORY_SEPARATOR.$file;
            $resolved = realpath($path);
            if ($resolved === false || dirname($resolved) !== $root || is_link($path) || ! is_file($path) || ! is_readable($path)
                || filesize($path) !== $bytes || $bytes > self::MAX_MEDIA_BYTES || ($binary = @file_get_contents($path)) === false
                || ! hash_equals($sha, hash('sha256', $binary)) || ($image = @getimagesizefromstring($binary)) === false
                || [$image[0], $image[1]] !== [695, 151] || ($image['mime'] ?? '') !== 'image/'.$extension) {
                self::fail();
            }
            $cid = 'railtime-'.substr($sha, 0, 20).'.'.$extension;
            $assets[$source] = ['file' => $file, 'sha256' => $sha, 'extension' => $extension,
                'attachment' => ['name' => $cid, 'contentId' => $cid, 'base64' => base64_encode($binary)]];
        }

        return $assets;
    }

    private static function budget(string $html, array $media, int $maxCharacters, int $maxCss): void
    {
        if (! mb_check_encoding($html, 'UTF-8')) {
            self::fail();
        }
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        if (array_sum(array_map('strlen', $styles[1])) >= $maxCss || intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) > $maxCharacters
            || array_sum(array_map(static fn (array $record): int => strlen((string) base64_decode($record['base64'], true)), $media)) > self::MAX_MEDIA_BYTES) {
            self::fail();
        }
    }

    private static function references(string $html, string $cid): bool
    {
        // Conservative over-retention is safer than deleting quoted, CSS or
        // conditional references. Entities are decoded once, like the compiler.
        return preg_match('~cid:'.preg_quote($cid, '~').'(?![A-Za-z0-9._@-])~i', CssSemantic::decodeHtmlEntitiesOnce($html)) === 1;
    }

    private static function attribute(string $opening, string $name, string $value): string
    {
        $output = preg_replace_callback('~(\s'.preg_quote($name, '~').'\s*=\s*)(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)~i', static fn (array $match): string => $match[1].'"'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"', $opening, -1, $count);
        if ($count !== 1) {
            self::fail();
        }

        return $output;
    }

    private static function cid(string $cid): void
    {
        if (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9._@-]{0,190}[A-Za-z0-9])?\z/D', $cid) !== 1) {
            self::fail();
        }
    }

    private static function nodes(string $html): array
    {
        preg_match_all('~<!--.*?-->|<style\b'.self::TAG.'>.*?</style\s*>|</?[a-z][a-z0-9:-]*'.self::TAG.'>~is', $html, $tokens, PREG_OFFSET_CAPTURE);
        $nodes = [];
        $stack = [];
        $quotedTail = false;
        foreach ($tokens[0] as [$opening, $start]) {
            if (str_starts_with($opening, '<!--') || preg_match('~\A<style\b~i', $opening)) {
                continue;
            }
            preg_match('~\A<(/?)([a-z][a-z0-9:-]*)\b~i', $opening, $tag);
            $name = strtolower($tag[2]);
            if ($tag[1] === '/') {
                $index = array_pop($stack);
                if ($index === null || $nodes[$index]['tag'] !== $name) {
                    self::fail();
                }

                continue;
            }
            $attrs = [];
            preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $opening, $attributes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($attributes as $attribute) {
                $key = strtolower($attribute[1]);
                if (array_key_exists($key, $attrs)) {
                    self::fail();
                }
                $attrs[$key] = CssSemantic::decodeHtmlEntitiesOnce($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '');
            }
            $parent = $stack === [] ? null : end($stack);
            $quotedTail = $quotedTail || in_array(strtolower($attrs['id'] ?? ''), ['divrplyfwdmsg', 'x_divrplyfwdmsg', 'mail-editor-reference-message-container'], true);
            $quoted = $quotedTail || $name === 'blockquote' || ($parent !== null && $nodes[$parent]['quoted'])
                || preg_match('/(?:^|\s)(?:gmail_quote|yahoo_quoted|moz-cite-prefix)(?=\s|$)/', $attrs['class'] ?? '') === 1;
            $index = count($nodes);
            $nodes[] = ['tag' => $name, 'attrs' => $attrs, 'parent' => $parent, 'quoted' => $quoted, 'start' => $start, 'opening' => $opening];
            if (! in_array($name, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true)) {
                $stack[] = $index;
            }
        }
        if ($stack !== []) {
            self::fail();
        }

        return $nodes;
    }

    private static function has(array $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($node['attrs']['class'] ?? '')), true);
    }

    private static function children(array $nodes, int $parent): array
    {
        return array_keys(array_filter($nodes, static fn (array $node): bool => $node['parent'] === $parent));
    }

    private static function child(array $nodes, int $parent, string $tag): int
    {
        $children = self::children($nodes, $parent);
        if (count($children) !== 1 || $nodes[$children[0]]['tag'] !== $tag) {
            self::fail();
        }

        return $children[0];
    }

    private static function fail(): never
    {
        throw new RuntimeException('Die intrinsische Outlook-Zugdarstellung besitzt keinen gueltigen HTML-/Medienvertrag.');
    }
}
