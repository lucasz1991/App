<div class="customer-profile__overview">
    @if($profile['metrics'])
        <div @class(['customer-profile__metrics grid', 'grid-cols-1'=>count($profile['metrics'])===1, 'grid-cols-2'=>count($profile['metrics'])>1, 'sm:grid-cols-3'=>count($profile['metrics'])>=3, 'xl:grid-cols-4'=>count($profile['metrics'])===4, 'xl:grid-cols-5'=>count($profile['metrics'])===5, 'xl:grid-cols-6'=>count($profile['metrics'])>=6]) aria-label="Kundenkennzahlen">
            @foreach($profile['metrics'] as $metric)
                @if(isset($views[$metric['view']]))
                <a href="#" wire:click.prevent="setView('{{ $metric['view'] }}')" class="block rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/50" aria-label="{{ $metric['label'] }}: {{ $metric['value'] }}. Öffnen">
                    <x-ui.dashboard.stat-card class="customer-profile__metric [&_dt]:break-words [&>div>span]:!hidden sm:[&>div>span]:!flex" :label="$metric['label']" :value="number_format($metric['value'],0,',','.')" tone="neutral" :compact-mobile="true"><i class="far {{ $metric['icon'] }}" aria-hidden="true"></i></x-ui.dashboard.stat-card>
                </a>
                @else
                    <x-ui.dashboard.stat-card class="customer-profile__metric [&_dt]:break-words [&>div>span]:!hidden sm:[&>div>span]:!flex" :label="$metric['label']" :value="number_format($metric['value'],0,',','.')" tone="neutral" :compact-mobile="true"><i class="far {{ $metric['icon'] }}" aria-hidden="true"></i></x-ui.dashboard.stat-card>
                @endif
            @endforeach
        </div>
    @endif

    <div class="customer-profile__overview-grid">
        @if($canCreate)
            <section class="{{ $surface }}" aria-label="Kundenstammdaten">
                <header class="customer-profile__card-heading"><span class="customer-profile__card-icon"><i class="far fa-building" aria-hidden="true"></i></span><h3>Stammdaten</h3><x-ui.buttons.button-basic type="button" mode="link" wire:click="editCustomer({{ $customerId }})" class="customer-profile__icon-button ml-auto" aria-label="Kundenstammdaten bearbeiten"><i class="far fa-pen" aria-hidden="true"></i></x-ui.buttons.button-basic></header>
                <dl class="customer-profile__details grid min-w-0 grid-cols-1 gap-x-6 sm:grid-cols-2">
                    <div class="min-w-0"><dt class="text-xs text-rt-muted dark:text-rt-dark-muted">Ansprechpartner</dt><dd class="mt-1 text-sm font-medium">{{ $profileCustomer->contact_name ?: '—' }}</dd></div>
                    <div class="min-w-0"><dt class="text-xs text-rt-muted dark:text-rt-dark-muted">Telefon</dt><dd class="mt-1 text-sm font-medium">@if($profileCustomer->phone)<a class="break-words hover:text-rt-red" href="tel:{{ $profileCustomer->phone }}">{{ $profileCustomer->phone }}</a>@else — @endif</dd></div>
                    <div class="min-w-0"><dt class="text-xs text-rt-muted dark:text-rt-dark-muted">E-Mail</dt><dd class="mt-1 break-all text-sm font-medium">@if($profileCustomer->email)<a class="hover:text-rt-red" href="mailto:{{ $profileCustomer->email }}">{{ $profileCustomer->email }}</a>@else — @endif</dd></div>
                    <div class="min-w-0"><dt class="text-xs text-rt-muted dark:text-rt-dark-muted">Anschrift</dt><dd class="mt-1 text-sm font-medium">{{ $profileCustomer->street ?: '—' }}@if($profileCustomer->postal_code || $profileCustomer->city)<span class="mt-0.5 block">{{ trim($profileCustomer->postal_code.' '.$profileCustomer->city) }}</span>@endif@if($profileCustomer->country)<span class="mt-0.5 block text-rt-muted dark:text-rt-dark-muted">{{ $profileCustomer->country }}</span>@endif</dd></div>
                </dl>
                @if($profileCustomer->notes)<div class="mt-5 border-t border-rt-border/70 pt-4 dark:border-rt-dark-border/70"><h4 class="text-xs text-rt-muted dark:text-rt-dark-muted">Interne Notiz</h4><p class="mt-1 whitespace-pre-line break-words text-sm">{{ $profileCustomer->notes }}</p></div>@endif
                <footer class="mt-5 flex flex-wrap gap-x-4 gap-y-1 border-t border-rt-border/70 pt-4 text-xs text-rt-muted dark:border-rt-dark-border/70 dark:text-rt-dark-muted">
                    @if($profileCustomer->created_at)<span>Angelegt {{ $profileCustomer->created_at->timezone(config('app.timezone'))->format('d.m.Y') }}</span>@endif
                    @if($profileCustomer->updated_at)<span>Geändert {{ $profileCustomer->updated_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>@endif
                </footer>
                <div class="mt-5 border-t border-rt-border/70 pt-5 dark:border-rt-dark-border/70"><livewire:operations.customer-logo :customer-id="$customerId" :key="'customer-logo-'.$customerId" /></div>
            </section>
        @endif
        <aside class="{{ $surface }}" aria-label="Kundenportalstatus">
            <header class="customer-profile__card-heading"><span class="customer-profile__card-icon"><i class="far fa-user-lock" aria-hidden="true"></i></span><h3>Kundenportal</h3></header>
            @if($profile['portal'])
                <x-ui.badge :color="$profile['portal']['enabled']?'emerald':'slate'">{{ $profile['portal']['enabled']?'Aktiviert':'Deaktiviert' }}</x-ui.badge>
                <dl class="customer-profile__portal-details text-sm">
                    <div class="flex items-center justify-between gap-3"><dt class="text-rt-muted dark:text-rt-dark-muted">Zugänge gesamt</dt><dd class="font-semibold tabular-nums">{{ array_sum($profile['portal']['memberships']) }}</dd></div>
                    <div class="flex items-center justify-between gap-3"><dt class="text-rt-muted dark:text-rt-dark-muted">Nutzbare Zugänge</dt><dd class="font-semibold tabular-nums">{{ $profile['portal']['usable_memberships'] }}</dd></div>
                    <div class="flex items-center justify-between gap-3"><dt class="text-rt-muted dark:text-rt-dark-muted">Offene Einladungen</dt><dd class="font-semibold tabular-nums">{{ $profile['portal']['invitations']['pending'] }}</dd></div>
                    <div class="flex items-center justify-between gap-3"><dt class="text-rt-muted dark:text-rt-dark-muted">Anfrageprüfung</dt><dd class="text-right font-medium">{{ ['manual'=>'Manuell','offer'=>'Angebotsassistenz','accept'=>'Regelgebundene Zusage'][$profile['portal']['automation_mode']] ?? 'Manuell' }}</dd></div>
                    <div class="flex items-center justify-between gap-3"><dt class="text-rt-muted dark:text-rt-dark-muted">Zusatzbestätigung</dt><dd class="font-medium">{{ $profile['portal']['require_mfa']?'Erforderlich':'Optional' }}</dd></div>
                </dl>
                @if($profile['portal']['modules'])<div class="mt-4 flex flex-wrap gap-1.5">@foreach($profile['portal']['modules'] as $module)<x-ui.badge color="slate">{{ ['orders'=>'Aufträge','requests'=>'Anfragen','offers'=>'Angebote','changes'=>'Änderungen','proofs'=>'Nachweise','documents'=>'Dokumente','messages'=>'Nachrichten','reports'=>'Berichte'][$module] ?? 'Modul' }}</x-ui.badge>@endforeach</div>@endif
                @if(isset($views['portal']))<x-ui.buttons.button-basic type="button" class="mt-5 w-full" wire:click="setView('portal')"><i class="far fa-sliders-h" aria-hidden="true"></i>Portalverwaltung</x-ui.buttons.button-basic>@endif
            @else
                <x-ui.badge color="slate"><i class="far fa-lock" aria-hidden="true"></i>{{ $portalReady?'Zugriff eingeschränkt':'Einrichtung erforderlich' }}</x-ui.badge>
            @endif
        </aside>
    </div>

    <div class="customer-profile__overview-grid">
        @if(isset($views['orders']))
            <section class="{{ $surface }}" aria-label="Letzte Kundenaufträge">
                <header class="customer-profile__card-heading"><span class="customer-profile__card-icon"><i class="far fa-briefcase" aria-hidden="true"></i></span><h3>Letzte Aufträge</h3><x-ui.buttons.button-basic type="button" mode="link" class="ml-auto" wire:click="setView('orders')">Alle<i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic></header>
                @include('livewire.operations.partials.customer-profile-records',['records'=>$profile['recentOrders'],'empty'=>'Noch keine Aufträge.'])
            </section>
        @endif
        @if(isset($views['inquiries']))
            <section class="{{ $surface }}" aria-label="Aktuelle Kundenanfragen">
                <header class="customer-profile__card-heading"><span class="customer-profile__card-icon"><i class="far fa-inbox" aria-hidden="true"></i></span><h3>Aktuelle Anfragen</h3><x-ui.buttons.button-basic type="button" mode="link" class="ml-auto" wire:click="setView('inquiries')">Alle<i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic></header>
                @include('livewire.operations.partials.customer-profile-records',['records'=>$profile['recentInquiries'],'empty'=>'Noch keine Anfragen.'])
            </section>
        @endif
        @if($profile['followUps']->isNotEmpty())
            <section class="{{ $surface }}" aria-label="Kundenwiedervorlagen">
                <header class="customer-profile__card-heading"><span class="customer-profile__card-icon"><i class="far fa-calendar-check" aria-hidden="true"></i></span><h3>Wiedervorlagen</h3></header>
                @include('livewire.operations.partials.customer-profile-records',['records'=>$profile['followUps'],'empty'=>'Keine offenen Wiedervorlagen.'])
            </section>
        @endif
    </div>
</div>
