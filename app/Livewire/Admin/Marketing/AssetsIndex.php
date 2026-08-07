<?php

namespace App\Livewire\Admin\Marketing;

use App\Models\MarketingAsset;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AssetsIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $assets = MarketingAsset::query()
            ->when($this->search !== '', function (Builder $query): void {
                $query->where('original_name', 'like', '%'.trim($this->search).'%');
            })
            ->latest('created_at')
            ->paginate(24);

        return view('livewire.admin.marketing.assets-index', [
            'assets' => $assets,
            'assetUiConfig' => [
                'uploadUrl' => route('admin.marketing.assets.store'),
                'replaceUrl' => route('admin.marketing.assets.update', '__ASSET__'),
                'deleteUrl' => route('admin.marketing.assets.destroy', '__ASSET__'),
            ],
        ])->layout('layouts.master', ['area' => 'admin']);
    }
}
