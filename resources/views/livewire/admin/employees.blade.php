<div>
    <x-ui.page
        :title="__('app.employees')"
        :eyebrow="__('app.administration')"
        :count="number_format($employeesTotal, 0, ',', '.')"
    >
        <x-slot:actions>
            <div class="hidden items-center gap-2 sm:flex">
                <x-ui.buttons.button-basic wire:click="openCreate" size="sm" :can="'employees.create'" :title="__('app.new_employee_hint')">
                    <i class="far fa-plus"></i>
                    {{ __('app.new_employee') }}
                </x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic wire:click="openInvite" size="sm" :can="'employees.create'" :title="__('app.invite_employee_hint')">
                    <i class="far fa-paper-plane"></i>
                    {{ __('app.invite_employee') }}
                </x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic wire:click="openTeamRbacModal" size="sm" :can="'roles.manage'" :title="__('app.teams_permissions_hint')">
                    <i class="far fa-shield-alt"></i>
                    {{ __('app.teams_permissions') }}
                </x-ui.buttons.button-basic>
            </div>

            <div class="sm:hidden">
                <x-ui.dropdown.anchor-dropdown align="right" width="64">
                    <x-slot:trigger>
                        <x-ui.dropdown.action-trigger :label="__('app.actions')" />
                    </x-slot:trigger>
                    <x-slot:content>
                        <x-dropdown-link wire:click.prevent="openCreate" :can="'employees.create'" :title="__('app.new_employee_hint')">
                            <i class="far fa-plus mr-2"></i>
                            {{ __('app.new_employee') }}
                        </x-dropdown-link>
                        <x-dropdown-link wire:click.prevent="openInvite" :can="'employees.create'" :title="__('app.invite_employee_hint')">
                            <i class="far fa-paper-plane mr-2"></i>
                            {{ __('app.invite_employee') }}
                        </x-dropdown-link>
                        <x-dropdown-link wire:click.prevent="openTeamRbacModal" :can="'roles.manage'" :title="__('app.teams_permissions_hint')">
                            <i class="far fa-shield-alt mr-2"></i>
                            {{ __('app.teams_permissions') }}
                        </x-dropdown-link>
                    </x-slot:content>
                </x-ui.dropdown.anchor-dropdown>
            </div>
        </x-slot:actions>

        {{-- Listen-Toolbar: links Massenauswahl, rechts Suche/Filter --}}
        <x-tables.toolbar data-anim="fade-up">
            <x-slot:bulk>
                <x-tables.bulk-actions :count="count($selectedEmployees)" select-all="toggleSelectAll">
                    <x-dropdown-link href="#" wire:click.prevent="clearSelection" tone="danger">
                        <i class="far fa-align-slash mr-2"></i>
                        {{ __('app.clear_selection') }}
                    </x-dropdown-link>
                    <x-dropdown-link href="#" wire:click.prevent="messageSelected" :can="'users.messages.create'">
                        <i class="far fa-paper-plane mr-2"></i>
                        {{ __('app.compose_message') }}
                    </x-dropdown-link>
                    <x-dropdown-link href="#" wire:click.prevent="exportSelected" tone="success">
                        <i class="far fa-download mr-2"></i>
                        {{ __('app.export') }}
                    </x-dropdown-link>
                </x-tables.bulk-actions>
            </x-slot:bulk>

            {{-- Suche --}}
            <x-tables.search-field
                resultsCount="{{ $employees->count() }}"
                wire:model.live="search"
            />
            {{-- Team-Filter --}}
            <div class="w-full sm:!w-44">
                <x-ui.forms.select wire:model.live="teamId">
                    <option value="">{{ __('app.all_teams') }}</option>
                    @foreach($teams as $t)
                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                    @endforeach
                </x-ui.forms.select>
            </div>
            {{-- Pro Seite --}}
            <div class="w-full sm:!w-40">
                <x-ui.forms.select wire:model.live="perPage">
                    <option value="15">{{ __('app.per_page', ['count' => 15]) }}</option>
                    <option value="30">{{ __('app.per_page', ['count' => 30]) }}</option>
                    <option value="50">{{ __('app.per_page', ['count' => 50]) }}</option>
                    <option value="100">{{ __('app.per_page', ['count' => 100]) }}</option>
                </x-ui.forms.select>
            </div>
        </x-tables.toolbar>

        {{-- Tabelle --}}
        <div class="w-full" data-anim="fade-up" data-anim-delay="0.05">
            <div class="rt-employee-mobile-header grid grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] gap-2 px-3 py-2.5 pr-14 text-[10px] font-bold uppercase tracking-[0.08em] md:hidden">
                <span>{{ __('app.name') }}</span>
                <span>{{ __('app.email') }}</span>
            </div>
            <x-tables.table
                class="rt-employee-table"
                :columns="[
                    ['label'=>__('app.name'),'key'=>'name','width'=>'35%','sortable'=>true,'hideOn'=>'none'],
                    ['label'=>__('app.email'),'key'=>'email','width'=>'30%','sortable'=>true,'hideOn'=>'none'],
                    ['label'=>__('app.status'),'key'=>'team','width'=>'20%','sortable'=>false,'hideOn'=>'lg'],
                    ['label'=>__('app.created'),'key'=>'created_at','width'=>'15%','sortable'=>true,'hideOn'=>'sm'],
                ]"
                :items="$employees"
                :selected-items="$selectedEmployees"
                selection-action="toggleEmployeeSelection"
                detail-route="{{ auth()->user()->usesAdminLayout() ? 'admin.user-profile' : 'employees.show' }}"
                row-view="components.tables.rows.employees.employee-row"
                actions-view="components.tables.rows.employees.employee-actions"
                :sort-by="$sortBy ?? null"
                :sort-dir="$sortDir ?? 'asc'"
            />
            <div class="py-4">
                {{ $employees->links() }}
            </div>
        </div>
    </x-ui.page>

    <livewire:admin.employees.employee-form-modal :key="'employee-form-modal'" />
    <livewire:admin.employees.team-rbac-modal :key="'employee-team-rbac-modal'" />
    <livewire:admin.employees.invite-employee-modal :key="'employee-invite-modal'" />
    <livewire:admin.users.messages.message-form :key="'employee-message-form'" />
</div>
