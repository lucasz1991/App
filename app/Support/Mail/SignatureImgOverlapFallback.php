<?php

declare(strict_types=1);

namespace App\Support\Mail;

use RuntimeException;

/** Final system-mail fallback, applied after the CSS inliner, never on imports. */
final class SignatureImgOverlapFallback
{
    public const STYLE = 'display:block;width:100%;height:0;max-height:0;margin:0;overflow:visible;font-size:0;line-height:0;text-align:left;';

    public static function apply(string $html): string
    {
        if (! SignatureImgOverlap::applies($html)) {
            return $html;
        }

        SignatureImgOverlap::assertRuntime($html);
        $count = 0;
        $result = preg_replace_callback(
            '~<div\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~i',
            static function (array $match) use (&$count): string {
                $tag = $match[0];
                if (preg_match('~\sclass\s*=\s*(["\'])(.*?)\1~is', $tag, $classes) !== 1
                    || ! in_array('rt-sign-train-layer', preg_split('/\s+/', trim($classes[2])) ?: [], true)) {
                    return $tag;
                }

                $count++;
                $tag = preg_replace('~\sstyle\s*=\s*(["\']).*?\1~is', '', $tag) ?? $tag;

                return substr($tag, 0, -1).' style="'.self::STYLE.'">';
            },
            $html,
        );

        if (! is_string($result) || $count !== 1) {
            throw new RuntimeException('Der V26-Ausgabefallback benoetigt genau einen validierten IMG-Layer.');
        }

        // New Outlook removes the negative inline margin. The first IMG must
        // therefore reserve no flow height. Its inner frame still bottom-aligns
        // it behind the following contact frame. P1 was received and approved.
        // Preserve all responsive/head rules: clients retaining them (including
        // the accepted iPhone layout) continue to use their existing geometry.
        // No CSS background, IMG mutation, height preset or source migration.
        SignatureImgOverlap::assertRuntime($result);

        return $result;
    }
}
