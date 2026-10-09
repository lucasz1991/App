@php
    $opsService = app(\App\Services\Operations\AiAssistService::class);
    $opsTab = $operationsTab;
@endphp
<div class="rt-ai-assist rt-assistant-operations__content" wire:poll.20s.visible>
    @if($opsTab === 'intake')
        <div class="rt-ai-assist__intake-head">
            <div><h3>AI-Annahme</h3><p>Text unten eingeben oder Bilder, Dokumente und Audio-Dateien anhängen. Anschließend ausdrücklich als Eingang erfassen.</p></div>
            <a class="rt-ai-assist__btn" href="{{ \App\Support\Operations\OperationsPages::url('cases', ['view' => 'inbox', 'section' => 'ai-intake']) }}" wire:navigate>Vollständige Annahme öffnen</a>
        </div>
        <p class="rt-ai-assist__note">{{ $operationsAssist['enabled'] ? 'Eingänge werden nach den Einstellungen der Disposition verarbeitet.' : 'Automatik pausiert. Neue Eingänge bleiben zur Prüfung erhalten.' }}</p>
        <div class="rt-ai-assist__tools">
            <label class="rt-ai-assist__search"><i class="far fa-search" aria-hidden="true"></i><span class="sr-only">AI-Eingang suchen</span><input type="search" wire:model.live.debounce.300ms="operationsIntakeSearch" placeholder="AI-Eingang suchen" maxlength="100"></label>
            <label class="sr-only" for="assistant-intake-status">Bearbeitungsstand</label><select id="assistant-intake-status" wire:model.live="operationsIntakeStatus"><option value="all">Alle Zustände</option>@foreach($operationsAssist['labels'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
        </div>
        @if($operationsAssist['intakes']->isEmpty())<p class="rt-ai-assist__note">Noch keine AI-Eingänge.</p>@endif
        <ol class="rt-ai-assist__activity">
            @foreach($operationsAssist['intakes'] as $intake)
                <li wire:key="assistant-intake-{{ $intake->id }}"><a class="rt-ai-assist__activity-item" href="{{ $opsService->intakeUrl($intake) }}" wire:navigate>
                    <span class="rt-ai-assist__activity-icon" data-tone="{{ $opsService->tone($intake->status) }}"><i class="far fa-inbox" aria-hidden="true"></i></span>
                    <span class="rt-ai-assist__activity-text"><strong>{{ $intake->title ?: 'Eingang #'.$intake->id }}</strong><small>{{ $intake->customer?->company_name ?? 'Kundenzuordnung prüfen' }}</small></span>
                    <span class="rt-ai-assist__badge" data-tone="{{ $opsService->tone($intake->status) }}">{{ $operationsAssist['labels'][$intake->status] ?? $intake->status }}</span>
                </a></li>
            @endforeach
        </ol>
    @elseif($opsTab === 'actions')
        <h3>Disposition unterstützen</h3>
        <div class="rt-ai-assist__actions">
            @foreach(collect($operationsAssist['actions']['page'])->concat($operationsAssist['actions']['global']) as $action)
                <button type="button" class="rt-ai-assist__action" x-on:click="handleOperationsAction(@js($action['key']))" x-bind:disabled="isLoading || operationsBusy">
                    <i class="far {{ $action['icon'] }}" aria-hidden="true"></i><span><strong>{{ $action['title'] }}</strong><small>{{ $action['detail'] }}</small></span><i class="far fa-arrow-right" aria-hidden="true"></i>
                </button>
            @endforeach
        </div>
    @else
        <div class="rt-ai-assist__intake-head"><div><h3>Aktivitäten</h3><p>Analyse, Rückfragen und bestätigte Planungsschritte mit ihrem tatsächlichen Stand.</p></div></div>
        <div class="rt-ai-assist__filter" role="group" aria-label="Aktivitäten filtern">
            @foreach(['all' => 'Alle', 'intake' => 'AI-Annahme', 'planning' => 'Planung'] as $key => $label)<button type="button" aria-pressed="{{ $operationsActivityFilter === $key ? 'true' : 'false' }}" wire:click="$set('operationsActivityFilter', '{{ $key }}')">{{ $label }}</button>@endforeach
        </div>
        @if($operationsAssist['activity']->isEmpty())<p class="rt-ai-assist__note">Noch keine Aktivitäten. Verarbeitete Eingänge und übernommene Vorschläge erscheinen hier.</p>@endif
        <ol class="rt-ai-assist__activity">
            @php $activityDay = null; @endphp
            @foreach($operationsAssist['activity'] as $item)
                @php $local = $item['at']->setTimezone(config('operations.display_timezone', 'Europe/Berlin')); $day = $local->isToday() ? 'Heute' : ($local->isYesterday() ? 'Gestern' : $local->format('d.m.Y')); @endphp
                @if($activityDay !== $day)<li class="rt-ai-assist__activity-group">{{ $day }}</li>@php $activityDay = $day; @endphp @endif
                <li wire:key="assistant-activity-{{ $item['id'] ?? sha1($item['kind'].'|'.$item['title'].'|'.$item['at']->toIso8601String()) }}">
                    @if($item['href'])<a class="rt-ai-assist__activity-item" href="{{ $item['href'] }}" wire:navigate>@else<div class="rt-ai-assist__activity-item">@endif
                        <span class="rt-ai-assist__activity-icon" data-tone="{{ $item['tone'] }}"><i class="far {{ $item['icon'] }}" aria-hidden="true"></i></span>
                        <span class="rt-ai-assist__activity-text"><strong>{{ $item['title'] }}</strong><small>{{ $item['detail'] }}</small></span>
                        <span class="rt-ai-assist__activity-side"><time datetime="{{ $local->toIso8601String() }}">{{ $local->format('H:i') }}</time><span class="rt-ai-assist__badge" data-tone="{{ $item['tone'] }}">{{ $item['status'] }}</span></span>
                    @if($item['href'])</a>@else</div>@endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
