<?php

declare(strict_types=1);

namespace App\Support\Mail;

use RuntimeException;

/** A source-selected personal view; the saved company ledger stays canonical. */
final class SignaturePersonalColumns
{
    public const SOURCE_CLASS = 'design-personal-columns';

    public const ATTRIBUTE = 'data-rt-personal-columns';

    public const MARKER = 'v1';

    private const TOKENS = '~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    public static function requested(string $html): bool
    {
        if (SignatureArtifactVersion::detect('signature', $html) !== 'v27' || ! str_contains($html, self::SOURCE_CLASS)) {
            return false;
        }
        $matches = array_filter(self::nodes($html), static fn (array $node): bool => $node['tag'] === 'table'
            && self::has($node, 'rt-sign-ledger') && self::has($node, 'design-train-logo') && self::has($node, self::SOURCE_CLASS));

        return count($matches) === 1;
    }

    public static function applies(string $html): bool
    {
        return str_contains($html, self::ATTRIBUTE.'="'.self::MARKER.'"');
    }

    public static function project(string $html): string
    {
        if (str_contains($html, self::ATTRIBUTE) && ! self::applies($html)) {
            self::fail();
        }
        if (self::applies($html)) {
            self::assertRuntime($html);

            return $html;
        }
        if (! self::requested($html)) {
            return $html;
        }
        SignatureTableOverlapDelivery::assertRuntime($html);
        $nodes = self::nodes($html);
        $ledger = self::one($nodes, 'rt-sign-ledger', 'table');
        $brand = self::one($nodes, 'rt-ledger-brand', 'th');
        $heading = self::one($nodes, 'rt-delivery-brand-heading', 'td');
        $contacts = self::one($nodes, 'rt-ledger-contacts', 'th');
        $contactTable = self::one($nodes, 'rt-delivery-contacts', 'table');
        $person = self::one($nodes, 'rt-person-kopf', 'div');
        $direct = self::one($nodes, 'rt-ledger-direct', 'td');
        $company = self::one($nodes, 'rt-ledger-company', 'td');
        $headingTable = $nodes[$heading]['parent'];
        while ($headingTable !== null && $nodes[$headingTable]['tag'] !== 'table') {
            $headingTable = $nodes[$headingTable]['parent'];
        }
        $wrapper = $person;
        while ($nodes[$wrapper]['parent'] !== $heading) {
            $wrapper = $nodes[$wrapper]['parent'] ?? self::fail();
        }
        // Do not discard plain notes or an unfamiliar wrapper outside the two
        // known groups. Such documents retain their complete previous view.
        if ($headingTable === null || trim(self::inner($html, $nodes[$brand])) !== trim(self::whole($html, $nodes[$headingTable]))
            || trim(self::inner($html, $nodes[$contacts])) !== trim(self::whole($html, $nodes[$contactTable]))
            || trim(substr($html, $nodes[$wrapper]['end'], $nodes[$heading]['close'] - $nodes[$wrapper]['end'])) !== '') {
            return $html;
        }
        $logo = substr($html, $nodes[$heading]['openEnd'], $nodes[$wrapper]['start'] - $nodes[$heading]['openEnd']);
        if (! preg_match('~<img\b[^>]*\bclass="[^"]*\brt-logo\b~', $logo)) {
            return $html;
        }
        $logo = self::personalLogo($logo);
        $personHtml = self::whole($html, $nodes[$wrapper]);
        if ($wrapper !== $person && trim(self::inner($html, $nodes[$wrapper])) === trim(self::whole($html, $nodes[$person]))
            && preg_replace('/\s+/', '', $nodes[$wrapper]['attrs']['style'] ?? '') === 'padding-top:13px;') {
            $personHtml = self::whole($html, $nodes[$person]);
        }
        $left = '<div class="rt-personal-name">'.self::presentation($personHtml).'</div>'
            .'<div class="rt-personal-direct" style="padding-top:14px;">'.self::presentation(self::inner($html, $nodes[$direct])).'</div>';
        $right = '<table class="rt-personal-company-block" role="presentation" width="220" align="right" border="0" cellspacing="0" cellpadding="0" style="width:220px;max-width:100%;margin:0 0 0 auto;border-collapse:collapse;"><tbody><tr><td style="padding:0;font-size:13px;line-height:18px;text-align:left;">'
            .$logo.'<div class="rt-personal-company-contacts" style="padding-top:14px;">'.self::presentation(self::inner($html, $nodes[$company])).'</div></td></tr></tbody></table>';
        $flow = '<div class="rt-sign-ledger design-train-logo '.self::SOURCE_CLASS.' rt-personal-columns" '.self::ATTRIBUTE.'="'.self::MARKER.'" style="font-size:0;line-height:0;text-align:left;">'
            .self::column('left', '0 40px', $left)
            .self::column('right', '0 40px', $right).'</div>';
        $before = self::inventory($html);
        $out = substr_replace($html, $flow, $nodes[$ledger]['start'], $nodes[$ledger]['end'] - $nodes[$ledger]['start']);
        self::assertRuntime($out);
        if ($before !== self::inventory($out)) {
            self::fail();
        }

        return $out;
    }

    public static function assertRuntime(string $html): void
    {
        if (! self::applies($html) || SignatureArtifactVersion::detect('signature', $html) !== 'v27') {
            self::fail();
        }
        $nodes = self::nodes($html);
        $flow = self::one($nodes, 'rt-personal-columns', 'div');
        $left = self::one($nodes, 'rt-personal-left', 'table');
        $right = self::one($nodes, 'rt-personal-right', 'table');
        $person = self::one($nodes, 'rt-person-kopf', 'div');
        $logos = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'img' && (self::has($node, 'rt-logo') || self::has($node, 'rt-client-logo'))));
        $logo = count($logos) === 1 ? $logos[0] : self::fail();
        $content = self::one($nodes, 'rt-sign-content', 'td');
        if (($nodes[$flow]['attrs'][self::ATTRIBUTE] ?? '') !== self::MARKER
            || $nodes[$flow]['parent'] !== $content || $nodes[$left]['parent'] !== $flow || $nodes[$right]['parent'] !== $flow
            || ! self::within($nodes, $person, $left) || ! self::within($nodes, $logo, $right)) {
            self::fail();
        }
        $groups = array_keys(array_filter($nodes, static fn (array $node): bool => $node['parent'] === $flow));
        if ($groups !== [$left, $right]) {
            self::fail();
        }
        foreach (['rt-personal-direct' => $left, 'rt-personal-company-contacts' => $right] as $class => $parent) {
            $group = self::one($nodes, $class, 'div');
            if (! self::within($nodes, $group, $parent)) {
                self::fail();
            }
        }
        $contacts = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'table' && self::has($node, 'rt-contact')));
        if (count($contacts) !== 2 || ! self::within($nodes, $contacts[0], $left) || ! self::within($nodes, $contacts[1], $right)) {
            self::fail();
        }
    }

    public static function css(string $html): string
    {
        if (self::applies($html)) {
            self::assertRuntime($html);
        } elseif (! self::requested($html)) {
            self::fail();
        }
        $p = 'tr[data-rt-artifact-version="v27"] ';

        return $p.'.rt-personal-columns{font-size:0!important;line-height:0!important;text-align:left!important;}'
            .$p.'.rt-personal-column{display:inline-table!important;vertical-align:top!important;width:50%!important;min-width:280px!important;min-width:min(280px,100%)!important;table-layout:fixed!important;border-collapse:collapse!important;}'
            .$p.'.rt-personal-cell-left{padding:0 40px!important;font-size:13px!important;line-height:18px!important;vertical-align:top!important;}'
            .$p.'.rt-personal-cell-right{padding:0 40px!important;font-size:13px!important;line-height:18px!important;vertical-align:top!important;}'
            .$p.'.rt-personal-left .rt-sign-name{font-size:22px!important;line-height:26px!important;}'
            .$p.'.rt-personal-role{font-size:10px!important;line-height:16px!important;}'
            .$p.'table.rt-personal-company-block img.rt-logo{width:200px!important;max-width:100%!important;height:auto!important;}'
            .$p.'table.rt-personal-logo-frame{width:200px!important;max-width:100%!important;height:auto!important;}'
            .$p.'.rt-personal-direct,'.$p.'.rt-personal-company-contacts{padding-top:14px!important;}'
            .$p.'.rt-personal-column td.rt-contact-text{line-height:18px!important;word-break:normal!important;overflow-wrap:anywhere!important;}'
            .'@media only screen and (max-width:560px){'.$p.'.rt-personal-column{width:100%!important;min-width:0!important;}'
            .$p.'.rt-personal-cell-left{padding:0 22px!important;}'.$p.'.rt-personal-cell-right{padding:24px 22px 0!important;}'
            .$p.'.rt-personal-company-block{float:none!important;margin:0!important;}}';
    }

    /** Exact compiler wrappers already have complete important runtime rules. */
    public static function ownsInlineStyle(array $classes, string $style): bool
    {
        $known = [
            'rt-personal-columns' => 'font-size:0;line-height:0;text-align:left',
            'rt-personal-column' => 'display:inline-table;vertical-align:top;width:50%;min-width:280px;min-width:min(280px,100%);table-layout:fixed;border-collapse:collapse',
            'rt-personal-cell-left' => 'padding:0 40px;font-size:13px;line-height:18px;vertical-align:top',
            'rt-personal-cell-right' => 'padding:0 40px;font-size:13px;line-height:18px;vertical-align:top',
        ];
        foreach ($known as $class => $declarations) {
            if (in_array($class, $classes, true) && rtrim($style, ';') === $declarations) {
                return true;
            }
        }

        return false;
    }

    private static function column(string $side, string $padding, string $contents): string
    {
        return '<table class="rt-personal-column rt-personal-'.$side.'" role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="display:inline-table;vertical-align:top;width:50%;min-width:280px;min-width:min(280px,100%);table-layout:fixed;border-collapse:collapse;"><tbody><tr><td class="rt-personal-cell-'.$side.'" style="padding:'.$padding.';font-size:13px;line-height:18px;vertical-align:top;">'.$contents.'</td></tr></tbody></table>';
    }

    private static function personalLogo(string $html): string
    {
        $nodes = self::nodes($html);
        $logo = self::one($nodes, 'rt-logo', 'img');
        $carrier = $nodes[$logo]['parent'];
        while ($carrier !== null && $nodes[$carrier]['tag'] !== 'table') {
            $carrier = $nodes[$carrier]['parent'];
        }
        if ($carrier === null) {
            return $html;
        }
        $node = $nodes[$carrier];
        $opening = substr($html, $node['start'], $node['openEnd'] - $node['start']);
        $style = preg_replace('/(?:^|;)\s*(?:(?:min-|max-)?(?:width|height))\s*:[^;]*/i', '', $node['attrs']['style'] ?? '');
        foreach (['class' => trim(($node['attrs']['class'] ?? '').' rt-personal-logo-frame'), 'width' => '200', 'style' => trim($style, ';').';width:200px;max-width:100%;height:auto;'] as $name => $value) {
            $attribute = ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            $opening = preg_replace('~\s+'.preg_quote($name, '~').'\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i', addcslashes($attribute, '\\$'), $opening, -1, $count);
            if ($count === 0) {
                $opening = substr($opening, 0, -1).$attribute.'>';
            }
        }

        return substr_replace($html, $opening, $node['start'], $node['openEnd'] - $node['start']);
    }

    private static function presentation(string $html): string
    {
        return preg_replace_callback(self::TOKENS, static function (array $match): string {
            $tag = $match[0];
            if (str_starts_with($tag, '<!--') || ! preg_match('~\A<(?:p|td)\b~i', $tag)
                || ! preg_match('~\sstyle="([^"]*)"~', $tag, $styles)) {
                return $tag;
            }
            $style = $styles[1];
            if (preg_match('~\bclass="[^"]*\brt-sign-name\b~', $tag)) {
                $style = preg_replace('/(?:^|;)\s*(?:font-size|line-height)\s*:[^;]*/i', '', $style);
                $style = rtrim($style, ';').';font-size:22px;line-height:26px;';
            } elseif (preg_match('~\A<p\b~i', $tag) && str_contains($style, 'text-transform:uppercase')) {
                $style = preg_replace('/(?:^|;)\s*(?:font-size|line-height)\s*:[^;]*/i', '', $style);
                $style = rtrim($style, ';').';font-size:10px;line-height:16px;';
                if (preg_match('~\sclass="([^"]*)"~', $tag, $class)) {
                    $tag = str_replace($class[0], ' class="'.trim($class[1].' rt-personal-role').'"', $tag);
                } else {
                    $tag = preg_replace('~\A<p\b~i', '<p class="rt-personal-role"', $tag, 1);
                }
            } elseif (preg_match('~\bclass="[^"]*\brt-contact-(?:text|icon)\b~', $tag)) {
                $style = preg_replace('/(?:^|;)padding:0 0 8px( 9px)?(?=;|$)/', ';padding:0 0 4px$1', $style);
                $style = str_replace('line-height:20px', 'line-height:18px', $style);
                if (str_contains($tag, 'rt-contact-text')) {
                    $style = preg_replace('/(?:^|;)\s*(?:word-break|overflow-wrap)\s*:[^;]*/i', '', $style);
                    $style = rtrim($style, ';').';word-break:normal;overflow-wrap:anywhere;';
                }
            } else {
                return $tag;
            }

            return str_replace($styles[0], ' style="'.ltrim($style, ';').'"', $tag);
        }, $html);
    }

    private static function inventory(string $html): array
    {
        preg_match_all('~<img\b[^>]*>~i', $html, $images);
        preg_match_all('~<a\b[^>]*>.*?</a>~is', $html, $links);
        $text = preg_replace('~<!--.*?-->|<style\b[^>]*>.*?</style>~is', '', $html);

        sort($images[0]);
        sort($links[0]);

        return [$images[0], $links[0], preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
    }

    private static function has(array $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($node['attrs']['class'] ?? '')), true);
    }

    private static function one(array $nodes, string $class, string $tag): int
    {
        $found = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === $tag && self::has($node, $class)));

        return count($found) === 1 ? $found[0] : self::fail();
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

    private static function whole(string $html, array $node): string
    {
        return substr($html, $node['start'], $node['end'] - $node['start']);
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
            preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $token, $attributes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            $attrs = [];
            foreach ($attributes as $attribute) {
                $key = strtolower($attribute[1]);
                if (array_key_exists($key, $attrs)) {
                    self::fail();
                }
                $attrs[$key] = CssSemantic::decodeHtmlEntitiesOnce($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '');
            }
            $void = in_array($tag, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true);
            $nodes[] = ['tag' => $tag, 'attrs' => $attrs, 'parent' => $stack === [] ? null : $stack[array_key_last($stack)], 'start' => $offset,
                'openEnd' => $offset + strlen($token), 'close' => $void ? $offset + strlen($token) : null, 'end' => $void ? $offset + strlen($token) : null];
            if (! $void) {
                $stack[] = array_key_last($nodes);
            }
        }
        if ($stack !== []) {
            self::fail();
        }

        return $nodes;
    }

    private static function fail(): never
    {
        throw new RuntimeException('Die persoenlichen Signaturspalten besitzen keinen eindeutigen Inhaltsvertrag.');
    }
}
