<?php

namespace App\Livewire\Admin\UserProfile;

use App\Models\User;
use App\Models\UserNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class UserNotes extends Component
{
    public int $userId;

    public string $noteBody = '';

    /** @var array<int, string> */
    public array $noteBodies = [];

    /** @var array<int, int> */
    public array $dirtyNoteIds = [];

    public function mount(int $userId): void
    {
        Gate::authorize('users.profiles.view');

        // Sicherstellen, dass der Benutzer existiert
        User::findOrFail($userId);

        $this->userId = $userId;
        $this->syncNoteBodies();
        $this->dirtyNoteIds = [];
    }

    public function updatedNoteBodies(mixed $value, int|string $noteId): void
    {
        abort_unless(ctype_digit((string) $noteId), 422);

        $noteId = (int) $noteId;

        if (! in_array($noteId, $this->dirtyNoteIds, true)) {
            $this->dirtyNoteIds[] = $noteId;
        }
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

        $body = trim((string) ($this->noteBodies[$noteId] ?? ''));

        if ($body === $note->body) {
            $this->noteBodies[$noteId] = $note->body;
            $this->forgetDirtyNoteIds([$noteId]);
            $this->resetValidation($property);

            return true;
        }

        $note->update([
            'body' => $body,
        ]);

        $this->noteBodies[$noteId] = $note->body;
        $this->forgetDirtyNoteIds([$noteId]);
        $this->resetValidation($property);
        $this->dispatch(
            'note-inline-saved',
            noteId: $noteId,
            savedFields: ['noteBodies.'.$noteId],
        );

        return true;
    }

    public function savePendingNoteChanges(): bool
    {
        Gate::authorize('users.profiles.view');

        $notes = UserNote::query()
            ->where('user_id', $this->userId)
            ->get()
            ->keyBy('id');

        $requestedNoteIds = array_values(array_unique($this->dirtyNoteIds));
        abort_if(array_diff($requestedNoteIds, $notes->keys()->all()) !== [], 422);
        abort_if(array_diff($requestedNoteIds, array_keys($this->noteBodies)) !== [], 422);

        $changedNotes = $notes->only($requestedNoteIds)->filter(function (UserNote $note): bool {
            return trim((string) $this->noteBodies[$note->id]) !== $note->body;
        });

        if ($changedNotes->isEmpty()) {
            $this->syncNoteBodies();
            $this->forgetDirtyNoteIds($requestedNoteIds);
            $this->resetValidation();
            $this->dispatch(
                'note-inline-saved',
                savedFields: array_map(
                    fn (int $noteId): string => 'noteBodies.'.$noteId,
                    $requestedNoteIds,
                ),
            );

            return true;
        }

        $rules = [];

        foreach ($changedNotes as $note) {
            $this->authorizeNoteMutation($note);
            $rules['noteBodies.'.$note->id] = ['required', 'string', 'max:5000'];
        }

        $this->validate($rules);

        DB::transaction(function () use ($changedNotes): void {
            foreach ($changedNotes as $note) {
                $note->update([
                    'body' => trim((string) $this->noteBodies[$note->id]),
                ]);
            }
        });

        $savedNoteIds = $changedNotes->keys()->values()->all();

        $this->syncNoteBodies();
        $this->forgetDirtyNoteIds($requestedNoteIds);
        $this->resetValidation();
        $this->dispatch(
            'note-inline-saved',
            noteIds: $savedNoteIds,
            savedFields: array_map(
                fn (int $noteId): string => 'noteBodies.'.$noteId,
                $requestedNoteIds,
            ),
        );

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
        $this->forgetDirtyNoteIds([$noteId]);
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

    /**
     * @param  array<int, int>  $noteIds
     */
    private function forgetDirtyNoteIds(array $noteIds): void
    {
        $this->dirtyNoteIds = array_values(array_diff($this->dirtyNoteIds, $noteIds));
    }
}
