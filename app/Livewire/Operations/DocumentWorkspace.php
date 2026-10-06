<?php

namespace App\Livewire\Operations;

use App\Support\Operations\OperationsPages;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DocumentWorkspace extends Component
{
    #[Locked]
    public string $view = 'files';

    public function mount(string $initialView = ''): void
    {
        $this->setView($initialView ?: 'files');
    }

    public function setView(string $view): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        Gate::authorize('files.manage');
        abort_unless(in_array($view, ['files', 'managed'], true), 404);
        $this->view = $view;
        $this->dispatch('rt-workspace-url', url: OperationsPages::url('documents', ['view' => $view]));
    }

    public function render()
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        Gate::authorize('files.manage');

        return view('livewire.operations.document-workspace');
    }
}
