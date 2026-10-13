<section class="rt-today-card rt-personal-requests" aria-labelledby="personal-requests-heading" data-personal-requests>
    <header class="rt-personal-requests__heading">
        <div><h3 id="personal-requests-heading">Deine Antwort ist gefragt</h3><p>{{ $pendingRequests->total() ? 'Bitte prüfe die Anfragen und gib deine Rückmeldung.' : 'Aktuell gibt es keine offenen Anfragen, die deine Zustimmung benötigen.' }}</p></div>
        @if($pendingRequests->total())<span class="rt-personal-requests__count" aria-label="{{ $pendingRequests->total() }} {{ $pendingRequests->total() === 1 ? 'offene Anfrage' : 'offene Anfragen' }}">{{ $pendingRequests->total() }} offen</span>@endif
    </header>
    @if($pendingRequests->total())
        <ul class="rt-personal-requests__list">
            @foreach($pendingRequests as $request)
                @php
                    $start = $request['starts_at']->setTimezone($request['timezone']);
                    $end = $request['ends_at']->setTimezone($request['timezone']);
                @endphp
                <li class="rt-personal-request" wire:key="personal-request-{{ $request['type'] }}-{{ $request['id'] }}-{{ $request['revision'] }}" data-personal-request="{{ $request['type'] }}-{{ $request['id'] }}">
                    <div class="rt-personal-request__date" aria-hidden="true"><span>{{ $start->locale('de')->isoFormat('MMM') }}</span><strong>{{ $start->format('d') }}</strong></div>
                    <div class="rt-personal-request__body">
                        <p class="rt-personal-request__kind">{{ $request['label'] }} · Version {{ $request['revision'] }}</p>
                        <h4>{{ $request['title'] }}</h4>
                        <p class="rt-personal-request__period">{{ $start->locale('de')->isoFormat('dd, D. MMM YYYY') }} · {{ $start->format('H:i') }} – {{ $end->format($start->isSameDay($end) ? 'H:i' : 'd.m.Y H:i') }} <span>{{ $request['timezone'] }}</span></p>
                        <p class="rt-personal-request__meta">{{ $request['customer'] ?: 'Kunde nicht hinterlegt' }} · {{ $request['location'] ?: 'Einsatzort nicht hinterlegt' }}@if($request['type'] === 'shift' && $request['role']) · {{ $request['role'] }}@endif</p>
                    </div>
                    <x-ui.buttons.button-basic mode="primary" type="button" wire:click="openConsentRequest('{{ $request['type'] }}', {{ $request['id'] }}, {{ $request['revision'] }})" wire:loading.attr="disabled" wire:target="openConsentRequest,respondToRequest" aria-label="{{ $request['label'] }} {{ $request['title'] }} vom {{ $start->format('d.m.Y') }} prüfen und beantworten">Prüfen & antworten</x-ui.buttons.button-basic>
                </li>
            @endforeach
        </ul>
        @if($pendingRequests->hasPages())<div class="rt-personal-requests__pagination">{{ $pendingRequests->links() }}</div>@endif
    @endif
</section>
