@props(['days', 'idPrefix' => 'personal-agenda'])
<div class="rt-pcal-agenda" data-calendar-agenda>
    @forelse($days as $day)
        <section class="rt-pcal-agenda-day" wire:key="{{ $idPrefix }}-{{ $day['date']->toDateString() }}" aria-labelledby="{{ $idPrefix }}-{{ $day['date']->toDateString() }}-title">
            <header class="rt-pcal-agenda-heading">
                <h3 id="{{ $idPrefix }}-{{ $day['date']->toDateString() }}-title">{{ $day['date']->locale('de')->isoFormat('dd., D. MMMM') }}</h3>
                <span>{{ $day['summary'] }}</span>
            </header>
            @if(count($day['segments']))
                <ol class="rt-pcal-agenda-list">
                    @foreach($day['segments'] as $segment)
                        @php($event = $segment['event'])
                        <li data-event-tone="{{ $segment['tone'] }}" wire:key="{{ $idPrefix }}-{{ $day['date']->toDateString() }}-{{ $event->id }}">
                            <button type="button" class="rt-pcal-agenda-event" wire:click="openCalendarEvent('{{ $event->id }}')" data-personal-event="{{ $event->id }}" aria-label="{{ $segment['accessible_label'] }} · Details öffnen">
                                <span class="rt-pcal-agenda-time">
                                    @if($segment['all_day'])
                                        Ganztägig
                                    @else
                                        <span>{{ $segment['visible_start']->format('H:i') }}</span>
                                        <span>{{ $segment['visible_end']->eq($day['date']->addDay()) ? '24:00' : $segment['visible_end']->format('H:i') }}</span>
                                    @endif
                                </span>
                                <span class="rt-pcal-agenda-node" aria-hidden="true"></span>
                                <span class="rt-pcal-agenda-copy">
                                    <strong>{{ $event->title }}</strong>
                                    <span>@if($segment['place']){{ $segment['place'] }}@else{{ $event->kind === 'absence' ? 'Abwesenheit' : ($event->kind === 'training' ? 'Schulung' : 'Dienst') }}@endif</span>
                                    @if($segment['continues_before'] || $segment['continues_after'])<small>{{ $event->starts->format('d.m. H:i') }} – {{ $event->ends->format('d.m. H:i') }}</small>@endif
                                    @if($segment['dst_changed'])<small>Zeitumstellung · {{ $event->starts->format('H:i P') }} – {{ $event->ends->format('H:i P') }}</small>@endif
                                    @if($event->kind === 'shift' && $event->record->plan_is_stale)<small>Planänderung in Prüfung</small>@endif
                                </span>
                                <span class="rt-pcal-agenda-status">{{ \App\Support\Operations\OperationsNavigation::status($event->status) }}</span>
                                <i class="far fa-chevron-right rt-pcal-agenda-chevron" aria-hidden="true"></i>
                            </button>
                        </li>
                    @endforeach
                </ol>
            @else
                <p class="rt-pcal-empty"><i class="far fa-calendar" aria-hidden="true"></i><span>Keine Termine an diesem Tag.</span></p>
            @endif
        </section>
    @empty
        <p class="rt-pcal-empty"><i class="far fa-calendar" aria-hidden="true"></i><span>Keine Termine in diesem Zeitraum.</span></p>
    @endforelse
</div>
