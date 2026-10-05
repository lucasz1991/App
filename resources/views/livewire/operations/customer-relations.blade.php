<div class="rt-ops ops-stack"><x-operations.feedback />
    <x-ui.forms.select wire:model.live="customerId" aria-label="Kunde auswählen"><option value="">Kunde auswählen</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->company_name }}</option>@endforeach</x-ui.forms.select>
    @if(!$ready)<p class="ops-empty">Kundenprozesse derzeit nicht verfügbar.</p>@elseif($customerId)
        @foreach(['contact'=>'Ansprechpartner','location'=>'Einsatzorte','condition'=>'Konditionen'] as $section => $title)
            <section class="ops-stack"><header class="ops-toolbar"><h2 class="font-semibold">{{ $title }}</h2><x-ui.buttons.button-basic type="button" size="sm" wire:click="create('{{ $section }}')"><i class="far fa-plus" aria-hidden="true"></i>Hinzufügen</x-ui.buttons.button-basic></header>
                @if($section === 'contact')<x-tables.table :columns="[['label'=>'Kontakt','key'=>'contact'],['label'=>'Rollen','key'=>'roles'],['label'=>'Status','key'=>'active']]" :items="$contacts" row-view="components.tables.rows.operations.customer-relation-row" table-key="customer-contacts" empty="Keine weiteren Ansprechpartner." />
                @elseif($section === 'location')<x-tables.table :columns="[['label'=>'Einsatzort','key'=>'location'],['label'=>'Adresse','key'=>'address'],['label'=>'Status','key'=>'active']]" :items="$locations" row-view="components.tables.rows.operations.customer-relation-row" table-key="customer-locations" empty="Keine weiteren Einsatzorte." />
                @else<x-tables.table :columns="[['label'=>'Leistung','key'=>'condition'],['label'=>'Preis netto','key'=>'price'],['label'=>'Gültigkeit','key'=>'valid']]" :items="$conditions" row-view="components.tables.rows.operations.customer-relation-row" table-key="customer-conditions" empty="Keine Konditionen hinterlegt." />@endif
            </section>
        @endforeach
    @endif
    <x-operations.modal wire:model="formOpen" :title="['contact'=>'Ansprechpartner','location'=>'Einsatzort','condition'=>'Kondition','condition_end'=>'Kondition beenden'][$kind] ?? 'Bearbeiten'">
        <form wire:submit="save" class="ops-form">
            @if($kind === 'contact')
                <x-operations.field label="Name" model="form.name" required :wide="true" /><x-operations.field label="E-Mail" model="form.email" type="email" /><x-operations.field label="Telefon" model="form.phone" type="tel" />
                <fieldset class="ops-full space-y-2"><legend class="font-semibold text-sm">Kontaktrollen</legend>@foreach(\App\Models\CustomerContact::ROLES as $role => $label)<x-ui.forms.checkbox wire:model="form.roles" :value="$role" :label="$label" />@endforeach</fieldset><x-ui.forms.checkbox wire:model="form.is_active" label="Aktiv" />
            @elseif($kind === 'location')
                <x-operations.field label="Bezeichnung" model="form.name" required :wide="true" /><x-operations.field label="Straße" model="form.street" :wide="true" /><x-operations.field label="Postleitzahl" model="form.postal_code" /><x-operations.field label="Ort" model="form.city" /><x-operations.field label="Land (ISO)" model="form.country" required /><x-operations.field label="Zugang / Übergabe" model="form.access_note" type="textarea" :wide="true" /><x-ui.forms.checkbox wire:model="form.is_active" label="Aktiv" />
            @elseif($kind === 'condition')
                <x-operations.field label="Leistungskennung" model="form.code" required /><x-operations.field label="Leistung" model="form.label" required /><x-operations.field label="Einheit" model="form.unit" required /><x-operations.field label="Preis netto (€)" model="form.price" type="number" min="0" step="0.01" required /><x-operations.field label="Gültig ab" model="form.valid_from" type="date" required /><x-operations.field label="Gültig bis" model="form.valid_until" type="date" /><x-operations.field label="Mindest-, Warte- und Zusatzbedingungen" model="form.terms" type="textarea" :wide="true" />
            @else<x-operations.field label="Letzter Gültigkeitstag" model="form.date" type="date" required />@endif
            <div class="ops-full ops-actions"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Speichern</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="$set('formOpen', false)">Abbrechen</x-ui.buttons.button-basic></div>
        </form>
    </x-operations.modal>
</div>
