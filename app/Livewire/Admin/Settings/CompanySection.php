<?php

namespace App\Livewire\Admin\Settings;

use App\Support\CompanyData;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Einstellungen > Firmendaten. Der Akkordeon-Rahmen bleibt in der Seite. */
class CompanySection extends Component
{
    /** @var array<string, string> */
    public array $company = [];

    public function mount(): void
    {
        Gate::authorize('settings.manage');

        $this->company = CompanyData::all(uncached: true);
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
        Gate::authorize('settings.manage');

        return view('livewire.admin.settings.company-section');
    }
}
