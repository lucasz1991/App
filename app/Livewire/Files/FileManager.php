<?php

namespace App\Livewire\Files;

use App\Models\FilePool;
use App\Support\Operations\OperationsPages;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Attributes\Locked;

class FileManager extends Component
{
    #[Locked]
    public int $companyPoolId;

    #[Locked]
    public bool $embedded = false;

    public function mount(bool $embedded = false): void
    {
        Gate::authorize('files.manage');
        $this->embedded = $embedded;

        $this->companyPoolId = FilePool::company()->id;
    }

    public function render()
    {
        Gate::authorize('files.manage');

        return view('livewire.files.file-manager', [
            'scopeLinks' => OperationsPages::documentScopeLinks($this->embedded),
        ])
            ->layout('layouts.master', ['area' => 'admin']);
    }
}
