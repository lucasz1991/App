<?php

namespace App\Livewire\Operations;

use App\Livewire\Concerns\ResolvesOperationsActor;
use App\Models\LexwareProfile;
use App\Models\User;
use App\Services\Operations\LexwareExportService;
use App\Services\Operations\LexwareProfileService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LexwareSettings extends Component
{
    use ResolvesOperationsActor;

    #[Locked]
    public string $employer = '';

    #[Locked]
    public int $revision = 0;

    #[Locked]
    public array $rowKeys = [];

    #[Locked]
    public string $notice = '';

    public array $form = ['company_name' => '', 'import_profile' => '', 'product_version' => '', 'mappings' => []];

    public bool $testAcknowledged = false;

    public function mount(LexwareProfileService $profiles): void
    {
        $employers = $profiles->employers($this->actor());
        if (count($employers) === 1) {
            $this->selectEmployer((string) reset($employers));
        }
    }

    public function selectEmployer(string $employer): void
    {
        $profile = app(LexwareProfileService::class)->get($employer, $this->actor());
        $this->employer = $employer;
        $this->notice = '';
        $this->loadProfile($profile);
    }

    public function addMapping(): void
    {
        $this->actor();
        $this->form['mappings'][] = ['source_code' => '', 'target_code' => '', 'quantity_kind' => 'base', 'disjoint' => false];
        $this->rowKeys[] = (string) Str::uuid();
        $this->notice = '';
    }

    public function removeMapping(int $index): void
    {
        $this->actor();
        if (array_key_exists($index, $this->rowKeys)) {
            array_splice($this->form['mappings'], $index, 1);
            array_splice($this->rowKeys, $index, 1);
            $this->resetValidation();
            $this->notice = '';
        }
    }

    public function save(LexwareProfileService $profiles): void
    {
        try {
            $profile = $profiles->save($this->employer, $this->revision, $this->form, $this->actor());
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $field = explode('.', $key)[0];
                $errors[array_key_exists($field, $this->form) ? 'form.'.$key : $key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
        $this->loadProfile($profile);
        $this->notice = 'Einstellungen gespeichert.';
    }

    public function downloadSample(): StreamedResponse
    {
        $this->actor();
        $bytes = LexwareExportService::sample();

        return response()->streamDownload(static fn () => print($bytes), 'Lexware-MUSTER-2026-03.txt', [
            'Content-Type' => 'text/plain; charset=Windows-1252',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function confirmTest(LexwareProfileService $profiles): void
    {
        $actor = $this->actor();
        $this->requireSavedForm($profiles, $actor);
        if (! $this->testAcknowledged) {
            throw ValidationException::withMessages(['testAcknowledged' => 'Bitte prüfen und bestätigen Sie zuerst das Ergebnis in der Lexware-Testfirma.']);
        }
        $this->loadProfile($profiles->confirmTest($this->employer, $this->revision, $actor));
        $this->notice = 'Probeimport für diese Profilversion bestätigt.';
    }

    public function activate(LexwareProfileService $profiles): void
    {
        $actor = $this->actor();
        $this->requireSavedForm($profiles, $actor);
        $this->loadProfile($profiles->activate($this->employer, $this->revision, $actor));
        $this->notice = 'Produktivexport für diesen Arbeitgeber aktiviert.';
    }

    public function deactivate(LexwareProfileService $profiles): void
    {
        $this->loadProfile($profiles->deactivate($this->employer, $this->revision, $this->actor()));
        $this->notice = 'Produktivexport für diesen Arbeitgeber gesperrt.';
    }

    private function actor(): User
    {
        $actor = $this->freshActor(requireReady: false);
        abort_unless(app(LexwareProfileService::class)->canManage($actor), 403);

        return $actor;
    }

    private function requireSavedForm(LexwareProfileService $profiles, User $actor): void
    {
        $profile = $profiles->get($this->employer, $actor);
        if (! $profile || $this->revision !== (int) $profile->revision || $this->form !== $this->fields($profile)) {
            throw ValidationException::withMessages(['workflow' => 'Bitte speichern Sie Ihre Eingaben oder laden Sie den aktuellen Stand, bevor Sie das Profil freigeben.']);
        }
    }

    private function fields(?LexwareProfile $profile): array
    {
        return [
            'company_name' => (string) $profile?->company_name,
            'import_profile' => (string) $profile?->import_profile,
            'product_version' => (string) $profile?->product_version,
            'mappings' => $profile?->mappings ?? [],
        ];
    }

    private function loadProfile(?LexwareProfile $profile): void
    {
        $this->form = $this->fields($profile);
        $this->revision = (int) $profile?->revision;
        $this->rowKeys = array_map(static fn () => (string) Str::uuid(), $this->form['mappings']);
        $this->testAcknowledged = false;
        $this->resetValidation();
    }

    public function render(LexwareProfileService $profiles)
    {
        $actor = $this->actor();
        $employers = $profiles->employers($actor);
        $profile = $this->employer !== '' ? $profiles->get($this->employer, $actor) : null;
        $missing = $this->employer !== '' ? $profiles->missing($this->fields($profile)) : [];
        $history = $profile ? $profiles->history($this->employer, $actor) : [];
        $canEditReferences = $actor->can('operations.time.export');

        return view('livewire.operations.lexware-settings', compact('employers', 'profile', 'missing', 'history', 'canEditReferences'));
    }
}
