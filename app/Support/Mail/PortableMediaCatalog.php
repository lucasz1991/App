<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;

/** Verbindliche Systemmedien der portablen Maildokument-Bundles. */
final class PortableMediaCatalog
{
    /**
     * Vom Browser verwendete, serverautoritative Medienvertraege.
     *
     * Der aktuell geoeffnete Entwurf darf nicht bestimmen, welche Medien ein
     * neu ausgewaehltes Bundle benoetigt: gerade beim Wechsel von v7 auf v8
     * unterscheiden sich die Zug- und Idle-Dateien absichtlich.
     *
     * @return array<string, list<string>>
     */
    public static function requiredSystemAssetContracts(MailDocumentKind|string $kind): array
    {
        $kind = is_string($kind) ? MailDocumentKind::tryFrom($kind) : $kind;

        return match ($kind) {
            MailDocumentKind::Signature => [
                SignatureArtifactVersion::V7 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V7,
                ),
                SignatureArtifactVersion::V8 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V8,
                ),
                SignatureArtifactVersion::V9 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V9,
                ),
            ],
            MailDocumentKind::Template => [
                'default' => self::requiredSystemAssetIds(MailDocumentKind::Template),
            ],
            default => ['default' => []],
        };
    }

    /** @return list<string> */
    public static function requiredSystemAssetIds(
        MailDocumentKind|string $kind,
        ?string $artifactVersion = null,
    ): array {
        $kind = is_string($kind) ? MailDocumentKind::tryFrom($kind) : $kind;

        return match ($kind) {
            MailDocumentKind::Signature => array_merge([
                'contact-email.png',
                'contact-location.png',
                'contact-mobile.png',
                'contact-phone.png',
                'contact-web.png',
                'wortmarke-signature-light.gif',
                'wortmarke-signature-light.png',
                'wortmarke-mail-dark.gif',
                'wortmarke-mail-dark.png',
            ], SignatureArtifactVersion::usesArrivalHoldTrain($artifactVersion)
                ? [
                    'zug-dampf-v8-light.gif',
                    'zug-dampf-v8-light.png',
                    'zug-dampf-v8-dark.gif',
                    'zug-dampf-v8-dark.png',
                ]
                : [
                    'zug-dampf-light.gif',
                    'zug-dampf-light.png',
                    'zug-dampf-dark.gif',
                    'zug-dampf-dark.png',
                    'zug-dampf-idle-light.gif',
                    'zug-dampf-idle-dark.gif',
                ]),
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
