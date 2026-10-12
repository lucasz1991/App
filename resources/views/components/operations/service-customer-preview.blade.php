@props(['customer' => null, 'previewId' => null])
@php
    $companyName = $customer?->company_name ?: 'Kunde nicht hinterlegt';
    $customerNumber = trim((string) $customer?->customer_number);
    $actor = auth()->user();
    $canOpenProfile = $customer instanceof \App\Models\Customer
        && (int) $customer->getKey() > 0
        && $actor instanceof \App\Models\User
        && $actor->status
        && $actor->can('operations.manage');
@endphp
<x-operations.service-cell-preview label="Kunde" :preview-id="$previewId" kind="customer">
    <x-slot:trigger>
        <div class="rt-service-customer"><x-operations.company-avatar :customer="$customer" :size="30" /><span class="rt-table-value">{{ $companyName }}</span></div>
    </x-slot:trigger>
    <x-slot:content>
        <header class="rt-service-customer-preview__identity">
            <x-operations.company-avatar :customer="$customer" :size="48" />
            <div>
                <span class="rt-service-preview-note">Kunde</span>
                <strong>{{ $companyName }}</strong>
                @if($customerNumber !== '')<span class="rt-service-customer-preview__number">{{ $customerNumber }}</span>@endif
            </div>
        </header>
        @if($canOpenProfile)
            <x-ui.buttons.button-basic mode="link" size="sm" :href="\App\Support\Operations\OperationsPages::url('customers', ['view' => 'master', 'customer' => (int) $customer->getKey()])" class="rt-service-customer-preview__link" data-service-customer-profile data-table-row-ignore>
                <i class="far fa-id-card" aria-hidden="true"></i>Kundenprofil öffnen<i class="far fa-arrow-right" aria-hidden="true"></i>
            </x-ui.buttons.button-basic>
        @endif
    </x-slot:content>
</x-operations.service-cell-preview>
