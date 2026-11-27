<?php

namespace App\Livewire\Concerns\Chat;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Neue Gespraeche und Gruppenverwaltung: Direktchat starten, Gruppe anlegen,
 * Gruppenname, -bild und Mitglieder aendern sowie die seitenweisen
 * Personenlisten der zugehoerigen Dialoge.
 *
 * Die Paginator-Namen (directContactsPage, groupParticipantsPage,
 * groupMembersPage) stehen auch in ChatBox::pageResetProperties().
 */
trait ManagesChatGroups
{
    private const MEMBER_PICKER_PER_PAGE = 6;

    /** Modal: neuer Chat / neue Gruppe */
    public bool $showNewChat = false;

    public string $newChatTab = 'direct'; // direct | group

    public string $groupName = '';

    public array $groupParticipants = [];

    public string $directContactSearch = '';

    public string $groupParticipantSearch = '';

    public bool $showGroupSettings = false;

    public string $groupEditName = '';

    /** Gruppenbild-Upload in den Gruppeneinstellungen. */
    public $groupPhotoUpload = null;

    /** @var array<int, int|string> */
    public array $groupMemberIds = [];

    public string $groupMemberSearch = '';

    public function startDirect(int $userId): void
    {
        $other = User::query()->where('status', true)->findOrFail($userId);

        abort_if($other->id === auth()->id(), 422);

        $chat = Chat::directBetween(auth()->user(), $other);

        $this->reset([
            'showNewChat',
            'showGroupSettings',
            'groupName',
            'groupParticipants',
            'directContactSearch',
            'groupParticipantSearch',
            'groupMemberSearch',
            'search',
        ]);
        $this->resetMemberPickerPages();
        $this->openChat($chat->id);
    }

    public function createGroup(): void
    {
        $this->validate([
            'groupName' => ['required', 'string', 'max:80'],
            'groupParticipants' => ['required', 'array', 'min:1'],
        ]);

        $ids = User::query()
            ->where('status', true)
            ->whereIn('id', array_map('intval', $this->groupParticipants))
            ->where('id', '!=', auth()->id())
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->addError('groupParticipants', __('app.select_participants'));

            return;
        }

        $joinedAt = now();
        $chat = Chat::create([
            'type' => 'group',
            'name' => trim($this->groupName),
            'created_by' => auth()->id(),
        ]);

        // Identische Pivot-Spalten je Zeile (sonst schlaegt der Bulk-Insert fehl)
        $chat->participants()->attach(
            $ids->mapWithKeys(fn ($id) => [$id => [
                'last_read_at' => null,
                'last_opened_at' => null,
                'joined_at' => $joinedAt,
                'hidden_at' => null,
                'cleared_at' => null,
            ]])
                ->put(auth()->id(), [
                    'last_read_at' => $joinedAt,
                    'last_opened_at' => $joinedAt,
                    'joined_at' => $joinedAt,
                    'hidden_at' => null,
                    'cleared_at' => null,
                ])
                ->all()
        );

        $this->reset([
            'showNewChat',
            'groupName',
            'groupParticipants',
            'directContactSearch',
            'groupParticipantSearch',
            'search',
        ]);
        $this->resetMemberPickerPages();
        $this->dispatch('swal:toast', type: 'success', text: __('app.group_created'));
        $this->openChat($chat->id);
    }

    public function updatedShowNewChat(bool $isOpen): void
    {
        if (! $isOpen) {
            return;
        }

        $this->reset(['directContactSearch', 'groupParticipantSearch']);
        $this->resetMemberPickerPages();
    }

    public function updatedNewChatTab(string $tab): void
    {
        if (! in_array($tab, ['direct', 'group'], true)) {
            $this->newChatTab = 'direct';
        }

        $this->resetMemberPickerPages();
    }

    /** Reiterwechsel im Dialog „Neuer Chat“ (x-operations.panel.tabs mit action). */
    public function selectNewChatTab(string $tab): void
    {
        $this->newChatTab = $tab;
        $this->updatedNewChatTab($tab);
    }

    public function openGroupSettings(): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        abort_unless($chat->canManageGroup(auth()->id()), 403);

        $this->resetValidation(['groupEditName', 'groupMemberIds', 'groupPhotoUpload']);
        $this->groupEditName = (string) $chat->name;

        if ($this->groupPhotoUpload instanceof TemporaryUploadedFile) {
            $this->groupPhotoUpload->delete();
        }
        $this->groupPhotoUpload = null;
        $this->groupMemberIds = $chat->participants
            ->where('id', '!=', auth()->id())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
        $this->groupMemberSearch = '';
        $this->resetPage('groupMembersPage');
        $this->showGroupSettings = true;
    }

    public function saveGroupSettings(): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $this->validate([
            'groupEditName' => ['required', 'string', 'max:80'],
            'groupMemberIds' => ['required', 'array', 'min:1'],
            'groupMemberIds.*' => ['integer', 'distinct'],
            'groupPhotoUpload' => ['nullable', 'image', 'max:5120'],
        ]);

        $chat = $this->myChat($this->selectedChatId);
        abort_unless($chat->canManageGroup(auth()->id()), 403);

        $requestedIds = collect($this->groupMemberIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id !== (int) auth()->id())
            ->unique()
            ->values();
        $currentIds = $chat->participants->pluck('id')->map(fn ($id): int => (int) $id);
        $validMemberIds = User::query()
            ->whereIn('id', $requestedIds)
            ->where(function ($query) use ($currentIds): void {
                $query->where('status', true)
                    ->orWhereIn('id', $currentIds);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($validMemberIds->isEmpty()) {
            $this->addError('groupMemberIds', __('app.select_participants'));

            return;
        }

        DB::transaction(function () use ($chat, $validMemberIds): void {
            $lockedChat = Chat::query()->lockForUpdate()->findOrFail($chat->id);
            $lockedChat->load('participants');
            abort_unless($lockedChat->canManageGroup(auth()->id()), 403);

            $existingParticipants = $lockedChat->participants->keyBy(
                fn (User $participant): int => (int) $participant->id
            );
            $desiredIds = $validMemberIds
                ->push((int) auth()->id())
                ->unique()
                ->values();
            $pivotRows = [];

            foreach ($desiredIds as $userId) {
                $existing = $existingParticipants->get((int) $userId);
                $pivotRows[(int) $userId] = [
                    'last_read_at' => $existing?->pivot?->last_read_at,
                    'last_opened_at' => $existing?->pivot?->last_opened_at,
                    'joined_at' => $existing?->pivot?->joined_at
                        ?: $existing?->pivot?->created_at
                        ?: now(),
                    'hidden_at' => null,
                    'cleared_at' => $existing?->pivot?->cleared_at,
                ];
            }

            $lockedChat->participants()->sync($pivotRows);

            $changes = ['name' => trim($this->groupEditName)];

            if ($this->groupPhotoUpload instanceof TemporaryUploadedFile) {
                $newPath = $this->groupPhotoUpload->store('chat-photos', 'public');

                // Altes Bild erst NACH erfolgreichem Ablegen des neuen
                // entfernen — schlaegt der Upload fehl, bleibt das alte stehen.
                if ($newPath) {
                    if ($lockedChat->photo_path) {
                        Storage::disk('public')->delete($lockedChat->photo_path);
                    }

                    $changes['photo_path'] = $newPath;
                }
            }

            $lockedChat->forceFill($changes)->save();
        });

        if ($this->groupPhotoUpload instanceof TemporaryUploadedFile) {
            $this->groupPhotoUpload->delete();
        }
        $this->groupPhotoUpload = null;

        $this->groupMemberIds = $validMemberIds->all();
        $this->groupMemberSearch = '';
        $this->resetPage('groupMembersPage');
        $this->showGroupSettings = false;
        $this->dispatch('swal:toast', type: 'success', text: __('app.group_updated'));
        $this->dispatch('inbox:refresh');
    }

    protected function resetMemberPickerPages(): void
    {
        $this->resetPage('directContactsPage');
        $this->resetPage('groupParticipantsPage');
        $this->resetPage('groupMembersPage');
    }

    protected function contactPickerQuery(string $search = '')
    {
        $needle = trim($search);

        return User::query()
            ->with(['profile', 'currentTeam'])
            ->withMax('activities as last_activity_at', 'created_at')
            ->where('status', true)
            ->where('id', '!=', auth()->id())
            ->when($needle !== '', function ($query) use ($needle): void {
                $query->where(function ($query) use ($needle): void {
                    $query->where('name', 'like', '%'.$needle.'%')
                        ->orWhere('email', 'like', '%'.$needle.'%');
                });
            })
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * Seitenweise Personenlisten fuer die Dialoge „Neuer Chat“, „Neue Gruppe“
     * und die Gruppenverwaltung. Geladen wird nur die Liste des gerade
     * offenen Dialogs; alle anderen bleiben null.
     *
     * @return array{contacts: mixed, groupParticipantsPaginator: mixed, groupCandidates: mixed}
     */
    protected function memberPickerViewData(?Chat $selectedChat, User $me): array
    {
        $contacts = null;
        $groupParticipants = null;
        $groupCandidates = null;

        if ($this->showNewChat && $this->newChatTab === 'direct') {
            $contacts = $this->contactPickerQuery($this->directContactSearch)
                ->paginate(
                    self::MEMBER_PICKER_PER_PAGE,
                    ['*'],
                    'directContactsPage',
                );
        }

        if ($this->showNewChat && $this->newChatTab === 'group') {
            $groupParticipants = $this->contactPickerQuery($this->groupParticipantSearch)
                ->paginate(
                    self::MEMBER_PICKER_PER_PAGE,
                    ['*'],
                    'groupParticipantsPage',
                );
        }

        if (
            $this->showGroupSettings
            && $selectedChat?->isGroup()
            && $selectedChat->canManageGroup($me)
        ) {
            $currentMemberIds = $selectedChat->participants
                ->where('id', '!=', $me->id)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values();
            $needle = trim($this->groupMemberSearch);

            $groupCandidates = User::query()
                ->with(['profile', 'currentTeam'])
                ->withMax('activities as last_activity_at', 'created_at')
                ->where('id', '!=', $me->id)
                ->where(function ($query) use ($currentMemberIds): void {
                    $query->where('status', true)
                        ->orWhereIn('id', $currentMemberIds);
                })
                ->when($needle !== '', function ($query) use ($needle): void {
                    $query->where(function ($query) use ($needle): void {
                        $query->where('name', 'like', '%'.$needle.'%')
                            ->orWhere('email', 'like', '%'.$needle.'%');
                    });
                })
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(
                    self::MEMBER_PICKER_PER_PAGE,
                    ['*'],
                    'groupMembersPage',
                );
        }

        return [
            'contacts' => $contacts,
            'groupParticipantsPaginator' => $groupParticipants,
            'groupCandidates' => $groupCandidates,
        ];
    }
}
