{{-- Halbkreis-Anzeige: Anteil der Empfaenger, die in den letzten 7 Tagen tatsaechlich versorgt wurden. --}}
@php
    $rate = $data['reachRate'];
    $gauge = $rate === null ? 'var(--ops-line)' : ($rate >= 95 ? 'var(--ops-ok)' : ($rate >= 80 ? 'var(--ops-warn)' : 'var(--ops-signal)'));
@endphp
<div class="wv-gauge-row">
    <div class="wv-gauge-wrap">
        <div class="wv-gauge" style="--pct:{{ $rate ?? 0 }};--gauge:{{ $gauge }};" role="img" aria-label="Zustellquote der letzten 7 Tage: {{ $rate === null ? 'noch keine Daten' : $rate.' Prozent' }}">
            <b>{{ $rate === null ? '—' : $rate.' %' }}</b>
        </div>
        <p class="wv-gauge-caption">zugestellt · 7 T</p>
    </div>
    <div style="min-width:0;">
        <span class="widget-primary-val">{{ $data['pending'] }}</span>
        <span class="widget-primary-lbl">im Versand ausstehend</span>
        <p class="wv-sub"><strong>{{ $data['sentWeek'] }}</strong> versendet · {{ $data['recipientsWeek'] }} Empfänger</p>
    </div>
</div>
@if($rows === 2)
    <div class="widget-detail">
        <p class="wv-label">Ausstehend</p>
        <div class="wv-list">
            @forelse($data['recent'] as $mail)
                @php
                    $recipients = collect($mail->recipients ?? []);
                    $done = $recipients->filter(fn ($recipient) => (bool) ($recipient['status'] ?? false))->count();
                    $all = $recipients->count();
                @endphp
                <div class="wv-item">
                    <span class="wv-item-ico ops-tone-warn"><i data-feather="send"></i></span>
                    <span class="wv-item-main">
                        <span class="wv-truncate">{{ $mail->content['subject'] ?? ucfirst($mail->type) }}</span>
                        <span class="wv-progress"><span style="width:{{ $all > 0 ? round($done / $all * 100) : 0 }}%;"></span></span>
                    </span>
                    <span class="ops-muted" style="font-size:12px;white-space:nowrap;" title="Empfänger versorgt">{{ $done }}/{{ $all }}</span>
                </div>
            @empty
                <x-dashboard.empty icon="check-circle">Alles versendet.</x-dashboard.empty>
            @endforelse
        </div>
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Mailverwaltung öffnen →</a>
