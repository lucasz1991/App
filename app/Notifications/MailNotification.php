<?php

namespace App\Notifications;

use App\Models\Mail as MailModel;
use App\Support\CompanyData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

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
        $company = CompanyData::all();
        // Content aus dem Mail-Model holen (falls als Array/JSON gespeichert)
        $content = is_array($this->mail->content) ? $this->mail->content : [];
        $subject = $content['subject'] ?? 'Nachricht';
        $greeting = $content['header'] ?? null;
        $body = $content['body'] ?? '';
        $lines = is_array($content['lines'] ?? null)
            ? $content['lines']
            : [$body];
        $link = $content['link'] ?? null;

        // sicherstellen, dass die Relation vorhanden ist (falls lazy)
        $this->mail->loadMissing('files');

        $m = (new MailMessage)
            ->from(config('mail.from.address'), $company['name'])
            ->subject($subject);

        if ($greeting) {
            $m->greeting($greeting);
        }

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line !== '') {
                $m->line($line);
            }
        }

        if (! empty($link)) {
            $m->action(__('app.continue'), $link);
        }

        $m->salutation(__('app.mail_salutation', ['app' => $company['name']]));

        // Anhänge aus der Relation anhängen
        foreach ($this->mail->files as $file) {
            // Falls du keine 'disk'-Spalte hast, Standard 'private'
            $disk = $file->disk ?? 'private';
            $path = $file->path;

            // Prefer attachFromStorageDisk, fällt zurück auf attach bei Bedarf
            if (method_exists($m, 'attachFromStorageDisk')) {
                $m->attachFromStorageDisk($disk, $path, [
                    'as' => $file->name ?: basename($path),
                    'mime' => $file->mime_type ?: null,
                ]);
            } else {
                $absolute = Storage::disk($disk)->path($path);
                $m->attach($absolute, [
                    'as' => $file->name ?: basename($path),
                    'mime' => $file->mime_type ?: null,
                ]);
            }
        }

        return $m;
    }
}
