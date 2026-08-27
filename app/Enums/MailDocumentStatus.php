<?php

namespace App\Enums;

/**
 * Nur zwei Zustaende pro Design-Slot: Am Entwurf wird gearbeitet oder dieser
 * Slot ist aktiv veroeffentlicht. Einen separaten Archivzustand gibt es nicht.
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
