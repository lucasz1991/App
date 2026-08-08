<?php

namespace App\Enums;

/**
 * Es gibt genau ZWEI Maildokumente.
 *
 * Hell und Dunkel sind bewusst KEINE eigenen Arten: beide Fassungen entstehen
 * zur Laufzeit aus den Palettenwerten (EmailTemplateBuilder::emailThemeValues),
 * die als {{PLATZHALTER}} im gespeicherten Markup stehen.
 */
enum MailDocumentKind: string
{
    /** Nachrichtenschale — Rueckfall ist resources/mail-templates/email-master.html. */
    case Template = 'template';

    /** Signaturblock samt Pflichtangaben — Rueckfall ist emails.parts.signature. */
    case Signature = 'signature';

    public function label(): string
    {
        return match ($this) {
            self::Template => 'E-Mail-Vorlage',
            self::Signature => 'Signatur',
        };
    }
}
