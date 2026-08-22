<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;

/** Verbindliche Systemmedien der portablen Maildokument-Bundles. */
final class PortableMediaCatalog
{
    /** @return list<string> */
    public static function requiredSystemAssetIds(MailDocumentKind|string $kind): array
    {
        $kind = is_string($kind) ? MailDocumentKind::tryFrom($kind) : $kind;

        return match ($kind) {
            MailDocumentKind::Signature => [
                'contact-email.png',
                'contact-location.png',
                'contact-mobile.png',
                'contact-phone.png',
                'contact-web.png',
                'wortmarke-signature-light.gif',
                'wortmarke-signature-light.png',
                'wortmarke-mail-dark.gif',
                'wortmarke-mail-dark.png',
                'zug-dampf-light.gif',
                'zug-dampf-light.png',
                'zug-dampf-dark.gif',
                'zug-dampf-dark.png',
                'zug-dampf-idle-light.gif',
                'zug-dampf-idle-dark.gif',
            ],
            MailDocumentKind::Template => [
                'icon-rt-light.gif',
                'icon-rt-light.png',
                'icon-rt-dark.gif',
                'icon-rt-dark.png',
            ],
            default => [],
        };
    }
}
