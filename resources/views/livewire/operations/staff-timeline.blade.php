<section class="rt-staff-timeline-layout min-w-0" aria-label="Mitarbeiter-Zeitleiste">
@if(!$absencesOnly)<x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" />@endif
<div class="rt-personnel-timeline" style="--timeline-days:{{ $days->count() }}" x-data="{
    gutterObserver: null,
    init() {
        this.gutterObserver = new ResizeObserver(() => this.updateGutter());
        this.gutterObserver.observe(this.$refs.timelineBody);
        this.$nextTick(() => this.updateGutter());
    },
    destroy() { this.gutterObserver?.disconnect(); },
    updateGutter() {
        this.$el.style.setProperty('--timeline-gutter', `${this.$refs.timelineBody.offsetWidth - this.$refs.timelineBody.clientWidth}px`);
    },
    syncHorizontal(source) {
        const offset = source.scrollLeft;
        for (const target of [this.$refs.timelineBody, this.$refs.timelineHeader, this.$refs.timelineScrollbar]) {
            if (target !== source && target.scrollLeft !== offset) target.scrollLeft = offset;
        }
    }
}">
    <div class="rt-personnel-timeline-header">
        <div class="rt-personnel-timeline-name rt-personnel-timeline-head">Mitarbeiter</div>
        <div class="rt-personnel-timeline-header-scroll" x-ref="timelineHeader">
            <div class="rt-personnel-timeline-header-days">
                @foreach($days as $day)<div class="rt-personnel-timeline-head" @class(['rt-personnel-timeline-head--weekend' => $day->isWeekend()])>{{ $day->locale('de')->translatedFormat('D, d.m.') }}</div>@endforeach
            </div>
        </div>
    </div>
    <div class="rt-personnel-timeline-body" x-ref="timelineBody" x-on:scroll="syncHorizontal($event.target)" tabindex="0" role="region" aria-label="Zeitfenster nach Mitarbeiter, vertikal scrollbar; horizontale Navigation unter der Tabelle">
    <div class="rt-personnel-timeline-grid">
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
            <div @class(['rt-personnel-timeline-day', 'rt-personnel-timeline-day--weekend' => $cell['date']->isWeekend(), 'rt-personnel-timeline-day--empty' => $cell['events']->isEmpty()]) wire:key="staff-day-{{ $row['user']->id }}-{{ $cell['date']->toDateString() }}">
            @if($cell['events']->isEmpty())
                <span class="rt-personnel-timeline-no-entry" role="img" aria-label="Kein Eintrag" title="Kein Eintrag"><i class="far fa-calendar-minus" aria-hidden="true"></i></span>
            @endif
            @foreach($cell['events'] as $event)
                <div class="rt-personnel-timeline-event" data-kind="{{ $event['kind'] }}">
                    <span class="rt-personnel-timeline-time">@if($event['start']->lte($cell['date']) && $event['end']->gte($cell['date']->addDay()))Ganztägig @else{{ $event['start']->lt($cell['date']) ? '← 00:00' : $event['start']->setTimezone($zone)->format('H:i') }} – {{ $event['end']->gte($cell['date']->addDay()) ? '24:00 →' : $event['end']->setTimezone($zone)->format('H:i') }}@endif</span>
                    @if($event['shift_id'])<a href="{{ route('operations.workspace',['module'=>'shift-management','shift'=>$event['shift_id']]) }}">{{ $event['title'] }}</a>@elseif($absencesOnly)<button type="button" class="text-left font-semibold underline underline-offset-4" wire:click="$dispatch('operations-open-absence', {id: {{ $event['absence_id'] }}})">{{ $event['title'] }}</button>@else<strong>{{ $event['title'] }}</strong>@endif
                    <span>{{ $event['detail'] }}</span><span>{{ $event['status'] }}</span>
                </div>
            @endforeach
            @if(!$absencesOnly && $cell['events']->isNotEmpty())@foreach($cell['free'] as [$start,$end])<p class="rt-personnel-timeline-free">Unbelegt {{ \Carbon\CarbonImmutable::createFromTimestamp($start,$zone)->format('H:i') }} – {{ $end===$cell['date']->addDay()->timestamp ? '24:00' : \Carbon\CarbonImmutable::createFromTimestamp($end,$zone)->format('H:i') }}</p>@endforeach @endif
            </div>
        @endforeach
    @empty<div class="rt-personnel-timeline-empty">Keine Mitarbeiter gefunden.</div>@endforelse
    @if($users->hasMorePages())
        <div class="rt-personnel-timeline-load-more" style="grid-column: 1 / -1" wire:key="staff-timeline-load-more-{{ $this->getPage('staffPage') }}" x-data x-intersect.once="$wire.loadMore()" aria-live="polite" role="status">
            <span wire:loading.remove wire:target="loadMore">Weitere Mitarbeitende laden</span>
            <span wire:loading wire:target="loadMore">Mitarbeitende werden geladen …</span>
        </div>
    @endif
    </div>
    </div>
    <div class="rt-personnel-timeline-scrollbar-row" aria-hidden="true">
        <span class="rt-personnel-timeline-scrollbar-corner"></span>
        <div class="rt-personnel-timeline-scrollbar" x-ref="timelineScrollbar" x-on:scroll="syncHorizontal($event.target)"><div class="rt-personnel-timeline-scrollbar-width"></div></div>
    </div>
</div>
</section>
