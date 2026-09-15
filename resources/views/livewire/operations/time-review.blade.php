<div class="ops-stack">
    <div class="ops-toolbar"><div class="ops-actions"><div class="ops-field"><input type="search" aria-label="Mitarbeiter suchen" placeholder="Mitarbeiter suchen" wire:model.live.debounce.300ms="search"></div>@if(!$exports)<div class="ops-field"><select wire:model.live="filter" aria-label="Zeitstatus"><option value="submitted">Zur Prüfung</option><option value="returned">Zurückgegeben</option><option value="approved">Freigegeben</option><option value="all">Alle</option></select></div>@endif</div>
        @if($exports)<x-ui.buttons.button-basic mode="primary" wire:click="export" wire:loading.attr="disabled">Auswahl exportieren</x-ui.buttons.button-basic>@endif
    </div>
    <x-operations.feedback />
    <section aria-label="Zeitmeldungen">
        @forelse($entries as $entry)
            @php($warnings = app(\App\Services\Operations\WorkTimeService::class)->warnings($entry))
            <article class="ops-panel ops-stack" wire:key="review-time-{{ $entry->id }}">
                <header class="ops-toolbar"><div class="ops-actions">@if($exports)<input type="checkbox" wire:model="selected" value="{{ $entry->id }}" aria-label="Zeitmeldung {{ $entry->id }} auswählen">@endif<div><p class="ops-kicker">{{ $entry->user->name }} · Revision {{ $entry->revision }}</p><h2>{{ $entry->plan_snapshot['title'] }}</h2></div></div><x-operations.status :value="$entry->status" /></header>
                <dl class="ops-meta"><div><dt>Plan</dt><dd>{{ \Carbon\CarbonImmutable::parse($entry->plan_snapshot['starts_at'])->format('d.m. H:i') }} – {{ \Carbon\CarbonImmutable::parse($entry->plan_snapshot['ends_at'])->format('d.m. H:i') }}</dd></div><div><dt>Ist · {{ $entry->timezone }}</dt><dd>{{ $entry->starts_at->format('d.m. H:i') }} – {{ $entry->ends_at?->format('d.m. H:i') ?? 'Läuft' }}</dd></div><div><dt>Pause</dt><dd>{{ intdiv($entry->pause_seconds,60) }} min</dd></div><div><dt>Netto</dt><dd>{{ \App\Support\Operations\OperationsDateTime::duration($entry->netSeconds()) }}</dd></div></dl>
                @if($warnings)<div class="ops-actions">@foreach($warnings as $warning)<span class="ops-badge" data-state="pending">{{ $warning }}</span>@endforeach</div>@endif
                @if($entry->note)<p>{{ $entry->note }}</p>@endif
                @if($entry->review_note)<p class="ops-muted">{{ $entry->review_note }}</p>@endif
                @if(!$exports && $entry->status === 'submitted' && $entry->user_id !== auth()->id())<x-operations.field label="Prüfvermerk" :model="'notes.'.$entry->id" maxlength="1000" /><div class="ops-actions"><x-ui.buttons.button-basic mode="primary" wire:click="decide({{ $entry->id }}, {{ $entry->revision }}, true)" wire:loading.attr="disabled">Freigeben</x-ui.buttons.button-basic><x-ui.buttons.button-basic wire:click="decide({{ $entry->id }}, {{ $entry->revision }}, false)" wire:loading.attr="disabled">Zur Korrektur</x-ui.buttons.button-basic></div>@endif
                <details><summary>Änderungsverlauf</summary>@foreach($entry->revisions()->orderByDesc('id')->get() as $revision)<div class="ops-row"><span>Revision {{ $revision->revision }} · {{ ['submitted'=>'Eingereicht','approved'=>'Freigegeben','returned'=>'Zurückgegeben','before_correction'=>'Vor Korrektur','corrected'=>'Korrigiert'][$revision->action] ?? 'Gespeichert' }}</span><span class="ops-muted">{{ $revision->created_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i') }} · {{ \App\Support\Operations\OperationsDateTime::duration($revision->snapshot['net_seconds']) }}</span></div>@endforeach</details>
            </article>
        @empty<div class="ops-panel ops-empty">Keine Zeitmeldungen in dieser Ansicht.</div>@endforelse
        {{ $entries->links() }}
    </section>
    @if($exports)<details class="ops-panel"><summary>Bisherige Exporte</summary>@forelse($history as $export)<div class="ops-row"><span>{{ $export->created_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i') }} · Export {{ $export->id }}</span><x-ui.buttons.button-basic wire:click="download({{ $export->id }})">Erneut herunterladen</x-ui.buttons.button-basic></div>@empty<p class="ops-muted">Noch keine Exporte.</p>@endforelse</details>@endif
</div>
