<?php

namespace App\Notifications;

use App\Support\CompanyData;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContactFormSubmitted extends Notification
{
    public $name;

    public $email;

    public $subject;

    public $message;

    // Konstruktor zum Initialisieren der Formulardaten
    public function __construct($name, $email, $subject, $message)
    {
        $this->name = $name;
        $this->email = $email;
        $this->subject = $subject;
        $this->message = $message;
    }

    // Der Notification-Kanal, den wir verwenden (in diesem Fall nur E-Mail)
    public function via($notifiable)
    {
        return ['mail'];
    }

    // E-Mail-Nachricht für die Notification
    public function toMail($notifiable)
    {
        $company = CompanyData::all();

        return (new MailMessage)
            ->from(config('mail.from.address'), $company['name'])
            ->subject('Neue Kontaktanfrage')
            ->greeting('Hallo, es gibt eine neue Nachricht!')
            ->line('Ein Kunde hat das Kontaktformular ausgefüllt.')
            ->line('Name: '.$this->name)
            ->line('E-Mail: '.$this->email)
            ->line('Betreff: '.$this->subject)
            ->line('Nachricht: '.$this->message)
            ->salutation('Mit freundlichen Grüßen, '.$company['name']);
    }

    // Optionale Methode für die Datenbankbenachrichtigung (falls gewünscht)
    public function toArray($notifiable)
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'subject' => $this->subject,
            'message' => $this->message,
        ];
    }
}
