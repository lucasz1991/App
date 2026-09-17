@if($data['stats']['available'])
    <p class="ops-kpi-val" style="font-size:28px;">{{ $data['stats']['total'] }}</p>
    <p class="ops-muted">eigene Geräte</p>
    @if($size === 'lg')
        <dl class="ops-meta" style="margin-top:10px;">
            <div><dt>Bereit</dt><dd>{{ $data['stats']['ready'] }}</dd></div>
            <div><dt>Ausstehend</dt><dd>{{ $data['stats']['pending'] }}</dd></div>
            <div><dt>Blockiert</dt><dd>{{ $data['stats']['blocked'] }}</dd></div>
        </dl>
    @endif
    @if($data['href'])<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Meine Geräte öffnen →</a>@endif
@else
    <p class="ops-empty" style="padding:16px 0;">Keine eigenen Geräte zugewiesen.</p>
@endif
