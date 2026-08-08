<?php

namespace App\Enums;

/**
 * Nur zwei Zustaende: Am Entwurf wird gearbeitet, veroeffentlicht wird
 * verschickt. Einen Archivzustand wie im Marketing-Studio gibt es nicht —
 * die beiden Maildokumente sind dauerhaft und lassen sich nicht stilllegen.
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
