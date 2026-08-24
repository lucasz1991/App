<?php

namespace App\Notifications;

use App\Enums\MailDocumentKind;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Bewusst synchron: der request-lokale Entwurfs-Snapshot darf keine Queue-Grenze ueberschreiten. */
final class MailDocumentTestNotification extends Notification
{
    public function __construct(
        private readonly MailDocumentKind $kind,
        private readonly int $documentVersion,
        private readonly ?string $artifactVersion,
        private readonly string $contentHash,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $layoutVersion = $this->artifactVersion ?? 'nicht gekennzeichnet';
        $shortHash = substr(strtolower(trim($this->contentHash)), 0, 12);

        return (new MailMessage)
            ->subject(
                '[TEST] '.$this->kind->label()
                .' · Layout '.$layoutVersion
                .' · Dokumentversion '.$this->documentVersion
                .' · Prüfung '.$shortHash
            )
            ->greeting('Test des Mail- & Signatur-Editors')
            ->line('Diese Nachricht verwendet den aktuell gespeicherten Entwurf des geöffneten Dokuments.')
            ->line('Verwendete Layoutversion: '.$layoutVersion.'.')
            ->line('Gespeicherte Dokumentversion: '.$this->documentVersion.'.')
            ->line('Prüfkennung: '.$shortHash.'.')
            ->line('Andere Mailbausteine stammen weiterhin aus ihrer veröffentlichten Version.')
            ->salutation('RailTime Systemtest');
    }
}
