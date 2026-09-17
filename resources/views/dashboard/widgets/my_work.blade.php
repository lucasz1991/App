@if($data['activeTime'])
    <p class="ops-service-date">Läuft seit {{ $data['activeTime']->starts_at->format('H:i') }}</p>
    <p class="ops-clock" style="font-size:28px;margin-top:4px;">{{ \App\Support\Operations\OperationsDateTime::duration($data['activeTime']->netSeconds()) }}</p>
@elseif($data['nextAssignment'])
    <p class="ops-service-date">{{ $data['nextAssignment']->shift->starts_at->translatedFormat('D, d. M · H:i') }}</p>
    <h3 style="margin:4px 0 0;">{{ $data['nextAssignment']->shift->title }}</h3>
    <p class="ops-muted">{{ $data['nextAssignment']->shift->location_name }}</p>
    @if($size === 'lg' && $data['nextAssignment']->shift->order?->customer)
        <p class="ops-muted">{{ $data['nextAssignment']->shift->order->customer->company_name }}</p>
    @endif
@else
    <p class="ops-empty" style="padding:16px 0;">Kein bevorstehender Dienst.</p>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate>Mein Arbeitstag öffnen →</a>
