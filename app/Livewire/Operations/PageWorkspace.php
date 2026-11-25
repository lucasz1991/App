<?php

namespace App\Livewire\Operations;

use App\Livewire\Concerns\ResolvesOperationsActor;
use App\Models\User;
use App\Support\Ai\AssistantAccess;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PageWorkspace extends Component
{
    use ResolvesOperationsActor;

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
        if ($this->page === 'shifts') {
            abort_unless($this->initialView === '' || in_array($this->initialView, ['plan', 'calendar'], true), 403);
            abort_unless($this->initialSection === '', 403);
            $this->redirect(OperationsPages::url('shifts', ['view' => $this->initialView ?: 'plan'] + $this->context));
        }
    }

    private function authorizePage(): User
    {
        abort_unless(isset(OperationsPages::definitions()[$this->page]), 404);
        $actor = $this->freshActor(requireReady: false);
        abort_unless(OperationsPages::views($actor, $this->page) || OperationsPages::sections($actor, $this->page), 403);

        return $actor;
    }

    /** Standalone disposition assist stays available while the central chat is switched off. */
    private function showsAssistFallback(User $actor, array $definition): bool
    {
        return ($definition['assist'] ?? false)
            && OperationsAccess::ready()
            && ($actor->can('operations.manage') || $actor->can('operations.inquiries.manage'))
            && ! app(AssistantAccess::class)->shouldRender($actor);
    }

    public function render()
    {
        $actor = $this->authorizePage();
        $definition = OperationsPages::definitions()[$this->page];

        return view('livewire.operations.page-workspace', [
            'definition' => $definition,
            'pageTitle' => $this->page === 'cases' && $this->initialView === 'offers' ? 'Angebote' : $definition['title'],
            'showAssistFallback' => $this->showsAssistFallback($actor, $definition),
        ])->layout('layouts.master');
    }
}
