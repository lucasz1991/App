<div>
    <div class="px-2 space-y-4">
        {{-- Header mit Counter --}}
        <div class="flex items-center">
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white">{{ __('app.employees') }}</h1>
            <span class="ml-2 bg-white text-rt-red text-xs shadow border border-slate-200 font-bold px-2 py-1 flex items-center justify-center rounded-full h-7 leading-none dark:bg-slate-800 dark:border-slate-700 dark:text-rt-red">
                {{ number_format($employeesTotal, 0, ',', '.') }}
            </span>
        </div>
        {{-- Toolbar --}}
        <div class="flex justify-between my-4 space-x-2">
            <div class="flex items-center gap-4">
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
                                'text-sm border border-slate-300 px-3 py-1 rounded-lg relative flex items-center justify-center bg-white hover:bg-slate-50 dark:bg-slate-700 dark:border-slate-600 dark:hover:bg-slate-600',
                                'cursor-not-allowed opacity-50' => $isDisabled,
                                'cursor-pointer' => !$isDisabled,
                            ])
                            @if($isDisabled) disabled @endif
                        >
                            <svg class="w-4 h-4 text-slate-600 dark:text-slate-300" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
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
                        <x-dropdown-link href="#" wire:click.prevent="exportSelected" class="hover:bg-green-100 dark:hover:bg-green-500/20">
                            <i class="far fa-download mr-2"></i>
                            {{ __('app.export') }}
                        </x-dropdown-link>
                    </x-slot>
                </x-dropdown>
            </div>
            <div class="flex items-center space-x-2">
                {{-- Suche --}}
                <x-tables.search-field 
                    resultsCount="{{ $employees->count() }}"
                    wire:model.live="search"
                />
                {{-- Team-Filter --}}
                <div class="w-44">
                    <x-ui.forms.select
                        wire:model.live="teamId"
                    >
                        <option value="">{{ __('app.all_teams') }}</option>
                        @foreach($teams as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </x-ui.forms.select>
                </div>
                {{-- Per Page --}}
                <div class="w-36">
                    <x-ui.forms.select
                        wire:model.live="perPage"
                    >
                        <option value="15">{{ __('app.per_page', ['count' => 15]) }}</option>
                        <option value="30">{{ __('app.per_page', ['count' => 30]) }}</option>
                        <option value="50">{{ __('app.per_page', ['count' => 50]) }}</option>
                        <option value="100">{{ __('app.per_page', ['count' => 100]) }}</option>
                    </x-ui.forms.select>
                </div>
                {{-- Neuer Mitarbeiter --}}
                <x-ui.buttons.button-basic wire:click="openCreate" size="sm" :can="'employees.create'" :title="__('app.new_employee_hint')">
                    <i class="far fa-plus mr-2"></i>
                    {{ __('app.new_employee') }}
                </x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic wire:click="openInvite" size="sm" :can="'employees.create'" :title="__('app.invite_employee_hint')">
                    <i class="far fa-paper-plane mr-2"></i>
                    {{ __('app.invite_employee') }}
                </x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic wire:click="openTeamRbacModal" size="sm" :can="'roles.manage'" :title="__('app.teams_permissions_hint')">
                    <i class="far fa-shield-alt mr-2"></i>
                    {{ __('app.teams_permissions') }}
                </x-ui.buttons.button-basic>
            </div>
        </div>
        {{-- Tabelle im gleichen Pattern wie Courses --}}
        <div class="w-full">
            <x-tables.table
                :columns="[
                    ['label'=>__('app.name'),'key'=>'name','width'=>'35%','sortable'=>true,'hideOn'=>'none'],
                    ['label'=>__('app.email'),'key'=>'email','width'=>'30%','sortable'=>true,'hideOn'=>'md'],
                    ['label'=>__('app.status'),'key'=>'team','width'=>'20%','sortable'=>false,'hideOn'=>'lg'],
                    ['label'=>__('app.created'),'key'=>'created_at','width'=>'15%','sortable'=>true,'hideOn'=>'md'],
                ]"
                :items="$employees"
                :selected-items="$selectedEmployees"
                row-view="components.tables.rows.employees.employee-row"
                actions-view="components.tables.rows.employees.employee-actions"
                :sort-by="$sortBy ?? null"
                :sort-dir="$sortDir ?? 'asc'"
            />
            <div class="py-4">
                {{ $employees->links() }}
            </div>
        </div>
    </div>
    <livewire:admin.employees.employee-form-modal :key="'employee-form-modal'" />
    <livewire:admin.employees.team-rbac-modal :key="'employee-team-rbac-modal'" />
    <livewire:admin.employees.invite-employee-modal :key="'employee-invite-modal'" />
    <livewire:admin.users.messages.message-form :key="'employee-message-form'" />
</div>
