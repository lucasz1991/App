<?php

namespace App\Livewire\People;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class PersonPreviewModal extends Component
{
    public bool $isOpen = false;

    #[Locked]
    public ?int $personId = null;

    #[On('person-preview:open')]
    public function open(int $userId): void
    {
        $person = $this->findPreviewablePerson($userId);

        $this->personId = $person->id;
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->reset(['isOpen', 'personId']);
    }

    public function updatedIsOpen(bool $isOpen): void
    {
        if (! $isOpen) {
            $this->personId = null;
        }
    }

    public function startChat()
    {
        $viewer = Auth::user();

        abort_unless($viewer && $this->personId, 403);

        $person = $this->findPreviewablePerson($this->personId);

        abort_if($viewer->is($person), 422);
        abort_unless($person->isActive(), 403);

        $chat = DB::transaction(
            fn (): Chat => Chat::directBetween($viewer, $person)
        );

        $this->close();

        return $this->redirectRoute('chat', ['chat' => $chat->id], navigate: true);
    }

    public function previewCommunication(string $channel): void
    {
        abort_unless(in_array($channel, ['voice', 'video'], true), 404);
        abort_unless($this->personId, 404);

        $person = $this->findPreviewablePerson($this->personId);
        $label = $channel === 'video'
            ? __('app.video_call')
            : __('app.voice_call');

        $this->dispatch(
            'swal:toast',
            type: 'info',
            text: __('app.communication_demo_unavailable', [
                'action' => $label,
                'name' => $person->name,
            ]),
        );
    }

    public function render()
    {
        $person = $this->personId
            ? $this->findPreviewablePerson($this->personId)
            : null;

        return view('livewire.people.person-preview-modal', [
            'person' => $person,
        ]);
    }

    private function findPreviewablePerson(int $userId): User
    {
        $viewer = Auth::user();

        abort_unless($viewer, 403);

        $person = User::query()
            ->with('profile:id,user_id,position')
            ->findOrFail($userId);

        // Aktive Kolleginnen und Kollegen duerfen sich gegenseitig in der
        // reduzierten Vorschau sehen. Inaktive Konten bleiben ausschliesslich
        // fuer Administratoren und Verwaltung sichtbar.
        abort_unless(
            $person->isActive() || $viewer->canViewManagementDashboard(),
            403,
        );

        return $person;
    }
}
