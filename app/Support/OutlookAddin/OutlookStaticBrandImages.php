<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\CssSemantic;

/** Final Add-in wire only: collapse an exact duplicate static branding pair. */
final class OutlookStaticBrandImages
{
    private const TAG = '(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*';

    public static function project(string $html): string
    {
        preg_match_all('~<!--.*?-->|<style\b'.self::TAG.'>.*?</style\s*>|</?[a-z][a-z0-9:-]*'.self::TAG.'>~is', $html, $tokens, PREG_OFFSET_CAPTURE);
        $stack = [];
        $counts = [];
        $pairs = [];
        foreach ($tokens[0] as $position => [$token, $offset]) {
            if (str_starts_with($token, '<!--') || preg_match('~\A<style\b~i', $token)) {
                continue;
            }
            preg_match('~\A<(/?)([a-z][a-z0-9:-]*)\b~i', $token, $name);
            $tag = strtolower($name[2]);
            if ($name[1] === '/') {
                $parent = array_pop($stack);
                if ($parent === null || $parent['tag'] !== $tag) {
                    return $html;
                }

                continue;
            }
            $attributes = self::attributes($token);
            if ($attributes === null) {
                return $html;
            }
            $quoted = ($stack === [] ? false : $stack[array_key_last($stack)]['quoted'])
                || $tag === 'blockquote'
                || in_array(strtolower($attributes['id'] ?? ''), ['divrplyfwdmsg', 'x_divrplyfwdmsg', 'mail-editor-reference-message-container'], true);
            if ($tag === 'img' && ! $quoted && ($role = self::role($attributes)) !== null) {
                $counts[$role] = ($counts[$role] ?? 0) + 1;
                if (isset($tokens[0][$position + 1])) {
                    [$fallback, $fallbackOffset] = $tokens[0][$position + 1];
                    $between = substr($html, $offset + strlen($token), $fallbackOffset - $offset - strlen($token));
                    if (trim($between) === '' && preg_match('~\A<!--\[if mso\]>\s*(<img\b'.self::TAG.'>)\s*<!\[endif\]-->\z~is', $fallback, $match)) {
                        $still = self::attributes($match[1]);
                        $visible = self::withoutMsoHide($token);
                        if ($still !== null && self::role($still) === $role && self::sameStaticImage($attributes, $still) && $visible !== null) {
                            $pairs[] = ['role' => $role, 'opening' => $token, 'pair' => $token.$between.$fallback, 'replacement' => $visible.$between];
                        }
                    }
                }
            }
            if (! in_array($tag, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true)) {
                $stack[] = ['tag' => $tag, 'quoted' => $quoted];
            }
        }
        if ($stack !== []) {
            return $html;
        }
        $pairs = array_values(array_filter($pairs, static fn (array $pair): bool => ($counts[$pair['role']] ?? 0) === 1 && substr_count($html, $pair['pair']) === 1));
        if ($pairs === []) {
            return $html;
        }
        $projected = OutlookBrandAliasVisibility::clear($html, $pairs);
        if ($projected === null) {
            return $html;
        }
        foreach ($pairs as $pair) {
            $projected = str_replace($pair['pair'], $pair['replacement'], $projected, $count);
            if ($count !== 1) {
                return $html;
            }
        }

        return $projected;
    }

    private static function sameStaticImage(array $normal, array $fallback): bool
    {
        $source = trim($normal['src'] ?? '');
        if ($source === '' || $source !== trim($fallback['src'] ?? '') || ! self::png($source)
            || array_key_exists('srcset', $normal) || array_key_exists('srcset', $fallback)
            || array_key_exists('sizes', $normal) || array_key_exists('sizes', $fallback)
            || array_key_exists('usemap', $normal) || array_key_exists('usemap', $fallback)
            || ($normal['alt'] ?? '') !== ($fallback['alt'] ?? '')) {
            return false;
        }
        foreach (['width', 'height'] as $dimension) {
            if (($normal[$dimension] ?? '') !== ($fallback[$dimension] ?? '')
                || (isset($normal[$dimension]) && preg_match('/\A[1-9][0-9]{0,3}\z/', $normal[$dimension]) !== 1)) {
                return false;
            }
        }

        return isset($normal['width']);
    }

    private static function png(string $source): bool
    {
        if (preg_match('~\Adata:image/png;base64,[A-Za-z0-9+/\r\n]+=*\z~i', $source)) {
            return true;
        }
        if (preg_match('~\Acid:[^\s<>"\']+\.png\z~i', $source)) {
            return true;
        }
        $scheme = parse_url($source, PHP_URL_SCHEME);

        return in_array($scheme, [null, 'http', 'https'], true)
            && preg_match('~\.png\z~i', (string) parse_url($source, PHP_URL_PATH)) === 1;
    }

    private static function role(array $attributes): ?string
    {
        $classes = preg_split('/\s+/', trim($attributes['class'] ?? ''));
        $roles = array_values(array_intersect($classes, ['rt-mark', 'rt-logo', 'rt-client-logo']));
        if (count($roles) !== 1) {
            return null;
        }

        return $roles[0] === 'rt-mark' ? 'mark' : 'logo';
    }

    private static function withoutMsoHide(string $opening): ?string
    {
        if (preg_match('~\sstyle\s*=\s*[^"\'\s]~i', $opening)) {
            return null;
        }
        $valid = true;
        $output = preg_replace_callback('~(\sstyle\s*=\s*)(["\'])(.*?)\2~is', static function (array $match) use (&$valid): string {
            $style = $match[3];
            if (preg_match('~["\'(){}\\\\]~', $style) || CssSemantic::decodeHtmlEntitiesOnce($style) !== $style) {
                $valid = false;

                return $match[0];
            }
            preg_match_all('/(?:\A|;)\s*mso-hide\s*:\s*([^;]*)/i', $style, $hide);
            if (count($hide[1]) > 1 || ($hide[1] !== [] && preg_match('/\Aall\s*(?:!important\s*)?\z/i', trim($hide[1][0])) !== 1)) {
                $valid = false;

                return $match[0];
            }
            $style = preg_replace('/(?:\A|(?<=;))\s*mso-hide\s*:\s*all\s*(?:!important\s*)?(?:;|\z)/i', '', $style);

            return $match[1].$match[2].$style.$match[2];
        }, $opening, -1, $count);

        return $valid && $count <= 1 ? $output : null;
    }

    private static function attributes(string $opening): ?array
    {
        preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $opening, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attributes = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (array_key_exists($name, $attributes)) {
                return null;
            }
            $attributes[$name] = CssSemantic::decodeHtmlEntitiesOnce($match[2] ?? $match[3] ?? $match[4] ?? '');
        }

        return $attributes;
    }
}
