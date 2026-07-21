<?php

namespace App\Livewire\Messages;

use App\Models\File;
use App\Models\Message;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageViewerModal extends Component
{
    public ?Message $message = null;

    public bool $isOpen = false;

    #[On('message-viewer:open')]
    public function open(int $messageId): void
    {
        $user = Auth::user();

        if (! $user) {
            $this->close();

            return;
        }

        $message = $user->receivedMessages()
            ->with(['sender', 'files'])
            ->find($messageId);

        if (! $message) {
            $this->close();

            return;
        }

        if ((int) $message->status !== 2) {
            $message->update(['status' => 2]);
        }

        $this->message = $message;
        $this->isOpen = true;

        // Topbar-Badge und Nachrichtenliste ohne Polling-Verzoegerung abgleichen.
        $this->dispatch('inbox:refresh');
    }

    #[On('message-viewer:close')]
    public function close(): void
    {
        $this->isOpen = false;
        $this->message = null;
    }

    public function updatedIsOpen(bool $isOpen): void
    {
        if (! $isOpen) {
            $this->message = null;
        }
    }

    /**
     * Anhaenge duerfen ausschliesslich aus eigenen empfangenen Nachrichten
     * heruntergeladen werden.
     */
    public function downloadFile(int $fileId): StreamedResponse
    {
        $user = Auth::user();

        abort_unless($user, 403);

        $file = File::query()
            ->where('fileable_type', Message::class)
            ->whereIn('fileable_id', $user->receivedMessages()->select('id'))
            ->findOrFail($fileId);

        return $file->download($file->disk ?: 'private');
    }

    public function render()
    {
        return view('livewire.messages.message-viewer-modal');
    }
}
