@if($viewMode === 'orders')
    <x-tables.filter-field label="Anzeige" icon="far fa-list-ol" for="service-page-size">
        <x-ui.forms.select id="service-page-size" wire:model.live="servicePerPage" aria-label="Leistungen pro Seite">
            @foreach([25, 50, 100] as $size)<option value="{{ $size }}">{{ $size }} pro Seite</option>@endforeach
        </x-ui.forms.select>
        @error('servicePerPage')<span class="text-xs text-rt-red">{{ $message }}</span>@enderror
    </x-tables.filter-field>
@endif
<x-tables.filter-field label="Auftrag" for="shift-order-filter"><x-ui.forms.select id="shift-order-filter" wire:model.live="orderFilter" search-model="orderSearch" aria-label="Auftrag"><option value="all">Alle Aufträge</option>@foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->title }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
<x-tables.filter-field label="Status" for="shift-status-filter"><x-ui.forms.select id="shift-status-filter" wire:model.live="statusFilter" aria-label="Schichtstatus filtern"><option value="all">Alle Status</option>@foreach($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
@if($nativeOperations)<x-tables.filter-field label="Handlungsbedarf" for="shift-attention"><x-ui.forms.select id="shift-attention" wire:model.live="attentionFilter" aria-label="Handlungsbedarf"><option value="all">Alle Schichten</option><option value="conflicts">Besetzung mit Konflikten</option><option value="unpublished">Unveröffentlichte Änderungen</option><option value="awaiting">Rückmeldung ausstehend</option><option value="declined">Abgelehnte Dienste</option></x-ui.forms.select></x-tables.filter-field>@endif
