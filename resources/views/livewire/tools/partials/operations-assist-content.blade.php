@php
    $opsService = app(\App\Services\Operations\AiAssistService::class);
    $opsTab = $operationsTab;
@endphp
<div class="rt-ai-assist rt-assistant-operations__content" wire:key="assistant-operations-content-{{ $opsTab }}" wire:poll.20s.visible>
    @if($opsTab === 'actions')
        <h3>Disposition unterstützen</h3>
        @include('livewire.tools.partials.operations-automation-status', ['automation' => $operationsAssist['automation'] ?? null])
        <div class="rt-ai-assist__actions">
            @foreach(collect($operationsAssist['actions']['page'])->concat($operationsAssist['actions']['global']) as $action)
                <button type="button" class="rt-ai-assist__action" x-on:click="handleOperationsAction(@js($action['key']))" x-bind:disabled="isLoading || operationsBusy">
                    <i class="far {{ $action['icon'] }}" aria-hidden="true"></i><span><strong>{{ $action['title'] }}</strong><small>{{ $action['detail'] }}</small></span><i class="far fa-arrow-right" aria-hidden="true"></i>
                </button>
            @endforeach
        </div>
    @else
        <div class="rt-ai-assist__intake-head"><div><h3>Aktivitäten</h3><p>Analyse, Rückfragen und bestätigte Planungsschritte mit ihrem tatsächlichen Stand.</p></div></div>
        <x-operations.activity-toolbar :value="$operationsActivityFilter" model="operationsActivityFilter" dropdown-id="assistant-operations-activity-filter" :options="['all' => 'Alle', 'intake' => 'AI-Annahme', 'communication' => 'Kundennachrichten', 'planning' => 'Planung', 'automation' => 'Personalanfragen']" />
        @if($operationsAssist['activity']->isEmpty())<p class="rt-ai-assist__note">Noch keine Aktivitäten. Verarbeitete Eingänge und übernommene Vorschläge erscheinen hier.</p>@endif
        <ol class="rt-ai-assist__activity">
            @php $activityDay = null; @endphp
            @foreach($operationsAssist['activity'] as $item)
                @php $local = $item['at']->setTimezone(config('operations.display_timezone', 'Europe/Berlin')); $day = $local->isToday() ? 'Heute' : ($local->isYesterday() ? 'Gestern' : $local->format('d.m.Y')); @endphp
                @if($activityDay !== $day)<li class="rt-ai-assist__activity-group">{{ $day }}</li>@php $activityDay = $day; @endphp @endif
                <li wire:key="assistant-activity-{{ $item['id'] ?? sha1($item['kind'].'|'.$item['title'].'|'.$item['at']->toIso8601String()) }}">
                    @if($item['href'])<a class="rt-ai-assist__activity-item" href="{{ $item['href'] }}" wire:navigate>@else<div class="rt-ai-assist__activity-item">@endif
                        <span class="rt-ai-assist__activity-icon" data-tone="{{ $item['tone'] }}"><i class="far {{ $item['icon'] }}" aria-hidden="true"></i></span>
                        <span class="rt-ai-assist__activity-text"><strong>{{ $item['title'] }}</strong><small>{{ $item['detail'] }}</small>@if($item['intake_id'] ?? null)<small>AI-Eingang #{{ $item['intake_id'] }}</small>@endif</span>
                        <span class="rt-ai-assist__activity-side"><time datetime="{{ $local->toIso8601String() }}">{{ $local->format('H:i') }}</time><span class="rt-ai-assist__badge" data-tone="{{ $item['tone'] }}">{{ $item['status'] }}</span></span>
                    @if($item['href'])</a>@else</div>@endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
