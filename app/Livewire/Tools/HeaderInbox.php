<?php

namespace App\Livewire\Tools;

use App\Models\File;
use App\Models\Message;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HeaderInbox extends Component
{
    public int $unreadMessagesCount = 0;
    public $receivedMessages;            // Collection (kleine Liste)
    public $selectedMessage = null;      // App\Models\Message|null
    public bool $showMessageModal = false;

    public function mount(): void
    {
        $this->loadInbox();
    }

    public function loadInbox(): void
    {
        $user = Auth::user();

        if (! $user) {
            $this->unreadMessagesCount = 0;
            $this->receivedMessages = collect();

            return;
        }

        // kleine Liste fuer das Dropdown (letzte 3)
        $this->receivedMessages = $user->receivedMessages()
            ->with(['sender:id,name,role,profile_photo_path'])
            ->withCount('files')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        $this->unreadMessagesCount = $user->receivedMessages()
            ->where('status', 1)
            ->count();
    }

    public function showMessage(int $messageId): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        // Nachricht holen (inkl. files & sender fuers Modal)
        $this->selectedMessage = $user->receivedMessages()
            ->with(['files', 'sender'])
            ->find($messageId);

        if ($this->selectedMessage) {
            // als gelesen markieren
            if ((int) $this->selectedMessage->status === 1) {
                $this->selectedMessage->update(['status' => 2]);
            }

            // Modal oeffnen
            $this->showMessageModal = true;

            // Zaehler/Liste aktualisieren
            $this->loadInbox();
        }
    }

    /**
     * Download eines Anhangs — nur Dateien aus eigenen empfangenen Nachrichten.
     */
    public function downloadFile(int $fileId): StreamedResponse
    {
        $file = File::query()
            ->where('fileable_type', Message::class)
            ->whereIn('fileable_id', Auth::user()->receivedMessages()->select('id'))
            ->findOrFail($fileId);

        return $file->download($file->disk ?: 'private');
    }

    public function render()
    {
        return view('livewire.tools.header-inbox');
    }
}
