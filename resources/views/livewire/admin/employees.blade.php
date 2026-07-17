<div>
    <div class="px-2 space-y-4">
        {{-- Hinweis-Banner im Stil der Course-Liste --}}
        <div class="border-l-4 border-rt-red bg-slate-50 text-slate-600 p-4 dark:bg-slate-800 dark:text-slate-300">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-6 w-6 text-rt-red" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 23.625 23.625" fill="currentColor" aria-hidden="true">
                        <path d="M11.812 0C5.289 0 0 5.289 0 11.812s5.289 11.813 11.812 11.813 11.813-5.29 11.813-11.813S18.335 0 11.812 0zm2.459 18.307c-.608.24-1.092.422-1.455.548a3.838 3.838 0 0 1-1.262.189c-.736 0-1.309-.18-1.717-.539s-.611-.814-.611-1.367c0-.215.015-.435.045-.659a8.23 8.23 0 0 1 .147-.759l.761-2.688c.067-.258.125-.503.171-.731.046-.23.068-.441.068-.633 0-.342-.071-.582-.212-.717-.143-.135-.412-.201-.813-.201-.196 0-.398.029-.605.09-.205.063-.383.12-.529.176l.201-.828c.498-.203.975-.377 1.43-.521a4.225 4.225 0 0 1 1.29-.218c.731 0 1.295.178 1.692.53.395.353.594.812.594 1.376 0 .117-.014.323-.041.617a4.129 4.129 0 0 1-.152.811l-.757 2.68a7.582 7.582 0 0 0-.167.736 3.892 3.892 0 0 0-.073.626c0 .356.079.599.239.728.158.129.435.194.827.194.185 0 .392-.033.626-.097.232-.064.4-.121.506-.17l-.203.827zm-.134-10.878a1.807 1.807 0 0 1-1.275.492c-.496 0-.924-.164-1.28-.492a1.57 1.57 0 0 1-.533-1.193c0-.465.18-.865.533-1.196a1.812 1.812 0 0 1 1.28-.497c.497 0 .923.165 1.275.497.353.331.53.731.53 1.196 0 .467-.177.865-.53 1.193z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <div class="text-sm">
                        <p class="font-medium">{{ __('app.tip') }}</p>
                        <p class="mt-1">
                            {{ __('app.employees_tip') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
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
                <div class="relative">
                    <select 
                        wire:model.live="teamId"
                        class="text-base border border-slate-300 rounded-lg px-2 py-1.5 bg-white shadow-sm focus:border-rt-red focus:ring focus:ring-rt-red/30 dark:bg-slate-800 dark:border-slate-600 dark:text-slate-200"
                    >
                        <option value="">{{ __('app.all_teams') }}</option>
                        @foreach($teams as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- Per Page --}}
                <div class="relative">
                    <select 
                        wire:model.live="perPage"
                        class="text-base border border-slate-300 rounded-lg px-2 py-1.5 bg-white shadow-sm focus:border-rt-red focus:ring focus:ring-rt-red/30 dark:bg-slate-800 dark:border-slate-600 dark:text-slate-200"
                    >
                        <option value="15">{{ __('app.per_page', ['count' => 15]) }}</option>
                        <option value="30">{{ __('app.per_page', ['count' => 30]) }}</option>
                        <option value="50">{{ __('app.per_page', ['count' => 50]) }}</option>
                        <option value="100">{{ __('app.per_page', ['count' => 100]) }}</option>
                    </select> 
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
