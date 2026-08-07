<?php

namespace App\Livewire\Admin\Marketing;

use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Models\MarketingCreative;
use App\Services\Marketing\MarketingStudioService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class CreativesIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function create(string $type, MarketingStudioService $studio): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $creativeType = MarketingCreativeType::tryFrom($type);
        abort_unless($creativeType, 404);

        $creative = $studio->createFromTemplate($creativeType, auth()->user());

        $this->redirectRoute(
            'admin.marketing.creatives.editor',
            ['creative' => $creative],
            navigate: true,
        );
    }

    public function duplicate(string $creativeId, MarketingStudioService $studio): void
    {
        $copy = $studio->duplicate($this->creative($creativeId), auth()->user());

        $this->dispatch(
            'swal:toast',
            type: 'success',
            text: sprintf('„%s“ wurde als Entwurf dupliziert.', $copy->title),
        );
    }

    public function approve(string $creativeId, MarketingStudioService $studio): void
    {
        $studio->approve($this->creative($creativeId), auth()->user());

        $this->dispatch('swal:toast', type: 'success', text: 'Motiv wurde zur Veröffentlichung freigegeben.');
    }

    public function archive(string $creativeId, MarketingStudioService $studio): void
    {
        $studio->archive($this->creative($creativeId), auth()->user());

        $this->dispatch('swal:toast', type: 'success', text: 'Motiv wurde archiviert.');
    }

    public function render()
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $creatives = MarketingCreative::query()
            ->with('variants')
            ->when($this->search !== '', function (Builder $query): void {
                $query->where('title', 'like', '%'.trim($this->search).'%');
            })
            ->when(MarketingCreativeType::tryFrom($this->type), function (Builder $query, MarketingCreativeType $type): void {
                $query->where('type', $type->value);
            })
            ->when(MarketingCreativeStatus::tryFrom($this->status), function (Builder $query, MarketingCreativeStatus $status): void {
                $query->where('status', $status->value);
            })
            ->latest('updated_at')
            ->paginate(12);

        return view('livewire.admin.marketing.creatives-index', [
            'creatives' => $creatives,
        ])->layout('layouts.master', ['area' => 'admin']);
    }

    private function creative(string $creativeId): MarketingCreative
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return MarketingCreative::query()->where('public_id', $creativeId)->firstOrFail();
    }
}
