<?php

namespace App\Support\Mail;

/** Exact opt-in variant: preserve the canonical header and its MSO fallback. */
final class TemplateForwardingStyle
{
    public static function markFragment(string $fragment): string
    {
        return str_replace(
            'display:block;width:44px;height:44px;',
            'display:block;width:44px!important;min-width:44px;max-width:44px!important;height:44px!important;min-height:44px;max-height:44px!important;',
            $fragment,
        );
    }
}
