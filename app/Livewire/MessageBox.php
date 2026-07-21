<?php

namespace App\Livewire;

use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

class MessageBox extends Component
{
    use WithPagination;

    public int $loadedPages = 1;

    public string $search = '';

    /** @var array<int, int> */
    public array $selectedMessages = [];

    protected $listeners = [
        'inbox:refresh' => '$refresh',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function loadMore(): void
    {
        $this->loadedPages++;
    }

    public function toggleMessageSelection(int $messageId): void
    {
        if (! auth()->user()->receivedMessages()->whereKey($messageId)->exists()) {
            return;
        }

        if (in_array($messageId, $this->selectedMessages, true)) {
            $this->selectedMessages = array_values(array_diff($this->selectedMessages, [$messageId]));
        } else {
            $this->selectedMessages[] = $messageId;
        }
    }

    public function openMessageDetail(int $messageId): void
    {
        if (! auth()->user()->receivedMessages()->whereKey($messageId)->exists()) {
            return;
        }

        $this->dispatch('message-viewer:open', messageId: $messageId);
    }

    public function markAsRead(int $messageId): void
    {
        $message = auth()->user()->receivedMessages()->find($messageId);

        if (! $message) {
            return;
        }

        if ((int) $message->status !== 2) {
            $message->update(['status' => 2]);
        }

        $this->dispatch('inbox:refresh');
    }

    public function deleteMessage(int $messageId): void
    {
        $message = auth()->user()->receivedMessages()
            ->with('files')
            ->find($messageId);

        if (! $message) {
            return;
        }

        foreach ($message->files as $file) {
            $file->delete();
        }

        $message->delete();

        $this->selectedMessages = array_values(array_diff($this->selectedMessages, [$messageId]));

        $this->dispatch('swal:toast', type: 'success', text: __('app.message_deleted'));
        $this->dispatch('inbox:refresh');
    }

    public function render()
    {
        $base = auth()->user()->receivedMessages()
            ->with(['sender:id,name,role,profile_photo_path'])
            ->withCount('files')
            ->orderByDesc('created_at');

        $perPage = 12 * $this->loadedPages;

        if (filled($this->search)) {
            // Verschlüsselte Inhalte können nicht per SQL-LIKE durchsucht
            // werden. Darum wird ausschließlich der eigene Posteingang
            // geladen, entschlüsselt und anschließend im Speicher gefiltert.
            $needle = mb_strtolower(trim($this->search));
            $filtered = $base->get()
                ->filter(function ($message) use ($needle): bool {
                    $haystack = mb_strtolower(implode(' ', [
                        $message->sender?->name,
                        $message->subject,
                        strip_tags($message->message),
                    ]));

                    return str_contains($haystack, $needle);
                })
                ->values();

            $messages = new LengthAwarePaginator(
                $filtered->take($perPage),
                $filtered->count(),
                $perPage,
                1,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        } else {
            $messages = $base->paginate($perPage);
        }

        $area = auth()->user()->usesAdminLayout() ? 'admin' : 'user';

        return view('livewire.message-box', compact('messages'))
            ->layout('layouts.master', ['area' => $area]);
    }
}
