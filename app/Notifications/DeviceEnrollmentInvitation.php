<?php

namespace App\Notifications;

use App\Models\DeviceEnrollment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bewusst nicht gequeued: Der kurzlebige Klartext-Token darf weder serialisiert
 * noch in einer Queue-Payload oder Datenbankbenachrichtigung landen.
 */
class DeviceEnrollmentInvitation extends Notification
{
    public function __construct(
        private readonly DeviceEnrollment $enrollment,
        private readonly string $plainToken,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $device = $this->enrollment->device;
        $expiresAt = $this->enrollment->expires_at?->timezone(config('app.timezone'));
        $url = route('devices.enrollment', ['token' => $this->plainToken]);

        return (new MailMessage)
            ->subject('Ihr RailTime-Gerät sicher einrichten')
            ->greeting('Hallo '.trim((string) $notifiable->name).',')
            ->line('Ihr bereits ausgegebenes Gerät wird jetzt der zentralen RailTime-Geräteverwaltung zugeordnet.')
            ->line(sprintf(
                'Gerät: %s%s',
                $device->display_name ?: $device->hostname ?: 'Firmen-Gerät',
                $device->asset_tag ? ' · Inventarnummer '.$device->asset_tag : '',
            ))
            ->line('Die Einrichtung dauert in der Regel 5 bis 15 Minuten. Sie sehen vor jedem Schritt, welche geschäftlichen Daten verwaltet werden. Private Zugangsdaten werden nicht an RailTime übertragen.')
            ->line('Microsoft-, Google- oder Apple-Zugangsdaten geben Sie ausschließlich in den offiziellen Anmeldefenstern des jeweiligen Anbieters ein. RailTime fragt niemals nach Ihrem Passwort.')
            ->action('Gerät jetzt in RailTime einrichten', $url)
            ->line($expiresAt
                ? 'Der persönliche Einmal-Link ist bis '.$expiresAt->format('d.m.Y, H:i').' Uhr gültig.'
                : 'Der persönliche Einmal-Link ist nur kurzzeitig gültig.')
            ->line('Bei Fragen nutzen Sie bitte den IT-Support in der RailTime-App.');
    }
}
