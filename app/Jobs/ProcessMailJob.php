<?php

namespace App\Jobs;

use App\Models\Mail;
use App\Models\User;
use App\Notifications\MailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ProcessMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $mail;

    /**
     * Create a new job instance.
     */
    public function __construct(Mail $mail)
    {
        $this->mail = $mail;
    }

    /**
     * Execute the job.
     *
     * Empfaenger sind entweder RailTime-Benutzer (user_id) oder freie
     * E-Mail-Adressen (email). Je nach type wird eine interne Nachricht
     * (message), eine E-Mail (mail) oder beides (both) verschickt.
     */
    public function handle(): void
    {
        $recipients = is_array($this->mail->recipients) ? $this->mail->recipients : [];
        $files = $this->mail->files ?? [];

        $typeValue = $this->mail->type;
        $type = is_bool($typeValue)
            ? ($typeValue ? 'both' : 'message')
            : strtolower((string) $typeValue);

        $sendMessageTo = in_array($type, ['message', 'both'], true);
        $sendMailTo = in_array($type, ['mail', 'both'], true);

        foreach ($recipients as &$recipient) {
            try {
                $userId = (int) ($recipient['user_id'] ?? 0);
                $email = trim((string) ($recipient['email'] ?? ''));
                $recipient['status'] = false;

                if ($userId > 0) {
                    $user = User::find($userId);

                    if (! $user) {
                        Log::warning("Benutzer mit ID {$userId} nicht gefunden.", [
                            'mail_id' => $this->mail->id,
                        ]);
                        continue;
                    }

                    $didProcess = false;

                    if ($sendMessageTo) {
                        $user->receiveMessage(
                            $this->mail->content['subject'] ?? 'Nachricht',
                            $this->mail->content['body'] ?? '',
                            $this->mail->from_user_id ?? 1,
                            $files
                        );
                        $didProcess = true;
                    }

                    if ($sendMailTo) {
                        $user->notify(new MailNotification($this->mail));
                        $didProcess = true;
                    }

                    $recipient['status'] = $didProcess;
                    continue;
                }

                if ($sendMailTo && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Notification::route('mail', $email)->notify(new MailNotification($this->mail));
                    $recipient['status'] = true;
                    continue;
                }

                Log::warning('Empfaenger konnte fuer den gewaehlten Mail-Typ nicht verarbeitet werden.', [
                    'recipient' => $recipient,
                    'mail_id' => $this->mail->id,
                    'type' => $this->mail->type,
                ]);
            } catch (\Throwable $e) {
                Log::error('Fehler beim Senden der Mail.', [
                    'mail_id' => $this->mail->id,
                    'recipient' => $recipient,
                    'error' => $e->getMessage(),
                ]);
                $recipient['status'] = false;
            }
        }
        unset($recipient);

        $this->mail->update([
            'recipients' => $recipients,
            'status' => ! empty($recipients) && collect($recipients)->every(fn ($recipient) => (bool) ($recipient['status'] ?? false)),
        ]);
    }
}
