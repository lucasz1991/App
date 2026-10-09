@php
    $cardState = $card['state'] ?? 'open';
@endphp
<section class="rt-ai-assist__card" data-state="{{ $cardState }}" aria-label="{{ $card['title'] }}">
    <div class="rt-ai-assist__card-head">
        <i class="far {{ $card['icon'] ?? 'fa-sparkles' }}" aria-hidden="true"></i>
        <strong>{{ $card['title'] }}</strong>
        @if(in_array($cardState, ['done', 'dismissed', 'undone'], true))
            <span class="rt-ai-assist__badge" data-tone="{{ $cardState === 'done' ? 'ok' : 'neutral' }}">{{ ['done' => 'Übernommen', 'dismissed' => 'Verworfen', 'undone' => 'Zurückgenommen'][$cardState] }}</span>
        @endif
    </div>
    @foreach($card['rows'] ?? [] as $row)
        <div class="rt-ai-assist__card-row">
            <span>{{ $row['label'] }}</span><i class="far fa-arrow-right" aria-hidden="true"></i><b>{{ $row['value'] }}</b>
            @if(!empty($row['small']))<small>{{ $row['small'] }}</small>@endif
            @if(!empty($row['why']))<details class="rt-ai-assist__why"><summary>Begründung anzeigen</summary><ul>@foreach($row['why'] as $reason)<li>{{ $reason }}</li>@endforeach</ul></details>@endif
        </div>
    @endforeach
    @if(!empty($card['note']))<p class="rt-ai-assist__card-note">{{ str_replace('**', '', $card['note']) }}</p>@endif
    @if(!empty($card['expired']))<p class="rt-ai-assist__card-note">Diese Auswertung ist abgelaufen. Bitte erneut auswerten.</p>@endif
    @foreach($card['failed'] ?? [] as $failure)<p class="rt-ai-assist__card-failed" role="alert">Nicht übernommen: {{ $failure }}</p>@endforeach
    <div class="rt-ai-assist__card-buttons">
        @if($cardState === 'done' && !empty($card['canUndo']))
            <button type="button" class="rt-ai-assist__btn" wire:click="actOperationsCard(@js($entryKey), 'undo')" wire:loading.attr="disabled">Zurücknehmen</button>
        @elseif($cardState === 'open')
            @foreach($card['buttons'] ?? [] as $button)
                @if(!empty($button['href']))
                    <a class="rt-ai-assist__btn" data-primary="{{ !empty($button['primary']) ? 'true' : 'false' }}" href="{{ $button['href'] }}" wire:navigate>{{ $button['label'] }}</a>
                @elseif(!empty($button['act']))
                    <button type="button" class="rt-ai-assist__btn" data-primary="{{ !empty($button['primary']) ? 'true' : 'false' }}" wire:click="actOperationsCard(@js($entryKey), @js($button['act']))" wire:loading.attr="disabled" @disabled(!empty($card['expired']) && $button['act'] === 'apply')>{{ $button['label'] }}</button>
                @endif
            @endforeach
        @endif
    </div>
</section>
