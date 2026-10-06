<section class="rt-ops ops-stack min-w-0" aria-label="Vorgänge und Aufträge">
    <header class="ops-toolbar">
        <x-ui.buttons.multi-toggle id="case-workspace-view" label="Vorgangsansicht" :value="$view" action="setView" :options="array_values($views)" />
        @if($view !== 'offers' && !in_array($section, ['imports','portal']))<x-ui.buttons.button-basic type="button" mode="primary" wire:click="$dispatch('operations-create')"><i class="far fa-plus" aria-hidden="true"></i>{{ $view === 'orders' ? 'Auftrag' : 'Anfrage' }}</x-ui.buttons.button-basic>@endif
    </header>
    @if($reservation)
        <div class="ops-actions" aria-label="Reservierungskontext"><x-operations.status :value="$reservation->status" />
            <x-ui.buttons.button-basic mode="link" :href="route('operations.page',['page'=>'cases','view'=>'inbox','section'=>'portal','customer'=>$reservation->customer_id,'source'=>'submission','record'=>$reservation->submission_id])">Portalvorgang</x-ui.buttons.button-basic>
            @if($reservation->order_id && auth()->user()->can('operations.manage'))<x-ui.buttons.button-basic mode="link" :href="route('operations.page',['page'=>'cases','view'=>'orders','customer'=>$reservation->customer_id,'order'=>$reservation->order_id])">Auftrag</x-ui.buttons.button-basic>@endif
        </div>
    @endif
    @if($view === 'inbox')
        @if(count($sections) > 1)<nav class="ops-actions" aria-label="Eingangsbereiche">@foreach($sections as $key=>$label)<x-ui.buttons.button-basic type="button" :mode="$section === $key ? 'primary':'link'" wire:click="setSection('{{ $key }}')" :aria-current="$section === $key ? 'page' : null">{{ $label }}</x-ui.buttons.button-basic>@endforeach</nav>@endif
        @if($section === 'imports')<livewire:operations.operations-enhancements tab="imports" :key="'case-imports'" />
        @elseif($section === 'portal')
            @if($portalCustomers->isNotEmpty())
                <x-ui.forms.select aria-label="Portalkunde" change="$wire.setPortalCustomer(Number($event.target.value))">@foreach($portalCustomers as $customer)<option value="{{ $customer->id }}" @selected($customer->id === ($context['customer'] ?? null))>{{ $customer->company_name }}</option>@endforeach</x-ui.forms.select>
                <livewire:operations.customer-portal-management tab="requests" :customer-id="$context['customer']" :embedded="true" :initial-record-id="in_array($context['source'] ?? '',['submission','request']) ? ($context['record'] ?? null) : null" :initial-source="in_array($context['source'] ?? '',['submission','request']) ? $context['source'] : ''" :key="'case-portal-'.($context['customer'] ?? 'all').'-'.($context['record'] ?? '')" />
            @else<p class="ops-muted">Keine betreuten Kunden.</p>@endif
        @else<livewire:operations.inquiry-inbox :initial-inquiry-id="$context['inquiry'] ?? null" :customer-id="$context['customer'] ?? null" :consolidated="true" :key="'case-inbox-'.($context['customer'] ?? 'all').'-'.($context['inquiry'] ?? '')" />@endif
    @elseif($view === 'offers')
        <livewire:operations.commercial-offer-index :customer-id="$context['customer'] ?? null" :inquiry-id="$context['inquiry'] ?? null" :order-id="$context['order'] ?? null" :initial-revision="$context['revision'] ?? null" :key="'case-offers-'.md5(json_encode($context))" />
    @elseif($view === 'orders')
        <livewire:admin.operations.orders :initial-order-id="$context['order'] ?? null" :customer-id="$context['customer'] ?? null" :initial-section="$section" :initial-record-id="($context['record_type'] ?? '') === 'proof' && $section === 'proofs' ? ($context['record'] ?? null) : null" :consolidated="true" :key="'case-orders-'.($context['customer'] ?? 'all').'-'.($context['order'] ?? '').'-'.$section" />
    @endif
</section>
