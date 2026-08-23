<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use RuntimeException;

/**
 * Verlustfreie Reparaturen fuer bekannte, eindeutig erkennbare Mailvertraege.
 *
 * Diese Klasse ist absichtlich kein allgemeiner HTML-Korrektor. Sie hebt nur
 * Signaturstaende an, die SignatureTrainCarrier bereits streng als einen
 * historischen RailTime-Vertrag erkannt hat. Mehrdeutige oder fremde Quellen
 * bleiben unveraendert und laufen danach weiterhin durch die fail-closed
 * Struktur- und Sicherheitspruefung.
 */
final class MailDocumentAutoRepair
{
    public static function repairHtml(MailDocumentKind $kind, string $html): string
    {
        if ($kind !== MailDocumentKind::Signature || trim($html) === '') {
            return $html;
        }

        try {
            return SignatureTrainCarrier::normalize($html);
        } catch (RuntimeException) {
            return $html;
        }
    }

    /**
     * Baut eine transiente, editorfaehige Quelle. Der Aufruf schreibt nichts
     * in die Datenbank; erst ein ausdrueckliches Speichern uebernimmt die
     * reparierte Fassung.
     *
     * @param  array<string, mixed>  $builderData
     * @return array{builderData: array<string, mixed>, html: string, repaired: bool}
     */
    public static function editorSource(
        MailDocumentKind $kind,
        array $builderData,
        string $html,
        string $fallbackPageName,
    ): array {
        if ($kind !== MailDocumentKind::Signature || trim($html) === '') {
            return [
                'builderData' => $builderData,
                'html' => $html,
                'repaired' => false,
            ];
        }

        try {
            $canonicalHtml = SignatureTrainCarrier::normalize($html);
        } catch (RuntimeException) {
            return [
                'builderData' => $builderData,
                'html' => $html,
                'repaired' => false,
            ];
        }

        $canonicalBuilderData = self::synchronizeBuilderData(
            $kind,
            $builderData,
            $canonicalHtml,
            $fallbackPageName,
        );

        return [
            'builderData' => $canonicalBuilderData,
            'html' => $canonicalHtml,
            'repaired' => $canonicalHtml !== $html || $canonicalBuilderData !== $builderData,
        ];
    }

    /**
     * @param  array<string, mixed>  $builderData
     * @return array<string, mixed>
     */
    public static function synchronizeBuilderData(
        MailDocumentKind $kind,
        array $builderData,
        string $html,
        string $fallbackPageName,
    ): array {
        $pageName = data_get($builderData, 'pages.0.name', $fallbackPageName);
        if (! is_string($pageName) || trim($pageName) === '') {
            $pageName = $fallbackPageName;
        }

        $metadata = is_array($builderData['railtime'] ?? null)
            ? $builderData['railtime']
            : [];
        $schema = $metadata['schema'] ?? null;

        $railtime = ['document' => $kind->value];
        if ($kind === MailDocumentKind::Signature) {
            $railtime['schema'] = SignatureDocumentContract::SCHEMA;
        } elseif (is_int($schema) && $schema > 0 && $schema <= 1000) {
            $railtime['schema'] = $schema;
        }

        return [
            'pages' => [[
                'name' => mb_substr(trim($pageName), 0, 80),
                'component' => $html,
            ]],
            'styles' => [],
            'railtime' => $railtime,
        ];
    }
}
