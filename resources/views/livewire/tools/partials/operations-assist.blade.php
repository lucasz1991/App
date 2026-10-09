@php
    $opsContext = $operationsAssist['context'];
    $opsTab = $operationsTab ?? 'chat';
    $opsService = app(\App\Services\Operations\AiAssistService::class);
    $opsLabels = $operationsAssist['labels'];
@endphp
<div class="rt-ai-assist rt-assistant-operations" data-assistant-operations>
    <details class="rt-ai-assist__context rt-ai-assist__context--compact">
        <summary><i class="far fa-sparkles" aria-hidden="true"></i><span>Disposition · {{ $opsContext['label'] }}</span><small>{{ $operationsAssist['loaded'] ? $operationsAssist['reviewCount'].' prüfen' : 'Stand wird geladen' }}</small><i class="far fa-chevron-down" aria-hidden="true"></i></summary>
        <p class="rt-ai-assist__note">{{ $operationsAssist['enabled'] ? 'AI-Annahme aktiv' : 'Automatische AI-Annahme pausiert' }} · Änderungen brauchen deine Freigabe.</p>
        @if(in_array($opsContext['page'], ['shifts', 'calendar', 'planning'], true))<p class="rt-ai-assist__note">Zeitraum {{ \Carbon\CarbonImmutable::parse($opsContext['from'])->format('d.m.Y') }} – {{ \Carbon\CarbonImmutable::parse($opsContext['until'])->format('d.m.Y') }}</p>@endif
        @if(auth()->user()->isSuperAdmin())<a class="rt-ai-assist__btn" href="{{ route('admin.settings', ['tab' => 'ai-disposition', 'section' => 'configuration']) }}" wire:navigate>AI-Disposition einrichten</a>@endif
    </details>
    <nav class="rt-ai-assist__tabs" aria-label="Assistentenbereiche" role="tablist">
        @foreach(['chat' => 'Chat', 'intake' => 'AI-Annahme', 'actions' => 'Aktionen', 'activity' => 'Aktivitäten'] as $key => $label)
            @continue($key === 'intake' && !in_array('intake', $opsService->permitted(auth()->user()), true))
            <button type="button" role="tab" id="railtime-assistant-tab-{{ $key }}" aria-controls="railtime-assistant-content" aria-selected="{{ $opsTab === $key ? 'true' : 'false' }}" wire:click="setOperationsTab('{{ $key }}')" wire:loading.attr="disabled">{{ $label }}@if($key === 'intake' && $operationsAssist['reviewCount'])<span class="rt-ai-assist__count">{{ $operationsAssist['reviewCount'] }}</span>@endif</button>
        @endforeach
    </nav>
    @if($opsTab === 'chat')
        <div class="rt-assistant-operations__quickbar" role="group" aria-label="Dispositionsaktionen im Chat">
            @foreach(array_slice($operationsAssist['actions']['page'], 0, 2) as $action)
                <button type="button" x-on:click="handleOperationsAction(@js($action['key']))" x-bind:disabled="isLoading || operationsBusy"><i class="far {{ $action['icon'] }}" aria-hidden="true"></i> {{ $action['title'] }}</button>
            @endforeach
            @if(!empty($operationsAssist['overview']))
                <button type="button" wire:click="setOperationsTab('activity')">{{ $operationsAssist['overview']['counts']['busy'] }} in Analyse · {{ $operationsAssist['overview']['counts']['waiting'] }} warten auf Antwort</button>
            @endif
        </div>
    @endif
</div>
