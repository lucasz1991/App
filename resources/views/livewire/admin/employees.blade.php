<div class="employee-workspace employee-directory" data-employee-directory-scroll x-data="{}">
    <x-dynamic-component :component="$embedded ? 'operations.surface' : 'ui.page'"
        :title="$embedded ? null : __('app.employees')"
        :count="number_format($employeesTotal, 0, ',', '.')"
        :auto-intro="!$embedded"
        :show-back="false"
        :title-in-topbar="!$embedded"
    >
        <x-slot:actions>
            @if($embedded)
                <template x-teleport="[data-page-header-action-list]" wire:key="employee-header-actions">
                    <div class="employee-directory__header-actions flex items-center gap-1">
                        @include('livewire.admin.employees.directory-actions')
                    </div>
                </template>
            @else
                <div class="employee-directory__header-actions flex items-center gap-1">
                @include('livewire.admin.employees.directory-actions')
                </div>
            @endif
        </x-slot:actions>

        <template x-teleport="[data-page-header-search]" wire:key="employee-header-search">
        <div class="employee-directory__controls" aria-label="Mitarbeiter auswählen und suchen">
            <div class="employee-directory__search-controls">
                <x-tables.bulk-actions :count="count($selectedEmployees)" select-all="toggleSelectAll">
                    <x-dropdown-link href="#" wire:click.prevent="clearSelection" tone="danger">
                        <i class="far fa-align-slash mr-2"></i>{{ __('app.clear_selection') }}
                    </x-dropdown-link>
                    <x-dropdown-link href="#" wire:click.prevent="messageSelected" :can="'users.messages.create'">
                        <i class="far fa-paper-plane mr-2"></i>{{ __('app.compose_message') }}
                    </x-dropdown-link>
                    <x-dropdown-link href="#" wire:click.prevent="exportSelected" tone="success">
                        <i class="far fa-download mr-2"></i>{{ __('app.export') }}
                    </x-dropdown-link>
                </x-tables.bulk-actions>
                <x-tables.search-field context="page-topbar" id="employee-list-search"
                    placeholder="Name, E-Mail oder Funktion suchen" :results-count="$employees->count()"
                    wire:model.live.debounce.300ms="search" />
            </div>
        </div>
        </template>

        {{-- Tabelle --}}
        <div class="employee-directory__results w-full">
            <x-tables.table
                class="rt-employee-table"
                :flush-top="true"
                :scroll-body="true"
                table-key="employee-directory"
                data-table-pagination-key="employee-directory"
                label="Mitarbeiter"
                :columns="[
                    ['label'=>__('app.name'),'key'=>'name','width'=>'32%','sortable'=>true,'hideOn'=>'none'],
                    ['label'=>__('app.position'),'key'=>'position','width'=>'25%','sortable'=>true,'hideOn'=>'md'],
                    ['label'=>__('app.status'),'key'=>'status','width'=>'25%','sortable'=>true,'hideOn'=>'none'],
                    ['label'=>__('app.last_active'),'key'=>'last_activity_at','width'=>'18%','sortable'=>true,'hideOn'=>'md'],
                ]"
                :items="$employees"
                :selected-items="$selectedEmployees"
                selection-action="toggleEmployeeSelection"
                :detail-route="auth()->user()->canViewManagementDashboard()
                    ? (auth()->user()->usesAdminLayout() ? 'admin.user-profile' : 'employees.show')
                    : null"
                row-view="components.tables.rows.employees.employee-row"
                actions-view="components.tables.rows.employees.employee-actions"
                :sort-by="$sortBy ?? null"
                :sort-dir="$sortDir ?? 'asc'"
            />
            <div class="employee-directory__pagination" data-employee-pagination>
                {{ $employees->onEachSide(1)->links('livewire.admin.employees.directory-pagination', data: ['scrollTo' => false]) }}
            </div>
        </div>
    </x-dynamic-component>

    <livewire:admin.employees.employee-form-modal :key="'employee-form-modal'" />
    <livewire:admin.employees.team-rbac-modal :key="'employee-team-rbac-modal'" />
    <livewire:admin.employees.invite-employee-modal :key="'employee-invite-modal'" />
    <livewire:admin.users.messages.message-form :key="'employee-message-form'" />
</div>
