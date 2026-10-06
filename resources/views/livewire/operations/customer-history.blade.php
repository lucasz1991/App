<div class="rt-ops ops-stack" data-customer-history>
    <x-operations.surface>
        <div class="ops-toolbar"><h3 class="mr-auto text-sm font-semibold">Kundenverlauf</h3><div class="w-full sm:w-64"><x-ui.forms.select id="customer-history-source" wire:model.live="source" aria-label="Verlaufsbereich">@foreach($sources as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-ui.forms.select></div></div>
        <x-tables.table label="Kundenaktivitäten" table-key="customer-history" :flush-top="true" :columns="[['label'=>'Aktivität','key'=>'label','width'=>'50%'],['label'=>'Bereich','key'=>'source_label','width'=>'25%'],['label'=>'Zeitpunkt','key'=>'occurred_at','width'=>'25%']]" :items="$events" row-view="components.tables.rows.customers.history-row" actions-view="components.tables.rows.customers.history-actions" empty="Noch keine Aktivitäten hinterlegt." />
        <div class="py-4">{{ $events->links() }}</div>
    </x-operations.surface>
</div>
