<div class="rt-disposition rt-disposition--inquiries">
    <section class="rt-disposition-summary" aria-label="Übersicht aller offenen Anfragen">
        @foreach([
            ['value' => $summary['active'], 'label' => 'Offene Vorgänge', 'detail' => 'im gesamten Eingang', 'tone' => 'neutral'],
            ['value' => $summary['new'], 'label' => 'Neue Anfragen', 'detail' => 'noch nicht geprüft', 'tone' => 'info'],
            ['value' => $summary['accepted'], 'label' => 'Zusage erhalten', 'detail' => 'bereit zur Übernahme', 'tone' => 'success'],
            ['value' => $summary['offered'], 'label' => 'Angebot offen', 'detail' => 'Kundenzusage ausstehend', 'tone' => 'warning'],
        ] as $metric)
            <div class="rt-disposition-summary__item" data-tone="{{ $metric['tone'] }}">
                <span class="rt-disposition-summary__value">{{ $metric['value'] }}</span>
                <div><span class="rt-disposition-summary__label">{{ $metric['label'] }}</span><span class="rt-disposition-summary__detail">{{ $metric['detail'] }}</span></div>
            </div>
        @endforeach
    </section>

    <x-operations.feedback />

    <div class="rt-disposition-workgrid">
        <section class="rt-disposition-panel" aria-labelledby="inquiries-list-heading">
            <div class="rt-disposition-section-heading">
                <h2 id="inquiries-list-heading">Posteingang <span class="rt-disposition-count">{{ $inquiries->total() }}</span></h2>
                <span>Vorgänge prüfen und weiterplanen</span>
            </div>
            <x-tables.toolbar title="Anfragen filtern" id="inquiry-filters" :search-in-header="true">
                <x-slot:search><x-tables.search-field context="page" wire:model.live.debounce.300ms="search" placeholder="Anfrage oder Kunde suchen" /></x-slot:search>
                <x-slot:bulk>
                    <div class="rt-disposition-segments" role="group" aria-label="Anfragestatus" data-tables-bulk>
                        @foreach(['all' => 'Alle Status', 'new' => 'Neu', 'accepted' => 'Zugesagt'] as $status => $label)
                            <button type="button" wire:click="$set('statusFilter', '{{ $status }}')" aria-pressed="{{ $statusFilter === $status ? 'true' : 'false' }}" wire:loading.attr="disabled" wire:target="statusFilter">{{ $label }}</button>
                        @endforeach
                    </div>
                </x-slot:bulk>
                <x-tables.filter-field label="Vorgänge" for="inquiry-status-filter">
                    <x-ui.forms.select id="inquiry-status-filter" wire:model.live="filter" aria-label="Anfragen filtern">
                        <option value="active" data-icon-class="far fa-inbox">Offene Vorgänge</option>
                        <option value="all" data-icon-class="far fa-layer-group">Alle Vorgänge</option>
                        <option value="email" data-icon-class="far fa-envelope">E-Mail</option>
                        <option value="phone" data-icon-class="far fa-phone">Telefon</option>
                        <option value="portal" data-icon-class="far fa-globe">Portal</option>
                        <option value="manual" data-icon-class="far fa-pen-to-square">Manuell</option>
                    </x-ui.forms.select>
                </x-tables.filter-field>
            </x-tables.toolbar>
            <div wire:loading.class="opacity-60" wire:target="tableSort,search,filter,statusFilter,gotoPage,nextPage,previousPage">
                <x-tables.table
                    :columns="[['label'=>'Vorgang','key'=>'title','width'=>'minmax(0,1.8fr)','sortable'=>true],['label'=>'Kunde','key'=>'customer','width'=>'minmax(0,1.15fr)','sortable'=>true],['label'=>'Einsatz','key'=>'schedule','width'=>'minmax(0,1.05fr)','sortable'=>true],['label'=>'Status','key'=>'status','width'=>'minmax(0,.8fr)','sortable'=>true]]"
                    sort-action="tableSort" :sort-by="$sortBy" :sort-dir="$sortDir" table-key="inquiries"
                    :items="$inquiries"
                    detail-action="select"
                    row-view="components.tables.rows.operations.inquiries-row"
                    actions-view="components.tables.rows.operations.inquiries-actions"
                    empty="Keine Anfragen für diese Auswahl gefunden."
                    :flush-top="true"
                />
            </div>
            <div class="rt-disposition-pagination">{{ $inquiries->links() }}</div>
        </section>

        <aside class="rt-disposition-workgrid__aside" aria-labelledby="inquiries-next-heading">
            <div class="rt-disposition-section-heading"><h2 id="inquiries-next-heading">Als Nächstes</h2><i class="far fa-clock" aria-hidden="true"></i></div>
            @forelse($nextInquiries as $inquiry)
                @php
                    $nextStep = match($inquiry->status) {
                        'accepted' => 'Auftrag anlegen',
                        'offered' => 'Kundenzusage dokumentieren',
                        'verified' => 'Angebot festhalten',
                        default => 'Bedarf prüfen',
                    };
                @endphp
                <button type="button" class="rt-disposition-context-item" wire:key="inquiry-next-{{ $inquiry->id }}" wire:click="select({{ $inquiry->id }})" wire:loading.attr="disabled" wire:target="select" aria-label="{{ $nextStep }}: {{ $inquiry->title }}">
                    <span class="rt-disposition-context-item__label">{{ \App\Support\Operations\OperationsNavigation::status($inquiry->status) }}</span>
                    <strong class="rt-disposition-context-item__title">{{ $nextStep }}</strong>
                    <span class="rt-disposition-context-item__meta">{{ $inquiry->title }}</span>
                    <span class="rt-disposition-context-item__meta">{{ $inquiry->customer?->company_name ?? 'Kunde noch zuordnen' }}@if($inquiry->starts_at) · {{ $inquiry->starts_at->format('d.m. H:i') }}@endif</span>
                    <span class="rt-disposition-context-item__footer"><span>{{ $inquiry->number }}</span><i class="far fa-arrow-right" aria-hidden="true"></i></span>
                </button>
            @empty
                <p class="rt-disposition-note">Aktuell stehen keine offenen Anfragen zur Bearbeitung an.</p>
            @endforelse
            <p class="rt-disposition-note"><strong>Vom Bedarf zum Auftrag.</strong> Angebot, Kundenzusage und Beauftragung sind eigene Schritte. Zugesagte Vorgänge stehen hier zuerst.</p>
        </aside>
    </div>

<x-operations.modal wire:model="detailOpen" :title="$editing ? ($selectedId ? 'Bedarf bearbeiten' : 'Neue Anfrage') : 'Anfrage'" max-width="4xl" :variant="$editing ? 'modal' : 'drawer'">
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
                @if(!$selected->duplicate_of_id)
                    @php
                        $workflowSteps = ['new' => 'Neu', 'verified' => 'Geprüft', 'offered' => 'Angebot', 'accepted' => 'Zugesagt', 'converted' => 'Beauftragt'];
                        $currentStep = array_search($selected->status, array_keys($workflowSteps), true);
                    @endphp
                    <ol class="rt-disposition-workflow-steps" aria-label="Bearbeitungsstand">
                        @foreach($workflowSteps as $status => $label)
                            <li class="rt-disposition-workflow-step" data-state="{{ $selected->status === $status ? 'current' : ($currentStep !== false && $loop->index < $currentStep ? 'done' : 'upcoming') }}" @if($selected->status === $status) aria-current="step" @endif>{{ $label }}</li>
                        @endforeach
                    </ol>
                @endif
                <dl class="ops-meta"><div><dt>Kunde</dt><dd>{{ $selected->customer?->company_name ?? 'Noch nicht zugeordnet' }}</dd></div><div><dt>Kontakt</dt><dd>{{ $selected->contact_name ?: '—' }}</dd></div><div><dt>Beginn</dt><dd>{{ $selected->starts_at?->format('d.m.Y H:i') ?? '—' }}</dd></div><div><dt>Ende · {{ $selected->timezone }}</dt><dd>{{ $selected->ends_at?->format('d.m.Y H:i') ?? '—' }}</dd></div><div><dt>Einsatzort</dt><dd>{{ $selected->location_name ?: '—' }}</dd></div><div><dt>Bedarf</dt><dd>{{ $selected->required_staff ?? '—' }} × {{ $selected->role_name ?: '—' }}</dd></div></dl>
                <details class="rt-disposition-detail-section"><summary>Originaleingang</summary><p class="ops-muted">{{ ['email' => 'E-Mail', 'phone' => 'Telefon', 'portal' => 'Portal', 'manual' => 'Manuell'][$selected->channel] ?? $selected->channel }}@if($selected->source_reference) · {{ $selected->source_reference }}@endif</p><div class="ops-original">{{ $selected->original }}</div></details>
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
                <details class="rt-disposition-detail-section"><summary>Verlauf</summary>@forelse($history as $event)<div class="ops-row" wire:key="inquiry-event-{{ $event->id }}"><span>{{ \App\Support\Operations\OperationsNavigation::auditLabel($event->action) }}</span><div class="min-w-0 space-y-1">@if($event->actor)<x-user.public-info :user="$event->actor" :size="6" :show-presence="false" />@endif<span class="ops-muted">{{ $event->created_at->setTimezone(config('operations.display_timezone'))->format('d.m. H:i') }}</span></div></div>@empty<p class="ops-muted">Noch keine Einträge.</p>@endforelse</details>
            @else<div class="ops-empty">Anfrage auswählen.</div>@endif

</x-operations.modal>
</div>
