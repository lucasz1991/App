<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;

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
        return (new MailMessage)
            ->subject('Passwort zurücksetzen')
            ->greeting('Hallo ' . $this->user->name . '!')
            ->line('Du hast eine Anfrage gestellt, dein Passwort zurückzusetzen.')
            ->action('Passwort zurücksetzen', $this->resetUrl($notifiable))
            ->line('Der Link ist 60 Minuten gültig.')
            ->salutation('Mit freundlichen Grüßen, CBW Schulnetz Team');
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

