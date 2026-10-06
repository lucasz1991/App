<?php

namespace App\Livewire\Operations;

use App\Models\Shift;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use App\Support\Operations\PlanningEnhancementSchema;
use App\Support\Operations\WorkforcePlanningSchema;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PlanningPageWorkspace extends Component
{
    #[Locked]
    public string $page = 'planning';

    #[Locked]
    public string $view = '';

    #[Locked]
    public string $section = '';

    #[Locked]
    public array $context = [];

    public function mount(string $page, string $initialView = '', string $initialSection = '', array $context = []): void
    {
        abort_unless(in_array($page, ['shifts', 'planning', 'duty'], true), 404);
        $this->page = $page;
        $this->context = $context;
        if (isset($context['shift'])) {
            OperationsAccess::authorize(auth()->user(), 'operations.manage');
            Shift::findOrFail((int) $context['shift']);
        }
        $views = $this->views();
        abort_unless($views, 403);
        abort_if($initialView !== '' && ! isset($views[$initialView]), 403);
        $this->view = $initialView ?: array_key_first($views);
        $sections = $this->sections();
        abort_if($initialSection !== '' && ! isset($sections[$initialSection]), 403);
        $this->section = $initialSection ?: (array_key_first($sections) ?? '');
    }

    public function views(): array
    {
        return OperationsPages::views(auth()->user(), $this->page);
    }

    public function sections(): array
    {
        return match ($this->page.':'.$this->view) {
            'planning:capacity' => ['capacity' => 'Kapazität'],
            'planning:staff' => ['pools' => 'Pools', 'wishes' => 'Wünsche', 'periods' => 'Abgabefristen', 'offers' => 'Dienstangebote'],
            'planning:tools' => (WorkforcePlanningSchema::ready() ? ['variants' => 'Planvarianten'] : []) + (PlanningEnhancementSchema::ready() ? ['bundles' => 'Qualifikationsbündel', 'fairness' => 'Verteilung', 'rotations' => 'Rotationen', 'teams' => 'Teams', 'chains' => 'Dienstketten', 'positions' => 'Stellenplanung', 'optimizer' => 'Planvorschlag'] : []),
            'planning:logistics' => ['travel' => 'Reisen', 'partners' => 'Partneranfragen'],
            'duty:board' => ['board' => 'Dienststand'] + (auth()->user()->can('operations.rules.manage') ? ['profiles' => 'Überwachungsprofile'] : []),
            'duty:cases' => ['cases' => 'Ausfall & Ablösung'],
            'duty:transfers' => ['transfers' => 'Übernahmen & Tausch'],
            default => [],
        };
    }

    public function selectView(string $view): void
    {
        abort_unless(isset($this->views()[$view]), 403);
        $this->view = $view;
        $this->section = array_key_first($this->sections()) ?? '';
        $this->syncUrl();
    }

    public function selectSection(string $section): void
    {
        abort_unless(isset($this->views()[$this->view]) && isset($this->sections()[$section]), 403);
        $this->section = $section;
        $this->syncUrl();
    }

    private function syncUrl(): void
    {
        $this->dispatch('rt-workspace-url', url: OperationsPages::url($this->page, ['view' => $this->view, 'section' => $this->section] + $this->context));
    }

    public function render()
    {
        abort_unless(isset($this->views()[$this->view]), 403);
        abort_if($this->section !== '' && ! isset($this->sections()[$this->section]), 403);

        return view('livewire.operations.planning-page-workspace');
    }
}
