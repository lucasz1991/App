@section('title', $definition['title'])
<x-ui.page :title="$definition['title']" :auto-intro="false" content-class="rt-ops ops-stack" :class="in_array($page, ['cases', 'shifts', 'planning', 'duty'], true) ? 'rt-disposition-page' : ''">
    @if($page === 'shifts')<x-slot:actions><x-operations.create-action :module="$initialView === 'calendar' ? 'calendar' : 'shift-management'" /></x-slot:actions>@endif
    <div class="min-w-0" x-data x-on:rt-workspace-url.window="const target = new URL($event.detail.url, location.origin); if (target.origin === location.origin && target.pathname === location.pathname) history.replaceState(history.state, '', target.href)">
    @if($page === 'cases')
        <livewire:operations.case-workspace :initial-view="$initialView" :initial-section="$initialSection" :context="$context" />
    @elseif($page === 'customers')
        <livewire:operations.customer-workspace :initial-view="$initialView" :initial-section="$initialSection" :context="$context" />
    @elseif(in_array($page, ['people','personnel-processes','leave','time-review','payroll'], true))
        <livewire:operations.personal-page-workspace :page="$page" :initial-view="$initialView" :initial-section="$initialSection" :context="$context" :key="'personal-page-'.$page" />
    @elseif($page === 'attention')
        <livewire:operations.attention-center />
    @elseif(in_array($page, ['shifts','planning','duty'], true))
        <livewire:operations.planning-page-workspace :page="$page" :initial-view="$initialView" :initial-section="$initialSection" :context="$context" :key="'planning-page-'.$page" />
    @elseif($page === 'documents')
        <livewire:operations.document-workspace :initial-view="$initialView" />
    @endif
    </div>
</x-ui.page>
