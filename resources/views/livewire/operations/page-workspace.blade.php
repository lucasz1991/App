@section('title', $pageTitle)
{{-- Frame and host per page come from OperationsPages::definitions(). --}}
<x-ui.page :embedded="$definition['embedded'] ?? false" :title="$pageTitle" :header-variant="$definition['header'] ?? 'default'" :auto-intro="false" :title-in-topbar="$definition['topbar_title'] ?? false" :show-back="$definition['back'] ?? true" content-class="rt-ops ops-stack" :class="$definition['class'] ?? ''">
    @if($page === 'shifts')
        <x-slot:actions><x-operations.create-action :module="$initialView === 'calendar' ? 'calendar' : 'shift-management'" /></x-slot:actions>
    @endif
    <div class="min-w-0" data-page-workspace-content x-data x-on:rt-workspace-url.window="const target = new URL($event.detail.url, location.origin); if (target.origin === location.origin && target.pathname === location.pathname) { history.replaceState(history.state, '', target.href); $nextTick(() => { $dispatch('rt-workspace-url-updated'); $dispatch('operations-assistant-context-changed', { page: @js($page), view: target.searchParams.get('view') || '', section: target.searchParams.get('section') || '' }); }); }">
    @if($definition['host'] === 'cases')
        <livewire:operations.case-workspace :initial-view="$initialView" :initial-section="$initialSection" :context="$context" />
    @elseif($definition['host'] === 'customers')
        <livewire:operations.customer-workspace :initial-view="$initialView" :initial-section="$initialSection" :context="$context" />
    @elseif($definition['host'] === 'personal')
        <livewire:operations.personal-page-workspace :page="$page" :initial-view="$initialView" :initial-section="$initialSection" :context="$context" :key="'personal-page-'.$page" />
    @elseif($definition['host'] === 'attention')
        <livewire:operations.attention-center />
    @elseif($definition['host'] === 'planning')
        <livewire:operations.planning-page-workspace :page="$page" :initial-view="$initialView" :initial-section="$initialSection" :context="$context" :key="'planning-page-'.$page" />
    @elseif($definition['host'] === 'documents')
        <livewire:operations.document-workspace :initial-view="$initialView" />
    @endif
    </div>
    @if($showAssistFallback)
        {{-- Eigenständige Disposition bleibt bei ausgeschaltetem zentralen Chat verfügbar. --}}
        <livewire:operations.ai-assist :page="$page" :view="$initialView" :section="$initialSection" :key="'ai-assist-'.$page.'-'.$initialView.'-'.$initialSection" />
    @endif
</x-ui.page>
