<?php

namespace App\Notifications;

use App\Support\CompanyData;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class CustomResetPasswordNotification extends Notification
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
            ->subject('Passwort zurücksetzen')
            ->greeting('Guten Tag '.$this->user->name.',')
            ->line('Sie haben angefordert, Ihr Passwort zurückzusetzen.')
            ->action('Passwort zurücksetzen', $this->resetUrl($notifiable))
            ->line('Der Link ist 60 Minuten gültig.')
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
}
