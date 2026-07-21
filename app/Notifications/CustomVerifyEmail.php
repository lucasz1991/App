<?php

namespace App\Notifications;

use App\Support\CompanyData;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class CustomVerifyEmail extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);
        $company = CompanyData::all();

        return (new MailMessage)
            ->from(config('mail.from.address'), $company['name'])
            ->subject('Bestätigen Sie Ihre E-Mail-Adresse')
            ->greeting('Willkommen bei '.$company['name'].'!')
            ->line('Vielen Dank, dass Sie sich registriert haben.')
            ->line('Bitte klicken Sie auf den folgenden Button, um Ihre E-Mail-Adresse zu bestätigen:')
            ->action('E-Mail bestätigen', $verificationUrl)
            ->line('Falls Sie diese Aktion nicht angefordert haben, ignorieren Sie bitte diese Nachricht.')
            ->salutation('Mit freundlichen Grüßen, Ihr Team von '.$company['name']);
    }

    /**
     * Get the verification URL for the given notifiable.
     */
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())]
        );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
