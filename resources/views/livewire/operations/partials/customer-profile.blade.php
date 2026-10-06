@php
    $profileCustomer = $profile['customer'];
    $profileIcons = ['master'=>'fa-building','orders'=>'fa-briefcase','inquiries'=>'fa-inbox','offers'=>'fa-file-invoice','contacts'=>'fa-address-book','conditions'=>'fa-tags','communication'=>'fa-comments','documents'=>'fa-folder-open','history'=>'fa-history','portal'=>'fa-user-lock'];
    $surface = 'min-w-0 rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 sm:p-5';
@endphp
<section class="min-w-0 max-w-full space-y-5" data-customer-profile aria-label="Kundenprofil {{ $customer->company_name }}">
    <header class="{{ $surface }}">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <x-ui.buttons.button-basic type="button" mode="link" wire:click="showList"><i class="far fa-arrow-left" aria-hidden="true"></i>Kundenliste</x-ui.buttons.button-basic>
            @if($canCreate)<x-ui.buttons.button-basic type="button" wire:click="editCustomer({{ $customerId }})"><i class="far fa-pen" aria-hidden="true"></i>Bearbeiten</x-ui.buttons.button-basic>@endif
        </div>
        <div class="flex min-w-0 items-start gap-3 sm:gap-4">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-rt-surface-muted text-xl text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted sm:h-14 sm:w-14" aria-hidden="true"><i class="far fa-building"></i></span>
            <div class="min-w-0 flex-1">
                @if($canCreate)<p class="mb-1 text-xs font-medium text-rt-muted dark:text-rt-dark-muted">{{ $profileCustomer->customer_number }}</p>@endif
                <h2 class="break-words text-xl font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-3xl">{{ $customer->company_name }}</h2>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if($canCreate)
                        <x-ui.badge :color="$profileCustomer->is_active?'emerald':'slate'"><i class="far {{ $profileCustomer->is_active?'fa-check-circle':'fa-pause-circle' }}" aria-hidden="true"></i>{{ $profileCustomer->is_active?'Aktiver Kunde':'Inaktiver Kunde' }}</x-ui.badge>
                        @if($profileCustomer->city)<span class="text-sm text-rt-muted dark:text-rt-dark-muted"><i class="far fa-map-marker-alt mr-1" aria-hidden="true"></i>{{ $profileCustomer->city }}</span>@endif
                    @endif
                    @if($profile['portal'])<x-ui.badge :color="$profile['portal']['enabled']?'sky':'slate'"><i class="far fa-user-lock" aria-hidden="true"></i>{{ $profile['portal']['enabled']?'Portal aktiviert':'Portal deaktiviert' }}</x-ui.badge>@endif
                </div>
            </div>
        </div>
    </header>

    <nav class="flex max-w-full items-center gap-1.5 overflow-x-auto pb-2" aria-label="Kundenakte" data-customer-profile-navigation>
        @foreach($views as $key=>$label)
            <x-ui.buttons.button-basic type="button" :mode="$view===$key?'primary':'basic'" class="shrink-0 whitespace-nowrap" wire:click="setView('{{ $key }}')" :aria-current="$view===$key?'page':null" wire:loading.attr="disabled" wire:target="setView"><i class="far {{ $profileIcons[$key] }}" aria-hidden="true"></i>{{ $label }}</x-ui.buttons.button-basic>
        @endforeach
    </nav>

    <div class="min-w-0" data-customer-profile-content>
        @if($view==='master')
            @include('livewire.operations.partials.customer-profile-overview')
        @elseif($view==='orders')
            <div class="mb-4 flex justify-end"><x-ui.buttons.button-basic type="button" mode="primary" wire:click="$dispatch('operations-create')"><i class="far fa-plus" aria-hidden="true"></i>Auftrag anlegen</x-ui.buttons.button-basic></div>
            <livewire:admin.operations.orders :customer-id="$customerId" :consolidated="true" :profile-embedded="true" :key="'customer-orders-'.$customerId.'-'.$contextRevision" />
        @elseif($view==='inquiries')
            <div class="mb-4 flex justify-end"><x-ui.buttons.button-basic type="button" mode="primary" wire:click="$dispatch('operations-create')"><i class="far fa-plus" aria-hidden="true"></i>Anfrage anlegen</x-ui.buttons.button-basic></div>
            <livewire:operations.inquiry-inbox :customer-id="$customerId" :consolidated="true" :profile-embedded="true" :key="'customer-inquiries-'.$customerId.'-'.$contextRevision" />
        @elseif($view==='offers')
            <livewire:operations.commercial-offer-index :customer-id="$customerId" :key="'customer-offers-'.$customerId.'-'.$contextRevision" />
        @elseif(in_array($view,['contacts','conditions'],true))
            <livewire:operations.customer-relations :customer-id="$customerId" :section="$view" :embedded="true" :key="'customer-relations-'.$customerId.'-'.$view.'-'.$contextRevision" />
        @elseif($view==='communication')
            <livewire:operations.customer-communications :customer-id="$customerId" :key="'customer-communication-'.$customerId.'-'.$contextRevision" />
        @elseif($view==='documents')
            <header class="mb-4 flex flex-wrap items-center justify-between gap-3"><h3 class="text-base font-semibold">Dokumente & Anlagen</h3>@if(isset($views['portal']))<x-ui.buttons.button-basic type="button" wire:click="openPortalPublications"><i class="far fa-share-square" aria-hidden="true"></i>Freigaben verwalten</x-ui.buttons.button-basic>@endif</header>
            <livewire:operations.customer-documents :customer-id="$customerId" :key="'customer-documents-'.$customerId.'-'.$contextRevision" />
        @elseif($view==='history')
            <livewire:operations.customer-history :customer-id="$customerId" :key="'customer-history-'.$customerId.'-'.$contextRevision" />
        @elseif($view==='portal')
            <nav class="ops-actions mb-4" aria-label="Portalverwaltung">
                @foreach($sections as $key=>$label)<x-ui.buttons.button-basic type="button" :mode="$section===$key?'primary':'basic'" wire:click="setSection('{{ $key }}')" :aria-current="$section===$key?'page':null">{{ $label }}</x-ui.buttons.button-basic>@endforeach
            </nav>
            <livewire:operations.customer-portal-management :customer-id="$customerId" :tab="$section" :embedded="true" :key="'customer-portal-'.$customerId.'-'.$section.'-'.$contextRevision" />
        @endif
    </div>
</section>
