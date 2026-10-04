{{-- Kennwert-Kacheln: die Grenzwerte des aktiven Pruefregelwerks auf einen Blick. --}}
@if($data['profile'])
    @php
        $profile = $data['profile'];
        $duration = function (int $minutes): string {
            if ($minutes < 60) {
                return $minutes.' min';
            }

            return intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '');
        };
        $tiles = [
            ['moon', $duration($profile->minimum_rest_minutes), 'Mindestruhezeit'],
            ['clock', $duration($profile->maximum_shift_minutes), 'Max. Schichtdauer'],
            ['coffee', $duration($profile->break_after_minutes), 'Pause spätestens nach'],
            ['pause-circle', $duration($profile->minimum_break_minutes), 'Mindestpause'],
        ];
    @endphp
    <div class="wv-inline">
        <span class="wv-truncate" style="font-size:15px;font-weight:700;">{{ $profile->name }}</span>
        <span class="wv-pill ops-tone-ok"><i data-feather="check"></i>Aktiv</span>
    </div>
    <div class="wv-spec">
        @foreach(array_slice($tiles, 0, $rows === 2 ? 4 : 2) as [$icon, $value, $label])
            <div class="wv-spec-tile"><i data-feather="{{ $icon }}"></i><div style="min-width:0;"><b>{{ $value }}</b><span>{{ $label }}</span></div></div>
        @endforeach
    </div>
    @if($rows === 2 && $profile->approved_at)
        <p class="wv-sub wv-gap">Freigegeben am {{ $profile->approved_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y') }}</p>
    @endif
@else
    <x-dashboard.empty icon="shield">Kein aktives Regelprofil.</x-dashboard.empty>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Regelprofil öffnen →</a>
