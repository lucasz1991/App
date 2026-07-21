<?php

namespace App\Notifications;

use App\Support\CompanyData;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class SetPasswordNotification extends Notification
{
    protected $user;

    protected $token;

    public function __construct($user, $token)
    {
        $this->user = $user;
        $this->token = $token;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $company = CompanyData::all();

        return (new MailMessage)
            ->from(config('mail.from.address'), $company['name'])
            ->subject('Herzlich willkommen bei '.$company['name'].' – richten Sie Ihr Passwort ein')
            ->greeting('Guten Tag '.$this->user->name.',')
            ->line('Um Ihr Konto zu vervollständigen, richten Sie bitte ein Passwort ein.')
            ->action('Passwort setzen', $this->resetUrl($notifiable))
            ->line('Der Link ist 60 Minuten gültig.')
            ->line('Falls der Link abgelaufen ist oder nicht funktioniert, können Sie jederzeit einen neuen Link zum Setzen Ihres Passworts anfordern.')
            ->salutation('Mit freundlichen Grüßen, Ihr Team von '.$company['name']);
    }

    protected function resetUrl($notifiable)
    {
        return URL::temporarySignedRoute(
            'password.reset',
            Carbon::now()->addMinutes(60),
            ['token' => $this->token, 'email' => $notifiable->email]
        );
    }

    protected function newRequestUrl($notifiable)
    {
        return URL::route('password.request'); // Standard-Route für Passwort-Reset-Anfrage
    }

    public function toArray($notifiable)
    {
        return [
            'user_id' => $notifiable->getKey(),
            'token' => $this->token,
        ];
    }
}
