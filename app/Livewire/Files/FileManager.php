<?php

namespace App\Livewire\Admin;

use App\Models\FilePool;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Attributes\Locked;

class FileManager extends Component
{
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
        return view('livewire.admin.file-manager')
            ->layout('layouts.master', ['area' => 'admin']);
    }
}
