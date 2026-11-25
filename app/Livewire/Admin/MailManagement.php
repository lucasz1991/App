<?php

namespace App\Livewire\Admin;

use App\Jobs\ProcessMailJob;
use App\Livewire\Concerns\ResetsPageOnFilterChange;
use App\Models\Mail;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class MailManagement extends Component
{
    use ResetsPageOnFilterChange;
    use WithPagination;

    protected $listeners = ['table-sort' => 'tableSort'];

    public string $sortBy = 'id';

    public string $sortDirection = 'desc';

    public string $search = '';

    public ?int $expandedMailId = null;

    /** @var array<int, int> */
    public array $selectedMails = [];

    protected function pageResetProperties(): array
    {
        return ['search'];
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function toggleMailSelection(int $id): void
    {
        if (! Mail::query()->whereKey($id)->exists()) {
            return;
        }

        if (in_array($id, $this->selectedMails, true)) {
            $this->selectedMails = array_values(array_diff($this->selectedMails, [$id]));
        } else {
            $this->selectedMails[] = $id;
        }
    }

    public function toggleMailDetails(int $id): void
    {
        if (! Mail::query()->whereKey($id)->exists()) {
            return;
        }

        $this->expandedMailId = $this->expandedMailId === $id ? null : $id;
    }

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

    public function tableSort(string $key, ?string $dir = null): void
    {
        if (! in_array($key, ['id', 'created_at', 'status'], true)) {
            return;
        }

        $this->sortBy = $key;
        $this->sortDirection = $dir === 'asc' ? 'asc' : 'desc';
        $this->resetPage();
    }

    public function resendMail(int $id): void
    {
        $mail = Mail::query()->find($id);

        if (! $mail) {
            $this->dispatch('swal:toast', type: 'error', text: 'Mail nicht gefunden.');

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
        ProcessMailJob::dispatch($mail->fresh());

        $this->dispatch('swal:toast', type: 'success', text: __('app.mail_requeued'));
    }

    public function sendMessageToSuperAdmin(int $id): void
    {
        $sourceMail = Mail::query()->find($id);

        if (! $sourceMail) {
            $this->dispatch('swal:toast', type: 'error', text: 'Ausgangs-Mail nicht gefunden.');

            return;
        }

        $superAdminMail = (string) config('mail.super_admin', '');

        if (! filter_var($superAdminMail, FILTER_VALIDATE_EMAIL)) {
            $this->dispatch('swal:toast', type: 'error', text: 'SUPER_ADMIN_MAIL ist nicht gesetzt oder ungültig.');

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

        $this->dispatch('swal:toast', type: 'success', text: 'Nachricht wurde unverändert an den SuperAdmin zur Zustellung eingeplant.');
    }

    public function render()
    {
        $search = trim($this->search);
        $mails = Mail::query()
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';

                $query->where(function ($query) use ($search, $like): void {
                    if (ctype_digit($search)) {
                        $query->orWhereKey((int) $search);
                    }

                    $query->orWhere('type', 'like', $like)
                        ->orWhere('recipients', 'like', $like)
                        ->orWhere('content', 'like', $like);
                });
            })
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
        ])->layout('layouts.master', ['area' => 'admin']);
    }
}
