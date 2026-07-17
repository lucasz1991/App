<?php

namespace App\Livewire\Admin;

use App\Models\FilePool;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class FileManager extends Component
{
    public int $companyPoolId;

    public function mount(): void
    {
        Gate::authorize('files.manage');

        $this->companyPoolId = FilePool::company()->id;
    }

    public function render()
    {
        return view('livewire.admin.file-manager')
            ->layout('layouts.master', ['area' => 'admin']);
    }
}
