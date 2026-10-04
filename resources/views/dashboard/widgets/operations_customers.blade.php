{{-- Rangliste: die Kunden mit dem meisten laufenden Geschaeft oben. --}}
<div class="wv-inline">
    <span class="widget-primary-val">{{ $data['active'] }}</span>
    <span class="widget-primary-lbl">aktive Kunden</span>
    <span class="wv-pill {{ $data['newRecently'] > 0 ? 'ops-tone-ok' : '' }}" title="Neu angelegt in den letzten 30 Tagen"><i data-feather="trending-up"></i>+{{ $data['newRecently'] }} in 30 T</span>
</div>
@if($rows === 1)
    @php($leader = $data['top']->first())
    @if($leader)
        <p class="wv-sub wv-truncate"><i data-feather="award"></i><strong>{{ $leader->company_name }}</strong> · {{ $leader->open_orders }} offene {{ (int) $leader->open_orders === 1 ? 'Leistung' : 'Leistungen' }}</p>
    @endif
    <p class="wv-sub">{{ $data['inactive'] }} inaktiv · {{ $data['total'] }} gesamt</p>
@else
    <div class="widget-detail">
        <p class="wv-label" style="margin-top:12px;">Nach offenen Leistungen</p>
        <div class="wv-rank" style="margin-top:0;">
            @forelse($data['top'] as $index => $customer)
                <div class="wv-rank-row">
                    <span class="wv-rank-no">{{ $index + 1 }}</span>
                    <span class="wv-rank-name wv-truncate">{{ $customer->company_name }}@if($customer->city) <small>· {{ $customer->city }}</small>@endif</span>
                    <span class="wv-rank-val" title="{{ $customer->orders_count }} Leistungen insgesamt">{{ $customer->open_orders }}</span>
                    <span class="wv-rank-bar"><span style="width:{{ $customer->open_orders > 0 ? max(4, round($customer->open_orders / $data['topMax'] * 100)) : 0 }}%;"></span></span>
                </div>
            @empty
                <x-dashboard.empty icon="briefcase">Noch keine aktiven Kunden.</x-dashboard.empty>
            @endforelse
        </div>
        <p class="wv-sub wv-gap">{{ $data['inactive'] }} inaktiv · {{ $data['total'] }} gesamt</p>
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Kundendatenbank öffnen →</a>
