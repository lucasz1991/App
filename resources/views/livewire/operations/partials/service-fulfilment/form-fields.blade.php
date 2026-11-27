@if(in_array($tab,['partners','costs']))
    <x-operations.field label="Mitarbeiter" model="form.user_id" type="select" :required="true"><option value="">Auswählen</option>@foreach($staff as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-operations.field>
@endif
                @if(in_array($tab,['proofs','travel','partners']))
                    @if($tab === 'proofs')<x-operations.field label="Auftrag" model="form.order_id" type="select" required><option value="">Auswählen</option>@foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->order_number }} · {{ $order->title }}</option>@endforeach</x-operations.field>@endif
                    <x-operations.field label="Dienst" model="form.shift_id" type="select" required><option value="">Auswählen</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}">{{ $shift->title }}</option>@endforeach</x-operations.field>
                    @if($tab !== 'partners')<x-operations.field label="Bezeichnung" model="form.title" maxlength="180" required />@endif
                    @if($tab === 'proofs')
                        @foreach($form['rows'] ?? [] as $index=>$row)<fieldset wire:key="proof-row-{{ $index }}" class="ops-full grid gap-3 sm:grid-cols-3"><x-operations.field label="Tätigkeit" :model="'form.rows.'.$index.'.activity'" required /><x-operations.field label="Kundenmenge" :model="'form.rows.'.$index.'.quantity'" type="number" step="0.01" min="0" required /><x-operations.field label="Einheit" :model="'form.rows.'.$index.'.unit'" required /></fieldset>@endforeach
                        <div class="ops-full"><x-ui.buttons.button-basic type="button" wire:click="addRow">Position hinzufügen</x-ui.buttons.button-basic></div>
                    @elseif($tab === 'travel')
                        <x-operations.field label="Art" model="form.travel_kind" type="select"><option value="train">Bahnreise</option><option value="hotel">Hotel</option><option value="car">PKW</option><option value="other">Sonstiges</option></x-operations.field>
                        <x-operations.field label="Beginn" model="form.starts_at" type="datetime-local" required /><x-operations.field label="Ende" model="form.ends_at" type="datetime-local" required /><x-operations.field label="Ziel" model="form.destination" required />
                    @else<x-operations.field label="Partner" model="form.partner_name" required /><x-operations.field label="Antwortfrist" model="form.deadline" type="datetime-local" required />@endif
                    <x-operations.field label="Vermerk" model="form.note" type="textarea" :wide="true" maxlength="2000" />
                @elseif($tab === 'imports')
                    <x-operations.field label="Format" model="form.format" type="select"><option value="text">Text</option><option value="csv">CSV (;)</option></x-operations.field><x-operations.field label="Quellenreferenz" model="form.reference" required /><x-operations.field label="Originaleingang" model="form.source" type="textarea" :wide="true" rows="8" maxlength="20000" required />
                @elseif($tab === 'costs')<x-operations.field label="Gültig ab" model="form.starts_on" type="date" required /><x-operations.field label="Gültig bis" model="form.ends_on" type="date" /><x-operations.field label="Stundenkosten (Cent)" model="form.hourly_cents" type="number" min="0" required />
                @endif
