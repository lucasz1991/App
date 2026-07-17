<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

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
        return (new MailMessage)
            ->subject('Neue Kontaktanfrage')
            ->greeting('Hallo, es gibt eine neue Nachricht!')
            ->line('Ein Kunde hat das Kontaktformular ausgefüllt.')
            ->line('Name: ' . $this->name)
            ->line('E-Mail: ' . $this->email)
            ->line('Betreff: ' . $this->subject)
            ->line('Nachricht: ' . $this->message)
            ->salutation('Mit freundlichen Grüßen, CBW Schulnetz Team');
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
