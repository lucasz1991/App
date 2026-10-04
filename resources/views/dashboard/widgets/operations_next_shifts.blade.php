{{-- Zeitleiste: die naechsten Dienste mit Besetzungsgrad je Dienst. --}}
@php
    $tz = config('operations.display_timezone');
@endphp
@if($rows === 2 && $data['shifts']->isNotEmpty())
    <div style="margin-bottom:6px;">
        @if($data['understaffed'] > 0)
            <span class="wv-pill ops-tone-warn"><i data-feather="alert-circle"></i>{{ $data['understaffed'] }} von {{ $data['shifts']->count() }} noch unterbesetzt</span>
        @else
            <span class="wv-pill ops-tone-ok"><i data-feather="check"></i>Alle angezeigten Dienste besetzt</span>
        @endif
    </div>
@endif
<div class="wv-timeline">
    @forelse($data['shifts'] as $shift)
        @php
            $start = $shift->starts_at->copy()->setTimezone($tz);
            $running = $shift->starts_at->lte(now());
            $short = $shift->reserved < $shift->required_staff;
            $fill = $shift->required_staff > 0 ? min(100, round($shift->reserved / $shift->required_staff * 100)) : 100;
            $context = collect([$shift->order?->customer?->company_name, $shift->location_name])->filter()->implode(' · ');
        @endphp
        <a class="wv-tl-item" data-state="{{ $short ? 'warn' : 'ok' }}" @if($running) data-running @endif href="{{ route('operations.workspace', 'shift-management') }}?shift={{ $shift->id }}" wire:navigate>
            <span class="wv-tl-time">{{ $start->format('H:i') }}<small>{{ $running ? 'läuft' : ($start->isToday() ? 'Heute' : ($start->isTomorrow() ? 'Morgen' : $start->translatedFormat('D d.m.'))) }}</small></span>
            <span class="wv-tl-rail" aria-hidden="true"></span>
            <span class="wv-tl-body">
                <h4>{{ $shift->title }}</h4>
                @if($rows === 2 && $context)<p>{{ $context }}</p>@endif
            </span>
            <span class="wv-tl-fill" title="{{ $shift->reserved }} von {{ $shift->required_staff }} zugesagt">{{ $shift->reserved }}/{{ $shift->required_staff }}<span><i style="width:{{ $fill }}%;"></i></span></span>
        </a>
    @empty
        <x-dashboard.empty icon="calendar">Keine Dienste in den nächsten 14 Tagen.</x-dashboard.empty>
    @endforelse
</div>
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Schichtplan öffnen →</a>
