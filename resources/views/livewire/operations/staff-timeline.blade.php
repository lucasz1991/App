<section class="min-w-0 space-y-3" aria-label="Mitarbeiter-Zeitleiste">
@if(!$absencesOnly)<x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" />@endif
<div class="rt-personnel-timeline" tabindex="0" role="region" aria-label="Zeitfenster nach Mitarbeiter, horizontal scrollbar">
<div class="rt-personnel-timeline-grid" style="--timeline-days:{{ $days->count() }}">
    <div class="rt-personnel-timeline-name rt-personnel-timeline-head">Mitarbeiter</div>
    @foreach($days as $day)<div class="rt-personnel-timeline-head">{{ $day->locale('de')->translatedFormat('D, d.m.') }}</div>@endforeach
    @forelse($rows as $row)
        <div class="rt-personnel-timeline-name min-w-0" wire:key="staff-person-{{ $row['user']->id }}">
            <x-user.person-anchor-preview :user="$row['user']" trigger-classes="flex min-w-0 w-full">
                <x-slot:trigger>
                    <button type="button" class="min-h-11 min-w-0 w-full rounded-lg text-left outline-none transition-colors hover:text-rt-red focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:hover:text-rt-dark-accent" aria-label="{{ __('app.open_person_preview') }}: {{ $row['user']->name }}" title="{{ $row['user']->name }}">
                        <x-user.public-info :user="$row['user']" :size="6" :show-email="false" :show-presence="false" />
                    </button>
                </x-slot:trigger>
            </x-user.person-anchor-preview>
            @if(!$row['user']->status)<span class="ops-muted">Inaktiv</span>@endif
        </div>
        @foreach($row['days'] as $cell)
            <div class="rt-personnel-timeline-day" wire:key="staff-day-{{ $row['user']->id }}-{{ $cell['date']->toDateString() }}">
            @foreach($cell['events'] as $event)
                <div class="rt-personnel-timeline-event" data-kind="{{ $event['kind'] }}">
                    <span class="rt-personnel-timeline-time">@if($event['start']->lte($cell['date']) && $event['end']->gte($cell['date']->addDay()))Ganztägig @else{{ $event['start']->lt($cell['date']) ? '← 00:00' : $event['start']->setTimezone($zone)->format('H:i') }} – {{ $event['end']->gte($cell['date']->addDay()) ? '24:00 →' : $event['end']->setTimezone($zone)->format('H:i') }}@endif</span>
                    @if($event['shift_id'])<a href="{{ route('operations.workspace',['module'=>'shift-management','shift'=>$event['shift_id']]) }}">{{ $event['title'] }}</a>@elseif($absencesOnly)<button type="button" class="text-left font-semibold underline underline-offset-4" wire:click="$dispatch('operations-open-absence', {id: {{ $event['absence_id'] }}})">{{ $event['title'] }}</button>@else<strong>{{ $event['title'] }}</strong>@endif
                    <span>{{ $event['detail'] }}</span><span>{{ $event['status'] }}</span>
                </div>
            @endforeach
            @if(!$absencesOnly)@foreach($cell['free'] as [$start,$end])<p class="rt-personnel-timeline-free">Unbelegt {{ \Carbon\CarbonImmutable::createFromTimestamp($start,$zone)->format('H:i') }} – {{ $end===$cell['date']->addDay()->timestamp ? '24:00' : \Carbon\CarbonImmutable::createFromTimestamp($end,$zone)->format('H:i') }}</p>@endforeach @elseif($cell['events']->isEmpty())<span class="ops-muted">—</span>@endif
            </div>
        @endforeach
    @empty<div class="p-4">Keine Mitarbeiter gefunden.</div>@endforelse
</div>
</div>
{{ $users->links() }}
</section>
