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
                SignatureArtifactVersion::V10 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V10,
                ),
                SignatureArtifactVersion::V11 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V11,
                ),
                SignatureArtifactVersion::V12 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V12,
                ),
                SignatureArtifactVersion::V13 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V13,
                ),
                SignatureArtifactVersion::V14 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V14,
                ),
                SignatureArtifactVersion::V15 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V15,
                ),
                SignatureArtifactVersion::V16 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V16,
                ),
                SignatureArtifactVersion::V17 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V17,
                ),
                SignatureArtifactVersion::V18 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V18,
                ),
                SignatureArtifactVersion::V19 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V19,
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
            ], SignatureArtifactVersion::usesV19MailAssets($artifactVersion)
                ? [
                    'icon-rt-v19-light.gif',
                    'icon-rt-v19-light.png',
                    'icon-rt-v19-dark.gif',
                    'icon-rt-v19-dark.png',
                    'wortmarke-signature-v19-light.gif',
                    'wortmarke-signature-v19-light.png',
                    'wortmarke-mail-v19-dark.gif',
                    'wortmarke-mail-v19-dark.png',
                ]
                : (SignatureArtifactVersion::usesOptimizedMailAssets($artifactVersion)
                ? [
                    'wortmarke-signature-v15-light.gif',
                    'wortmarke-signature-v15-light.png',
                    'wortmarke-mail-v15-dark.gif',
                    'wortmarke-mail-v15-dark.png',
                ]
                : [
                    'wortmarke-signature-light.gif',
                    'wortmarke-signature-light.png',
                    'wortmarke-mail-dark.gif',
                    'wortmarke-mail-dark.png',
                ]), SignatureArtifactVersion::usesV19MailAssets($artifactVersion)
                ? [
                    'zug-dampf-v19-light.gif',
                    'zug-dampf-v19-light.png',
                    'zug-dampf-v19-dark.gif',
                    'zug-dampf-v19-dark.png',
                ]
                : (SignatureArtifactVersion::usesV17TrainAssets($artifactVersion)
                ? [
                    'zug-dampf-v17-light.gif',
                    'zug-dampf-v17-light.png',
                    'zug-dampf-v17-dark.gif',
                    'zug-dampf-v17-dark.png',
                ]
                : (SignatureArtifactVersion::usesOptimizedMailAssets($artifactVersion)
                ? [
                    'zug-dampf-v15-light.gif',
                    'zug-dampf-v15-light.png',
                    'zug-dampf-v15-dark.gif',
                    'zug-dampf-v15-dark.png',
                ]
                : (SignatureArtifactVersion::usesSmokeSafeArrivalTrain($artifactVersion)
                ? [
                    'zug-dampf-v13-light.gif',
                    'zug-dampf-v13-light.png',
                    'zug-dampf-v13-dark.gif',
                    'zug-dampf-v13-dark.png',
                ]
                : (SignatureArtifactVersion::usesOptimizedArrivalTrain($artifactVersion)
                ? [
                    'zug-dampf-v12-light.gif',
                    'zug-dampf-v12-light.png',
                    'zug-dampf-v12-dark.gif',
                    'zug-dampf-v12-dark.png',
                ]
                : (SignatureArtifactVersion::usesArrivalHoldTrain($artifactVersion)
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
                ])))))),
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
