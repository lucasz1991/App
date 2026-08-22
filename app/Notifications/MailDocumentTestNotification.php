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
        private readonly int $version,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[TEST] '.$this->kind->label().' · Version '.$this->version)
            ->greeting('Test des Mail- & Signatur-Editors')
            ->line('Diese Nachricht verwendet den aktuell gespeicherten Entwurf des geöffneten Dokuments.')
            ->line('Andere Mailbausteine stammen weiterhin aus ihrer veröffentlichten Version.')
            ->salutation('RailTime Systemtest');
    }
}
