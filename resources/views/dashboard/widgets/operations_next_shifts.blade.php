@forelse($data['shifts'] as $shift)
    <a class="ops-row ops-service" href="{{ route('operations.workspace', 'shift-management') }}?shift={{ $shift->id }}" wire:navigate>
        <div>
            <p class="ops-service-date">{{ $shift->starts_at->format('d.m. · H:i') }}</p>
            <h3 style="margin:2px 0 0;">{{ $shift->title }}</h3>
            @if($rows === 2)<p class="ops-muted">{{ $shift->order?->customer?->company_name }} · {{ $shift->location_name }}</p>@endif
        </div>
        <span class="ops-badge" data-state="{{ $shift->reserved >= $shift->required_staff ? 'confirmed' : 'pending' }}">{{ $shift->reserved }}/{{ $shift->required_staff }}</span>
    </a>
@empty
    <div class="ops-empty">Keine Dienste in den nächsten 14 Tagen.</div>
@endforelse
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Schichtplan öffnen →</a>
