<section class="rt-personnel-management rt-capture-review ops-stack" aria-label="Erfassungen zur Prüfung"
    @if($personal) id="personal-time-capture-conflicts" tabindex="-1" x-data="{ requested: false }"
        x-on:personal-time-conflicts-opened.window="requested = true; if (!$event.detail?.eventKey || !(await $wire.openPersonalEvent($event.detail.eventKey))) $nextTick(() => $el.focus())"
    @endif>
    @if($records->total())
        <header class="rt-personnel-section-heading">
            <div><h3>Erfassungen zur Prüfung</h3><p>{{ $personal ? 'Die zuständige Zeitprüfung klärt diese Erfassungen. Öffne einen Eintrag für die Details.' : 'Ereigniszeit, Eingang und Grund des Konflikts vergleichen.' }}</p></div>
            <span class="rt-personnel-count">{{ $records->total() }}</span>
        </header>
        <x-tables.table class="rt-personnel-table" label="Erfassungskonflikte"
            :columns="[['label'=>'Erfasst','key'=>'occurred_at'],['label'=>'Eingang','key'=>'received_at'],['label'=>'Konflikt','key'=>'reason']]"
            :items="$records" detail-action="open" :detail-on-click="true" row-view="components.tables.rows.operations.record" />
        {{ $records->links() }}
    @else
        <div class="rt-personnel-empty rt-capture-review__empty" @if($personal) x-show="requested" x-cloak @endif>
            <strong>Keine Erfassungskonflikte</strong><p>Aktuell liegen keine Erfassungen zur Prüfung vor.</p>
        </div>
    @endif
    <x-operations.modal wire:model="detailOpen" :title="$confirmReview ? 'Konfliktprüfung abschließen' : 'Erfassung prüfen'">
        @if($selected)
            @php
                $action = ['start'=>'Beginn','pause'=>'Pause','resume'=>'Fortsetzen','stop'=>'Ende','submit'=>'Einreichen','activity'=>'Tätigkeitswechsel'][$payload['action'] ?? ''] ?? 'Erfassung';
                $canReview = !$personal && (int) $selected->device->user_id !== auth()->id();
            @endphp
            <div class="rt-personnel-management rt-personnel-detail ops-stack">
                <dl class="ops-meta">
                    <div><dt>Aktion</dt><dd>{{ $action }}</dd></div>
                    <div><dt>Tätigkeit</dt><dd>{{ $payload['title'] ?? 'Keine Angabe' }}</dd></div>
                    <div><dt>Erfasst</dt><dd>{{ $selected->occurred_at->setTimezone($payload['timezone'] ?? config('operations.display_timezone'))->format('d.m.Y H:i:s P') }}</dd></div>
                    <div><dt>Eingang</dt><dd>{{ $selected->received_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i:s') }}</dd></div>
                </dl>
                <p class="ops-errors">{{ $selected->reason }}</p>
                @if($canReview && $confirmReview)
                    <div><h3>Prüfvermerk</h3><p class="break-words">{{ $note }}</p></div>
                    <p>Der Vorgang wird als geprüft abgeschlossen. Die erfasste Arbeitszeit bleibt unverändert.</p>
                    <div class="ops-actions">
                        <x-ui.buttons.button-basic type="button" wire:click="$set('confirmReview', false)">Zurück</x-ui.buttons.button-basic>
                        <x-ui.buttons.button-basic mode="primary" wire:click="reviewed" wire:loading.attr="disabled">Prüfung abschließen</x-ui.buttons.button-basic>
                    </div>
                @elseif($canReview)
                    <x-operations.field label="Prüfvermerk" model="note" type="textarea" minlength="5" maxlength="1000" required />
                    <x-ui.buttons.button-basic mode="primary" wire:click="prepareReview" wire:loading.attr="disabled">Als geprüft abschließen</x-ui.buttons.button-basic>
                @else
                    <p class="ops-muted">Den Prüfvermerk erfasst eine zuständige andere Person in der Zeitprüfung.</p>
                @endif
            </div>
        @endif
    </x-operations.modal>
</section>
