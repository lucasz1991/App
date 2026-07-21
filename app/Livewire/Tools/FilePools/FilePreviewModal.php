<?php

namespace App\Livewire\Tools\FilePools;

use App\Models\ChatMessage;
use Livewire\Component;
use App\Models\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilePreviewModal extends Component
{
    public bool $open = false;
    public ?int $fileId = null;
    public ?File $file = null;

    #[On('filepool:preview')] // Livewire-Event (PHP -> PHP / JS -> PHP)
    public function handlePreview(int $id): void
    {
        $this->openWith($id);
    }

    public function downloadFile(int $fileId): StreamedResponse
    {
        $file = File::findOrFail($fileId);
        $this->ensureCanAccess($file);

        return $file->download($file->disk ?: 'private', denyExpired: $file->fileable_type !== ChatMessage::class);
    }

    public function openWith(int $id): void
    {
        $this->fileId = $id;
        $this->file   = File::find($id);

        if (!$this->file) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.file_not_found'));
            return;
        }

        $this->ensureCanAccess($this->file);

        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    #[Computed]
    public function url(): ?string
    {
        if (! $this->file) {
            return null;
        }

        if ($this->file->fileable_type === ChatMessage::class) {
            return route('chat.attachments', ['file' => $this->file]);
        }

        return $this->file->getEphemeralPublicUrl();
    }

    protected function ensureCanAccess(File $file): void
    {
        if ($file->fileable_type !== ChatMessage::class) {
            return;
        }

        $message = ChatMessage::query()->findOrFail($file->fileable_id);

        // Einmal-Sprachnachrichten duerfen weder ueber die globale Vorschau
        // noch ueber deren Download-Methode am Einmal-Player vorbeigelangen.
        abort_if($message->isVoice() && $message->view_once, 403);

        abort_unless(
            auth()->check()
                && $message->chat()
                    ->whereHas('participants', fn ($query) => $query->where('users.id', auth()->id()))
                    ->exists(),
            403
        );
    }

    public function render()
    {
        return view('livewire.tools.file-pools.file-preview-modal');
    }
}
