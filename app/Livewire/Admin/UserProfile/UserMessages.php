<?php

namespace App\Livewire\Admin\UserProfile;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithPagination;

#[Lazy]
class UserMessages extends Component
{
    use WithPagination;

    protected $paginationTheme = 'tailwind';

    public int $userId;

    public ?Message $selectedMessage = null;

    public bool $showMessageModal = false;

    protected $listeners = [
        'mailStored' => '$refresh',
    ];

    public function mount(int $userId): void
    {
        Gate::authorize('users.messages.view');
        $this->userId = $userId;
    }

    /**
     * Neue Nachricht an diesen Benutzer verfassen (oeffnet das Compose-Modal).
     */
    public function compose(): void
    {
        Gate::authorize('users.messages.create');

        $this->dispatch('openMailModal', payload: $this->userId)
            ->to(\App\Livewire\Admin\Users\Messages\MessageForm::class);
    }

    public function showMessage(int $messageId): void
    {
        $this->selectedMessage = Message::with(['sender', 'files'])
            ->where('to_user', $this->userId)
            ->findOrFail($messageId);

        $this->showMessageModal = true;
    }

    public function closeMessage(): void
    {
        $this->showMessageModal = false;
        $this->selectedMessage = null;
    }

    public function deleteMessage(int $messageId): void
    {
        Gate::authorize('users.messages.delete');

        Message::where('to_user', $this->userId)->findOrFail($messageId)->delete();

        $this->closeMessage();
        $this->dispatch('swal:toast', type: 'success', text: __('app.message_deleted'));
    }

    public function placeholder()
    {
        $loading = e(__('app.loading'));

        return <<<HTML
            <div role="status" class="h-24 w-full animate-pulse rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800 flex items-center justify-center">
                <span class="text-sm text-slate-500 dark:text-slate-400">{$loading}</span>
            </div>
        HTML;
    }

    public function render()
    {
        $messages = Message::with('sender')
            ->where('to_user', $this->userId)
            ->latest()
            ->paginate(10);

        return view('livewire.admin.user-profile.user-messages', [
            'messages' => $messages,
            'user' => User::find($this->userId),
        ]);
    }
}
