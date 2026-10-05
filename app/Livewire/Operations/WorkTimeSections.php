<?php

namespace App\Livewire\Operations;

use App\Models\WorkTimeEntry;
use App\Services\Operations\WorkTimeActivityService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\WorkTimeSchema;
use Livewire\Attributes\Locked;
use Livewire\Component;

class WorkTimeSections extends Component
{
    #[Locked]
    public int $entryId;

    #[Locked]
    public int $revision;

    public array $sections = [];

    private function entry(): WorkTimeEntry
    {
        OperationsAccess::own(auth()->user(), auth()->id());
        WorkTimeSchema::requireReady();

        return WorkTimeEntry::where('user_id', auth()->id())->findOrFail($this->entryId);
    }

    public function mount(int $entryId): void
    {
        $this->entryId = $entryId;
        $entry = $this->entry();
        abort_unless(in_array($entry->status, ['completed', 'returned'], true), 403);
        $this->revision = $entry->revision;
        $this->sections = $entry->activities()->orderBy('starts_at')->get()->map(fn ($section) => [
            'kind' => $section->kind, 'starts_at' => $section->starts_at->setTimezone($entry->timezone)->format('Y-m-d\TH:i:s'),
            'ends_at' => $section->ends_at?->setTimezone($entry->timezone)->format('Y-m-d\TH:i:s') ?? '', 'note' => $section->note ?? '',
        ])->all();
    }

    public function add(): void
    {
        $this->entry();
        abort_if(count($this->sections) >= 60, 422);
        $this->sections[] = ['kind' => 'work', 'starts_at' => '', 'ends_at' => '', 'note' => ''];
    }

    public function remove(int $index): void
    {
        $this->entry();
        unset($this->sections[$index]);
        $this->sections = array_values($this->sections);
    }

    public function save(WorkTimeActivityService $service): void
    {
        $this->entry();
        $service->replace($this->entryId, $this->revision, $this->sections, auth()->user());
        $this->dispatch('time-sections-saved');
    }

    public function render()
    {
        return view('livewire.operations.work-time-sections', ['entry' => $this->entry(), 'kinds' => WorkTimeActivityService::KINDS]);
    }
}
