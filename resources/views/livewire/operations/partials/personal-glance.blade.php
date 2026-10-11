{{-- Kurzstatus der Bereiche, die eigene Menüpunkte haben. Jede Kachel führt direkt dorthin. --}}
@php
    $areas = \App\Support\Operations\PersonalWorkspaceAreas::all();
    $tiles = array_filter([
        $workTimeReady ? ['label' => 'Zeitmeldungen', 'value' => $glance['open_times'] ? $glance['open_times'].' offen' : 'Alles eingereicht', 'attention' => $glance['open_times'] > 0, 'tab' => 'time'] : null,
        ['label' => 'Urlaub & Abwesenheit', 'value' => $glance['pending_absences'] ? $glance['pending_absences'].' Antrag'.($glance['pending_absences'] === 1 ? '' : 'e').' in Prüfung' : ($glance['next_absence'] ? 'Nächste ab '.$glance['next_absence']->format('d.m.') : 'Nichts geplant'), 'attention' => false, 'area' => 'absences'],
        ['label' => 'Qualifikationen', 'value' => $glance['expiring_qualifications'] ? $glance['expiring_qualifications'].' laufen bald ab' : 'Alle gültig', 'attention' => $glance['expiring_qualifications'] > 0, 'area' => 'qualifications'],
        isset($areas['inbox']) ? ['label' => 'Arbeitsliste', 'value' => 'Aufgaben öffnen', 'attention' => false, 'area' => 'inbox'] : null,
    ]);
@endphp
<nav class="rt-personal-glance" aria-label="Auf einen Blick">
    @foreach($tiles as $tile)
        @if(isset($tile['tab']))
            <button type="button" class="rt-personal-glance__tile" wire:click="showTab('{{ $tile['tab'] }}')" wire:loading.attr="disabled" wire:target="showTab" @if($tile['attention']) data-attention @endif>
        @else
            <a class="rt-personal-glance__tile" href="{{ route('operations.mine', ['area' => $tile['area']]) }}" wire:navigate @if($tile['attention']) data-attention @endif>
        @endif
                <span class="rt-personal-glance__label">{{ $tile['label'] }}</span>
                <strong class="rt-personal-glance__value">{{ $tile['value'] }}</strong>
        @if(isset($tile['tab']))</button>@else</a>@endif
    @endforeach
</nav>
