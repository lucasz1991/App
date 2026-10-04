{{-- Avatar-Stapel: wer zum Team gehoert, wie viele aktiv sind und wer neu dazukam. --}}
<div class="wv-inline">
    <span class="widget-primary-val">{{ $data['total'] }}</span>
    <span class="widget-primary-lbl">Mitarbeiter</span>
    <span class="wv-pill {{ $data['newRecently'] > 0 ? 'ops-tone-ok' : '' }}" title="Neu in den letzten 30 Tagen"><i data-feather="user-plus"></i>+{{ $data['newRecently'] }} in 30 T</span>
</div>
<div class="wv-stack-row">
    @if($data['faces']->isNotEmpty())
        <div class="wv-stack" aria-label="Zuletzt hinzugekommene aktive Mitarbeiter">
            @foreach($data['faces'] as $face)
                <x-dashboard.avatar :user="$face" :size="30" />
            @endforeach
            @if($data['active'] > $data['faces']->count())
                <span class="wv-stack-more">+{{ $data['active'] - $data['faces']->count() }}</span>
            @endif
        </div>
    @else
        <span class="wv-sub" style="margin:0;">Noch keine aktiven Konten</span>
    @endif
    <span class="wv-sub" style="margin:0;white-space:nowrap;"><strong>{{ $data['activePct'] }} %</strong> aktiv</span>
</div>
@if($rows === 2)
    <div class="widget-detail">
        <div class="wv-ratio" role="img" aria-label="{{ $data['active'] }} aktiv, {{ $data['inactive'] }} inaktiv"><span style="width:{{ $data['activePct'] }}%;"></span></div>
        <p class="wv-sub">{{ $data['active'] }} aktiv · {{ $data['inactive'] }} inaktiv</p>
        <p class="wv-label wv-gap">Zuletzt hinzugekommen</p>
        <div class="wv-list">
            @forelse($data['recent'] as $employee)
                <div class="wv-item">
                    <x-dashboard.avatar :user="$employee" :size="28" />
                    <span class="wv-item-main"><span class="wv-truncate">{{ $employee->name }}</span><small>seit {{ $employee->created_at->translatedFormat('d.m.Y') }}</small></span>
                </div>
            @empty
                <x-dashboard.empty icon="users">Keine Mitarbeiter.</x-dashboard.empty>
            @endforelse
        </div>
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Mitarbeiter öffnen →</a>
