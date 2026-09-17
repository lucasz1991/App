@if($data['profile'])
    <p class="ops-badge" data-state="approved">Aktiv</p>
    <h3 style="margin:8px 0 0;">{{ $data['profile']->name }}</h3>
    @if($rows === 2)
        <dl class="ops-meta widget-detail">
            <div><dt>Mindestruhezeit</dt><dd>{{ $data['profile']->minimum_rest_minutes }} min</dd></div>
            <div><dt>Max. Schichtdauer</dt><dd>{{ $data['profile']->maximum_shift_minutes }} min</dd></div>
        </dl>
    @endif
@else
    <p class="ops-empty" style="padding:16px 0;">Kein aktives Regelprofil.</p>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Regelprofil öffnen →</a>
