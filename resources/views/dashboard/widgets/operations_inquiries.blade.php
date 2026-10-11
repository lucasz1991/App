{{--
    Offene Anfragen: Alters-Ampel (wie lange liegen sie schon), bei zwei
    Zeilen der Eingang der letzten 14 Tage (hell = eingegangen, rot = schon
    zum Auftrag geworden), die Umwandlungsquote und die Kanaele.
--}}
@php
    $ages = $data['ages'];
    $channelIcons = ['email' => 'mail', 'phone' => 'phone', 'manual' => 'edit-3', 'portal' => 'globe', 'web' => 'globe'];
    $channelNames = ['email' => 'E-Mail', 'phone' => 'Telefon', 'manual' => 'Manuell', 'portal' => 'Kundenportal', 'web' => 'Web'];
    $ageLabels = ['fresh' => 'unter 24 Stunden', 'aging' => '1–3 Tage alt', 'overdue' => 'älter als 3 Tage'];
    $axis = collect($data['intakeLabels'])->map(fn ($label, $i) => $i % 2 === 1 ? '' : mb_substr($label, 0, 2))->all();
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
    <div class="wv-chart-head">
        <p class="wv-label wv-truncate">Eingang · 14 Tage</p>
        <span class="wv-pill" title="{{ $data['converted30'] }} von {{ $data['created30'] }} Anfragen der letzten 30 Tage wurden zu Aufträgen"><b style="color:var(--ops-ink);">{{ $data['conversion30'] === null ? '—' : $data['conversion30'].' %' }}</b>&nbsp;zu Auftrag</span>
    </div>
    <x-dashboard.columns
        :values="$data['intake']" :fill="$data['intakeConverted']" :labels="$data['intakeLabels']" :axis="$axis"
        value-label="eingegangen" fill-label="zu Auftrag" :height="44"
        aria-label="Eingang der letzten 14 Tage: {{ array_sum($data['intake']) }} Anfragen, davon {{ array_sum($data['intakeConverted']) }} zu Aufträgen"
    />
    <p class="wv-chart-note"><span><i style="background:color-mix(in srgb, var(--ops-ink) 12%, transparent)"></i>neu</span><span><i style="background:var(--ops-signal)"></i>zu Auftrag</span>@foreach(array_slice($data['byChannel'], 0, 2, true) as $channel => $count)<span title="offen über {{ $channelNames[$channel] ?? $channel }}"><b>{{ $count }}</b> {{ $channelNames[$channel] ?? $channel }}</span>@endforeach</p>
    <div class="widget-detail wv-list">
        @forelse($data['items']->take(1) as $item)
            <a class="wv-item" href="{{ $item['href'] }}" wire:navigate>
                <span class="wv-item-ico ops-tone-neutral" title="Kanal: {{ $channelNames[$item['channel']] ?? $item['channel'] }}"><i data-feather="{{ $channelIcons[$item['channel']] ?? 'inbox' }}"></i></span>
                <span class="wv-item-main">
                    <span class="wv-truncate">{{ $item['title'] }}</span>
                    <small class="wv-truncate">{{ $item['meta'] ?? 'Ohne Kunde' }} · {{ $item['when']?->diffForHumans() }}</small>
                </span>
                <span class="wv-age-dot" data-age="{{ $item['age'] }}" title="{{ $ageLabels[$item['age']] }}"></span>
            </a>
        @empty
            <x-dashboard.empty icon="check-circle">Keine offenen Anfragen.</x-dashboard.empty>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Anfragen öffnen →</a>
