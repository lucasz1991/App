<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\Operations\AiIntakeMailboxService;
use App\Support\Ai\OpenRouterSettings;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class AiDispositionConfiguration extends Component
{
    public array $form = [];
    public array $diagnostic = [];
    #[Locked]
    public array $historyPreview = [];
    #[Locked]
    public string $historyToken = '';
    public array $historySelection = [];

    public function mount(): void
    {
        $this->actor();
        $this->form = AiDispositionSettings::forForm();
    }

    private function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User && $actor->status && $actor->isSuperAdmin(), 403);
        Gate::forUser($actor)->authorize('settings.manage');

        return $actor;
    }

    public function save(): void
    {
        $actor = $this->actor();
        $this->resetValidation();
        try {
            AiDispositionSettings::save($this->form, $actor);
            $this->form = AiDispositionSettings::forForm();
            $this->historyPreview = [];
            $this->historyToken = '';
            $this->historySelection = [];
            $this->dispatch('swal:toast', type: 'success', text: 'AI-Disposition wurde gespeichert.');
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('form.'.$field, implode(' ', $messages));
            }
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 403) throw $exception;
            $this->addError('form', $exception->getStatusCode() === 409
                ? 'Die Konfiguration wurde inzwischen geändert. Laden Sie die Einstellungen erneut.'
                : ($exception->getMessage() ?: 'Bitte die vollständige Postfach-Konfiguration prüfen.'));
        }
    }

    public function reloadConfiguration(): void
    {
        $this->actor();
        $this->form = AiDispositionSettings::forForm();
        $this->resetValidation();
    }

    public function probe(): void
    {
        $actor = $this->actor();
        $this->resetValidation('connection');
        try {
            $this->diagnostic = app(AiIntakeMailboxService::class)->probe($actor);
        } catch (Throwable) {
            $this->addError('connection', 'Die gespeicherte Postfachverbindung konnte nicht geprüft werden. Server, Zugang und Verschlüsselung prüfen.');
        }
    }

    public function probeWorker(): void
    {
        $this->actor();
        $this->resetValidation('runtime');
        try {
            app(AiIntakeMailboxService::class)->probeWorker($this->actor());
            $this->dispatch('swal:toast', type: 'info', text: 'Der Hintergrundtest wurde eingeplant. Der Status zeigt die tatsächliche Verarbeitung.');
        } catch (Throwable) {
            $this->addError('runtime', 'Der Hintergrundtest konnte nicht eingeplant werden. Datenbank und Worker prüfen.');
        }
    }

    public function pollNow(): void
    {
        $this->actor();
        $this->resetValidation('runtime');
        try {
            app(AiIntakeMailboxService::class)->pollNow($this->actor());
            $this->dispatch('swal:toast', type: 'info', text: 'Der Postfachabruf wurde eingeplant.');
        } catch (Throwable) {
            $this->addError('runtime', 'Der Abruf konnte nicht eingeplant werden. Aktivierung und Betriebsstatus prüfen.');
        }
    }

    public function previewHistory(): void
    {
        $this->actor();
        $this->resetValidation('history');
        try {
            $preview = app(AiIntakeMailboxService::class)->previewRecent($this->actor(), 20);
            $this->historyPreview = $preview['messages'] ?? $preview['headers'] ?? [];
            $this->historyToken = $preview['token'] ?? '';
            $this->historySelection = [];
        } catch (Throwable) {
            $this->addError('history', 'Die Vorschau älterer Nachrichten konnte nicht geladen werden. Gespeicherte Verbindung prüfen.');
        }
    }

    public function importHistory(): void
    {
        $this->actor();
        $this->validate(['historySelection' => ['required', 'array', 'min:1', 'max:20'], 'historySelection.*' => ['integer', 'min:1']]);
        try {
            app(AiIntakeMailboxService::class)->importPreview($this->actor(), $this->historyToken, $this->historySelection);
            $this->historyToken = '';
            $this->historyPreview = [];
            $this->historySelection = [];
            $this->dispatch('swal:toast', type: 'success', text: 'Die ausgewählten Nachrichten wurden zum Import vorgemerkt.');
        } catch (Throwable) {
            $this->addError('history', 'Die Auswahl konnte nicht übernommen werden. Laden Sie eine neue Vorschau und prüfen Sie die Aktivierung.');
        }
    }

    public function render()
    {
        $this->actor();
        return view('livewire.admin.ai-disposition-configuration', [
            'status' => AiDispositionSettings::status(),
            'runtime' => app(AiIntakeMailboxService::class)->status(),
            'schemaReady' => AiIntakeSchema::ready(),
            'models' => array_intersect_key(OpenRouterSettings::forForm(), array_flip(['text_model', 'data_model', 'image_understanding_model', 'speech_to_text_model'])),
            'supervisors' => User::where('status', true)->orderBy('name')->get()->filter(fn (User $user) => $user->can('operations.inquiries.manage')),
        ]);
    }
}
