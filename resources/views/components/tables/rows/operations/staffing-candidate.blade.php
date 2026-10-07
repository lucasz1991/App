@foreach($columnsMeta as $column)
<div class="rt-table-cell {{ $column['key'] === 'name' ? 'rt-table-cell--primary' : '' }}" data-rt-table-label="{{ $column['label'] }}">
    @if($column['key'] === 'name')
        <div class="min-w-0">
            <x-user.person-anchor-preview :user="$item" :show-presence="false" :show-email="false" :size="8" />
            <button type="button" class="rt-staffing-region-link" wire:click="editRegionalPreference({{ $item->id }})" aria-label="Einsatzgebiete für {{ $item->name }} bearbeiten"><i class="far fa-map-marker-alt" aria-hidden="true"></i>{{ $item->staffing_region['label'] ?? 'Einsatzgebiete' }}<i class="far fa-pen" aria-hidden="true"></i></button>
        </div>
    @elseif($column['key'] === 'eligibility')
        <div class="rt-staffing-fit" data-fit="{{ $item->staffing_state }}">
            <div class="rt-staffing-fit__heading"><strong>{{ $item->staffing_score }}<small>/100</small></strong><span>{{ $item->staffing_label }}</span></div>
            <span class="rt-staffing-fit__track" aria-hidden="true"><span style="width:{{ (int) $item->staffing_score }}%"></span></span>
            <details class="rt-staffing-reasons"><summary>Gründe anzeigen</summary><ul>@foreach($item->staffing_reasons as $reason)<li>{{ $reason }}</li>@endforeach</ul></details>
        </div>
    @else
        <x-ui.buttons.button-basic type="button" class="rt-staffing-choose" wire:click="chooseCandidate({{ $item->id }})" :disabled="$item->staffing_state === 'blocked' ? true : null" wire:loading.attr="disabled" :aria-label="$item->name.($item->staffing_state === 'review' ? ': Zeitkonflikt prüfen' : ' auswählen')">{{ $item->staffing_state === 'review' ? 'Prüfen' : ($item->staffing_state === 'blocked' ? 'Gesperrt' : 'Auswählen') }}</x-ui.buttons.button-basic>
    @endif
</div>
@endforeach
