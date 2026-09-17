@if($data['profile'])
    <p class="ops-badge" data-state="approved">Aktiv</p>
    <h3 style="margin:8px 0 0;">{{ $data['profile']->name }}</h3>
    @if($size === 'lg')
        <dl class="ops-meta" style="margin-top:10px;">
            <div><dt>Mindestruhezeit</dt><dd>{{ $data['profile']->minimum_rest_minutes }} min</dd></div>
            <div><dt>Max. Schichtdauer</dt><dd>{{ $data['profile']->maximum_shift_minutes }} min</dd></div>
        </dl>
    @endif
@else
    <p class="ops-empty" style="padding:16px 0;">Kein aktives Regelprofil.</p>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Regelprofil öffnen →</a>
