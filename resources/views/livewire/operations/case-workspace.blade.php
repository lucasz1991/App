<section class="rt-ops ops-stack min-w-0" aria-label="Vorgänge und Aufträge" data-case-workspace="{{ $view }}" x-data="{}">
    <template x-teleport="[data-topbar-planning-navigation]" wire:key="case-topbar-navigation">
        <x-ui.navigation.area-menu id="case-workspace-view" label="Vorgangsansicht" :value="$view === 'shifts' && $section === 'calendar' ? 'calendar' : $view" action="setPlanningView" :options="$planningOptions" :navigate="true" />
    </template>
    @if($view === 'shifts' && $section === 'plan')
        <template x-teleport="[data-page-header-actions]" wire:key="case-shift-create-action">
            <x-operations.create-action module="shift-management" />
        </template>
    @endif
    @if($view === 'orders' && !$costsOnly)
        <template x-teleport="[data-page-header-action-list]" wire:key="case-order-create-action">
            <x-ui.buttons.button-basic class="rt-case-create-action rt-case-create-action--icon" type="button" mode="primary" aria-label="Auftrag anlegen" title="Auftrag anlegen" wire:click="$dispatch('operations-create')"><i class="far fa-plus" aria-hidden="true"></i></x-ui.buttons.button-basic>
        </template>
    @endif
    @if($reservation)
        <div class="ops-actions" aria-label="Reservierungskontext"><x-operations.status :value="$reservation->status" />
            <x-ui.buttons.button-basic mode="link" :href="route('operations.page',['page'=>'cases','view'=>'inbox','section'=>'portal','customer'=>$reservation->customer_id,'source'=>'submission','record'=>$reservation->submission_id])">Portalvorgang</x-ui.buttons.button-basic>
            @if($reservation->order_id && auth()->user()->can('operations.manage'))<x-ui.buttons.button-basic mode="link" :href="route('operations.page',['page'=>'cases','view'=>'orders','customer'=>$reservation->customer_id,'order'=>$reservation->order_id])">Auftrag</x-ui.buttons.button-basic>@endif
        </div>
    @endif
    @if($view === 'inbox')
        @if($section !== 'overview')<x-ui.buttons.button-basic mode="link" :href="\App\Support\Operations\OperationsPages::url('cases', ['view'=>'inbox','customer'=>$context['customer'] ?? null])" wire:navigate><i class="far fa-inbox" aria-hidden="true"></i>Gemeinsamen Eingang öffnen</x-ui.buttons.button-basic>@endif
        @if($section === 'imports')
            <livewire:operations.operations-enhancements tab="imports" :embedded="true" :key="'case-imports'" />
        @elseif($section === 'portal')
            @if($portalCustomers->isNotEmpty())
                <x-ui.forms.select aria-label="Portalkunde" change="$wire.setPortalCustomer(Number($event.target.value))">@foreach($portalCustomers as $customer)<option value="{{ $customer->id }}" @selected($customer->id === ($context['customer'] ?? null))>{{ $customer->company_name }}</option>@endforeach</x-ui.forms.select>
                <livewire:operations.customer-portal-management tab="requests" :customer-id="$context['customer']" :embedded="true" :initial-record-id="in_array($context['source'] ?? '',['submission','request']) ? ($context['record'] ?? null) : null" :initial-source="in_array($context['source'] ?? '',['submission','request']) ? $context['source'] : ''" :key="'case-portal-'.($context['customer'] ?? 'all').'-'.($context['record'] ?? '')" />
            @else
                <p class="ops-muted">Keine betreuten Kunden.</p>
            @endif
        @else
            <livewire:operations.unified-inbox :customer-id="$context['customer'] ?? null" :key="'case-unified-inbox-'.($context['customer'] ?? 'all')" />
            @if(($context['source'] ?? '') === 'ai-intake' && ($context['record'] ?? null) && !request()->has('assistant'))<livewire:operations.ai-intake-inbox :initial-intake-id="$context['record']" :customer-id="$context['customer'] ?? null" :modal-only="true" :key="'case-intake-form-'.$context['record']" />@endif
            @if($context['inquiry'] ?? null)<livewire:operations.inquiry-inbox :initial-inquiry-id="$context['inquiry']" :customer-id="$context['customer'] ?? null" :modal-only="true" :key="'case-inquiry-detail-'.$context['inquiry']" />@endif
        @endif
    @elseif($view === 'shifts')
        <livewire:operations.planning-page-workspace page="shifts" :initial-view="$section" :context="$context" :embedded="true" :key="'case-shifts-'.$section" />
    @elseif($view === 'offers')
        <livewire:operations.commercial-offer-index :header-in-page="true" :customer-id="$context['customer'] ?? null" :inquiry-id="$context['inquiry'] ?? null" :order-id="$context['order'] ?? null" :initial-revision="$context['revision'] ?? null" :key="'case-offers-'.md5(json_encode($context))" />
    @elseif($view === 'orders')
        @if($costsOnly)
            <livewire:operations.operations-enhancements tab="costs" :order-id="$context['order'] ?? null" :embedded="true" :key="'case-costs-'.($context['order'] ?? 'all')" />
        @else
            <livewire:admin.operations.orders :initial-order-id="$context['order'] ?? null" :customer-id="$context['customer'] ?? null" :initial-section="$section" :initial-record-id="($context['record_type'] ?? '') === 'proof' && $section === 'proofs' ? ($context['record'] ?? null) : null" :consolidated="true" :key="'case-orders-'.($context['customer'] ?? 'all').'-'.($context['order'] ?? '').'-'.$section" />
        @endif
    @endif
</section>
