@section('title', $definition['title'])
<x-ui.page :title="$definition['title']" :auto-intro="false" content-class="rt-ops ops-stack" :class="in_array($page, ['cases', 'shifts', 'planning', 'duty'], true) ? 'rt-disposition-page' : (in_array($page, ['people', 'personnel-processes', 'leave', 'time-review', 'payroll'], true) ? 'rt-personnel-page' : '')">
    @if($page === 'shifts')
        <x-slot:actions><x-operations.create-action :module="$initialView === 'calendar' ? 'calendar' : 'shift-management'" /></x-slot:actions>
    @endif
    <div class="min-w-0" data-page-workspace-content x-data x-on:rt-workspace-url.window="const target = new URL($event.detail.url, location.origin); if (target.origin === location.origin && target.pathname === location.pathname) { history.replaceState(history.state, '', target.href); $nextTick(() => { $dispatch('rt-workspace-url-updated'); $dispatch('operations-assistant-context-changed', { page: @js($page), view: target.searchParams.get('view') || '', section: target.searchParams.get('section') || '' }); }); }">
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
    @if(in_array($page, ['attention', 'cases', 'planning', 'duty'], true) && \App\Support\Operations\OperationsAccess::ready() && (auth()->user()->can('operations.manage') || auth()->user()->can('operations.inquiries.manage')) && ! app(\App\Support\Ai\AssistantAccess::class)->shouldRender(auth()->user()))
        {{-- Eigenständige Disposition bleibt bei ausgeschaltetem zentralen Chat verfügbar. --}}
        <livewire:operations.ai-assist :page="$page" :view="$initialView" :section="$initialSection" :key="'ai-assist-'.$page.'-'.$initialView.'-'.$initialSection" />
    @endif
</x-ui.page>
