<?php

namespace App\Livewire\Admin;

use App\Models\Mail;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class MailManagement extends Component
{
    use WithPagination;

    public string $sortBy = 'id';
    public string $sortDirection = 'desc';

    public function sortByField(string $field): void
    {
        if (! in_array($field, ['id', 'created_at', 'status'], true)) {
            return;
        }

        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function resendMail(int $id): void
    {
        $mail = Mail::query()->find($id);

        if (! $mail) {
            session()->flash('error', 'Mail nicht gefunden.');
            return;
        }

        $recipients = is_array($mail->recipients) ? $mail->recipients : [];

        foreach ($recipients as &$recipient) {
            $recipient['status'] = false;
        }
        unset($recipient);

        $mail->update([
            'status' => false,
            'recipients' => $recipients,
        ]);

        // Der Versand laeuft ueber den bestehenden Job-Flow.
        \App\Jobs\ProcessMailJob::dispatch($mail->fresh());

        session()->flash('message', __('app.mail_requeued'));
    }

    public function sendMessageToSuperAdmin(int $id): void
    {
        $sourceMail = Mail::query()->find($id);

        if (! $sourceMail) {
            session()->flash('error', 'Ausgangs-Mail nicht gefunden.');
            return;
        }

        $superAdminMail = (string) config('mail.super_admin', '');

        if (! filter_var($superAdminMail, FILTER_VALIDATE_EMAIL)) {
            session()->flash('error', 'SUPER_ADMIN_MAIL ist nicht gesetzt oder ungueltig.');
            return;
        }

        $sourceContent = is_array($sourceMail->content) ? $sourceMail->content : [];

        // Inhalt 1:1 uebernehmen, nur Empfaenger auf SUPER_ADMIN_MAIL setzen.
        Mail::query()->create([
            'type' => 'mail',
            'status' => false,
            'content' => $sourceContent,
            'recipients' => [
                [
                    'email' => $superAdminMail,
                    'status' => false,
                ],
            ],
        ]);

        session()->flash('message', 'Nachricht wurde unveraendert an den SuperAdmin zur Zustellung eingeplant.');
    }

    public function render()
    {
        $mails = Mail::query()
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(10);

        $recipientUserIds = $mails->getCollection()
            ->flatMap(function ($mail) {
                $recipients = is_array($mail->recipients) ? $mail->recipients : [];
                return collect($recipients)->pluck('user_id');
            })
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $recipientUsers = User::query()
            ->whereIn('id', $recipientUserIds)
            ->get()
            ->keyBy('id');

        return view('livewire.admin.mail-management', [
            'mails' => $mails,
            'recipientUsers' => $recipientUsers,
        ])->layout('layouts.master');
    }
}
