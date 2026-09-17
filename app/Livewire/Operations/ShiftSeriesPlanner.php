<?php

namespace App\Livewire\Operations;

use App\Models\Order;
use App\Models\QualificationType;
use App\Models\ShiftSeries;
use App\Models\ShiftTemplate;
use App\Services\Operations\ShiftSeriesService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\PlanningSchema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShiftSeriesPlanner extends Component
{
    public bool $open = false;

    public bool $templateOpen = false;

    public bool $seriesOpen = false;

    #[Locked]
    public ?int $templateId = null;

    #[Locked]
    public ?int $templateRevision = null;

    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public string $fingerprint = '';

    #[Locked]
    public array $previewRows = [];

    public array $template = ['name' => '', 'title' => '', 'role_name' => '', 'timezone' => 'Europe/Berlin', 'start_time' => '08:00', 'end_time' => '16:00', 'end_next_day' => false, 'required_staff' => 1, 'planned_break_minutes' => 30, 'location_name' => '', 'qualification_ids' => []];

    public array $series = ['template_id' => '', 'order_id' => '', 'from' => '', 'until' => '', 'weekdays' => [1, 2, 3, 4, 5]];

    public string $exceptions = '';

    private function access(): void
    {
        OperationsAccess::authorize(auth()->user(), 'operations.manage');
        PlanningSchema::requireReady();
    }

    public function editTemplate(?int $id = null): void
    {
        $this->access();
        $this->reset(['template', 'templateRevision']);
        $this->templateId = $id;
        if ($id) {
            $record = ShiftTemplate::findOrFail($id);
            $this->template = $record->definition + ['name' => $record->name];
            $this->templateRevision = $record->revision;
        }
        $this->open = false;
        $this->templateOpen = true;
        $this->resetValidation();
    }

    public function saveTemplate(ShiftSeriesService $service): void
    {
        $this->access();
        $service->saveTemplate($this->templateId, $this->templateRevision, $this->template, auth()->user());
        $this->templateOpen = false;
        $this->open = true;
    }

    public function newSeries(): void
    {
        $this->access();
        $this->reset(['series', 'exceptions', 'fingerprint', 'previewRows']);
        $this->requestKey = (string) Str::uuid();
        $this->open = false;
        $this->seriesOpen = true;
        $this->resetValidation();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'series') || $property === 'exceptions') {
            $this->reset(['fingerprint', 'previewRows']);
        }
    }

    private function requestData(): array
    {
        return $this->series + ['exceptions' => array_values(array_filter(preg_split('/[\s,;]+/', trim($this->exceptions))))];
    }

    public function preview(ShiftSeriesService $service): void
    {
        $this->access();
        $this->resetValidation();
        $result = $service->preview($this->requestData(), auth()->user());
        $this->fingerprint = $result['fingerprint'];
        $this->previewRows = $result['rows'];
    }

    public function generate(ShiftSeriesService $service): void
    {
        $this->access();
        $result = $service->generate($this->requestData(), $this->fingerprint, $this->requestKey, auth()->user());
        $this->seriesOpen = false;
        $this->open = true;
        session()->flash('operations.saved', $result->occurrences()->count().' Entwurfsschichten angelegt.');
        $this->dispatch('operations-plan-changed');
    }

    public function render()
    {
        $this->access();

        return view('livewire.operations.shift-series-planner', [
            'templates' => ShiftTemplate::orderBy('name')->get(), 'orders' => Order::where('status', '!=', 'cancelled')->orderByDesc('starts_at')->get(),
            'types' => QualificationType::where('is_active', true)->orderBy('name')->get(),
            'history' => ShiftSeries::with('order')->withCount('occurrences')->latest()->limit(20)->get(),
        ]);
    }
}
