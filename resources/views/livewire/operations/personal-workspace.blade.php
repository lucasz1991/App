@section('title', 'Mein Arbeitstag')
<x-ui.page title="Mein Arbeitstag" :auto-intro="false" class="rt-personal-workspace-page" content-class="rt-personal-workspace-content">
    <div class="rt-personal-area-navigation">
        <x-ui.buttons.multi-toggle :options="array_values($this->areas())" :value="$area" action="setArea" label="Mein Arbeitsbereich" id="personal-workspace-area" :show-labels="true" />
    </div>
    @if($area === 'work')<livewire:operations.my-work />
    @elseif($area === 'inbox')<livewire:operations.attention-center :personal="true" />
    @elseif($area === 'capacity')<livewire:operations.customer-capacity-consent />
    @elseif($area === 'personnel')<livewire:operations.personnel-enhancements :personal="true" :tab="$initialTab ?: 'workflows'" />
    @elseif($area === 'operations')<livewire:operations.operations-enhancements :personal="true" :tab="$initialTab ?: 'proofs'" />
    @endif
</x-ui.page>
