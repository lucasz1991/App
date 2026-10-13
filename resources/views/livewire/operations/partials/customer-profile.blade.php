@php
    $profileCustomer = $profile['customer'];
    $profileIcons = ['master'=>'fa-building','orders'=>'fa-briefcase','inquiries'=>'fa-inbox','offers'=>'fa-file-invoice','contacts'=>'fa-address-book','conditions'=>'fa-tags','communication'=>'fa-comments','documents'=>'fa-folder-open','history'=>'fa-history','portal'=>'fa-user-lock'];
    $profilePrefix = 'customer-profile-'.$customerId;
    $profileGroups = [];
    foreach ([
        'profile' => ['label' => 'Profil', 'icon' => 'fa-building', 'views' => ['master', 'contacts', 'conditions']],
        'operations' => ['label' => 'Betrieb', 'icon' => 'fa-briefcase', 'views' => ['orders', 'inquiries', 'offers']],
        'records' => ['label' => 'Kundenakte', 'icon' => 'fa-folder-open', 'views' => ['communication', 'documents', 'history', 'portal']],
    ] as $groupKey => $definition) {
        $groupViews = [];
        foreach ($definition['views'] as $viewKey) {
            if (isset($views[$viewKey])) {
                $groupViews[$viewKey] = ['label' => $views[$viewKey], 'icon' => 'far '.$profileIcons[$viewKey]];
            }
        }
        if ($groupViews !== []) {
            $profileGroups[$groupKey] = $definition + ['tabs' => $groupViews];
        }
    }
    $profileGroup = collect($profileGroups)->search(fn ($definition) => isset($definition['tabs'][$view]));
    $surface = 'customer-profile__card';
@endphp
<section class="customer-profile" data-customer-profile aria-label="Kundenprofil {{ $customer->company_name }}">
    <div class="customer-profile__dossier">
        <header class="customer-profile__identity" data-customer-profile-identity>
            <div class="customer-profile__person">
                <span class="customer-profile__portrait"><x-operations.company-avatar :customer="$profileCustomer" :size="48" /></span>
                <div class="customer-profile__person-copy">
                    <div class="customer-profile__name-line">
                        <h2>{{ $customer->company_name }}</h2>
                        @if($canCreate)<span class="customer-profile__number" aria-label="Kundennummer">{{ $profileCustomer->customer_number }}</span>@endif
                    </div>
                    <div class="customer-profile__meta-line">
                        @if($canCreate)
                            <x-ui.badge :color="$profileCustomer->is_active?'emerald':'slate'"><i class="far {{ $profileCustomer->is_active?'fa-check-circle':'fa-pause-circle' }}" aria-hidden="true"></i>{{ $profileCustomer->is_active?'Aktiver Kunde':'Inaktiver Kunde' }}</x-ui.badge>
                            @if($profileCustomer->city)<span class="customer-profile__city"><i class="far fa-map-marker-alt" aria-hidden="true"></i>{{ $profileCustomer->city }}</span>@endif
                        @endif
                        @if($profile['portal'])<x-ui.badge :color="$profile['portal']['enabled']?'sky':'slate'"><i class="far fa-user-lock" aria-hidden="true"></i>{{ $profile['portal']['enabled']?'Portal aktiviert':'Portal deaktiviert' }}</x-ui.badge>@endif
                    </div>
                </div>
            </div>
            <div class="customer-profile__identity-actions">
                <x-ui.buttons.button-basic type="button" mode="basic" wire:click="showList" class="customer-profile__icon-button" title="Kundenliste" aria-label="Zur Kundenliste"><i class="far fa-arrow-left" aria-hidden="true"></i></x-ui.buttons.button-basic>
                @if($canCreate)<x-ui.buttons.button-basic type="button" wire:click="editCustomer({{ $customerId }})" class="customer-profile__icon-button" title="Kunden bearbeiten" aria-label="Kunden bearbeiten"><i class="far fa-pen" aria-hidden="true"></i></x-ui.buttons.button-basic>@endif
            </div>
        </header>

        <div class="customer-profile__navigation" data-customer-profile-navigation x-data="{}">
            <nav class="customer-profile__groups" aria-label="Bereiche der Kundenakte">
                @foreach($profileGroups as $key => $definition)
                    @php
                        $groupAction = '$wire.call('.\Illuminate\Support\Js::from('setView').', '.\Illuminate\Support\Js::from((string) array_key_first($definition['tabs'])).').then(() => { if ($el.isConnected) $el.focus({ preventScroll: true }); })';
                    @endphp
                    <x-ui.buttons.button-basic type="button" mode="link" :x-on:click="$groupAction"
                        wire:key="{{ $profilePrefix }}-group-{{ $key }}" class="customer-profile__group"
                        wire:loading.attr="disabled" wire:target="setView"
                        :aria-current="$profileGroup === $key ? 'page' : 'false'" :data-profile-group="$key">
                        <i class="far {{ $definition['icon'] }}" aria-hidden="true"></i><span>{{ $definition['label'] }}</span>
                    </x-ui.buttons.button-basic>
                @endforeach
                <span class="employee-profile__selection-marker customer-profile__selection-marker" data-profile-marker="main" aria-hidden="true" wire:ignore x-data="rtProfileTabMotion({ variant: 'main' })"></span>
            </nav>
            @if($profileGroup !== false)
                <div class="customer-profile__tab-row">
                    <x-operations.panel.tabs :tabs="$profileGroups[$profileGroup]['tabs']"
                        :label="$profileGroups[$profileGroup]['label']" :id-prefix="$profilePrefix" :active="$view"
                        action="setView" class="customer-profile__tabs" wire:key="{{ $profilePrefix }}-tabs-{{ $profileGroup }}">
                        <x-slot:selectionIndicator>
                            <span class="employee-profile__selection-marker customer-profile__selection-marker" data-profile-marker="sub" aria-hidden="true" wire:ignore x-data="rtProfileTabMotion({ variant: 'sub' })"></span>
                        </x-slot:selectionIndicator>
                    </x-operations.panel.tabs>
                </div>
            @endif
            <span class="customer-profile__loading" wire:loading.delay wire:target="setView" role="status"><i class="far fa-spinner fa-spin" aria-hidden="true"></i><span class="sr-only">Kundenbereich wird geladen …</span></span>
        </div>
    </div>

    @if($profileGroup !== false)
        @foreach($profileGroups[$profileGroup]['tabs'] as $key => $definition)
            @if($key !== $view)<section hidden role="tabpanel" id="{{ $profilePrefix }}-panel-{{ $key }}" aria-labelledby="{{ $profilePrefix }}-tab-{{ $key }}"></section>@endif
        @endforeach
    @endif
    <section class="customer-profile__content" data-customer-profile-content
        role="tabpanel" tabindex="0" id="{{ $profilePrefix }}-panel-{{ $view }}" aria-labelledby="{{ $profilePrefix }}-tab-{{ $view }}"
        wire:loading.class="customer-profile__content--loading" wire:target="setView">
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
            <nav class="customer-profile__portal-navigation" aria-label="Portalverwaltung">
                @foreach($sections as $key=>$label)<x-ui.buttons.button-basic type="button" :mode="$section===$key?'primary':'basic'" wire:click="setSection('{{ $key }}')" :aria-current="$section===$key?'page':null" wire:loading.attr="disabled" wire:target="setSection">{{ $label }}</x-ui.buttons.button-basic>@endforeach
            </nav>
            <livewire:operations.customer-portal-management :customer-id="$customerId" :tab="$section" :embedded="true" :key="'customer-portal-'.$customerId.'-'.$section.'-'.$contextRevision" />
        @endif
    </section>
</section>
