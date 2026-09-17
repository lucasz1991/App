<?php

namespace App\Support\Mail;

/** Exact opt-in variant: preserve the canonical header and its MSO fallback. */
final class TemplateForwardingStyle
{
    public static function framedMarkFragment(string $fragment): string
    {
        $fragment = self::markFragment($fragment);

        return preg_replace_callback('/(<td\b[^>]*>)(.*)(<\/td>)/s', static fn (array $m): string => $m[1]
            .'<table role="presentation" width="44" border="0" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="width:44px!important;max-width:44px!important;margin-left:auto;background-color:#ffffff!important;table-layout:fixed;border-collapse:collapse;"><tr><td width="44" height="44" bgcolor="#ffffff" style="width:44px;height:44px;padding:0;background-color:#ffffff!important;font-size:0;line-height:0;">'
            .$m[2].'</td></tr></table>'.$m[3], $fragment);
    }

    public static function markFragment(string $fragment): string
    {
        return str_replace(
            'display:block;width:44px;height:44px;',
            'display:block;width:44px!important;min-width:44px;max-width:44px!important;height:44px!important;min-height:44px;max-height:44px!important;',
            $fragment,
        );
    }
}
