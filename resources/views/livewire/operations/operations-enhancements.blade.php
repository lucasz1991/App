<div class="min-w-0 space-y-4">
    <x-operations.feedback />
    @if(!$embedded)<nav class="flex flex-wrap gap-2" aria-label="Leistungen und Zeitwirtschaft">
        @foreach($this->tabs() as $key => $label)
            <x-ui.buttons.button-basic type="button" :mode="$tab === $key ? 'primary' : 'secondary'" wire:click="setTab('{{ $key }}')" :aria-current="$tab === $key ? 'page' : null">{{ $label }}</x-ui.buttons.button-basic>
        @endforeach
    </nav>@endif
    @if(!$ready)
        <p class="ops-muted">Arbeitsbereich nicht verfügbar.</p>
    @else
        @unless($modalOnly)
        <x-tables.toolbar :title="\App\Livewire\Operations\OperationsEnhancements::TABS[$tab]" id="operations-enhancement-filters">
            <x-slot:search>
                @if($tab === 'payroll')
                    <x-ui.forms.search wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter oder Monat (JJJJ-MM)" />
                @elseif(in_array($tab, ['proofs','travel','partners','imports']))
                    <x-ui.forms.search wire:model.live.debounce.300ms="search" placeholder="Vorgang suchen" />
                @endif
            </x-slot:search>
            <x-slot:bulk>@if(!$payrollExportOnly && !($personal && $tab === 'partners') && !(!$personal && in_array($tab,['proofs','travel'])))<x-ui.buttons.button-basic mode="primary" type="button" wire:click="create">{{ $tab === 'terminal' ? 'Zugang einrichten' : 'Neu' }}</x-ui.buttons.button-basic>@endif</x-slot:bulk>
        </x-tables.toolbar>
        @if($tab === 'terminal')
            <ul class="divide-y divide-rt-border dark:divide-rt-dark-border" aria-label="{{ $personal ? 'Mein Terminalzugang' : 'Terminalzugänge der Mitarbeitenden' }}">
                @forelse($records as $profile)
                    <li wire:key="terminal-access-{{ $profile->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $personal ? 'Mein Terminalzugang' : ($profile->user?->name ?? 'Mitarbeiter #'.$profile->user_id) }}</p>
                            <p class="break-all text-sm text-rt-muted dark:text-rt-dark-muted">Terminal-ID · {{ $profile->terminal_id }}</p>
                            <p class="text-sm">{{ $profile->revoked_at ? 'Zugang widerrufen' : 'Zugang aktiv' }} · Standortprüfung {{ $profile->location_consent ? 'freigegeben' : 'aus' }}</p>
                        </div>
                        <x-ui.buttons.button-basic type="button" wire:click="openDetails({{ $profile->id }})" wire:loading.attr="disabled">Details</x-ui.buttons.button-basic>
                    </li>
                @empty
                    <li class="py-4 text-sm text-rt-muted dark:text-rt-dark-muted">Noch kein Terminalzugang eingerichtet.</li>
                @endforelse
            </ul>
        @else
            <x-tables.table :columns="[['label'=>'Vorgang','key'=>'title'],['label'=>'Mitarbeiter','key'=>'user_id'],['label'=>'Status','key'=>'status'],['label'=>'Revision','key'=>'revision'],['label'=>'Aktion','key'=>'action']]" :items="$records" row-view="components.tables.rows.operations.enhancement" empty="Keine Vorgänge in dieser Ansicht." />
        @endif
        @if(method_exists($records, 'links')){{ $records->links() }}@endif
        @if($tab === 'costs')
            <section class="ops-panel ops-stack"><h2>Nachkalkulation</h2><form wire:submit="calculateCosts" class="ops-form"><x-operations.field label="Auftrags-ID" model="form.order_id" type="number" min="1" required :disabled="(bool)$orderId" /><div class="self-end"><x-ui.buttons.button-basic type="submit">Prüfen</x-ui.buttons.button-basic></div></form>
                @if($costResult)<dl class="ops-meta">@foreach(['actual_cost_cents'=>'Istkosten','accepted_revenue_cents'=>'Abgenommener Erlös','contribution_cents'=>'Deckungsbeitrag'] as $key=>$label)<div><dt>{{ $label }}</dt><dd class="tabular-nums">{{ isset($costResult[$key]) ? number_format($costResult[$key]/100,2,',','.').' €' : 'Offen' }}</dd></div>@endforeach</dl>@if($costResult['missing_entry_ids'])<p class="ops-muted">Kostenbasis für {{ count($costResult['missing_entry_ids']) }} Zeitmeldungen fehlt.</p>@endif @endif
            </section>
            @if($acceptedProofs->isNotEmpty())<section class="ops-panel"><h2>Abgenommener Erlös</h2><form wire:submit="saveBilling" class="ops-form"><x-operations.field label="Leistungsnachweis" model="form.proof_id" type="select" required><option value="">Auswählen</option>@foreach($acceptedProofs as $proof)<option value="{{ $proof->id }}">{{ $proof->title }} · Revision {{ $proof->revision }}</option>@endforeach</x-operations.field><x-operations.field label="Abgenommener Betrag (Cent)" model="form.billing_cents" type="number" min="0" required /><div class="ops-full"><x-ui.buttons.button-basic type="submit">Betrag dokumentieren</x-ui.buttons.button-basic></div></form></section>@endif
        @endif
        @endunless
        <x-operations.modal wire:model="formOpen" :title="$tab === 'terminal' ? ($personal ? 'Eigenen Terminalzugang einrichten' : 'Terminalzugang für Mitarbeiter einrichten') : \App\Livewire\Operations\OperationsEnhancements::TABS[$tab]" max-width="4xl">
            @if($formOpen)
            <form wire:submit="save" class="ops-form">
                @if(in_array($tab,['partners','payroll','costs','rules']) || ($tab==='terminal' && !$personal))
                    <x-operations.field label="Mitarbeiter" model="form.user_id" type="select" :required="$tab !== 'rules'"><option value="">{{ $tab === 'rules' ? 'Alle Mitarbeiter' : 'Auswählen' }}</option>@foreach($staff as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-operations.field>
                @endif
                @if(in_array($tab,['proofs','travel','partners']))
                    @if($tab === 'proofs')<x-operations.field label="Auftrag" model="form.order_id" type="select" required><option value="">Auswählen</option>@foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->order_number }} · {{ $order->title }}</option>@endforeach</x-operations.field>@endif
                    <x-operations.field label="Dienst" model="form.shift_id" type="select" :required="$tab !== 'proofs'"><option value="">Auswählen</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}">{{ $shift->title }}</option>@endforeach</x-operations.field>
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
                @elseif($tab === 'rules')
                    <x-operations.field label="Bezeichnung" model="form.name" required />
                    <div class="space-y-1.5">
                        <x-ui.forms.label for="enhancement-rule-kind-{{ $this->getId() }}" value="Regelart" />
                        <x-ui.forms.select id="enhancement-rule-kind-{{ $this->getId() }}" wire:model.live="form.kind">
                            <option value="planning">Planungsgrenze</option><option value="remuneration">Vergütungsbewertung</option>
                        </x-ui.forms.select>
                    </div>
                    <x-operations.field label="Gültig ab" model="form.starts_on" type="date" required />
                    <x-operations.field label="Gültig bis (optional)" model="form.ends_on" type="date" />
                    @if(($form['kind'] ?? 'planning') === 'planning')
                        <fieldset class="ops-full rt-personnel-management rt-personnel-section" wire:key="rule-planning">
                            <legend>Planungsgrenze</legend>
                            <div class="ops-form">
                                <div class="min-w-0 space-y-1.5">
                                    <x-ui.forms.label for="rule-check-{{ $this->getId() }}" value="Prüfung" />
                                    <x-ui.forms.select id="rule-check-{{ $this->getId() }}" wire:model.live="form.configuration.type">
                                        <option value="rolling_minutes">Minuten im Prüfzeitraum</option>
                                        <option value="consecutive_days">Diensttage in Folge</option>
                                        <option value="night_count">Nachtdienste im Prüfzeitraum</option>
                                    </x-ui.forms.select>
                                    <x-ui.forms.input-error for="form.configuration.type" />
                                </div>
                                <x-operations.field label="Prüfzeitraum (Tage)" model="form.configuration.days" type="number" min="1" max="90" required />
                                <x-operations.field :label="match($form['configuration']['type'] ?? 'rolling_minutes') {'night_count' => 'Obergrenze (Nachtdienste)', 'consecutive_days' => 'Obergrenze (Diensttage)', default => 'Obergrenze (min)'}" model="form.configuration.limit" type="number" min="1" required />
                                <x-operations.field label="Zeitzone" model="form.configuration.timezone" required />
                                <x-operations.field label="Abschnitt" model="form.configuration.activity" type="select">
                                    <option value="all">Gesamter Dienst</option><option value="on_call">Bereitschaft</option><option value="travel">Reise</option>
                                </x-operations.field>
                            </div>
                        </fieldset>
                    @else
                        <fieldset class="ops-full rt-personnel-management rt-personnel-section" wire:key="rule-remuneration">
                            <legend>Vergütungsbewertung</legend>
                            <div class="ops-form">
                                <x-operations.field label="Tätigkeit" model="form.configuration.activity" type="select" required>
                                    <option value="">Tätigkeit auswählen</option>
                                    @foreach(\App\Services\Operations\WorkTimeActivityService::KINDS as $kind => $label)<option value="{{ $kind }}">{{ $label }}</option>@endforeach
                                </x-operations.field>
                                <x-operations.field label="Lohnart" model="form.configuration.wage_code" required />
                                <x-operations.field label="Bewertung (Basispunkte)" model="form.configuration.multiplier_bps" type="number" min="0" required />
                                <x-ui.forms.checkbox wire:model="form.configuration.additive" label="Zusätzliche Zuschlagsmenge" />
                                <x-operations.field label="Rundung (Minuten)" model="form.configuration.rounding_minutes" type="number" min="0" max="60" required />
                                <x-operations.field label="Rundungsart" model="form.configuration.rounding_mode" type="select">
                                    <option value="none">Keine</option><option value="nearest">Kaufmännisch</option><option value="up">Aufrunden</option><option value="down">Abrunden</option>
                                </x-operations.field>
                            </div>
                        </fieldset>
                        <fieldset class="ops-full rt-personnel-management rt-personnel-section">
                            <legend>Wochentage</legend>
                            <div class="flex flex-wrap gap-x-4">
                                @foreach([1=>'Mo',2=>'Di',3=>'Mi',4=>'Do',5=>'Fr',6=>'Sa',7=>'So'] as $day => $label)
                                    <x-ui.forms.checkbox wire:model="form.configuration.weekdays" :value="$day" :label="$label" />
                                @endforeach
                            </div>
                        </fieldset>
                    @endif
                    <fieldset class="ops-full rt-personnel-management rt-personnel-section">
                        <legend>Zeitfenster</legend>
                        <div class="ops-form">
                            <x-operations.field label="Zeitfenster ab" type="time" model="form.configuration.window_start" :required="($form['kind'] ?? 'planning') === 'planning' && ($form['configuration']['type'] ?? '') === 'night_count'" />
                            <x-operations.field label="Zeitfenster bis" type="time" model="form.configuration.window_end" :required="($form['kind'] ?? 'planning') === 'planning' && ($form['configuration']['type'] ?? '') === 'night_count'" />
                        </div>
                    </fieldset>
                    <div class="ops-full"><x-ui.forms.checkbox wire:model="form.confirmed" required label="Betrieblich geprüfte Regel freigeben" /><x-ui.forms.input-error for="form.confirmed" /></div>
                @elseif($tab === 'payroll')
                    <x-operations.field label="Monat auswählen" model="form.month_date" type="month" required />
                    <p class="ops-muted ops-full">Die Prüfung umfasst den gesamten Abrechnungsmonat dieser Person.</p>
                @elseif($tab === 'costs')<x-operations.field label="Gültig ab" model="form.starts_on" type="date" required /><x-operations.field label="Gültig bis" model="form.ends_on" type="date" /><x-operations.field label="Stundenkosten (Cent)" model="form.hourly_cents" type="number" min="0" required />
                @elseif($tab === 'terminal')
                    <x-operations.field label="Terminal-ID" model="form.terminal_id" required autocomplete="off" autocapitalize="none" spellcheck="false" />
                    <x-operations.field label="PIN (6–10 Ziffern)" model="form.pin" type="password" inputmode="numeric" pattern="[0-9]{6,10}" maxlength="10" required autocomplete="new-password" />
                    @if($personal)
                        <div class="ops-full">
                            <x-ui.forms.checkbox wire:model="form.location_consent" label="Standortprüfung für diesen Zugang freigeben" />
                        </div>
                        <div x-show="$wire.form.location_consent" x-cloak class="ops-full grid gap-4 sm:grid-cols-2">
                            <x-operations.field label="Breitengrad" model="form.latitude" type="number" min="-90" max="90" step="0.0000001" x-bind:required="$wire.form.location_consent" />
                            <x-operations.field label="Längengrad" model="form.longitude" type="number" min="-180" max="180" step="0.0000001" x-bind:required="$wire.form.location_consent" />
                            <x-operations.field label="Radius in Metern" model="form.radius_metres" type="number" min="30" max="10000" x-bind:required="$wire.form.location_consent" />
                        </div>
                    @endif
                @endif
                <div class="ops-full ops-actions"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">{{ $tab === 'imports' ? 'Vorschau erstellen' : ($tab === 'payroll' ? 'Monat prüfen' : 'Speichern') }}</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="$set('formOpen',false)">Abbrechen</x-ui.buttons.button-basic></div>
            </form>
            @endif
        </x-operations.modal>
        <x-operations.modal wire:model="detailOpen" :title="\App\Livewire\Operations\OperationsEnhancements::TABS[$tab]" max-width="4xl" :variant="$modalOnly ? 'drawer' : 'modal'">
            @if($selected)
                @unless($tab === 'terminal')<header class="ops-toolbar"><h2>{{ $selected->title ?? $selected->name ?? $selected->month ?? 'Terminalzugang' }}</h2><span class="ops-muted">Revision {{ $selectedRevision }}</span></header>@endunless
                @if($selected instanceof \App\Models\OperationWorkflow)
                    <x-operations.status :value="$selected->status" />
                    @if($tab === 'proofs')
                        <x-tables.table :columns="[['label'=>'Tätigkeit','key'=>'activity'],['label'=>'Kundenmenge','key'=>'quantity'],['label'=>'Einheit','key'=>'unit']]" :items="collect($selected->payload['rows'])->map(fn($r,$i)=>(object)(['id'=>$i]+$r))" row-view="components.tables.rows.operations.proof-quantity" />
                        @if(in_array($selected->status,['draft','rejected']) && $selected->user_id===auth()->id())<details><summary class="ops-link">Nachweis bearbeiten</summary><form wire:submit="act('revise')" class="ops-form">@foreach($form['rows']??[] as $index=>$row)<fieldset class="ops-full grid gap-3 sm:grid-cols-3" wire:key="proof-revise-{{ $index }}"><x-operations.field label="Tätigkeit" :model="'form.rows.'.$index.'.activity'" required /><x-operations.field label="Kundenmenge" :model="'form.rows.'.$index.'.quantity'" type="number" step="0.01" min="0" required /><x-operations.field label="Einheit" :model="'form.rows.'.$index.'.unit'" required /></fieldset>@endforeach<x-operations.field label="Änderungsvermerk" model="note" type="textarea" required /><div class="ops-full"><x-ui.buttons.button-basic type="submit">Neue Revision speichern</x-ui.buttons.button-basic></div></form></details>@endif
                        @if($selected->status === 'draft' && $selected->user_id === auth()->id())<x-ui.buttons.button-basic type="button" mode="primary" wire:click="act('submit')">Zur Prüfung einreichen</x-ui.buttons.button-basic>@endif
                        @if(!$personal && $selected->status === 'submitted')<x-operations.field label="Prüfvermerk" model="note" type="textarea" /><div class="ops-actions"><x-ui.buttons.button-basic type="button" wire:click="act('review')">Prüfen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="act('return')">Zurückgeben</x-ui.buttons.button-basic></div>@endif
                        @if(!$personal && $selected->status === 'reviewed')<x-operations.field label="Kundenkontakt" model="contactId" type="select"><option value="">Auswählen</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }}</option>@endforeach</x-operations.field><x-operations.field label="Dokumentierte Kundenentscheidung" model="note" type="textarea" /><div class="ops-actions"><x-ui.buttons.button-basic type="button" wire:click="act('accept')">Abnahme dokumentieren</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="act('reject')">Ablehnung dokumentieren</x-ui.buttons.button-basic></div>@endif
                        @foreach($selected->payload['attachments'] ?? [] as $attachment)<p><a class="ops-link" href="{{ route('operations.proofs.attachment',['id'=>$selected->id,'attachment'=>$attachment['id']]) }}">{{ $attachment['name'] }}</a></p>@endforeach
                    @elseif($tab === 'travel')
                        <dl class="ops-meta"><div><dt>Ziel</dt><dd>{{ $selected->payload['destination'] }}</dd></div><div><dt>Reisezeitraum</dt><dd>{{ $selected->payload['starts_at'] }} – {{ $selected->payload['ends_at'] }}</dd></div>@if(isset($selected->payload['cancel_until']))<div><dt>Stornofrist</dt><dd>{{ $selected->payload['cancel_until'] }}</dd></div>@endif</dl>
                        @foreach($selected->payload['receipts']??[] as $receipt)<p><a class="ops-link" href="{{ route('operations.travel.receipt',['id'=>$selected->id,'receipt'=>$receipt['id']]) }}">{{ $receipt['name'] }}</a></p>@endforeach
                        <x-operations.field label="Vermerk" model="note" type="textarea" />
                        @if(!$personal && $selected->status === 'draft')<div class="ops-actions"><x-ui.buttons.button-basic type="button" wire:click="act('approve')">Freigeben</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="act('return')">Zurückgeben</x-ui.buttons.button-basic></div>@endif
                        @if(!$personal && $selected->status === 'approved')<div class="ops-form"><x-operations.field label="Buchungsreferenz" model="form.booking_reference" /><x-operations.field label="Storno bis" model="form.cancel_until" type="datetime-local" /><x-ui.buttons.button-basic type="button" wire:click="act('book')">Buchung dokumentieren</x-ui.buttons.button-basic></div>@endif
                        @if($personal && in_array($selected->status,['approved','booked']))<x-ui.buttons.button-basic type="button" wire:click="act('cancel_requested')">Storno anfragen</x-ui.buttons.button-basic>@endif
                        @if(!$personal && in_array($selected->status,['approved','booked','cancel_requested']))<x-ui.buttons.button-basic type="button" wire:click="act('cancel')">Storno dokumentieren</x-ui.buttons.button-basic>@endif
                        @if($personal && $selected->status === 'booked')<x-operations.field label="Tatsächlicher Betrag (Cent)" model="form.actual_cents" type="number" min="0" /><x-ui.buttons.button-basic type="button" wire:click="act('expense')">Belege einreichen</x-ui.buttons.button-basic>@endif
                        @if(!$personal && $selected->status==='expense_submitted')<p class="tabular-nums">{{ number_format(($selected->payload['actual_cents']??0)/100,2,',','.') }} €</p><div class="ops-actions"><x-ui.buttons.button-basic type="button" wire:click="act('expense_approve')">Belege freigeben</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="act('expense_return')">Belege zurückgeben</x-ui.buttons.button-basic></div>@endif
                    @elseif($tab === 'partners')
                        <p class="ops-muted">Antwortfrist {{ $selected->payload['deadline'] }}</p>
                        @if($personal && in_array($selected->status,['draft','prepared']))@foreach($ownProofs as $proof)<x-ui.forms.checkbox wire:model="qualificationIds" value="{{ $proof->id }}" :label="$proof->type->name" />@endforeach<div class="ops-actions"><x-ui.buttons.button-basic type="button" wire:click="act('consent')">Ausgewählte Nachweise freigeben</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="act('revoke')">Freigabe widerrufen</x-ui.buttons.button-basic></div>@elseif(!$personal && $selected->status === 'draft')<x-ui.buttons.button-basic type="button" wire:click="act('prepare')">Anfrage prüfen und vorbereiten</x-ui.buttons.button-basic>@endif
                        @if(!$personal && $selected->status==='prepared')<x-ui.buttons.button-basic type="button" wire:click="exportPartner">Geprüfte Anfrage exportieren</x-ui.buttons.button-basic>@endif
                    @elseif($tab === 'imports')
                        <details><summary class="ops-link">Originaleingang</summary><pre class="whitespace-pre-wrap break-words text-sm">{{ $selected->payload['source'] }}</pre></details>
                        @foreach($selected->payload['warnings'] as $warning)<p class="ops-muted">{{ $warning }}</p>@endforeach
                        @if($selected->status === 'draft')@foreach($form['rows'] ?? [] as $index=>$row)<fieldset class="ops-form" wire:key="import-row-{{ $index }}"><x-operations.field label="Bezeichnung" :model="'form.rows.'.$index.'.title'" /><x-operations.field label="Kunden-ID" :model="'form.rows.'.$index.'.customer_id'" type="number" min="1" /><x-operations.field label="Beginn" :model="'form.rows.'.$index.'.starts_at'" type="datetime-local" /><x-operations.field label="Ende" :model="'form.rows.'.$index.'.ends_at'" type="datetime-local" /><x-operations.field label="Zeitzone" :model="'form.rows.'.$index.'.timezone'" /><x-operations.field label="Tätigkeit" :model="'form.rows.'.$index.'.role_name'" /><x-operations.field label="Personalbedarf" :model="'form.rows.'.$index.'.required_staff'" type="number" min="1" /><x-operations.field label="Einsatzort" :model="'form.rows.'.$index.'.location_name'" /></fieldset>@endforeach @if($selected->payload['duplicate_ids'])<x-ui.forms.checkbox wire:model="duplicatesConfirmed" label="Mögliche Dublette geprüft" />@endif<x-ui.buttons.button-basic type="button" wire:click="act('review')">Bedarf prüfen</x-ui.buttons.button-basic>@elseif($selected->status === 'reviewed')<x-ui.buttons.button-basic type="button" wire:click="act('convert')">Anfragen erstellen</x-ui.buttons.button-basic>@endif
                    @endif
                    @if(($tab === 'proofs' && $selected->status === 'draft' && $selected->user_id === auth()->id()) || ($tab === 'travel' && $selected->status === 'booked' && $selected->user_id === auth()->id()))<form wire:submit="uploadFile" class="ops-form"><x-operations.field label="Beleg (PDF/JPG/PNG)" model="upload" type="file" accept="application/pdf,image/jpeg,image/png" /><div class="self-end"><x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled">Beleg hinzufügen</x-ui.buttons.button-basic></div></form>@endif
                @elseif($tab === 'payroll')
                    <div class="rt-personnel-management rt-personnel-detail ops-stack">
                        <dl class="ops-meta">
                            <div><dt>Mitarbeiter</dt><dd>{{ $selected->user?->name }}</dd></div>
                            <div><dt>Monat</dt><dd>{{ $selected->month }} · {{ $selected->timezone }}</dd></div>
                            <div><dt>Abschlussstand</dt><dd><x-operations.status :value="$payrollExportOnly ? 'closed' : $selected->status" /></dd></div>
                            <div><dt>Zeitmeldungen</dt><dd>{{ count($closingSnapshot['entries'] ?? []) }} · {{ ($closingSnapshot['complete'] ?? false) ? 'vollständig geprüft' : 'Prüfung offen' }}</dd></div>
                        </dl>
                        @foreach($closingSnapshot['issues'] ?? [] as $issue)<p class="ops-errors">Zeit #{{ $issue['entry_id'] }} · {{ implode(' · ', $issue['issues']) }}</p>@endforeach
                        @if(!($closingSnapshot['completeness']['complete'] ?? true))
                            <p class="ops-muted">{{ count($closingSnapshot['completeness']['missing_shift_times'] ?? []) }} fehlende Dienste · {{ count($closingSnapshot['completeness']['missing_training_times'] ?? []) }} fehlende Schulungszeiten · {{ count($closingSnapshot['completeness']['unfinished_times'] ?? []) }} offene Zeiten</p>
                        @endif
                        @if($payrollExportOnly || $selected->status === 'closed')
                            @can('operations.time.export')
                                <div class="ops-actions">
                                    <x-ui.buttons.button-basic mode="primary" wire:click="exportPayroll(false)" wire:loading.attr="disabled">CSV Lohnvorbereitung</x-ui.buttons.button-basic>
                                    <x-ui.buttons.button-basic wire:click="exportPayroll(true)" wire:loading.attr="disabled">CSV Änderungen</x-ui.buttons.button-basic>
                                </div>
                            @endcan
                            @unless($payrollExportOnly)
                                <details class="rt-employee-form__more" wire:key="open-month-{{ $selected->id }}-{{ $selectedRevision }}">
                                    <summary>Monat kontrolliert öffnen</summary>
                                    <form wire:submit="act('reopen')" class="ops-stack">
                                        <x-operations.field label="Vermerk zur Wiedereröffnung" model="note" type="textarea" required minlength="10" maxlength="2000" />
                                        <p class="ops-muted">Mindestens 10 Zeichen. Die abgeschlossene Revision bleibt im Verlauf verfügbar.</p>
                                        <div class="ops-actions"><x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled">Kontrolliert öffnen</x-ui.buttons.button-basic></div>
                                    </form>
                                </details>
                            @endunless
                        @elseif($selected->status === 'prepared')
                            @if(!($closingSnapshot['complete'] ?? false))
                                <p class="ops-muted">Vor dem Abschluss alle offenen Punkte klären und den Monat neu prüfen.</p>
                            @elseif((int) $selected->prepared_by === auth()->id() || (int) $selected->user_id === auth()->id())
                                <p class="ops-muted">Eine zweite berechtigte Person schließt diesen Monat ab.</p>
                            @endif
                            <div class="ops-actions">
                                <x-ui.buttons.button-basic wire:click="act('prepare')" wire:loading.attr="disabled">Neu prüfen</x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic mode="primary" wire:click="act('close')" wire:loading.attr="disabled"
                                    :disabled="!($closingSnapshot['complete'] ?? false) || (int) $selected->prepared_by === auth()->id() || (int) $selected->user_id === auth()->id()">Vier-Augen-Abschluss</x-ui.buttons.button-basic>
                            </div>
                        @else
                            @if($correctionTimes->isNotEmpty())
                                <h3>Korrekturen</h3>
                                <x-operations.field label="Korrekturgrund für die ausgewählte Meldung" model="note" type="textarea" minlength="10" maxlength="2000" />
                                @foreach($correctionTimes as $time)
                                    <div class="rt-personnel-section-heading" wire:key="payroll-correction-{{ $time->id }}">
                                        <div><strong>{{ $time->plan_snapshot['title'] ?? $time->contextLabel() }}</strong><p>{{ $time->starts_at->format('d.m.Y') }} · Zeit #{{ $time->id }} · Revision {{ $time->revision }}</p></div>
                                        <x-ui.buttons.button-basic wire:click="returnTime({{ $time->id }},{{ $time->revision }})" wire:loading.attr="disabled">Zur Korrektur zurückgeben</x-ui.buttons.button-basic>
                                    </div>
                                @endforeach
                            @endif
                            <div><x-ui.buttons.button-basic mode="primary" wire:click="act('prepare')" wire:loading.attr="disabled">Erneut vorbereiten</x-ui.buttons.button-basic></div>
                        @endif
                        @if($closedVersions->isNotEmpty() && auth()->user()->can('operations.time.export'))
                            <details><summary>Abschlussversionen</summary><div class="ops-actions">
                                @foreach($closedVersions as $version)<x-ui.buttons.button-basic wire:click="exportPayroll(false,{{ $version->revision }})" wire:loading.attr="disabled">CSV Revision {{ $version->revision }}</x-ui.buttons.button-basic>@endforeach
                            </div></details>
                        @endif
                    </div>
                @elseif($tab === 'rules')
                    @php
                        $configLabels = ['type'=>'Prüfung','days'=>'Prüfzeitraum (Tage)','limit'=>'Obergrenze','activity'=>'Abschnitt','timezone'=>'Zeitzone','wage_code'=>'Lohnart','multiplier_bps'=>'Bewertung (Basispunkte)','rounding_minutes'=>'Rundung (min)','rounding_mode'=>'Rundungsart','window_start'=>'Zeitfenster ab','window_end'=>'Zeitfenster bis','weekdays'=>'Wochentage','additive'=>'Zusätzliche Zuschlagsmenge'];
                        $configValues = ['type'=>['rolling_minutes'=>'Minuten im Prüfzeitraum','consecutive_days'=>'Diensttage in Folge','night_count'=>'Nachtdienste im Prüfzeitraum'], 'rounding_mode'=>['none'=>'Keine','nearest'=>'Kaufmännisch','up'=>'Aufrunden','down'=>'Abrunden'], 'activity'=>['all'=>'Gesamter Dienst'] + \App\Services\Operations\WorkTimeActivityService::KINDS];
                        $weekdayLabels = [1=>'Mo', 2=>'Di', 3=>'Mi', 4=>'Do', 5=>'Fr', 6=>'Sa', 7=>'So'];
                    @endphp
                    <dl class="ops-meta">
                        <div><dt>Regelart</dt><dd>{{ $selected->kind === 'planning' ? 'Planungsgrenze' : 'Vergütungsbewertung' }}</dd></div>
                        <div><dt>Gültigkeit</dt><dd>{{ $selected->starts_on->format('d.m.Y') }} – {{ $selected->ends_on?->format('d.m.Y') ?? 'offen' }}</dd></div>
                        @foreach($selected->configuration as $key => $value)
                            <div><dt>{{ $configLabels[$key] ?? 'Regelwert' }}</dt><dd>@if($key === 'weekdays' && is_array($value)){{ $value ? implode(' · ', array_map(fn ($day) => $weekdayLabels[$day] ?? $day, $value)) : 'Alle Wochentage' }}@elseif(is_bool($value)){{ $value ? 'Ja' : 'Nein' }}@elseif(is_scalar($value)){{ $configValues[$key][$value] ?? $value }}@else{{ is_array($value) ? implode(', ', $value) : 'Offen' }}@endif</dd></div>
                        @endforeach
                    </dl>
                @elseif($tab === 'costs')<dl class="ops-meta"><div><dt>Stundenkosten</dt><dd>{{ number_format($selected->hourly_cents/100,2,',','.') }} €</dd></div><div><dt>Gültig ab</dt><dd>{{ $selected->starts_on->format('d.m.Y') }}</dd></div></dl>
                @elseif($tab === 'terminal')
                    <dl class="ops-meta">
                        @unless($personal)<div><dt>Mitarbeiter</dt><dd>{{ $selected->user?->name ?? 'Mitarbeiter #'.$selected->user_id }}</dd></div>@endunless
                        <div><dt>Terminal-ID</dt><dd class="break-all">{{ $selected->terminal_id }}</dd></div>
                        <div><dt>Standortprüfung</dt><dd>{{ $selected->location_consent ? 'Freigegeben' : 'Aus' }}</dd></div>
                    </dl>
                    <div class="ops-actions"><x-ui.buttons.button-basic type="button" wire:click="$set('detailOpen', false)">Schließen</x-ui.buttons.button-basic></div>
                @endif
            @endif
        </x-operations.modal>
    @endif
</div>
