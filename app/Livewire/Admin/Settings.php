<?php

namespace App\Livewire\Admin;

use App\Models\Setting;
use App\Support\Ai\AssistantSettings;
use App\Support\Ai\OpenRouterSettings;
use App\Support\Calls\CallSettings;
use App\Support\CompanyData;
use App\Support\Sound\SoundLibrary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Settings extends Component
{
    /** Wartungsmodus: nur Admins koennen die App nutzen, alle anderen sehen die Wartungsseite. */
    public bool $maintenanceMode = false;

    /** Systemweite Freigabe fuer RailTime Assist. */
    public bool $assistantEnabled = true;

    /** Empfaengeradresse fuer Systemnachrichten / Testmails aus der Mailverwaltung. */
    public string $adminEmail = '';

    /** Gueltigkeit neuer Mitarbeiter-Einladungslinks in Tagen. */
    public int $invitationExpiryDays = 7;

    /** @var array<string, string> */
    public array $company = [];

    /**
     * Betriebswerte der Anruffunktion (Klingeldauer, Token-Gueltigkeit, ...).
     *
     * @var array<string, int>
     */
    public array $calls = [];

    /**
     * Systemweite Standard-Ton-Zuordnung (Ereignis => Signatur).
     *
     * @var array<string, string>
     */
    public array $sounds = [];

    /**
     * OpenRouter-Zugang und Modellprofile — nur fuer den Super-Admin.
     *
     * @var array<string, mixed>
     */
    public array $openRouter = [];

    public function mount(): void
    {
        Gate::authorize('settings.manage');
        $this->loadSettings();
    }

    public function loadSettings(): void
    {
        $this->maintenanceMode = (bool) (Setting::getValueUncached('system', 'maintenance_mode') ?? false);
        $this->assistantEnabled = AssistantSettings::enabled(uncached: true);
        $this->adminEmail = (string) (Setting::getValueUncached('mails', 'admin_email') ?? '');

        $days = (int) (Setting::getValueUncached('invitations', 'expiry_days') ?? 7);
        $this->invitationExpiryDays = $days > 0 ? $days : 7;
        $this->company = CompanyData::all(uncached: true);
        $this->calls = CallSettings::all(uncached: true);
        $this->sounds = SoundLibrary::systemMap();

        if ($this->isSuperAdmin()) {
            $this->openRouter = OpenRouterSettings::forForm();
        }
    }

    /**
     * Der Super-Admin-Bereich buendelt Zugangsdaten zu externen Diensten.
     * Ein normaler Administrator soll den OpenRouter-Schluessel weder sehen
     * noch tauschen koennen, deshalb reicht `settings.manage` hier nicht.
     */
    public function isSuperAdmin(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    /** OpenRouter-Verbindung und Modellprofile speichern. */
    public function saveOpenRouter(): void
    {
        Gate::authorize('settings.manage');
        abort_unless($this->isSuperAdmin(), 403);

        $rules = [
            'openRouter.api_url' => ['required', 'url', 'max:2048'],
            'openRouter.api_key' => ['nullable', 'string', 'max:512'],
            'openRouter.referer_url' => ['nullable', 'url', 'max:2048'],
            'openRouter.model_title' => ['nullable', 'string', 'max:255'],
            'openRouter.stream_enabled' => ['boolean'],
        ];

        foreach (array_keys(OpenRouterSettings::MODEL_FIELDS) as $field) {
            $rules["openRouter.{$field}"] = ['nullable', 'string', 'max:255'];
        }

        foreach (OpenRouterSettings::RUNTIME_FIELDS as $field => [, $min, $max]) {
            $rules["openRouter.{$field}"] = $field === 'temperature'
                ? ['required', 'numeric', "min:{$min}", "max:{$max}"]
                : ['required', 'integer', "min:{$min}", "max:{$max}"];
        }

        $this->validate($rules);

        OpenRouterSettings::save($this->openRouter);

        // Zurueckgelesen, damit die Oberflaeche exakt zeigt, was gilt — und
        // der Schluessel weiterhin nur als Maske erscheint.
        $this->openRouter = OpenRouterSettings::forForm();

        $this->dispatch('openrouter-settings-saved');
        $this->skipRender();
    }

    /** Betriebswerte der Anruffunktion speichern. */
    public function saveCalls(): void
    {
        Gate::authorize('settings.manage');

        $rules = [];

        foreach (CallSettings::FIELDS as $key => [, $min, $max]) {
            $rules["calls.{$key}"] = ['required', 'integer', "min:{$min}", "max:{$max}"];
        }

        $this->validate($rules);

        CallSettings::save($this->calls);

        // Zurueckgelesen, damit die Oberflaeche exakt zeigt, was gilt.
        $this->calls = CallSettings::all(uncached: true);

        $this->dispatch('call-settings-saved');
        $this->skipRender();
    }

    /**
     * Systemweite Standard-Ton-Zuordnung speichern (Ereignis => Signatur).
     */
    public function saveSounds(): void
    {
        Gate::authorize('settings.manage');

        $this->validate([
            'sounds' => ['required', 'array'],
            'sounds.*' => ['required', 'string', Rule::in(SoundLibrary::signatures())],
        ]);

        foreach (SoundLibrary::events() as $event) {
            if (array_key_exists($event, $this->sounds)) {
                Setting::setValue('sounds', $event, (string) $this->sounds[$event]);
            }
        }

        $this->dispatch(
            'sound-settings-saved',
            fields: array_map(fn (string $event): string => 'sounds.'.$event, SoundLibrary::events()),
        );

        // Neue wirksame Zuordnung sofort im Browser uebernehmen (rt-sounds.js).
        $this->dispatch('rt-sounds:map', map: SoundLibrary::mapFor(auth()->user()));
        $this->skipRender();
    }

    public function saveSystem(): void
    {
        Gate::authorize('settings.manage');

        Setting::setValue('system', 'maintenance_mode', (bool) $this->maintenanceMode);

        // Der MaintenanceMode-Middleware-Cache liest den Schluessel 'maintenance_mode'
        // 60s lang aus dem Cache — nach dem Umschalten sofort invalidieren.
        Cache::forget('maintenance_mode');

        $this->dispatch('system-settings-saved', fields: ['maintenanceMode']);
    }

    public function updatedMaintenanceMode(): void
    {
        $this->saveSystem();
    }

    public function saveAssistant(): void
    {
        Gate::authorize('settings.manage');

        AssistantSettings::setEnabled($this->assistantEnabled);

        $this->dispatch('assistant-settings-saved', fields: ['assistantEnabled']);
    }

    public function updatedAssistantEnabled(): void
    {
        $this->saveAssistant();
    }

    public function saveInvitations(): void
    {
        Gate::authorize('settings.manage');

        $this->validate([
            'invitationExpiryDays' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        Setting::setValue('invitations', 'expiry_days', (int) $this->invitationExpiryDays);

        $this->dispatch('invitation-settings-saved', fields: ['invitationExpiryDays']);
        $this->skipRender();
    }

    public function saveMails(): void
    {
        Gate::authorize('settings.manage');

        $this->validate([
            'adminEmail' => ['nullable', 'email'],
        ]);

        Setting::setValue('mails', 'admin_email', (string) $this->adminEmail);

        $this->dispatch('mail-settings-saved', fields: ['adminEmail']);
        $this->skipRender();
    }

    public function saveCompany(): void
    {
        Gate::authorize('settings.manage');

        $validated = $this->validate([
            'company.name' => ['required', 'string', 'max:160'],
            'company.street' => ['required', 'string', 'max:160'],
            'company.postal_code' => ['required', 'string', 'max:20'],
            'company.city' => ['required', 'string', 'max:120'],
            'company.country' => ['required', 'string', 'max:120'],
            'company.phone' => ['nullable', 'string', 'max:60'],
            'company.emergency_phone' => ['nullable', 'string', 'max:60'],
            'company.email' => ['required', 'email', 'max:160'],
            'company.website' => ['nullable', 'url', 'max:255'],
            'company.managing_directors' => ['nullable', 'string', 'max:255'],
            'company.register_court' => ['nullable', 'string', 'max:160'],
            'company.commercial_register_number' => ['nullable', 'string', 'max:80'],
            'company.vat_id' => ['nullable', 'string', 'max:80'],
            'company.tax_number' => ['nullable', 'string', 'max:80'],
        ]);

        CompanyData::save($validated['company']);

        $this->dispatch(
            'company-settings-saved',
            fields: array_map(
                fn (string $field): string => 'company.'.$field,
                array_keys($validated['company']),
            ),
        );
        $this->skipRender();
    }

    public function render()
    {
        return view('livewire.admin.settings', [
            'isSuperAdmin' => $this->isSuperAdmin(),
        ])->layout('layouts.master', ['area' => 'admin']);
    }
}
