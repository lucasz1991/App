@section('title', $definition['label'])
<x-ui.page :title="$definition['label']" :auto-intro="false" :title-in-topbar="$area === 'work'" :class="$area === 'work' ? 'rt-personal-workspace-page rt-workday-page' : 'rt-personal-workspace-page'" content-class="rt-personal-workspace-content">
    @switch($definition['view'])
        @case('my-work')<livewire:operations.my-work :focus="(string) $definition['tab']" :initial-tab="$initialTab" :initial-request="$initialRequest" :key="'my-work-'.$area" />@break
        @case('capacity')<livewire:operations.customer-capacity-consent />@break
        @case('planning')<livewire:operations.workforce-planning :personal="true" :tab="request()->query('planning_tab') === 'offers' ? 'offers' : 'wishes'" />@break
        @case('account')<livewire:operations.workforce-accounts :personal="true" />@break
        @case('personnel')<livewire:operations.personnel-enhancements :personal="true" :embedded="true" :tab="$definition['tab']" :key="'personnel-'.$area" />@break
        @case('operations')<livewire:operations.operations-enhancements :personal="true" :embedded="true" :tab="$definition['tab']" :key="'operations-'.$area" />@break
    @endswitch
</x-ui.page>
