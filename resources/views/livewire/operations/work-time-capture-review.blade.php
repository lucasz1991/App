<section class="rt-personnel-management rt-capture-review ops-stack" aria-label="Erfassungen zur Prüfung">
    @if($records->total())
        <header class="rt-personnel-section-heading"><div><h3>Erfassungen zur Prüfung</h3><p>Erfassungen mit offenen Konflikten.</p></div><span class="rt-personnel-count">{{ $records->total() }}</span></header>
        <x-tables.table class="rt-personnel-table" label="Erfassungskonflikte" :columns="[['label'=>'Eingang','key'=>'received_at'],['label'=>'Erfasst','key'=>'occurred_at'],['label'=>'Konflikt','key'=>'reason']]" :items="$records" detail-action="open" row-view="components.tables.rows.operations.record" />{{ $records->links() }}
    @elseif(!$personal)
        <div class="rt-personnel-empty rt-capture-review__empty"><strong>Keine Erfassungskonflikte</strong><p>Aktuell liegen keine Erfassungen zur Prüfung vor.</p></div>
    @endif
    <x-operations.modal wire:model="detailOpen" title="Erfassung prüfen">
        <div class="rt-personnel-management rt-personnel-detail ops-stack">
        @if($selected)
            <dl class="ops-meta"><div><dt>Aktion</dt><dd>{{ $payload['action'] ?? '—' }}</dd></div><div><dt>Tätigkeit</dt><dd>{{ $payload['title'] ?? '—' }}</dd></div><div><dt>Erfasst</dt><dd>{{ $selected->occurred_at->setTimezone($payload['timezone'])->format('d.m.Y H:i:s P') }}</dd></div><div><dt>Eingang</dt><dd>{{ $selected->received_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i:s') }}</dd></div></dl>
            <p class="ops-errors">{{ $selected->reason }}</p>
            @unless($personal)<x-operations.field label="Prüfvermerk" model="note" minlength="5" required /><x-ui.buttons.button-basic wire:click="reviewed" wire:confirm="Prüfung abschließen? Arbeitszeiten werden dabei nicht geändert." wire:loading.attr="disabled">Als geprüft abschließen</x-ui.buttons.button-basic>@endunless
        @endif
        </div>
    </x-operations.modal>
</section>
