<section class="space-y-3" aria-label="Auftragsbedarf">
<header class="ops-toolbar"><h3 class="text-sm font-semibold">Auftragsbedarf</h3><x-ui.buttons.button-basic type="button" wire:click="edit">Bedarf hinzufügen</x-ui.buttons.button-basic></header>
<x-operations.feedback />
<x-tables.table :columns="[['label'=>'Tätigkeit','key'=>'role'],['label'=>'Zeitfenster','key'=>'interval'],['label'=>'Bedarf / Planung','key'=>'coverage'],['label'=>'Aktionen','key'=>'actions']]" :items="$demands" row-view="components.tables.rows.operations.order-demand" empty="Noch kein eigenständiger Bedarf erfasst." />
@if($demands->where('status','active')->isNotEmpty())<x-operations.field label="Pause für neue Entwurfsschicht (min)" model="breakMinutes" type="number" min="0" max="1439" />@endif
<x-operations.modal wire:model="formOpen" title="Auftragsbedarf">
<form wire:submit="save" class="ops-form">
    <x-operations.field label="Tätigkeit / Funktion" model="form.role_name" required /><x-operations.field label="Personalbedarf" model="form.required_staff" type="number" min="1" max="999" required />
    @if($extended)
        <x-operations.field label="Bedarfsart" model="form.staffing_mode" type="select"><option value="minimum">Mindestbedarf</option><option value="range">Mindest- / Höchstbedarf</option><option value="exact">Exakter Bedarf</option></x-operations.field>
        <x-operations.field label="Höchstbedarf (nur Bereich)" model="form.maximum_staff" type="number" min="1" max="999" />
        <x-operations.field label="Planungspool" model="form.workforce_pool_id" type="select"><option value="">Alle passenden Mitarbeiter</option>@foreach($pools as $pool)<option value="{{ $pool->id }}">{{ $pool->name }}</option>@endforeach</x-operations.field>
        <fieldset class="ops-full ops-actions"><legend>Erforderliche Nachweise</legend>@foreach($types as $type)<x-ui.forms.checkbox wire:model="form.qualification_ids" :value="$type->id" :label="$type->name" />@endforeach</fieldset>
    @endif
    <x-operations.field label="Beginn" model="form.starts_at" type="datetime-local" required /><x-operations.field label="Ende" model="form.ends_at" type="datetime-local" required />
    <x-operations.field label="Zeitzone" model="form.timezone" required />
    <div class="ops-full"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">Bedarf speichern</x-ui.buttons.button-basic></div>
</form>
@if($matrix->isNotEmpty())<x-tables.table :columns="[['label'=>'Von','key'=>'from'],['label'=>'Bis','key'=>'until'],['label'=>'Geplant','key'=>'planned'],['label'=>'Reserviert','key'=>'reserved'],['label'=>'Angefragt','key'=>'requested'],['label'=>'Bestätigt','key'=>'confirmed']]" :items="$matrix" row-view="components.tables.rows.operations.record" />@endif
</x-operations.modal>
</section>
