<?php

namespace App\Livewire\Operations;

use App\Support\Operations\OperationsPages;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PageWorkspace extends Component
{
    #[Locked]
    public string $page;

    #[Locked]
    public string $initialView = '';

    #[Locked]
    public string $initialSection = '';

    #[Locked]
    public array $context = [];

    public function mount(string $page): void
    {
        $this->page = $page;
        $this->authorizePage();
        foreach (['view' => 'initialView', 'section' => 'initialSection'] as $query => $property) {
            $value = request()->query($query, '');
            abort_unless(is_string($value) && mb_strlen($value) <= 40, 422);
            $this->$property = $value;
        }
        $this->context = OperationsPages::context(request());
    }

    private function authorizePage(): void
    {
        abort_unless(isset(OperationsPages::definitions()[$this->page]), 404);
        $actor = auth()->user();
        abort_unless($actor && $actor->status, 403);
        abort_unless(OperationsPages::views($actor, $this->page) || OperationsPages::sections($actor, $this->page), 403);
    }

    public function render()
    {
        $this->authorizePage();

        return view('livewire.operations.page-workspace', ['definition' => OperationsPages::definitions()[$this->page]])->layout('layouts.master');
    }
}
