<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;

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
        return (new MailMessage)
            ->subject('Herzlich willkommen bei CBW Schulnetz! Setze dein Passwort')
            ->greeting('Hallo ' . $this->user->name . '!')
            ->line('Um dein Konto zu vervollständigen, setze bitte ein Passwort.')
            ->action('Passwort setzen', $this->resetUrl($notifiable))
            ->line('Der Link ist 60 Minuten gültig.')
            ->line('Falls der Link abgelaufen ist oder nicht funktioniert, kannst du jederzeit einen neuen Passwort-Setzen-Link anfordern.')
            ->salutation('Mit freundlichen Grüßen,')
            ->salutation('dein CBW Schulnetz Team');
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
