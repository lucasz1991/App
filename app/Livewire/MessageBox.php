<?php

namespace App\Livewire;

use App\Models\File;
use App\Models\Message;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageBox extends Component
{
    use WithPagination;

    public $selectedMessage;
    public bool $showMessageModal = false;
    public int $loadedPages = 1;

    public string $search = '';

    protected $listeners = [
        'refreshComponent' => '$refresh',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function loadMore(): void
    {
        $this->loadedPages++;
    }

    public function showMessage(int $messageId): void
    {
        $this->selectedMessage = auth()->user()->receivedMessages()
            ->with(['files', 'sender'])
            ->find($messageId);

        if ($this->selectedMessage) {
            $this->selectedMessage->update(['status' => 2]); // gelesen
            $this->showMessageModal = true;
        }

        $this->dispatch('refreshComponent');
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

        $this->dispatch('refreshComponent');
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

        if ($this->selectedMessage && (int) $this->selectedMessage->id === $messageId) {
            $this->selectedMessage = null;
            $this->showMessageModal = false;
        }

        $this->dispatch('swal:toast', type: 'success', text: __('app.message_deleted'));
        $this->dispatch('refreshComponent');
    }

    /**
     * Download eines Anhangs — nur Dateien aus eigenen empfangenen Nachrichten.
     */
    public function downloadFile(int $fileId): StreamedResponse
    {
        $file = File::query()
            ->where('fileable_type', Message::class)
            ->whereIn('fileable_id', auth()->user()->receivedMessages()->select('id'))
            ->findOrFail($fileId);

        return $file->download($file->disk ?: 'private');
    }

    public function render()
    {
        $base = auth()->user()->receivedMessages()
            ->with(['sender:id,name,role,profile_photo_path'])
            ->withCount('files')
            ->orderByDesc('created_at');

        if (filled($this->search)) {
            $s = '%'.trim($this->search).'%';
            $base->where(function ($q) use ($s) {
                $q->where('subject', 'like', $s)
                    ->orWhere('message', 'like', $s)
                    ->orWhereHas('sender', fn ($qs) => $qs->where('name', 'like', $s));
            });
        }

        $messages = $base->paginate(12 * $this->loadedPages);

        return view('livewire.message-box', compact('messages'))
            ->layout('layouts.master', ['area' => 'user']);
    }
}
