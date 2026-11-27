<section class="rt-ops ops-stack min-w-0" aria-label="Planungswerkzeuge">
    @php
        $tabs=['capacity'=>'Kapazität','bundles'=>'Qualifikationsbündel','fairness'=>'Verteilung','rotations'=>'Rotationen','teams'=>'Teams','chains'=>'Dienstketten','positions'=>'Stellenplanung','optimizer'=>'Planvorschlag'];
        $columns=match($tab){
            'bundles'=>[['label'=>'Bündel','key'=>'bundle'],['label'=>'Anforderungen','key'=>'requirements'],['label'=>'Status','key'=>'status'],['label'=>'Aktionen','key'=>'actions']],
            'teams'=>[['label'=>'Team','key'=>'name'],['label'=>'Mitglieder','key'=>'members'],['label'=>'Aktionen','key'=>'actions']],
            'rotations'=>[['label'=>'Rotation','key'=>'name'],['label'=>'Zyklus','key'=>'cycle'],['label'=>'Aktionen','key'=>'actions']],
            'chains'=>[['label'=>'Dienstkette','key'=>'chain'],['label'=>'Übergabe','key'=>'handover'],['label'=>'Ressourcen','key'=>'resources']],
            'fairness'=>[['label'=>'Mitarbeiter','key'=>'name'],['label'=>'Nächte','key'=>'nights'],['label'=>'Wochenenden','key'=>'weekends'],['label'=>'Gewählte Tätigkeiten','key'=>'unfavourable'],['label'=>'Planstunden','key'=>'planned_hours'],['label'=>'Wochensoll','key'=>'weekly_target']],
            'positions'=>[['label'=>'Stellenplan','key'=>'name'],['label'=>'Ziel-FTE','key'=>'target_fte'],['label'=>'Bekannte FTE','key'=>'known_fte'],['label'=>'Unbekannt','key'=>'unknown'],['label'=>'Fehlende FTE','key'=>'gap_fte'],['label'=>'Freigabe','key'=>'actions']],
            default=>[['label'=>'Dienst','key'=>'name'],['label'=>'Tätigkeit / Ort','key'=>'context'],['label'=>'Beginn','key'=>'period'],['label'=>'Bedarf','key'=>'required'],['label'=>'Reserviert','key'=>'reserved'],['label'=>'Bestätigt','key'=>'confirmed'],['label'=>'Geeignet frei','key'=>'eligible'],['label'=>'Unterdeckung','key'=>'missing'],['label'=>'Nachweisrisiko','key'=>'proof_risks']]
        };
    @endphp
    <header class="ops-toolbar">@if(!$embedded)<x-ui.buttons.multi-toggle :id="'planning-enhancements-area-'.$this->getId()" label="Planungsbereiche" :show-labels="true" :value="$tab" action="setTab"
            :options="collect($tabs)->map(fn ($label, $key) => ['value' => $key, 'label' => $label, 'icon' => ['capacity' => 'fa-chart-bar', 'bundles' => 'fa-layer-group', 'fairness' => 'fa-balance-scale', 'rotations' => 'fa-sync-alt', 'teams' => 'fa-users', 'chains' => 'fa-link', 'positions' => 'fa-sitemap', 'optimizer' => 'fa-magic'][$key] ?? 'fa-circle'])->values()->all()" />@endif
        @if(in_array($tab,['bundles','teams','rotations','chains','positions']))<x-ui.buttons.button-basic type="button" wire:click="edit('{{ ['bundles'=>'bundle','teams'=>'team','rotations'=>'rotation','chains'=>'chain','positions'=>'position'][$tab] }}')"><i class="far fa-plus" aria-hidden="true"></i>{{ ['bundles'=>'Bündel','teams'=>'Team','rotations'=>'Rotation','chains'=>'Dienstkette','positions'=>'Stellenplan'][$tab] }} anlegen</x-ui.buttons.button-basic>@endif
    </header>
    <x-operations.feedback />
    @if(in_array($tab,['capacity','fairness','positions','optimizer']))
        <div class="ops-form"><x-operations.field label="Von" model="from" type="date" /><x-operations.field label="Bis" model="until" type="date" />
            @if($tab==='capacity')<x-operations.field label="Tätigkeit" model="filters.role" /><x-operations.field label="Standort" model="filters.location" /><x-operations.field label="Pool" model="filters.pool_id" type="select"><option value="">Alle</option>@foreach($pools as $pool)<option value="{{ $pool->id }}">{{ $pool->name }}</option>@endforeach</x-operations.field>@endif
            @if(in_array($tab,['fairness','optimizer']))<x-operations.field label="Nachtfenster von" model="goals.night_start" type="time" /><x-operations.field label="Nachtfenster bis" model="goals.night_end" type="time" />@endif
            @if($tab==='fairness')<fieldset class="ops-full ops-actions"><legend class="text-sm font-semibold">Tätigkeiten vergleichen</legend>@foreach($shifts->pluck('role_name')->unique()->sort() as $role)<x-ui.forms.checkbox wire:model="unfavourableRoles" :value="$role" :label="$role" />@endforeach</fieldset>@endif
            <div class="ops-actions"><x-ui.buttons.button-basic type="button" wire:click="$refresh" wire:loading.attr="disabled">Aktualisieren</x-ui.buttons.button-basic></div>
        </div>
    @endif
    @if($tab==='optimizer')
        <div class="ops-form">@foreach(['wish_weight'=>'Wünsche','load_weight'=>'Planstunden','night_weight'=>'Nächte','weekend_weight'=>'Wochenenden'] as $key=>$label)<x-operations.field :label="'Gewicht: '.$label" :model="'goals.'.$key" type="number" min="0" max="100" />@endforeach<div class="ops-full ops-actions"><x-ui.buttons.button-basic type="button" wire:click="prepare" wire:loading.attr="disabled">Vorschlag berechnen</x-ui.buttons.button-basic>@if(isset($preview['fingerprint']))<x-ui.buttons.button-basic type="button" mode="primary" wire:click="applyPreview" wire:loading.attr="disabled">Als Planvariante speichern</x-ui.buttons.button-basic><span class="ops-muted tabular-nums">{{ $preview['open'] }} offene Plätze</span>@endif</div></div>
        @if(isset($preview['rows']))<x-tables.table :columns="[['label'=>'Dienst','key'=>'name'],['label'=>'Beginn','key'=>'period'],['label'=>'Vorher','key'=>'before'],['label'=>'Vorschlag','key'=>'after'],['label'=>'Offen','key'=>'open'],['label'=>'Begründung','key'=>'proposals']]" :items="collect($preview['rows'])->map(fn($r)=>(object)$r)" row-view="components.tables.rows.planning.enhancement-row" />@endif
        <x-tables.table :columns="[['label'=>'Variante','key'=>'name'],['label'=>'Status','key'=>'status'],['label'=>'Aktionen','key'=>'variant_actions']]" :items="$variants" row-view="components.tables.rows.planning.enhancement-row" empty="Keine Planvarianten." />
    @else
        <x-tables.table :columns="$columns" :items="$items" row-view="components.tables.rows.planning.enhancement-row" empty="Keine Einträge im Zeitraum." />
    @endif
    @if($tab==='capacity')
        <x-ui.section-header title="Auftragsbedarf" size="base" /><x-tables.table :columns="[['label'=>'Auftrag / Tätigkeit','key'=>'name'],['label'=>'Bedarf','key'=>'required'],['label'=>'Geplant','key'=>'planned'],['label'=>'Reserviert','key'=>'reserved'],['label'=>'Bestätigt','key'=>'confirmed'],['label'=>'Offen','key'=>'missing']]" :items="collect($matrix['demands'])->map(fn($r)=>(object)$r)" row-view="components.tables.rows.planning.enhancement-row" empty="Keine offenen Bedarfe." />
        <x-ui.section-header title="Wochenkapazität" size="base" /><x-tables.table :columns="[['label'=>'Woche','key'=>'name'],['label'=>'Modelle','key'=>'known'],['label'=>'Unbekannt','key'=>'unknown'],['label'=>'Modellstunden','key'=>'target_hours'],['label'=>'Dienstplanung','key'=>'planned_hours'],['label'=>'Schulung','key'=>'training_hours'],['label'=>'Modellrest','key'=>'remaining_hours']]" :items="collect($matrix['weeks'])->map(fn($r)=>(object)$r)" row-view="components.tables.rows.planning.enhancement-row" />
        @if($matrix['limited'])<p class="ops-muted">Erste 500 Dienste. Zeitraum oder Filter eingrenzen.</p>@endif
    @endif
    <div class="hidden"><x-operations.modal wire:model="formOpen" :title="match($modal){'bundle'=>'Bündelversion','attach'=>'Anforderung zuordnen','team'=>'Team','bulk'=>'Team einteilen','rotation'=>'Rotation','rotation-preview'=>'Rotationsvorschau','chain'=>'Dienstkette','position'=>'Stellenplan',default=>'Planvariante prüfen'}" max-width="4xl">
        @if(in_array($modal,['bulk','rotation-preview','variant','bundle-coverage']))
            @include('livewire.operations.partials.planning-enhancements.preview-dialog')
        @else
            <form wire:submit="save" class="ops-form">
                @include('livewire.operations.partials.planning-enhancements.form-fields')
                <div class="ops-full ops-actions"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">{{ $modal==='bundle' && $recordId?'Neue Version erstellen':'Speichern' }}</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="$set('formOpen',false)">Abbrechen</x-ui.buttons.button-basic></div>
            </form>
        @endif
    </x-operations.modal></div>
</section>
