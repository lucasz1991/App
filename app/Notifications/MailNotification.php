<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use App\Models\Mail as MailModel;

class MailNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected MailModel $mail;

    public function __construct(MailModel $mail)
    {
        // dank SerializesModels wird hier nur die Model-ID in die Queue geschrieben
        $this->mail = $mail;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Content aus dem Mail-Model holen (falls als Array/JSON gespeichert)
        $content  = is_array($this->mail->content) ? $this->mail->content : [];
        $subject  = $content['subject'] ?? 'Nachricht';
        $greeting = $content['header']  ?? null;
        $body     = $content['body']    ?? '';
        $link     = $content['link']    ?? null;

        // sicherstellen, dass die Relation vorhanden ist (falls lazy)
        $this->mail->loadMissing('files');

        $m = (new MailMessage)
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject($subject);

        if ($greeting) {
            $m->greeting($greeting);
        }

        $m->line($body);

        if (!empty($link)) {
            $m->action(__('app.continue'), $link);
        }

        $m->salutation(__('app.mail_salutation', ['app' => config('app.name')]));

        // Anhänge aus der Relation anhängen
        foreach ($this->mail->files as $file) {
            // Falls du keine 'disk'-Spalte hast, Standard 'private'
            $disk = $file->disk ?? 'private';
            $path = $file->path;

            // Prefer attachFromStorageDisk, fällt zurück auf attach bei Bedarf
            if (method_exists($m, 'attachFromStorageDisk')) {
                $m->attachFromStorageDisk($disk, $path, [
                    'as'   => $file->name ?: basename($path),
                    'mime' => $file->mime_type ?: null,
                ]);
            } else {
                $absolute = Storage::disk($disk)->path($path);
                $m->attach($absolute, [
                    'as'   => $file->name ?: basename($path),
                    'mime' => $file->mime_type ?: null,
                ]);
            }
        }

        return $m;
    }
}
