<div class="employee-workspace employee-directory" data-employee-directory-scroll x-data="{}">
    <x-ui.page :embedded="$embedded"
        :title="$embedded ? null : __('app.employees')"
        :count="number_format($employeesTotal, 0, ',', '.')"
        :auto-intro="!$embedded"
        :show-back="false"
        :title-in-topbar="!$embedded"
    >
        <x-slot:actions>
            @if($embedded)
                <x-ui.page-header-actions target="action-list" wire:key="employee-header-actions">
                    <div class="employee-directory__header-actions flex items-center gap-1">
                        @include('livewire.employees.directory-actions')
                    </div>
                </x-ui.page-header-actions>
            @else
                <div class="employee-directory__header-actions flex items-center gap-1">
                @include('livewire.employees.directory-actions')
                </div>
            @endif
        </x-slot:actions>

        {{-- Kopfsteuerung ueber den controls-Slot: Ein x-teleport in den eigenen
             Seitenkopf wird beim ersten Livewire-Morph entfernt. Eingebettet
             reicht x-ui.page den Slot per Teleport an die Gastseite weiter. --}}
        <x-slot:controls>
        <div class="employee-directory__controls" aria-label="Mitarbeiter auswählen und suchen" wire:key="employee-header-search">
            <div class="employee-directory__search-controls">
                <x-tables.bulk-actions :count="count($selectedEmployees)" select-all="toggleSelectAll">
                    <x-ui.dropdown.dropdown-link href="#" wire:click.prevent="clearSelection" tone="danger">
                        <i class="far fa-align-slash mr-2"></i>{{ __('app.clear_selection') }}
                    </x-ui.dropdown.dropdown-link>
                    <x-ui.dropdown.dropdown-link href="#" wire:click.prevent="messageSelected" :can="'users.messages.create'">
                        <i class="far fa-paper-plane mr-2"></i>{{ __('app.compose_message') }}
                    </x-ui.dropdown.dropdown-link>
                    @if($canManageEmployeeTeams)
                        <x-ui.dropdown.dropdown-link href="#" wire:click.prevent="openTeamEditSelected">
                            <i class="far fa-edit mr-2" aria-hidden="true"></i>Teams bearbeiten
                        </x-ui.dropdown.dropdown-link>
                        <x-ui.dropdown.dropdown-link href="#" wire:click.prevent="openTeamChangeSelected">
                            <i class="far fa-users mr-2" aria-hidden="true"></i>Team wechseln
                        </x-ui.dropdown.dropdown-link>
                    @endif
                </x-tables.bulk-actions>
                <x-ui.forms.input-error for="employeeIds" class="mt-1" />
                <x-ui.forms.search context="page-topbar" id="employee-list-search"
                    placeholder="Name, E-Mail oder Funktion suchen" :results-count="$employees->count()"
                    wire:model.live.debounce.300ms="search" />
            </div>
        </div>
        </x-slot:controls>

        {{-- Tabelle --}}
        <div class="employee-directory__results w-full">
            <x-tables.table
                class="rt-employee-table"
                :flush-top="true"
                :scroll-body="true"
                :mobile-header="true"
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
                :detail-route="auth()->user()->canViewManagementDashboard() ? 'employees.show' : null"
                row-view="components.tables.rows.employees.employee-row"
                actions-view="components.tables.rows.employees.employee-actions"
                :sort-by="$sortBy ?? null"
                :sort-dir="$sortDir ?? 'asc'"
            />
            <div class="employee-directory__pagination" data-employee-pagination>
                <x-tables.pagination :paginator="$employees" table-key="employee-directory" class="employee-directory__pager" />
            </div>
        </div>
    </x-ui.page>

    <livewire:employees.employee-form-modal :key="'employee-form-modal'" />
    <livewire:employees.team-rbac-modal :key="'employee-team-rbac-modal'" />
    <livewire:employees.employee-team-modal mode="single" :key="'employee-team-change-modal'" />
    <livewire:employees.employee-team-modal mode="bulk" :key="'employee-team-bulk-modal'" />
    <livewire:employees.invite-employee-modal :key="'employee-invite-modal'" />
    <livewire:messages.message-form :key="'employee-message-form'" />
</div>
