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

    public function mount(int $userId): void
    {
        Gate::authorize('users.profiles.view');

        // Sicherstellen, dass der Benutzer existiert
        User::findOrFail($userId);

        $this->userId = $userId;
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

        $this->dispatch('swal:toast', type: 'success', text: __('app.note_added'));
    }

    public function deleteNote(int $noteId): void
    {
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
}
