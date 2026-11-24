<section class="ops-stack" aria-label="Tätigkeitenkatalog">
    @if(!$ready)
        <p>Der Tätigkeitenkatalog benötigt die Datenbankaktualisierung.</p>
    @else
        <div class="ops-actions"><x-operations.field label="Tätigkeit suchen" model="search" :live="true" />
            <x-ui.buttons.button-basic type="button" wire:click="edit">Neue Tätigkeit</x-ui.buttons.button-basic></div>
        <ul class="divide-y">@forelse($activities as $activity)
            <li wire:key="activity-{{ $activity->id }}"><button type="button" class="flex min-h-11 w-full items-center gap-4 px-3 text-left" wire:click="edit({{ $activity->id }})"><strong>{{ $activity->code }}</strong><span>{{ $activity->title ?: 'Titel zu klären' }}</span><small class="ml-auto">{{ !$activity->active ? 'Inaktiv' : ($activity->requirements_confirmed ? 'Freigegeben' : 'Anforderungen offen') }}</small></button></li>
        @empty<li>Keine passenden Tätigkeiten.</li>@endforelse</ul>
        <x-operations.modal wire:model.live="open" title="Tätigkeit bearbeiten" variant="drawer">
            @if($open)<form wire:submit="save" class="ops-form p-4">
                <x-operations.field label="Abkürzung" model="form.code" required />
                <x-operations.field label="Ausgeschriebener Titel" model="form.title" />
                <x-operations.field label="Beschreibung" model="form.description" type="textarea" :wide="true" />
                <x-operations.field label="Qualifikationsbündel" model="form.qualification_bundle_id" type="select"><option value="">Kein zusätzliches Bündel</option>@foreach($bundles as $bundle)<option value="{{ $bundle->id }}">{{ $bundle->name }} · Version {{ $bundle->version }}</option>@endforeach</x-operations.field>
                <x-operations.field label="Erforderlicher Dienstnachweis" model="form.proof_type" required />
                <x-ui.forms.checkbox wire:model="form.wagon_list" label="Erfassung mit der Wagenliste zulassen" />
                <x-ui.forms.checkbox wire:model="form.individual_proof" label="Ein Beleg je eingeteilter Person" />
                <x-ui.forms.checkbox wire:model="form.customer_acceptance" label="Kundenabnahme für den Dienstnachweis erforderlich" />
                <x-ui.forms.checkbox wire:model="form.active" label="Tätigkeit aktiv" />
                <x-ui.forms.checkbox wire:model="form.requirements_confirmed" label="Anforderungen für die Schichtbesetzung fachlich freigegeben" />
                <div class="ops-full"><x-ui.buttons.button-basic type="submit" mode="primary">Speichern</x-ui.buttons.button-basic></div>
            </form>@endif
        </x-operations.modal>
    @endif
</section>
