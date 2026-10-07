<div @class(['contents' => $shiftId === null]) data-ai-planning-helper="{{ $shiftId === null ? 'period' : 'shift' }}">
    @if($shiftId !== null)
        <details class="rt-staffing-more">
            <summary><i class="far fa-sparkles" aria-hidden="true"></i> AI-Besetzungshilfe</summary>
            <div class="ops-stack pt-3">
                @include('livewire.operations.partials.ai-planning-proposal')
            </div>
        </details>
    @else
        <x-operations.modal wire:model="open" title="AI-Besetzung für den Zeitraum" max-width="4xl">
            @if($open)
                <p class="ops-muted">{{ \Carbon\CarbonImmutable::parse($from)->format('d.m.Y') }} – {{ \Carbon\CarbonImmutable::parse($until)->format('d.m.Y') }} · bis zu 24 offene Schichten</p>
                @include('livewire.operations.partials.ai-planning-proposal')
            @endif
        </x-operations.modal>
    @endif
</div>
