<div class="space-y-4">
<x-tables.toolbar title="Filter" id="inquiry-filters">
<x-slot:search><x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Anfrage oder Kunde suchen" /></x-slot:search>
<x-slot:bulk><x-ui.buttons.button-basic mode="primary" wire:click="create">Anfrage erfassen</x-ui.buttons.button-basic></x-slot:bulk>
<x-ui.forms.select wire:model.live="filter" aria-label="Anfragen filtern"><option value="active">Offene Vorgänge</option><option value="all">Alle Vorgänge</option><option value="email">E-Mail</option><option value="phone">Telefon</option><option value="portal">Portal</option><option value="manual">Manuell</option></x-ui.forms.select>
</x-tables.toolbar>
<x-operations.feedback />
<x-tables.table :columns="[['label'=>'Anfrage','key'=>'title','width'=>'2fr'],['label'=>'Nummer','key'=>'number'],['label'=>'Kunde','key'=>'customer.company_name'],['label'=>'Eingang','key'=>'channel'],['label'=>'Status','key'=>'status']]" :items="$inquiries" detail-action="select" row-view="components.tables.rows.operations.record" empty="Keine Anfragen." />
{{ $inquiries->links() }}
<x-operations.modal wire:model="detailOpen" :title="$editing ? ($selectedId ? 'Bedarf bearbeiten' : 'Neue Anfrage') : 'Anfrage'" max-width="4xl">
            @if($editing)
                <form wire:submit="save" class="ops-form">
                    <x-operations.field label="Betreff" model="form.title" required maxlength="180" :wide="true" />
                    @if(!$selectedId)
                        <x-operations.field label="Eingangsweg" model="form.channel" type="select"><option value="manual">Manuell</option><option value="email">E-Mail</option><option value="phone">Telefon</option><option value="portal">Portal</option></x-operations.field>
                        <x-operations.field label="Eingangsreferenz" model="form.source_reference" maxlength="190" />
                        <x-operations.field label="Original / Gesprächsnotiz" model="form.original" type="textarea" required maxlength="20000" :wide="true" />
                    @endif
                    <x-operations.field label="Kunde" model="form.customer_id" type="select" :wide="true"><option value="">Bitte zuordnen</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->company_name }}</option>@endforeach</x-operations.field>
                    <x-operations.field label="Ansprechpartner" model="form.contact_name" maxlength="180" />
                    <x-operations.field label="E-Mail" model="form.contact_email" type="email" />
                    <x-operations.field label="Telefon" model="form.contact_phone" type="tel" />
                    <x-operations.field label="Zeitzone" model="form.timezone" required />
                    <x-operations.field label="Beginn" model="form.starts_at" type="datetime-local" />
                    <x-operations.field label="Ende" model="form.ends_at" type="datetime-local" />
                    <x-operations.field label="Einsatzort" model="form.location_name" maxlength="180" />
                    <x-operations.field label="Tätigkeit" model="form.role_name" maxlength="160" />
                    <x-operations.field label="Personalbedarf" model="form.required_staff" type="number" min="1" max="999" />
                    <div class="ops-full ops-actions"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Speichern</x-ui.buttons.button-basic><x-ui.buttons.button-basic wire:click="close">Abbrechen</x-ui.buttons.button-basic></div>
                </form>
            @elseif($selected)
                <header class="ops-toolbar"><div><p class="ops-kicker">{{ $selected->number }} · Revision {{ $selected->revision }}</p><h2>{{ $selected->title }}</h2></div><x-operations.status :value="$selected->status" /></header>
                <dl class="ops-meta"><div><dt>Kunde</dt><dd>{{ $selected->customer?->company_name ?? 'Noch nicht zugeordnet' }}</dd></div><div><dt>Kontakt</dt><dd>{{ $selected->contact_name ?: '—' }}</dd></div><div><dt>Beginn</dt><dd>{{ $selected->starts_at?->format('d.m.Y H:i') ?? '—' }}</dd></div><div><dt>Ende · {{ $selected->timezone }}</dt><dd>{{ $selected->ends_at?->format('d.m.Y H:i') ?? '—' }}</dd></div><div><dt>Einsatzort</dt><dd>{{ $selected->location_name ?: '—' }}</dd></div><div><dt>Bedarf</dt><dd>{{ $selected->required_staff ?? '—' }} × {{ $selected->role_name ?: '—' }}</dd></div></dl>
                <details><summary>Originaleingang</summary><div class="ops-original">{{ $selected->original }}</div></details>
                @if(!$selected->order_id && !$selected->duplicate_of_id)
                    <div class="ops-actions"><x-ui.buttons.button-basic wire:click="$set('editing', true)">Bedarf bearbeiten</x-ui.buttons.button-basic>@if($selected->status === 'new')<x-ui.buttons.button-basic mode="primary" wire:click="transition('verify')" wire:loading.attr="disabled">Bedarf bestätigen</x-ui.buttons.button-basic>@endif</div>
                    @if(in_array($selected->status, ['verified','offered']))
                        <form wire:submit="transition('offer')" class="ops-form"><x-operations.field label="Angebotsbetrag netto (€)" model="amount" type="number" min="0" step="0.01" required /><x-operations.field label="Leistungsumfang und Konditionen" model="terms" type="textarea" required /><div class="ops-full"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Angebot festhalten</x-ui.buttons.button-basic></div></form>
                    @endif
                    @if($selected->offer)<div class="ops-panel"><p class="ops-kicker">Angebot · Revision {{ $selected->offer['revision'] }}</p><h2>{{ number_format($selected->offer['amount_cents']/100,2,',','.') }} € netto</h2><p>{{ $selected->offer['terms'] }}</p></div>@endif
                    @if($selected->status === 'offered')
                        <form wire:submit="transition('accept')" class="ops-stack"><x-operations.field label="Kundenzusage · Person, Zeitpunkt, Referenz" model="acceptance" type="textarea" required /><x-ui.forms.checkbox wire:model="authorized" required label="Beauftragung durch berechtigten Kundenkontakt bestätigt" /><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Zusage dokumentieren</x-ui.buttons.button-basic></form>
                    @elseif($selected->status === 'accepted')<x-ui.buttons.button-basic mode="primary" wire:click="transition('convert')" wire:loading.attr="disabled">Auftrag anlegen</x-ui.buttons.button-basic>@endif
                    @if(!$selected->offer)<details><summary>Dublette zuordnen</summary><form wire:submit="transition('duplicate')" class="ops-actions"><x-operations.field label="Nummer des älteren Originals" model="duplicateId" type="number" min="1" required /><x-ui.buttons.button-basic type="submit">Verknüpfen</x-ui.buttons.button-basic></form></details>@endif
                @elseif($selected->order)@can('operations.manage')<a class="ops-link" href="{{ route('operations.workspace','orders') }}" wire:navigate>Auftrag {{ $selected->order->order_number }} →</a>@else<span>Auftrag {{ $selected->order->order_number }}</span>@endcan
                @else<p>Original: {{ $selected->duplicateOf?->number }}</p>@endif
                <details><summary>Verlauf</summary>@foreach($history as $event)<div class="ops-row"><span>{{ \App\Support\Operations\OperationsNavigation::auditLabel($event->action) }}</span><span class="ops-muted">{{ $event->actor?->name }} · {{ $event->created_at->setTimezone(config('operations.display_timezone'))->format('d.m. H:i') }}</span></div>@endforeach</details>
            @else<div class="ops-empty">Anfrage auswählen.</div>@endif

</x-operations.modal>
</div>
