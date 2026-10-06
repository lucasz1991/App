<div class="rt-ops ops-stack" data-customer-communications>
    <x-operations.surface>
        <x-slot:actions>
            @if(count($views)>1)
                <nav class="ops-actions mr-auto" aria-label="Kommunikationsansichten">
                    @foreach($views as $key=>$label)<x-ui.buttons.button-basic type="button" :mode="$view===$key?'primary':'link'" wire:click="setView('{{ $key }}')" :aria-current="$view===$key?'page':null">{{ $label }}</x-ui.buttons.button-basic>@endforeach
                </nav>
            @else<h3 class="mr-auto text-sm font-semibold">{{ $views[$view] }}</h3>@endif
            @if($canRecord && $ready && $view==='records')<x-ui.buttons.button-basic type="button" mode="primary" wire:click="create"><i class="far fa-plus" aria-hidden="true"></i>Kontakt protokollieren</x-ui.buttons.button-basic>@endif
        </x-slot:actions>
        @if($records)
            <x-tables.toolbar id="customer-communication-filters" :single-line="true" :filter-count="(trim($search)!==''?1:0)+($channelFilter!=='all'?1:0)" title="Kommunikation filtern" reset-action="resetFilters" search-for="customer-communication-search">
                <x-slot:search><x-tables.search-field id="customer-communication-search" placeholder="Betreff suchen" maxlength="100" :results-count="$records->total()" wire:model.live.debounce.300ms="search" /></x-slot:search>
                @if($view==='records')<x-tables.filter-field label="Kanal" icon="far fa-comments" for="customer-communication-channel"><x-ui.forms.select id="customer-communication-channel" wire:model.live="channelFilter" aria-label="Kommunikationskanal"><option value="all">Alle Kanäle</option>@foreach(\App\Models\CustomerInteraction::CHANNELS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>@endif
            </x-tables.toolbar>
            <x-tables.table label="Kundenkommunikation" table-key="customer-communication" :flush-top="true" :columns="[['label'=>'Betreff','key'=>'subject','width'=>'46%'],['label'=>'Kanal','key'=>'channel','width'=>'20%'],['label'=>'Zeitpunkt','key'=>'date','width'=>'34%']]" :items="$records" detail-action="openDetails" row-view="components.tables.rows.customers.communication-row" actions-view="components.tables.rows.customers.communication-actions" empty="Noch keine Kommunikation hinterlegt." />
            <div class="py-4">{{ $records->links() }}</div>
        @else<p class="rounded-xl border border-rt-border p-4 text-sm text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted">Kommunikationsprotokoll nach Datenbankaktualisierung verfügbar.</p>@endif
    </x-operations.surface>
    @if($formOpen && $canRecord && $ready)
    <x-dialog-modal wire:model="formOpen" maxWidth="2xl">
        <x-slot name="title">Kontakt protokollieren</x-slot>
        <x-slot name="content">
            <form wire:submit="save" id="customer-communication-form" class="ops-form">
                <x-operations.field label="Kanal" model="form.channel" type="select" required>@foreach(\App\Models\CustomerInteraction::CHANNELS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-operations.field>
                <x-operations.field label="Richtung" model="form.direction" type="select" required>@foreach(\App\Models\CustomerInteraction::DIRECTIONS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-operations.field>
                <x-operations.field label="Zeitpunkt" model="form.occurred_at" type="datetime-local" required />
                <x-operations.field label="Zeitzone" model="form.timezone" required maxlength="64" />
                @if($canLinkContacts)<x-operations.field label="Ansprechpartner" model="form.contact_id" type="select"><option value="">Ohne Zuordnung</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }}</option>@endforeach</x-operations.field>@endif
                @if($canLinkOrders)<x-operations.field label="Auftrag" model="form.order_id" type="select"><option value="">Ohne Zuordnung</option>@foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->order_number }} · {{ $order->title }}</option>@endforeach</x-operations.field>@endif
                @if($canLinkInquiries)<x-operations.field label="Anfrage" model="form.inquiry_id" type="select"><option value="">Ohne Zuordnung</option>@foreach($inquiries as $inquiry)<option value="{{ $inquiry->id }}">{{ $inquiry->number }} · {{ $inquiry->title }}</option>@endforeach</x-operations.field>@endif
                <x-operations.field label="Betreff" model="form.subject" :wide="true" required maxlength="180" />
                <x-operations.field label="Gesprächsnotiz / Inhalt" model="form.body" type="textarea" :wide="true" required maxlength="5000" rows="5" />
            </form>
        </x-slot>
        <x-slot name="footer"><x-ui.buttons.button-basic type="button" wire:click="close">Abbrechen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="submit" form="customer-communication-form" mode="primary" wire:loading.attr="disabled" wire:target="save">Protokollieren</x-ui.buttons.button-basic></x-slot>
    </x-dialog-modal>
    @endif
    @if($detailOpen && $selected)
    <x-dialog-modal wire:model="detailOpen" maxWidth="2xl">
        <x-slot name="title">{{ $selected?->subject ?? 'Kommunikation' }}</x-slot>
        <x-slot name="content">
            @if($selected)
                <div class="space-y-4">
                    <div class="flex flex-wrap gap-2 text-xs text-rt-muted dark:text-rt-dark-muted">
                        @if($view==='portal')<x-ui.badge :color="$selected->visibility==='internal'?'slate':'sky'">{{ $selected->visibility==='internal'?'Interne Notiz':'Portalnachricht' }}</x-ui.badge><span>{{ $selected->identity_id?'Vom Kunden':'Von der Verwaltung' }}</span>@else<x-ui.badge color="slate">{{ \App\Models\CustomerInteraction::CHANNELS[$selected->channel] ?? 'Kontakt' }}</x-ui.badge><span>{{ \App\Models\CustomerInteraction::DIRECTIONS[$selected->direction] ?? 'Intern' }} · Protokolleintrag</span>@endif
                        <span>{{ ($view==='portal'?$selected->created_at:$selected->occurred_at)?->setTimezone(config('operations.display_timezone','Europe/Berlin'))->format('d.m.Y H:i') }}</span>
                    </div>
                    <p class="whitespace-pre-wrap break-words text-sm leading-6">{{ $selected->body }}</p>
                </div>
            @endif
        </x-slot>
        <x-slot name="footer"><x-ui.buttons.button-basic type="button" wire:click="close">Schließen</x-ui.buttons.button-basic></x-slot>
    </x-dialog-modal>
    @endif
</div>
