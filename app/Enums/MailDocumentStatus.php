<?php

namespace App\Enums;

/**
 * Nur zwei Zustaende pro Design-Slot: Am Entwurf wird gearbeitet oder dieser
 * Slot ist veroeffentlicht. Bei Outlook-Vorlagen sind Freigabe und Standard
 * voneinander und von der aktiven Systemmail unabhaengig.
 */
enum MailDocumentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Published => 'Veröffentlicht',
        };
    }
}
