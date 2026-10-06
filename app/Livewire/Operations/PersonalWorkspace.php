<?php

namespace App\Livewire\Operations;

use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsNavigation;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PersonalWorkspace extends Component
{
    #[Locked]
    public string $area = 'work';

    #[Locked]
    public string $initialTab = '';

    public function mount(): void
    {
        $area = request()->query('area', 'work');
        $this->area = is_string($area) && array_key_exists($area, $this->areas()) ? $area : 'work';
        $tab = request()->query('tab', '');
        $this->initialTab = is_string($tab) ? mb_substr($tab, 0, 40) : '';
    }

    public function areas(): array
    {
        return array_filter([
            'work' => ['value' => 'work', 'label' => 'Mein Arbeitstag', 'icon' => 'fa-calendar-day'],
            'inbox' => ['value' => 'inbox', 'label' => 'Arbeitsliste', 'icon' => 'fa-inbox'],
            'capacity' => CustomerPortalIntakeSchema::ready() ? ['value' => 'capacity', 'label' => 'Kapazitätsanfragen', 'icon' => 'fa-calendar-check'] : null,
            'personnel' => OperationsNavigation::enhancementReady('personnel-enhancements') ? ['value' => 'personnel', 'label' => 'Mein Personalbereich', 'icon' => 'fa-user'] : null,
            'operations' => OperationsNavigation::enhancementReady('operations-enhancements') ? ['value' => 'operations', 'label' => 'Nachweise & Reisen', 'icon' => 'fa-file-check'] : null,
        ]);
    }

    public function setArea(string $area): void
    {
        OperationsAccess::own(auth()->user(), auth()->id());
        abort_unless(array_key_exists($area, $this->areas()), 404);
        $this->area = $area;
    }

    public function render()
    {
        OperationsAccess::requireReady();
        OperationsAccess::own(auth()->user(), auth()->id());
        abort_unless(array_key_exists($this->area, $this->areas()), 503);

        return view('livewire.operations.personal-workspace')->layout('layouts.master');
    }
}
