@section('title', $modules[$module]['title'])
<x-ui.page :title="$modules[$module]['title']" :auto-intro="false" content-class="rt-ops ops-stack">
    <x-operations.navigation :modules="$modules" :current="$module" />
    @if($module === 'inquiries')<livewire:operations.inquiry-inbox />
    @elseif(in_array($module, ['qualifications', 'absences', 'rules']))<livewire:operations.personnel-review :module="$module" :key="$module" />
    @elseif(in_array($module, ['times', 'exports']))<livewire:operations.time-review :exports="$module === 'exports'" :key="$module" />
    @elseif($module === 'shift-management')<livewire:admin.operations.shift-management />
    @elseif($module === 'orders')<livewire:admin.operations.orders />
    @elseif($module === 'customers')<livewire:admin.operations.customers />
    @elseif($module === 'calendar')<livewire:admin.operations.calendar />
    @endif
</x-ui.page>
