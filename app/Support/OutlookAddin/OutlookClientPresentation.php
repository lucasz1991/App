<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;
use App\Support\Mail\EmailClientStyleBlocks;
use App\Support\Mail\SignatureTableOverlapDelivery;
use RuntimeException;

/** Final wire presentation, after every canonical inverse/mobile/combined check. */
final class OutlookClientPresentation
{
    public const REVISION = 'outlook-client-presentation-v1';

    private const SIGNATURE_VERSION = 'RT-SIGNATURE-VERSION:';

    private const TOKENS = '~<!--.*?-->|<style\b[^>]*>.*?</style\s*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is';

    public static function payload(array $payload): array
    {
        $desktopFlow = ! OutlookDesktopFlow::mobile($payload['signature']['html']);
        $payload['signature']['html'] = self::document($payload['signature']['html'], 30000, 12288, $desktopFlow);
        $payload['version']['signature'] = self::version('signature', $payload['version']['signature']);
        if (isset($payload['template']['html'])) {
            $payload['template']['html'] = self::document($payload['template']['html'], 99000, 24576, $desktopFlow);
        }
        foreach ($payload['templates'] as &$template) {
            foreach (['html', 'composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                if (isset($template[$field])) {
                    $mobile = $field === 'mobileComposeHtml';
                    $template[$field] = self::document($template[$field], $mobile ? 30000 : 99000, $mobile ? 12288 : 24576, $desktopFlow);
                }
            }
            if (isset($template['signature'])) {
                $template['signature']['html'] = self::document($template['signature']['html'], 30000, 12288, $desktopFlow);
                $template['signatureVersion'] = self::version('signature', $template['signatureVersion']);
            }
            if (isset($template['mobileComposeVersion'])) {
                $template['mobileComposeVersion'] = self::version('mobile-compose', $template['mobileComposeVersion']);
            }
        }
        unset($template);

        return $payload;
    }

    private static function version(string $kind, string $version): string
    {
        if (preg_match('/\A[0-9a-f]{16}\z/', $version) !== 1) {
            throw new RuntimeException('Die finale Outlook-Darstellung besitzt keine gueltige Artefaktversion.');
        }

        // Canonical IDs already bind source bytes/profile/media. Mapping them
        // preserves desktop/mobile distinctions after the reversible compilers.
        return substr(hash('sha256', self::REVISION."\0".$kind."\0".$version), 0, 16);
    }

    private static function document(string $html, int $maxCharacters, int $maxCss, bool $desktopFlow = true): string
    {
        $bounded = str_contains($html, 'data-rt-train-delivery="'.SignatureTableOverlapDelivery::MARKER.'"');
        // Advanced authored selectors can observe the old class negatively or
        // through exact attribute values. Keep that author's logo contract;
        // independent media blocks and URL protection still apply normally.
        $authoredLogoObserver = preg_match('~<style\b[^>]*data-rt-mail-document-css=(["\'])signature\1[^>]*>(.*?)</style\s*>~is', $html, $authored) === 1
            && (str_contains($authored[2], '\\') || preg_match('~:(?:not|has|is|where)\s*\([^{}]*\.rt-logo|\[\s*class[^{}]*rt-logo~is', $authored[2]) === 1);
        if ($bounded && ! $authoredLogoObserver) {
            $tables = [];
            $html = preg_replace_callback(self::TOKENS, static function (array $match) use (&$tables): string {
                $tag = $match[0];
                if (str_starts_with($tag, '<!--')) {
                    return $tag;
                }
                if (preg_match('~\A<style\b~i', $tag)) {
                    // Preserve generic fixed legacy rules. Only the bounded
                    // ledger selectors follow the renamed output-only image.
                    return self::logoStyles($tag);
                }
                if (preg_match('~\A</table\s*>~i', $tag)) {
                    array_pop($tables);
                } elseif (preg_match('~\A<table\b~i', $tag)) {
                    $classes = self::classes($tag);
                    $tables[] = in_array('rt-sign-ledger', $classes, true) || in_array('xo', $classes, true) || in_array(true, $tables, true);
                } elseif (preg_match('~\A<img\b~i', $tag) && in_array(true, $tables, true)) {
                    $tag = preg_replace_callback('~(\sclass\s*=\s*)(["\'])(.*?)\2~is', static fn (array $attribute): string => $attribute[1].$attribute[2]
                        .preg_replace('/(^|\s)rt-logo(?=\s|$)/', '$1rt-client-logo', $attribute[3]).$attribute[2], $tag) ?? $tag;
                }

                return $tag;
            }, $html) ?? $html;
        }
        if ($desktopFlow) {
            $html = OutlookDesktopFlow::project($html);
            $html = OutlookCombinedComposeDocument::stabilizeTableScopes($html);
        }
        $html = self::wrapUrls($html);
        $html = EmailClientStyleBlocks::project($html, static fn (string $opening, string $css): bool => preg_match('~\bdata-rt-outlook-(?:signature|template)-css=["\']1["\']~', $opening) === 1);
        $html = preg_replace_callback('/'.self::SIGNATURE_VERSION.'([0-9a-f]{16})/', static fn (array $marker): string => self::SIGNATURE_VERSION.self::version('signature', $marker[1]), $html) ?? $html;
        $html = preg_replace_callback('/'.OutlookMobileCombinedComposeDocument::VERSION_MARKER.'([0-9a-f]{16})/', static fn (array $marker): string => OutlookMobileCombinedComposeDocument::VERSION_MARKER.self::version('mobile-compose', $marker[1]), $html) ?? $html;
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        if (array_sum(array_map('strlen', $styles[1])) >= $maxCss
            || intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) > $maxCharacters) {
            throw new RuntimeException('Die finale Outlook-Darstellung ueberschreitet ihr unveraendertes Transportbudget.');
        }

        return $html;
    }

    /** @return list<string> */
    private static function classes(string $tag): array
    {
        preg_match('~\sclass\s*=\s*(["\'])(.*?)\1~is', $tag, $match);

        return preg_split('/\s+/', trim(CssSemantic::decodeHtmlEntitiesOnce($match[2] ?? ''))) ?: [];
    }

    private static function wrapUrls(string $html): string
    {
        $paragraph = null;
        $edits = [];
        $class = 'rtu'.substr(hash('sha256', $html), 0, 10);
        preg_match_all('~<!--.*?-->|<style\b[^>]*>.*?</style\s*>|</?p\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>|<a\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</a\s*>~is', $html, $tokens, PREG_OFFSET_CAPTURE);
        foreach ($tokens[0] as [$token, $offset]) {
            if (preg_match('~\A<p\b~i', $token)) {
                $paragraph = [$offset, $token];
            } elseif (preg_match('~\A</p\b~i', $token)) {
                $paragraph = null;
            } elseif (preg_match('~\A(<a\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>)(.*?)</a\s*>\z~is', $token, $anchor)
                && preg_match('~^https?://\S+$~u', trim(CssSemantic::decodeHtmlEntitiesOnce(strip_tags($anchor[2])))) === 1) {
                $edits[$offset] = [$anchor[1], self::wrapStyle(self::withClass($anchor[1], $class))];
                if ($paragraph !== null) {
                    $edits[$paragraph[0]] = [$paragraph[1], self::wrapStyle(self::withClass($paragraph[1], $class))];
                }
            }
        }
        krsort($edits);
        foreach ($edits as $offset => [$before, $after]) {
            $html = substr_replace($html, $after, $offset, strlen($before));
        }

        return $edits === [] ? $html : '<style data-rt-client-url-css="1">.'.$class.'{word-break:break-all!important;overflow-wrap:anywhere!important;}</style>'.$html;
    }

    private static function withClass(string $opening, string $class): string
    {
        if (preg_match('~\sclass\s*=\s*(["\'])(.*?)\1~is', $opening)) {
            return preg_replace_callback('~(\sclass\s*=\s*)(["\'])(.*?)\2~is', static fn (array $attribute): string => $attribute[1].$attribute[2].$attribute[3].' '.$class.$attribute[2], $opening, 1) ?? $opening;
        }

        return preg_replace('~\s*/?>\z~', ' class="'.$class.'"$0', $opening, 1) ?? $opening;
    }

    /** Rewrite actual selector heads only; CSS strings/comments stay opaque. */
    private static function logoStyles(string $style): string
    {
        if (preg_match('~\A(<style\b[^>]*>)(.*)(</style\s*>)\z~is', $style, $parts) !== 1) {
            return $style;
        }
        $published = preg_match('~\bdata-rt-mail-document-css=(["\'])signature\1~', $parts[1]) === 1;
        $css = $parts[2];
        $blocks = [];
        $start = 0;
        $quote = null;
        $comment = false;
        $edits = [];
        for ($i = 0, $length = strlen($css); $i < $length; $i++) {
            $character = $css[$i];
            if ($comment) {
                if ($character === '*' && ($css[$i + 1] ?? '') === '/') {
                    $comment = false;
                    $i++;
                }

                continue;
            }
            if ($quote !== null) {
                if ($character === '\\') {
                    $i++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($character === '/' && ($css[$i + 1] ?? '') === '*') {
                $comment = true;
                $i++;
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '{') {
                $head = substr($css, $start, $i - $start);
                $plain = preg_replace('~/\*.*?\*/~s', '', $head);
                $group = str_starts_with(ltrim($plain), '@');
                if (! $group && ($blocks === [] || end($blocks))) {
                    $rewritten = self::logoSelectorHead($head, $published);
                    if ($rewritten !== $head) {
                        $edits[$start] = [$head, $rewritten];
                    }
                }
                $blocks[] = $group;
                $start = $i + 1;
            } elseif ($character === '}') {
                array_pop($blocks);
                $start = $i + 1;
            } elseif ($character === ';' && ($blocks === [] || end($blocks))) {
                $start = $i + 1;
            }
        }
        krsort($edits);
        foreach ($edits as $offset => [$before, $after]) {
            $css = substr_replace($css, $after, $offset, strlen($before));
        }

        return $parts[1].$css.$parts[3];
    }

    private static function logoSelectorHead(string $head, bool $published): string
    {
        $selectors = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $comment = false;
        for ($i = 0, $length = strlen($head); $i <= $length; $i++) {
            $character = $head[$i] ?? ',';
            if ($comment) {
                if ($character === '*' && ($head[$i + 1] ?? '') === '/') {
                    $comment = false;
                    $i++;
                }

                continue;
            }
            if ($quote !== null) {
                if ($character === '\\') {
                    $i++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($character === '/' && ($head[$i + 1] ?? '') === '*') {
                $comment = true;
                $i++;
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $selector = substr($head, $start, $i - $start);
                $masked = preg_replace('~/\*.*?\*/|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'~s', '', $selector);
                $rename = $published || preg_match('/\.(?:rt-sign-ledger|xo)(?![\w-])/', $masked) === 1;
                $rewritten = $rename ? preg_replace_callback('~/\*.*?\*/|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|\.rt-logo(?![\w-])~s', static fn (array $token): string => $token[0] === '.rt-logo' ? '.rt-client-logo' : $token[0], $selector) : $selector;
                if ($published && $rewritten !== $selector) {
                    $selectors[] = $selector;
                }
                $selectors[] = $rewritten;
                $start = $i + 1;
            }
        }

        return implode(',', $selectors);
    }

    private static function wrapStyle(string $opening): string
    {
        $wrap = 'word-break:break-all;overflow-wrap:anywhere;';
        if (preg_match('~\sstyle\s*=\s*(["\'])(.*?)\1~is', $opening)) {
            return preg_replace_callback('~(\sstyle\s*=\s*)(["\'])(.*?)\2~is', static fn (array $style): string => $style[1].$style[2].rtrim($style[3], ';').';'.$wrap.$style[2], $opening, 1) ?? $opening;
        }

        return preg_replace('~\s*/?>\z~', ' style="'.$wrap.'"$0', $opening, 1) ?? $opening;
    }
}
