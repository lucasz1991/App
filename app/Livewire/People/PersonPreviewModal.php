<?php

namespace App\Livewire\People;

use App\Livewire\Concerns\InteractsWithPersonQuickActions;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class PersonPreviewModal extends Component
{
    use InteractsWithPersonQuickActions;

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

    /**
     * Die Schnellaktionen uebergeben stets die Benutzer-ID. Sie ist hier
     * redundant, dient aber als Abgleich gegen eine veraltete Vorschau.
     */
    public function startChat(?int $userId = null)
    {
        $viewer = Auth::user();

        abort_unless($viewer && $this->personId, 403);
        abort_if($userId !== null && $userId !== $this->personId, 422);

        $person = $this->findPreviewablePerson($this->personId);

        abort_if($viewer->is($person), 422);
        abort_unless($person->isActive(), 403);

        $chat = DB::transaction(
            fn (): Chat => Chat::directBetween($viewer, $person)
        );

        $this->close();

        return $this->redirectRoute('chat', ['chat' => $chat->id], navigate: true);
    }

    /**
     * Anruf aus der Vorschau: schliesst das Fenster und uebergibt an die
     * gemeinsame Aktion, damit hier dieselbe Logik wie in der Mitarbeiter-
     * liste greift.
     */
    public function startPreviewCall(int $userId, string $mode)
    {
        abort_unless($this->personId, 404);
        abort_if($userId !== $this->personId, 422);

        $personId = $this->personId;
        $this->close();

        return $this->startDirectCall($personId, $mode);
    }

    /**
     * Interne Nachricht aus der Vorschau verfassen.
     */
    public function messagePerson(?int $userId = null): void
    {
        abort_unless($this->personId, 404);
        abort_if($userId !== null && $userId !== $this->personId, 422);

        $personId = $this->personId;
        $this->close();

        $this->openMessage($personId);
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
