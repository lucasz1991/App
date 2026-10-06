<section class="ops-stack">
    @if($showList)
    <header class="ops-toolbar"><h3 class="font-semibold">{{ $subjectType === 'Order' ? 'Angebot & Nachträge' : 'Angebotsstände' }}</h3>@if($canCreate)<x-ui.buttons.button-basic type="button" wire:click="create" size="sm"><i class="far fa-plus" aria-hidden="true"></i>{{ $subjectType === 'Order' ? 'Nachtrag' : 'Angebot' }}</x-ui.buttons.button-basic>@endif</header>
    <x-tables.table :columns="[['label'=>'Stand','key'=>'revision'],['label'=>'Betrag netto','key'=>'total'],['label'=>'Status','key'=>'status']]" :items="$offers" detail-action="select" row-view="components.tables.rows.operations.commercial-offer-row" empty="Noch keine Angebotsstände." table-key="commercial-offers" />
    @endif
    <x-operations.modal wire:model="formOpen" :title="$subjectType === 'Order' ? 'Nachtrag anlegen' : 'Angebot anlegen'" max-width="4xl">
        <form wire:submit="save" class="ops-stack">
            @foreach($form['positions'] ?? [] as $index => $row)
                <fieldset class="ops-panel ops-stack" wire:key="offer-position-{{ $index }}"><legend class="font-semibold">Position {{ $index + 1 }}</legend><div class="ops-form">
                    @if($conditions->isNotEmpty())
                        <div class="ops-full"><x-ui.forms.label value="Kundenkondition" /><x-ui.forms.select wire:model.live="form.positions.{{ $index }}.condition_id" aria-label="Kundenkondition"><option value="">Freie Position</option>@foreach($conditions as $condition)<option value="{{ $condition->id }}">{{ $condition->label }} · {{ number_format($condition->unit_price_cents / 100, 2, ',', '.') }} € / {{ $condition->unit }}</option>@endforeach</x-ui.forms.select></div>
                    @endif
                    <x-operations.field label="Leistung" :model="'form.positions.'.$index.'.title'" required :wide="true" />
                    <x-operations.field label="Menge" :model="'form.positions.'.$index.'.quantity'" type="number" min="0.001" step="0.001" required />
                    <x-operations.field label="Einheit" :model="'form.positions.'.$index.'.unit'" required />
                    <x-operations.field label="Einzelpreis netto (€)" :model="'form.positions.'.$index.'.price'" type="number" min="0" step="0.01" required />
                    <x-operations.field label="Positionsart" :model="'form.positions.'.$index.'.kind'" type="select"><option value="standard">Fest</option><option value="alternative">Alternative · ohne Summe</option><option value="eventual">Optional · ohne Summe</option><option value="eventual_included">Option beauftragt · in Summe</option></x-operations.field>
                    <x-operations.field label="Leistungsbeginn" :model="'form.positions.'.$index.'.starts_at'" type="datetime-local" />
                    <x-operations.field label="Leistungsende" :model="'form.positions.'.$index.'.ends_at'" type="datetime-local" />
                </div><div class="ops-actions"><x-ui.buttons.button-basic type="button" size="sm" wire:click="removePosition({{ $index }})">Entfernen</x-ui.buttons.button-basic></div></fieldset>
            @endforeach
            <x-ui.forms.input-error for="positions" /><x-ui.buttons.button-basic type="button" wire:click="addPosition" size="sm">Position hinzufügen</x-ui.buttons.button-basic>
            <div class="ops-form"><x-operations.field label="Gültig bis" model="form.valid_until" type="date" /><x-operations.field label="Konditionen" model="form.terms" type="textarea" required :wide="true" />@if($subjectType === 'Order')<x-operations.field label="Zusatzumfang / Anlass" model="form.reason" type="textarea" required :wide="true" />@endif</div>
            <div class="ops-actions"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Entwurf speichern</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="$set('formOpen', false)">Abbrechen</x-ui.buttons.button-basic></div>
        </form>
    </x-operations.modal>
    <x-operations.modal wire:model="detailOpen" title="Angebotsstand" max-width="3xl">
        @if($selected)
            <header class="ops-toolbar"><h3 class="font-semibold">Version {{ $selected->revision }}</h3><span class="tabular-nums font-semibold">{{ number_format($selected->total_cents / 100, 2, ',', '.') }} € netto</span></header>
            <dl class="ops-meta">
                @foreach($selected->snapshot['positions'] ?? [] as $row)
                    <div><dt>{{ $row['title'] }}</dt><dd class="tabular-nums">{{ number_format($row['quantity_milli'] / 1000, 3, ',', '.') }} {{ $row['unit'] }} × {{ number_format($row['unit_price_cents'] / 100, 2, ',', '.') }} €
                        @if(!$row['included']) · ohne Summe @endif
                    </dd></div>
                    @if($row['starts_at'] ?? null)<div><dt>Leistungszeitraum</dt><dd>{{ \Carbon\CarbonImmutable::parse($row['starts_at'])->setTimezone($row['timezone'])->format('d.m.Y H:i') }} – {{ \Carbon\CarbonImmutable::parse($row['ends_at'])->setTimezone($row['timezone'])->format('d.m.Y H:i') }}</dd></div>@endif
                @endforeach
            </dl>
            <p class="whitespace-pre-line">{{ $selected->snapshot['terms'] ?? '' }}</p>@if($selected->snapshot['reason'] ?? '')<p>{{ $selected->snapshot['reason'] }}</p>@endif
            @if($selected->valid_until)<p class="ops-muted">Gültig bis {{ $selected->valid_until->format('d.m.Y') }}</p>@endif
            @if($selected->acceptance_note)<p>{{ $selected->acceptance_note }}</p>@endif
            <div class="ops-actions">@if($selected->status === 'draft')<x-ui.buttons.button-basic type="button" mode="primary" wire:click="issue" wire:loading.attr="disabled">Angebot festhalten</x-ui.buttons.button-basic>@elseif($selected->status === 'offered')<x-ui.buttons.button-basic type="button" mode="primary" wire:click="$set('acceptanceOpen', true)">Zusage dokumentieren</x-ui.buttons.button-basic>@endif</div>
        @endif
    </x-operations.modal>
    <x-operations.modal wire:model="acceptanceOpen" title="Kundenzusage">
        <form wire:submit="accept" class="ops-stack"><x-operations.field label="Person, Zeitpunkt und Referenz" model="note" type="textarea" required /><x-ui.forms.checkbox wire:model="authorized" required label="Berechtigten Kundenkontakt bestätigt" /><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Zusage speichern</x-ui.buttons.button-basic></form>
    </x-operations.modal>
</section>
