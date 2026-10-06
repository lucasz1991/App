<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/** Approved native mobile ownership; derived output only, never authoring/cache. */
final class OutlookMobileCombinedComposeDocument
{
    public const MODE = 'combined-native-v1';

    public const VERSION_MARKER = 'RT-MOBILE-COMPOSE-VERSION:';

    public const MAX_CHARACTERS = 30000;

    public const MAX_CSS_BYTES = 12288;

    private const TOKENS = '~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    // Stable semantic aliases across template versions and quoted documents.
    // Do not derive these from encounter order or alias unrelated authored CSS.
    private const CLASS_ALIASES = [
        'rt-shell' => 'x1', 'rt-pad' => 'x2', 'rt-native-compose-frame' => 'x3',
        'design-page-pad' => 'x4', 'rt-native-compose-inner' => 'x5',
        'rt-native-template-pad' => 'x6', 'rt-native-template-mark' => 'x7',
        'rt-outlook-template' => 'x8', 'design-v27' => 'x9', 'design-salutation' => 'xa',
        'design-spacer' => 'xb', 'design-greeting' => 'xc', 'rt-sign-content-frame' => 'xd',
        'rt-contact' => 'xe', 'rt-sign-name' => 'xf', 'rt-contact-text' => 'xg',
        'rt-contact-icon' => 'xh', 'rt-person-kopf' => 'xi', 'rt-company-contact' => 'xj',
        'rt-company-contact-text' => 'xk', 'rt-sign-cell' => 'xl', 'rt-sign-content' => 'xm',
        'rt-sign-ledger-content' => 'xn', 'rt-sign-ledger' => 'xo', 'rt-address-break' => 'xp',
        'rt-delivery-train-cell' => 'xq', 'rt-delivery-train' => 'xr',
        'rt-delivery-group-cell' => 'xs', 'rt-delivery-contact-value' => 'xt',
        'rt-ledger-brand' => 'xu', 'rt-ledger-direct' => 'xv', 'rt-ledger-company' => 'xw',
        'rt-delivery-wide-column' => 'xx', 'rtm' => 'xy', 'rt-outlook-signature' => 'xz',
        'rt-mobile-ledger' => 'x10', 'rt-sign-stage' => 'x11', 'rt-hotline-banner' => 'x12',
        'rt-ledger-contacts' => 'x13', 'rt-delivery-contacts' => 'x14',
        'rt-delivery-ledger-group' => 'x15', 'rt-company-contact-icon' => 'x16',
        'rt-delivery-train-row' => 'x17', 'rt-combined-compose-frame' => 'x18',
        'rt-combined-compose-cell' => 'x19', 'rt-sign-role' => 'x1a',
        'rt-native-train-overlay' => 'x1b',
    ];

    /** @return array{html:string,media:array,version:string} */
    public static function build(array $template, array $desktopSignature, array $mobileSignature): array
    {
        if (($template['composeDocumentMode'] ?? null) !== OutlookCombinedComposeDocument::MODE
            || ! is_string($template['composeHtml'] ?? null) || ! is_string($template['combinedComposeHtml'] ?? null)
            || ! is_string($desktopSignature['html'] ?? null) || ! is_string($mobileSignature['html'] ?? null)
            || ! is_array($template['composeMedia'] ?? null) || ! is_array($desktopSignature['media'] ?? null)
            || ($desktopSignature['media'] ?? null) !== ($mobileSignature['media'] ?? null)) {
            self::fail();
        }
        $desktop = OutlookCombinedComposeDocument::build($template['composeHtml'], $desktopSignature['html']);
        if ($desktop !== $template['combinedComposeHtml']) {
            self::fail();
        }
        $mobile = self::mobileFrame($mobileSignature['html']);
        [, $templateEnd] = self::rootRange($desktop, 'rt-outlook-template');
        [, $signatureEnd] = self::rootRange($desktop, 'rt-outlook-signature');
        if ($templateEnd >= $signatureEnd) {
            self::fail();
        }
        $joined = substr($desktop, 0, $templateEnd).$mobile.substr($desktop, $signatureEnd);
        $joined = self::mobileTemplateInset($joined);
        $joined = str_replace('data-rt-compose-document="combined-v1"', 'data-rt-compose-document="'.self::MODE.'"', $joined, $roots);
        if ($roots !== 1 || str_contains($joined, 'RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE')
            || str_contains($joined, self::VERSION_MARKER)) {
            self::fail();
        }
        $placeholder = self::VERSION_MARKER.str_repeat('0', 16);
        $metadata = '<!-- '.$placeholder.' --><span hidden aria-hidden="true" class="rt-office-metadata" '
            .'data-rt-mobile-compose-version="1" style="display:none!important;mso-hide:all;font-size:0;line-height:0;max-height:0;overflow:hidden;">'.$placeholder.'</span>';
        $joined = preg_replace_callback('~<td\b[^>]*class="rt-combined-compose-cell"[^>]*>~', static fn (array $match): string => $match[0].$metadata, $joined, -1, $cells);
        if ($cells !== 1) {
            self::fail();
        }
        $media = self::media($template['composeMedia'], $mobileSignature['media'], $joined);
        [$output, $map] = self::compactIdentifiers($joined);
        if (self::rewrite($output, array_flip($map)) !== $joined) {
            throw new RuntimeException('Die mobile Klassenprojektion ist nicht verlustfrei umkehrbar.');
        }
        $version = substr(hash('sha256', self::MODE."\0".$output."\0".json_encode($media, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 0, 16);
        $output = str_replace($placeholder, self::VERSION_MARKER.$version, $output, $markers);
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $output, $styles);
        if ($markers !== 2 || array_sum(array_map('strlen', $styles[1])) >= self::MAX_CSS_BYTES
            || intdiv(strlen(mb_convert_encoding($output, 'UTF-16LE', 'UTF-8')), 2) > self::MAX_CHARACTERS) {
            throw new RuntimeException('Die gemeinsame mobile Vorlage ueberschreitet das unveraenderte HTML-/CSS-Transportbudget.');
        }

        return ['html' => $output, 'media' => $media, 'version' => $version];
    }

    /** Project only the exact existing mobile frame border and its generated mirror. */
    private static function mobileFrame(string $html): string
    {
        [$start, $end, $opening] = self::rootRange($html, 'rt-outlook-signature');
        $outside = substr($html, 0, $start).substr($html, $end);
        if (trim(preg_replace('~<!--.*?-->|<style\b[^>]*>.*?</style\s*>~is', '', $outside)) !== '') {
            self::fail();
        }
        $root = self::attributes($opening);
        if (preg_match('/(?:^|\s)(m[0-9a-z]+)(?:\s|$)/', $root['class'] ?? '', $scope) !== 1
            || preg_match('/(?:^|\s)(rts[0-9a-f]{10})(?:\s|$)/', $root['class'] ?? '', $signatureScope) !== 1
            || $scope[1] !== 'm'.base_convert(substr($signatureScope[1], 3), 16, 36)
            || ! str_contains(' '.($root['class'] ?? '').' ', ' rt-mobile-ledger ')
            || ! str_contains(' '.($root['class'] ?? '').' ', ' rtm ')
            || substr_count($html, 'data-rt-artifact-version="v27"') !== 1
            || substr_count($html, 'RT-SIGNATURE-MANAGED-V1') !== 2) {
            self::fail();
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        foreach (['RT-SIGNATURE-MANAGED-V1', 'RT-SIGNATURE-VERSION:'] as $marker) {
            $metadata = $xpath->query('//span[starts-with(text(),"'.$marker.'")]');
            if ($metadata->length !== 1 || ! $metadata->item(0)->hasAttribute('hidden')
                || $metadata->item(0)->getAttribute('aria-hidden') !== 'true'
                || $metadata->item(0)->parentNode->nodeName !== 'td') {
                self::fail();
            }
        }
        $frames = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," rt-outlook-signature ")]/table');
        if (! $loaded || $frames->length !== 1) {
            self::fail();
        }
        $frame = $frames->item(0);
        $aliases = array_values(array_filter(preg_split('/\s+/', $frame->getAttribute('class')), static fn (string $class): bool => preg_match('/\Am[0-9a-z]+\z/', $class) === 1));
        if (count($aliases) !== 1 || $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$aliases[0].' ")]')->length !== 1) {
            self::fail();
        }
        $inline = $frame->getAttribute('style');
        if (substr_count($inline, 'border-left:6px solid #e90032;') !== 1) {
            self::fail();
        }
        $declarations = trim(preg_replace('/\s*!important\b/i', '', $inline), "; \t\r\n");
        $rule = '.'.$scope[1].'.rtm .'.$aliases[0].'{'.str_replace(';', '!important;', $declarations).'!important;}';
        $html = self::replaceStyleRule($html, 'data-rt-outlook-mobile-css', $rule, str_replace('border-left:6px solid #e90032!important;', 'border-left:0!important;', $rule));
        $html = preg_replace_callback('~<table\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is', static function (array $match) use ($aliases): string {
            $attrs = self::attributes($match[0]);
            if (! in_array($aliases[0], preg_split('/\s+/', $attrs['class'] ?? ''), true)) {
                return $match[0];
            }

            return str_replace('border-left:6px solid #e90032;', 'border-left:0;', $match[0]);
        }, $html);

        return $html;
    }

    private static function mobileTemplateInset(string $html): string
    {
        $changed = 0;
        $html = preg_replace_callback(self::TOKENS, static function (array $match) use (&$changed): string {
            $tag = $match[0];
            if (! preg_match('~\A<td\b~i', $tag)) {
                return $tag;
            }
            $attrs = self::attributes($tag);
            if (! in_array('rt-native-template-pad', preg_split('/\s+/', $attrs['class'] ?? ''), true)) {
                return $tag;
            }
            if (substr_count($attrs['style'] ?? '', 'padding-left:40px;padding-right:40px;') !== 1) {
                self::fail();
            }
            $changed++;

            return str_replace('padding-left:40px;padding-right:40px;', 'padding-left:18px;padding-right:18px;', $tag);
        }, $html);
        if ($changed !== 2 || preg_match('/(?:^|\s)(rtt[0-9a-f]{12})(?:\s|$)/', self::attributes(self::rootRange($html, 'rt-outlook-template')[2])['class'], $scope) !== 1) {
            self::fail();
        }
        foreach (['40', '22'] as $padding) {
            $prefix = $padding === '40' ? '.'.$scope[1].' .rt-native-template-pad{' : '@media(max-width:860px){.'.$scope[1].' .rt-native-template-pad{';
            $old = $prefix.'padding-left:'.$padding.'px!important;padding-right:'.$padding.'px!important;}';
            $html = self::replaceStyleRule($html, 'data-rt-outlook-template-css', $old, $prefix.'padding-left:18px!important;padding-right:18px!important;}');
        }

        return $html;
    }

    private static function replaceStyleRule(string $html, string $attribute, string $old, string $new): string
    {
        $html = preg_replace_callback('~<style '.$attribute.'="1">(.*?)</style>~s', static function (array $match) use ($old, $new): string {
            if (substr_count($match[1], $old) !== 1) {
                self::fail();
            }

            return str_replace($old, $new, $match[0]);
        }, $html, -1, $count);
        if ($count !== 1) {
            self::fail();
        }

        return $html;
    }

    /** Bijective identifiers only: no declarations, inline fallback or rules removed. */
    private static function compactIdentifiers(string $html): array
    {
        $classes = [];
        $protected = ['rt-office-metadata' => true, 'rt-combined-compose-document' => true];
        $scopeClasses = [];
        foreach (['rt-combined-compose-document', 'rt-outlook-template', 'rt-outlook-signature'] as $rootClass) {
            $root = self::attributes(self::rootRange($html, $rootClass)[2]);
            foreach (preg_split('/\s+/', $root['class'] ?? '') as $class) {
                if (preg_match('/\A(?:rts[0-9a-f]{10}|rtt[0-9a-f]{12}|rtc[0-9a-f]{12}|m[0-9a-z]{6,})\z/', $class)) {
                    $scopeClasses[$class] = true;
                }
            }
        }
        preg_match_all(self::TOKENS, $html, $tokens);
        foreach ($tokens[0] as $token) {
            if (str_starts_with($token, '<!--')) {
                preg_match_all('~\bclass\s*=\s*(["\'])(.*?)\1~s', $token, $comments);
                foreach ($comments[2] as $value) {
                    foreach (preg_split('/\s+/', trim(CssSemantic::decodeHtmlEntitiesOnce($value))) as $class) {
                        $protected[$class] = true;
                    }
                }
            } elseif (preg_match('~\A<style\b~i', $token)) {
                preg_match('~\A(<style\b[^>]*>)(.*)(</style\s*>)\z~is', $token, $parts);
                self::css($parts[2], [], $classes, $protected);
            } elseif (! str_starts_with($token, '</')) {
                foreach (preg_split('/\s+/', trim(self::attributes($token)['class'] ?? '')) as $class) {
                    if ($class !== '') {
                        self::identifier($class);
                        $classes[$class] = true;
                    }
                }
            }
        }
        foreach (array_keys($classes) as $class) {
            if (preg_match('/\Arts[0-9a-f]{10}vm\z/', $class)) {
                $protected[$class] = true;
            }
        }
        // Global metadata/client selectors stay named, rather than acquiring a
        // short alias capable of colliding with a quoted historical document.
        foreach ($tokens[0] as $token) {
            if (preg_match('~\A<style\b[^>]*>(.*?)</style\s*>\z~is', $token, $style)) {
                self::css($style[1], [], $classes, $protected, $scopeClasses);
            }
        }
        $map = [];
        $used = $classes + $protected;
        foreach (array_keys($classes) as $class) {
            if (isset($protected[$class])) {
                continue;
            }
            if (isset($scopeClasses[$class]) && preg_match('/\A(rts|rtt|rtc)([0-9a-f]+)\z/', $class, $scope)) {
                $short = $scope[1][2].base_convert($scope[2], 16, 36);
                if (isset($used[$short]) || str_pad(base_convert(substr($short, 1), 36, 16), strlen($scope[2]), '0', STR_PAD_LEFT) !== $scope[2]) {
                    self::fail();
                }
            } elseif (isset($scopeClasses[$class])) {
                continue;
            } else {
                $short = self::CLASS_ALIASES[$class] ?? null;
                if ($short === null) {
                    continue;
                }
                if (isset($used[$short])) {
                    self::fail();
                }
            }
            $used[$short] = true;
            $map[$class] = $short;
        }

        return [self::rewrite($html, $map), $map];
    }

    private static function rewrite(string $html, array $map): string
    {
        return preg_replace_callback(self::TOKENS, static function (array $match) use ($map): string {
            $token = $match[0];
            if (str_starts_with($token, '<!--') || str_starts_with($token, '</')) {
                return $token;
            }
            if (preg_match('~\A(<style\b[^>]*>)(.*)(</style\s*>)\z~is', $token, $style)) {
                $classes = $protected = [];

                return $style[1].self::css($style[2], $map, $classes, $protected).$style[3];
            }
            self::attributes($token);

            return preg_replace_callback('~(\sclass\s*=\s*)(["\'])(.*?)\2~is', static fn (array $attr): string => $attr[1].$attr[2]
                .preg_replace_callback('/[A-Za-z_][A-Za-z0-9_-]*/', static fn (array $name): string => $map[$name[0]] ?? $name[0], $attr[3]).$attr[2], $token);
        }, $html);
    }

    /** Strict CSS blocks; class tokens are rewritten in selector preludes ONLY. */
    private static function css(string $css, array $map, array &$classes, array &$protected, ?array $scopes = null): string
    {
        $output = '';
        $offset = 0;
        $length = strlen($css);
        while ($offset < $length) {
            $start = $offset;
            self::trivia($css, $offset);
            $output .= substr($css, $start, $offset - $start);
            if ($offset === $length) {
                break;
            }
            $opening = strpos($css, '{', $offset);
            if ($opening === false) {
                self::fail();
            }
            $prelude = substr($css, $offset, $opening - $offset);
            $closing = self::closingBrace($css, $opening);
            $body = substr($css, $opening + 1, $closing - $opening - 1);
            if (str_starts_with(ltrim($prelude), '@')) {
                if (preg_match('/\A@media[ A-Za-z0-9():.,%\t\r\n-]*\z/', trim($prelude)) !== 1) {
                    self::fail();
                }
                $output .= $prelude.'{'.self::css($body, $map, $classes, $protected, $scopes).'}';
            } else {
                if (preg_match('/[{}]/', self::withoutStringsAndComments($body)) || preg_match('/\battr\s*\(\s*class\b/i', $body)) {
                    self::fail();
                }
                $selectors = explode(',', $prelude);
                $rewritten = [];
                foreach ($selectors as $selector) {
                    $found = [];
                    $rewritten[] = self::selector($selector, $map, $found);
                    foreach ($found as $class) {
                        $classes[$class] = true;
                    }
                    if ($scopes !== null && array_intersect($found, array_keys($scopes)) === []) {
                        foreach ($found as $class) {
                            $protected[$class] = true;
                        }
                    }
                }
                $output .= implode(',', $rewritten).'{'.$body.'}';
            }
            $offset = $closing + 1;
        }

        return $output;
    }

    private static function selector(string $selector, array $map, array &$classes): string
    {
        $offset = 0;
        $output = '';
        while ($offset < strlen($selector)) {
            $start = $offset;
            self::trivia($selector, $offset);
            $output .= substr($selector, $start, $offset - $start);
            if ($offset === strlen($selector)) {
                break;
            }
            if (preg_match('/\G\.([A-Za-z_][A-Za-z0-9_-]*)/', $selector, $match, 0, $offset)) {
                $classes[] = $match[1];
                $output .= '.'.($map[$match[1]] ?? $match[1]);
            } elseif (preg_match('/\G(?:[A-Za-z_][A-Za-z0-9_-]*|\*|>|:(?:hover|focus|active|visited|link|root))/', $selector, $match, 0, $offset)) {
                $output .= $match[0];
            } else {
                // Escaped classes, attribute tests, functions/IDs and sibling
                // contexts need another proven grammar, not substring replacement.
                self::fail();
            }
            $offset += strlen($match[0]);
        }

        return $output;
    }

    private static function trivia(string $value, int &$offset): void
    {
        while ($offset < strlen($value)) {
            if (ctype_space($value[$offset])) {
                $offset++;
            } elseif (substr($value, $offset, 2) === '/*') {
                $end = strpos($value, '*/', $offset + 2);
                if ($end === false) {
                    self::fail();
                }
                $offset = $end + 2;
            } else {
                break;
            }
        }
    }

    private static function closingBrace(string $css, int $opening): int
    {
        $depth = 0;
        $quote = null;
        for ($offset = $opening; $offset < strlen($css); $offset++) {
            $char = $css[$offset];
            if ($quote !== null) {
                if ($char === '\\') {
                    $offset++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif (substr($css, $offset, 2) === '/*') {
                $end = strpos($css, '*/', $offset + 2);
                if ($end === false) {
                    self::fail();
                }
                $offset = $end + 1;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return $offset;
            }
        }
        self::fail();
    }

    private static function withoutStringsAndComments(string $value): string
    {
        return preg_replace('~"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|/\*.*?\*/~s', '', $value);
    }

    /** Strict offset tree/range; opaque comments/styles never enter the tree. */
    private static function rootRange(string $html, string $class): array
    {
        preg_match_all(self::TOKENS, $html, $tokens, PREG_OFFSET_CAPTURE);
        $stack = [];
        $ranges = [];
        foreach ($tokens[0] as [$tag, $offset]) {
            if (str_starts_with($tag, '<!--') || preg_match('~\A<style\b~i', $tag)) {
                continue;
            }
            preg_match('~\A<(/?)([a-z][a-z0-9:-]*)\b~i', $tag, $name);
            $element = strtolower($name[2]);
            if ($name[1] === '') {
                $attrs = self::attributes($tag);
                if (in_array($element, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true)) {
                    continue;
                }
                if (preg_match('~/\s*>\z~', $tag)) {
                    self::fail();
                }
                $stack[] = ['tag' => $element, 'start' => $offset, 'opening' => $tag, 'target' => $element === 'div' && in_array($class, preg_split('/\s+/', trim($attrs['class'] ?? '')), true)];
            } else {
                $node = array_pop($stack);
                if ($node === null || $node['tag'] !== $element || preg_match('~\A</[a-z][a-z0-9:-]*\s*>\z~i', $tag) !== 1) {
                    self::fail();
                }
                if ($node['target']) {
                    $ranges[] = [$node['start'], $offset + strlen($tag), $node['opening']];
                }
            }
        }
        if ($stack !== [] || count($ranges) !== 1) {
            self::fail();
        }

        return $ranges[0];
    }

    private static function attributes(string $tag): array
    {
        preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $tag, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attrs = [];
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            if (isset($attrs[$key]) || (in_array($key, ['class', 'style'], true) && $match[4] !== null)) {
                self::fail();
            }
            $raw = $match[2] ?? $match[3] ?? $match[4] ?? '';
            $attrs[$key] = CssSemantic::decodeHtmlEntitiesOnce($raw);
            if ($key === 'class' && $raw !== $attrs[$key]) {
                // Raw lexical rewriting must never diverge from the DOM's
                // entity-decoded class predicate, despite an inverse-byte proof.
                self::fail();
            }
        }

        return $attrs;
    }

    private static function identifier(string $value): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/', $value) !== 1) {
            self::fail();
        }
    }

    private static function media(array $template, array $signature, string $html): array
    {
        $union = [];
        $bytes = 0;
        foreach (array_merge($template, $signature) as $item) {
            $cid = $item['contentId'] ?? null;
            $encoded = $item['base64'] ?? null;
            if (! is_string($cid) || ($item['name'] ?? null) !== $cid || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._@-]{0,191}\z/', $cid) !== 1
                || ! is_string($encoded) || preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/', $encoded) !== 1 || strlen($encoded) % 4 !== 0
                || ($binary = base64_decode($encoded, true)) === false || ($image = getimagesizefromstring($binary)) === false
                || (['png' => 'image/png', 'gif' => 'image/gif', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][strtolower(pathinfo($cid, PATHINFO_EXTENSION))] ?? null) !== $image['mime']) {
                self::fail();
            }
            if (isset($union[$cid])) {
                if ($union[$cid] !== $item) {
                    self::fail();
                }

                continue;
            }
            $bytes += strlen($binary);
            $union[$cid] = $item;
        }
        preg_match_all('~\bsrc\s*=\s*(["\'])(.*?)\1~is', $html, $images);
        $referenced = [];
        foreach ($images[2] as $source) {
            $source = CssSemantic::decodeHtmlEntitiesOnce($source);
            if (! str_starts_with($source, 'cid:') || ! isset($union[substr($source, 4)])) {
                self::fail();
            }
            $referenced[substr($source, 4)] = true;
        }
        if ($bytes > 2097152 || $union === [] || array_diff_key($union, $referenced) !== []) {
            self::fail();
        }

        return array_values($union);
    }

    private static function fail(): never
    {
        throw new RuntimeException('Die gemeinsame mobile Outlook-Vorlage besitzt keinen eindeutigen freigegebenen Projektionsvertrag.');
    }
}
