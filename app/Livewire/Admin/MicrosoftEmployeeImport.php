<?php

namespace App\Livewire\Admin;

use App\Services\DeviceManagement\MicrosoftEmployeeImportService;
use App\Services\DeviceManagement\MicrosoftEmployeeImportSettings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class MicrosoftEmployeeImport extends Component
{
    public bool $enabled = false;

    public string $pilotObjectIds = '';

    public bool $confirmInactiveImport = false;

    #[Locked]
    public string $loadedFingerprint = '';

    public function mount(): void
    {
        $this->authorizeAdministrator();
        $this->reloadForm();
    }

    public function save(): void
    {
        $this->authorizeAdministrator();
        $this->resetValidation();

        try {
            app(MicrosoftEmployeeImportSettings::class)->save([
                'enabled' => $this->enabled,
                'pilot_object_ids' => preg_split('/[\s,;]+/', strtolower(trim($this->pilotObjectIds)), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ], auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $field = str_starts_with($key, 'pilot_object_ids') ? 'pilotObjectIds' : 'enabled';
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        } catch (Throwable) {
            $this->addError('operation', 'Die Pilot-Einstellungen konnten nicht gespeichert werden.');

            return;
        }

        $this->reloadForm();
        $this->dispatch('swal:toast', type: 'success', text: 'Benutzerimport-Pilot gespeichert. Es wurden noch keine Mitarbeiter importiert.');
    }

    public function preview(): void
    {
        $this->authorizeAdministrator();
        $this->resetValidation('operation');
        $this->confirmInactiveImport = false;

        if (! $this->savedConfigurationMatches()) {
            return;
        }

        try {
            app(MicrosoftEmployeeImportService::class)->preview($this->loadedFingerprint, auth()->user());
        } catch (Throwable) {
            $this->addError('operation', 'Die Vorschau konnte nicht abgeschlossen werden. Prüfen Sie die Microsoft-Verbindung und das Benutzer-Leserecht.');
        }
    }

    public function importInactive(): void
    {
        $this->authorizeAdministrator();
        Gate::authorize('employees.create');
        $this->resetValidation('operation');

        if (! $this->confirmInactiveImport) {
            $this->addError('operation', 'Bestätigen Sie zuerst die Anlage ausschließlich inaktiver Mitarbeiter.');

            return;
        }
        $this->confirmInactiveImport = false;

        if (! $this->savedConfigurationMatches()) {
            return;
        }

        try {
            app(MicrosoftEmployeeImportService::class)->import($this->loadedFingerprint, auth()->user());
        } catch (Throwable) {
            $this->addError('operation', 'Der Import konnte nicht abgeschlossen werden. Prüfen Sie den Ergebnisstatus; es werden keine technischen Fehlerdetails oder Zugangsdaten angezeigt.');
        }
    }

    private function savedConfigurationMatches(): bool
    {
        $settings = app(MicrosoftEmployeeImportSettings::class);
        $saved = $settings->forForm();
        if (! ($saved['enabled'] ?? false)) {
            $this->addError('operation', 'Der Benutzerimport-Pilot ist deaktiviert. Speichern Sie zuerst die freigegebene Pilot-Auswahl.');

            return false;
        }
        $typedIds = preg_split('/[\s,;]+/', strtolower(trim($this->pilotObjectIds)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $typedIds = array_values(array_unique($typedIds));
        $savedIds = $saved['pilot_object_ids'] ?? [];
        sort($typedIds);
        sort($savedIds);

        if ($typedIds !== $savedIds || $this->enabled !== (bool) ($saved['enabled'] ?? false)
            || ! hash_equals($this->loadedFingerprint, $settings->snapshot()['fingerprint'])) {
            $this->addError('operation', 'Die Konfiguration wurde geändert. Laden Sie die Seite neu oder speichern Sie die Pilot-Einstellungen vor dem Abruf.');

            return false;
        }

        return true;
    }

    private function reloadForm(): void
    {
        $settings = app(MicrosoftEmployeeImportSettings::class);
        $form = $settings->forForm();
        $this->enabled = (bool) ($form['enabled'] ?? false);
        $this->pilotObjectIds = implode("\n", $form['pilot_object_ids'] ?? []);
        $this->loadedFingerprint = $settings->snapshot()['fingerprint'];
        $this->confirmInactiveImport = false;
    }

    private function authorizeAdministrator(): void
    {
        Gate::authorize('settings.manage');
        abort_unless(auth()->user()?->isSuperAdmin() && auth()->user()->isActive(), 403);
    }

    public function render()
    {
        $this->authorizeAdministrator();

        return view('livewire.admin.microsoft-employee-import', [
            'importStatus' => app(MicrosoftEmployeeImportSettings::class)->status(),
        ]);
    }
}
