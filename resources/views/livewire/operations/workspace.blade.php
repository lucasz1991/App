@section('title', $modules[$module]['title'])
<x-ui.page :title="$modules[$module]['title']" :auto-intro="false" content-class="rt-ops ops-stack" :class="in_array($module, ['inquiries', 'orders', 'shift-management', 'calendar'], true) ? 'rt-disposition-page' : ''">
    <x-slot:actions><x-operations.create-action :module="$module" /></x-slot:actions>
    <x-operations.navigation :modules="$modules" :current="$module" />
    @if($module === 'inquiries')<livewire:operations.inquiry-inbox />
    @elseif(in_array($module, ['qualifications', 'absences', 'rules']))<livewire:operations.personnel-review :module="$module" :key="$module" />
    @elseif(in_array($module, ['times', 'exports']))<livewire:operations.time-review :exports="$module === 'exports'" :key="$module" />
    @elseif($module === 'shift-management')<livewire:admin.operations.shift-management />
    @elseif($module === 'orders')<livewire:admin.operations.orders />
    @elseif($module === 'customers')<livewire:admin.operations.customers />
    @elseif($module === 'customer-portal')<livewire:operations.customer-portal-management :tab="$initialTab ?: 'access'" />
    @elseif($module === 'calendar')<livewire:admin.operations.calendar />
    @elseif($module === 'workforce-accounts')<livewire:operations.workforce-accounts />
    @elseif($module === 'personnel-processes')<livewire:operations.personnel-processes />
    @elseif($module === 'workforce-planning')<livewire:operations.workforce-planning />
    @elseif($module === 'plan-variants')<livewire:operations.plan-variants />
    @elseif($module === 'planning-enhancements')<livewire:operations.planning-enhancements :tab="$initialTab ?: 'capacity'" />
    @elseif($module === 'personnel-enhancements')<livewire:operations.personnel-enhancements :tab="$initialTab ?: 'workflows'" />
    @elseif($module === 'operations-enhancements')<livewire:operations.operations-enhancements :tab="$initialTab ?: 'proofs'" />
    @elseif($module === 'attention-center')<livewire:operations.attention-center />
    @elseif($module === 'duty-monitor')<livewire:operations.attention-center mode="monitor" />
    @endif
</x-ui.page>
