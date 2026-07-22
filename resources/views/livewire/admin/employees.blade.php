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
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" data-anim="fade-up">
            <div class="flex items-center gap-2">
                <x-ui.buttons.button-basic
                    wire:click="toggleSelectAll"
                    :size="'sm'"
                    :title="__('app.select_all')"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
                    </svg>
                </x-ui.buttons.button-basic>
                @php $isDisabled = count($selectedEmployees) === 0; @endphp
                <x-dropdown align="left">
                    <x-slot name="trigger">
                        <button
                            type="button"
                            @class([
                                'text-sm border border-rt-border px-3 py-1.5 rounded-lg relative flex items-center justify-center bg-rt-control shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted active:scale-[0.98] dark:bg-rt-dark-control dark:border-rt-dark-border dark:hover:bg-rt-dark-surface-muted',
                                'cursor-not-allowed opacity-50' => $isDisabled,
                                'cursor-pointer' => !$isDisabled,
                            ])
                            @if($isDisabled) disabled @endif
                        >
                            <svg class="w-4 h-4 text-rt-muted dark:text-rt-dark-muted" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
                                width="24" height="24" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5.005 11.19V12l6.998 4.042L19 12v-.81M5 16.15v.81L11.997 21l6.998-4.042v-.81M12.003 3 5.005 7.042l6.998 4.042L19 7.042 12.003 3Z"/>
                            </svg>
                            @unless($isDisabled)
                                <span class="ml-2 bg-rt-red text-white text-xs font-bold px-2 py-0.5 rounded-full">
                                    {{ count($selectedEmployees) }}
                                </span>
                            @endunless
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <x-dropdown-link href="#" wire:click.prevent="clearSelection" class="hover:bg-red-100 dark:hover:bg-red-500/20">
                            <i class="far fa-align-slash mr-2"></i>
                            {{ __('app.clear_selection') }}
                        </x-dropdown-link>
                        <x-dropdown-link href="#" wire:click.prevent="messageSelected" :can="'users.messages.create'">
                            <i class="far fa-paper-plane mr-2"></i>
                            {{ __('app.compose_message') }}
                        </x-dropdown-link>
                        <x-dropdown-link href="#" wire:click.prevent="exportSelected" class="hover:bg-green-100 dark:hover:bg-green-500/20">
                            <i class="far fa-download mr-2"></i>
                            {{ __('app.export') }}
                        </x-dropdown-link>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                {{-- Suche --}}
                <x-tables.search-field
                    resultsCount="{{ $employees->count() }}"
                    wire:model.live="search"
                />
                {{-- Team-Filter --}}
                <div class="w-44">
                    <x-ui.forms.select wire:model.live="teamId">
                        <option value="">{{ __('app.all_teams') }}</option>
                        @foreach($teams as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </x-ui.forms.select>
                </div>
                {{-- Pro Seite --}}
                <div class="w-36">
                    <x-ui.forms.select wire:model.live="perPage">
                        <option value="15">{{ __('app.per_page', ['count' => 15]) }}</option>
                        <option value="30">{{ __('app.per_page', ['count' => 30]) }}</option>
                        <option value="50">{{ __('app.per_page', ['count' => 50]) }}</option>
                        <option value="100">{{ __('app.per_page', ['count' => 100]) }}</option>
                    </x-ui.forms.select>
                </div>
            </div>
        </div>

        {{-- Tabelle --}}
        <div class="w-full" data-anim="fade-up" data-anim-delay="0.05">
            <x-tables.table
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
