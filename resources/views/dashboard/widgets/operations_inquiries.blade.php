{{-- Alters-Ampel: nicht nur wie viele Anfragen offen sind, sondern wie lange schon. --}}
@php
    $ages = $data['ages'];
    $channelIcons = ['email' => 'mail', 'phone' => 'phone', 'manual' => 'edit-3', 'portal' => 'globe', 'web' => 'globe'];
    $ageLabels = ['fresh' => 'unter 24 Stunden', 'aging' => '1–3 Tage alt', 'overdue' => 'älter als 3 Tage'];
@endphp
<div class="wv-inline">
    <span class="widget-primary-val">{{ $data['count'] }}</span>
    <span class="widget-primary-lbl">{{ $data['count'] === 1 ? 'offene Anfrage' : 'offene Anfragen' }}</span>
    @if($data['oldest'])
        <span class="wv-pill {{ $ages['overdue'] > 0 ? 'ops-tone-warn' : '' }}" title="Älteste offene Anfrage"><i data-feather="clock"></i>{{ $data['oldest']->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</span>
    @endif
</div>
<div class="wv-ampel" role="list" aria-label="Offene Anfragen nach Alter">
    @foreach([['fresh', 'ok', '< 24 Std.'], ['aging', 'warn', '1–3 Tage'], ['overdue', 'danger', '> 3 Tage']] as [$key, $tone, $label])
        <div class="wv-ampel-col" role="listitem" data-tone="{{ $tone }}" @if($ages[$key] === 0) data-empty @endif aria-label="{{ $ages[$key] }} {{ $ageLabels[$key] }}">
            <b>{{ $ages[$key] }}</b><span>{{ $label }}</span>
        </div>
    @endforeach
</div>
@if($rows === 2)
    <div class="widget-detail wv-list">
        @forelse($data['items'] as $item)
            <div class="wv-item">
                <span class="wv-item-ico ops-tone-neutral" title="Kanal: {{ $item['channel'] }}"><i data-feather="{{ $channelIcons[$item['channel']] ?? 'inbox' }}"></i></span>
                <span class="wv-item-main">
                    <span class="wv-truncate">{{ $item['title'] }}</span>
                    <small class="wv-truncate">{{ $item['meta'] ?? 'Ohne Kunde' }} · {{ $item['when']?->diffForHumans() }}</small>
                </span>
                <span class="wv-age-dot" data-age="{{ $item['age'] }}" title="{{ $ageLabels[$item['age']] }}"></span>
            </div>
        @empty
            <x-dashboard.empty icon="check-circle">Keine offenen Anfragen.</x-dashboard.empty>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Offene Anfragen öffnen →</a>
