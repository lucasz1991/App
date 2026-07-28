<?php

namespace App\Livewire\Admin\UserProfile;

use App\Models\User;
use App\Models\UserNote;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class UserNotes extends Component
{
    public int $userId;

    public string $noteBody = '';

    /** @var array<int, string> */
    public array $noteBodies = [];

    public function mount(int $userId): void
    {
        Gate::authorize('users.profiles.view');

        // Sicherstellen, dass der Benutzer existiert
        User::findOrFail($userId);

        $this->userId = $userId;
        $this->syncNoteBodies();
    }

    public function addNote(): void
    {
        Gate::authorize('users.profiles.view');

        $this->validate([
            'noteBody' => 'required|string|max:5000',
        ]);

        UserNote::create([
            'user_id' => $this->userId,
            'author_id' => auth()->id(),
            'body' => trim($this->noteBody),
        ]);

        $this->reset('noteBody');
        $this->resetValidation('noteBody');
        $this->syncNoteBodies();

        $this->dispatch('swal:toast', type: 'success', text: __('app.note_added'));
    }

    public function saveNote(int $noteId): bool
    {
        Gate::authorize('users.profiles.view');

        $note = UserNote::query()
            ->where('user_id', $this->userId)
            ->find($noteId);

        if (! $note) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.note_not_found'));

            return false;
        }

        $this->authorizeNoteMutation($note);
        $property = 'noteBodies.'.$noteId;
        $this->validateOnly($property, [
            $property => ['required', 'string', 'max:5000'],
        ]);

        $note->update([
            'body' => trim((string) ($this->noteBodies[$noteId] ?? '')),
        ]);

        $this->noteBodies[$noteId] = $note->body;
        $this->resetValidation($property);
        $this->dispatch('note-inline-saved', noteId: $noteId);

        return true;
    }

    public function cancelNoteEdit(int $noteId): void
    {
        Gate::authorize('users.profiles.view');

        $note = UserNote::query()
            ->where('user_id', $this->userId)
            ->findOrFail($noteId);

        $this->authorizeNoteMutation($note);
        $this->noteBodies[$noteId] = $note->body;
        $this->resetValidation('noteBodies.'.$noteId);
    }

    public function deleteNote(int $noteId): void
    {
        Gate::authorize('users.profiles.view');

        $note = UserNote::query()
            ->where('user_id', $this->userId)
            ->find($noteId);

        if (! $note) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.note_not_found'));

            return;
        }

        // Loeschen nur durch den Verfasser oder einen Admin
        if ($note->author_id !== auth()->id() && ! auth()->user()->isAdmin()) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.no_permission'));

            return;
        }

        $note->delete();
        unset($this->noteBodies[$noteId]);

        $this->dispatch('swal:toast', type: 'success', text: __('app.note_deleted'));
    }

    public function render()
    {
        $notes = UserNote::query()
            ->with('author')
            ->where('user_id', $this->userId)
            ->latest()
            ->get();

        return view('livewire.admin.user-profile.user-notes', [
            'notes' => $notes,
        ]);
    }

    private function authorizeNoteMutation(UserNote $note): void
    {
        if ($note->author_id !== auth()->id() && ! auth()->user()->isAdmin()) {
            abort(403);
        }
    }

    private function syncNoteBodies(): void
    {
        $this->noteBodies = UserNote::query()
            ->where('user_id', $this->userId)
            ->pluck('body', 'id')
            ->mapWithKeys(fn (string $body, int|string $id): array => [(int) $id => $body])
            ->all();
    }
}
