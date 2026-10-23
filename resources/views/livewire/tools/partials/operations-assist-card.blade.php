@php
    $cardState = $card['state'] ?? 'open';
@endphp
<x-operations.assist-response-card :card="$card" :card-id="'operations-response-'.$this->getId().'-'.$entryKey">
    <x-slot:actions>
    <div class="rt-ai-assist__card-buttons">
        @if($cardState === 'done' && !empty($card['canUndo']))
            <button type="button" class="rt-ai-assist__btn" wire:click="actOperationsCard(@js($entryKey), 'undo')" wire:loading.attr="disabled">Zurücknehmen</button>
        @elseif($cardState === 'open')
            @foreach($card['buttons'] ?? [] as $button)
                @if(!empty($button['href']))
                    <a class="rt-ai-assist__btn" data-primary="{{ !empty($button['primary']) ? 'true' : 'false' }}" href="{{ $button['href'] }}" wire:navigate>{{ $button['label'] }}</a>
                @elseif(!empty($button['act']))
                    <button type="button" class="rt-ai-assist__btn" data-primary="{{ !empty($button['primary']) ? 'true' : 'false' }}" wire:click="actOperationsCard(@js($entryKey), @js($button['act']))" wire:loading.attr="disabled" @disabled(!empty($card['expired']) && in_array($button['act'], ['apply', 'approve', 'retry', 'reanalyze', 'prepare'], true))>{{ $button['label'] }}</button>
                @endif
            @endforeach
        @endif
    </div>
    </x-slot:actions>
</x-operations.assist-response-card>
