<?php

namespace App\Livewire\Tools\FilePools;

use App\Models\ChatMessage;
use App\Models\File;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilePreviewModal extends Component
{
    public bool $open = false;

    #[Locked]
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
        $this->ensureCanAccess($file, 'download');

        return $file->download($file->disk ?: 'private', denyExpired: $file->fileable_type !== ChatMessage::class);
    }

    public function openWith(int $id): void
    {
        $file = File::find($id);

        if (! $file) {
            $this->resetPreview();
            $this->dispatch('swal:toast', type: 'error', text: __('app.file_not_found'));

            return;
        }

        // Erst autorisieren, danach den Datensatz in den oeffentlichen
        // Livewire-Zustand uebernehmen. Ein abgewiesener Request darf keine
        // Metadaten der fremden Datei im Snapshot hinterlassen.
        $this->ensureCanAccess($file);

        $this->fileId = (int) $file->getKey();
        $this->file = $file;
        $this->open = true;
    }

    public function close(): void
    {
        $this->resetPreview();
    }

    #[Computed]
    public function url(): ?string
    {
        if (! $this->file) {
            return null;
        }

        $this->ensureCanAccess($this->file);

        if ($this->file->fileable_type === ChatMessage::class) {
            return route('chat.attachments', ['file' => $this->file]);
        }

        return $this->file->getEphemeralPublicUrl();
    }

    #[Computed]
    public function canDownload(): bool
    {
        return $this->open
            && $this->file instanceof File
            && $this->canAccess($this->file, 'download');
    }

    #[Computed]
    public function versionKey(): ?string
    {
        if (! $this->file) {
            return null;
        }

        $version = $this->file->content_sha256
            ?: $this->file->updated_at?->getTimestamp()
            ?: $this->file->getKey();

        return $this->file->getKey().'-'.$version;
    }

    protected function ensureCanAccess(File $file, string $action = 'view'): void
    {
        abort_unless($this->canAccess($file, $action), 403);
    }

    protected function canAccess(File $file, string $action = 'view'): bool
    {
        if ($file->fileable_type !== ChatMessage::class) {
            $user = auth()->user();

            if (! $user) {
                return false;
            }

            if ($user->isAdmin()
                || Gate::forUser($user)->allows('files.manage')
                || Gate::forUser($user)->allows('users.edit')) {
                return true;
            }

            return $user->canAccessFile($file, $action);
        }

        $message = ChatMessage::query()
            ->with('chat.participants')
            ->find($file->fileable_id);

        if (! $message) {
            return false;
        }

        // Einmal-Sprachnachrichten duerfen weder ueber die globale Vorschau
        // noch ueber deren Download-Methode am Einmal-Player vorbeigelangen.
        if ($message->isVoice() && $message->view_once) {
            return false;
        }

        $user = auth()->user();
        $chat = $message->chat;
        $participant = $user && $chat
            ? $chat->participantFor($user)
            : null;

        // Bewusst keine globale Gate-Freigabe: Auch Administratoren muessen
        // sichtbare Teilnehmer des privaten Chats sein.
        if (! $user
            || $participant === null
            || $participant->pivot?->hidden_at !== null) {
            return false;
        }

        $visibleSince = $chat->visibleSinceFor($user);

        return $visibleSince === null || ! $message->created_at->lt($visibleSince);
    }

    protected function resetPreview(): void
    {
        $this->open = false;
        $this->fileId = null;
        $this->file = null;

        unset($this->url, $this->canDownload, $this->versionKey);
    }

    public function render()
    {
        return view('livewire.tools.file-pools.file-preview-modal');
    }
}
