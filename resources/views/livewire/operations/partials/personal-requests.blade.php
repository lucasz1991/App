@php
    $rows = $requestRows ?? $pendingRequests;
    $filter = $requestFilter ?? 'open';
@endphp
<section class="rt-personal-requests rt-personal-requests--review" aria-labelledby="personal-requests-heading" data-personal-requests>
    <h2 id="personal-requests-heading" class="sr-only">Meine Anfragen</h2>
    @isset($requestCounts)
        <x-ui.buttons.multi-toggle id="personal-request-filter" class="rt-personal-requests__filters" label="Anfragen filtern" :show-labels="true" :value="$filter" action="showRequestFilter" :options="[
            ['value'=>'all','label'=>'Alle','icon'=>'fa-inbox'],
            ['value'=>'open','label'=>'Offen '.$requestCounts['open'],'icon'=>'fa-clock'],
            ['value'=>'answered','label'=>'Beantwortet','icon'=>'fa-check'],
        ]" />
    @endisset
    @if($rows->total())
        <ul class="rt-personal-requests__list" wire:loading.attr="aria-busy" wire:target="showRequestFilter">
            @foreach($rows as $request)
                @php
                    $start = $request['starts_at']->setTimezone($request['timezone']);
                    $end = $request['ends_at']->setTimezone($request['timezone']);
                    $canRespond = $request['can_respond'] ?? true;
                    $status = $request['status'] ?? 'requested';
                @endphp
                <li @class(['rt-personal-request', 'rt-personal-request--answered' => !$canRespond]) wire:key="personal-request-{{ $request['type'] }}-{{ $request['id'] }}-{{ $request['revision'] }}" data-personal-request="{{ $request['type'] }}-{{ $request['id'] }}">
                    <button type="button" class="rt-personal-request__open" wire:click="openConsentRequest('{{ $request['type'] }}', {{ $request['id'] }}, {{ $request['revision'] }})" wire:loading.attr="disabled" wire:target="openConsentRequest,respondToRequest"
                        aria-label="{{ $request['label'] }} {{ $request['title'] }} vom {{ $start->format('d.m.Y') }} {{ $canRespond ? 'prüfen und beantworten' : 'ansehen' }}">
                        <span class="rt-personal-request__date" aria-hidden="true"><span>{{ $start->locale('de')->isoFormat('dd') }}</span><strong>{{ $start->format('d') }}</strong><span>{{ $start->locale('de')->isoFormat('MMM') }}</span></span>
                        <span class="rt-personal-request__body">
                            <strong class="rt-personal-request__title">{{ $request['title'] }}</strong>
                            <span class="rt-personal-request__period">{{ $start->format('H:i') }} – {{ $end->format($start->isSameDay($end) ? 'H:i' : 'd.m.Y H:i') }} · {{ $request['location'] ?: 'Einsatzort folgt' }}</span>
                            <span class="rt-personal-request__meta">@if($request['type'] === 'shift' && $request['role']){{ $request['role'] }} · @endif{{ $request['customer'] ?: 'Kunde folgt' }}</span>
                            @if($request['timezone'] !== $displayTimezone)<span class="rt-personal-request__meta">{{ $request['timezone'] }}</span>@endif
                            <span @class(['rt-personal-request__status', 'is-open'=>$canRespond])>{{ $canRespond ? 'Antwort offen' : \App\Support\Operations\OperationsNavigation::status($status) }}</span>
                        </span>
                        <i class="fal fa-chevron-right rt-personal-request__chevron" aria-hidden="true"></i>
                        @if($canRespond)<span class="rt-personal-request__action">Prüfen & antworten</span>@endif
                    </button>
                </li>
            @endforeach
        </ul>
        @if($rows->hasPages())<div class="rt-personal-requests__pagination">{{ $rows->links() }}</div>@endif
    @else
        <div class="rt-personal-requests__empty">
            <i class="fal fa-inbox" aria-hidden="true"></i>
            <strong>{{ $filter === 'answered' ? 'Noch keine beantworteten Anfragen' : 'Keine offenen Anfragen' }}</strong>
            <p>{{ $filter === 'answered' ? 'Deine Rückmeldungen erscheinen anschließend hier.' : 'Sobald eine Rückmeldung benötigt wird, findest du die Anfrage hier.' }}</p>
        </div>
    @endif
</section>
