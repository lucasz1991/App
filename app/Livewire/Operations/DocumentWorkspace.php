<?php

namespace App\Livewire\Operations;

use App\Livewire\Concerns\SyncsWorkspaceUrl;
use App\Support\Operations\OperationsPages;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DocumentWorkspace extends Component
{
    use SyncsWorkspaceUrl;

    #[Locked]
    public string $view = 'files';

    public function mount(string $initialView = ''): void
    {
        $this->authorizeView($initialView ?: 'files');
        $this->view = $initialView ?: 'files';
    }

    public function setView(string $view): void
    {
        $this->authorizeView($view);
        $this->view = $view;
        $this->dispatchWorkspaceUrl('documents', ['view' => $view]);
    }

    public function render()
    {
        $this->authorizeView($this->view);

        return view('livewire.operations.document-workspace', [
            'scopeLinks' => OperationsPages::documentScopeLinks(),
        ]);
    }

    private function authorizeView(string $view): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        Gate::authorize('files.manage');
        abort_unless(in_array($view, ['files', 'personal', 'managed'], true), 404);
    }
}
