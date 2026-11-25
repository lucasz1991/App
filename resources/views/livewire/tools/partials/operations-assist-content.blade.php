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
        <x-operations.ai-activity-list :items="$operationsAssist['activity']" empty="Noch keine Aktivitäten. Verarbeitete Eingänge und übernommene Vorschläge erscheinen hier." navigate key-prefix="assistant-activity" />
    @endif
</div>
