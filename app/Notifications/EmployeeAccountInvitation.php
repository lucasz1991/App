<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class EmployeeAccountInvitation extends Notification
{
    public function __construct(private string $token) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Ihre Einladung zu '.config('app.name'))
            ->greeting('Guten Tag '.$notifiable->name.',')
            ->line('Die Administration hat Sie eingeladen, Ihren bestehenden Mitarbeiterzugang einzurichten.')
            ->line('Legen Sie über den folgenden Link Ihr Passwort fest. Damit bestätigen Sie zugleich Ihre E-Mail-Adresse.')
            ->action('Zugang einrichten', URL::temporarySignedRoute(
                'employee-invitation.create', now()->addMinutes($minutes),
                ['token' => $this->token, 'email' => $notifiable->email],
            ))
            ->line('Dieser Link ist '.$minutes.' Minuten gültig und kann nur einmal verwendet werden.')
            ->line('Anschließend werden Sie direkt angemeldet. Falls Sie die Zwei-Faktor-Anmeldung eingerichtet haben, bestätigen Sie zusätzlich Ihren Sicherheitscode.');
    }
}
