<div class="rt-personnel-workspace min-w-0" data-personal-page="{{ $page }}" data-personal-view="{{ $view }}" data-personal-section="{{ $section }}">
    <header class="rt-personnel-workspace__toolbar">
        @if($viewOptions)
            <x-ui.buttons.multi-toggle :id="'personal-page-'.$page" label="Personalansicht" :show-labels="true" :options="$viewOptions" :value="$section === '' ? $view : null" action="setView" />
        @endif
        @if($sections)
            <div class="rt-personnel-workspace__tools">
                @if($section !== '' && $view !== '')
                    <x-ui.buttons.button-basic type="button" wire:click="setSection('')" aria-label="Zur Ansicht zurück" title="Zur Ansicht zurück"><i class="far fa-arrow-left" aria-hidden="true"></i></x-ui.buttons.button-basic>
                @endif
                <x-ui.dropdown.anchor-dropdown width="72" :dropdown-id="'personnel-tools-'.$page" content-label="Weitere Personalwerkzeuge" dropdown-classes="rt-personnel-tools-menu">
                    <x-slot:trigger>
                        <x-ui.buttons.button-basic type="button" x-bind:aria-expanded="open.toString()" aria-haspopup="menu" :aria-label="$section !== '' ? 'Personalwerkzeuge: '.$sections[$section] : 'Weitere Personalwerkzeuge'" class="rt-personnel-workspace__tools-trigger"
                            x-on:keydown.arrow-down.prevent.stop="openDropdown(true); $nextTick(() => $refs.panel.querySelector('[data-rt-dropdown-item]')?.focus())"
                            x-on:keydown.enter.prevent.stop="openDropdown(true); $nextTick(() => $refs.panel.querySelector('[data-rt-dropdown-item]')?.focus())"
                            x-on:keydown.space.prevent.stop="openDropdown(true); $nextTick(() => $refs.panel.querySelector('[data-rt-dropdown-item]')?.focus())"
                        >
                            <i class="far fa-sliders" aria-hidden="true"></i>
                            <span>{{ $section !== '' ? $sections[$section] : 'Werkzeuge' }}</span>
                            <i class="far fa-chevron-down" aria-hidden="true"></i>
                        </x-ui.buttons.button-basic>
                    </x-slot:trigger>
                    <x-slot:content>
                        <div x-on:keydown="if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes($event.key)) { $event.preventDefault(); const items = Array.from($el.querySelectorAll('[data-rt-dropdown-item]')); const current = items.indexOf(document.activeElement); const index = $event.key === 'Home' ? 0 : $event.key === 'End' ? items.length - 1 : (current + ($event.key === 'ArrowUp' ? -1 : 1) + items.length) % items.length; items[index]?.focus(); }">
                        @foreach($sections as $key => $label)
                            <x-dropdown-link href="#" role="menuitem" wire:click.prevent="setSection('{{ $key }}')" :aria-current="$section === $key ? 'true' : null">
                                <span class="min-w-0 flex-1">{{ $label }}</span>
                                @if($section === $key)<i class="far fa-check" aria-hidden="true"></i>@endif
                            </x-dropdown-link>
                        @endforeach
                        </div>
                    </x-slot:content>
                </x-ui.dropdown.anchor-dropdown>
            </div>
        @endif
    </header>
    @if($employees->isNotEmpty())
        <div class="rt-personnel-workspace__context">
            <x-ui.forms.label for="personal-page-user" value="Mitarbeiter" />
            <x-ui.forms.select id="personal-page-user" wire:model.live="userId"><option value="0">Auswählen</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach</x-ui.forms.select>
        </div>
    @endif
    @if($section !== '')
        <h2 class="text-base font-semibold">{{ $sections[$section] }}</h2>
        @if($page === 'time-review' && $section === 'rules')
            @php
                $ruleOptions = [['value' => 'profiles', 'label' => 'Prüfprofile', 'icon' => 'fa-shield']];
                if (\App\Support\Operations\OperationsEnhancementsSchema::ready()) {
                    $ruleOptions[] = ['value' => 'rates', 'label' => 'Fachregeln & Bewertung', 'icon' => 'fa-sliders'];
                }
            @endphp
            <x-ui.buttons.multi-toggle id="personal-rule-kind" label="Regelbereich" :show-labels="true" :value="$ruleView" action="setRuleView" :options="$ruleOptions" />
            @if($ruleView === 'profiles')
                <livewire:operations.personnel-review module="rules" :embedded="true" :key="$contentKey" />
            @else
                <livewire:operations.operations-enhancements tab="rules" :embedded="true" :initial-record-id="$recordId" :key="$contentKey" />
            @endif
        @elseif($section === 'terminal')
            <livewire:operations.operations-enhancements tab="terminal" :embedded="true" :initial-user-id="$userId ?: null" :initial-record-id="$recordId" :key="$contentKey" />
        @elseif($section === 'payroll-references')
            <livewire:operations.payroll-references :key="$contentKey" />
        @elseif(in_array($section, ['models','policies','rules','checks','responsibilities'], true))
            <livewire:operations.workforce-accounts :tab="$section" :embedded="true" :initial-user-id="$userId ?: null" :initial-record-id="$recordId" :key="$contentKey" />
        @else
            <livewire:operations.personnel-enhancements :tab="$section === 'signatures' ? 'documents' : $section" :embedded="true" :initial-user-id="$userId ?: null" :initial-record-id="$recordId" :initial-record-type="$context['record_type'] ?? ''" :key="$contentKey" />
        @endif
    @elseif($page === 'people')
        @if($view === 'employees')
            @if($userId)
                <x-ui.buttons.button-basic type="button" wire:click="showEmployees">Mitarbeiterliste</x-ui.buttons.button-basic>
                <livewire:admin.user-profile :user-id="$userId" :embedded="true" :key="$contentKey" />
            @else
                <livewire:admin.employees :embedded="true" :key="$contentKey" />
            @endif
        @elseif($view === 'documents')
            @if($userId)
                <livewire:admin.user-profile.employee-documents :user-id="$userId" :key="$contentKey" />
            @else
                <p class="ops-muted">Keine Mitarbeiter in dieser Ansicht.</p>
            @endif
        @elseif($view === 'qualifications')
            <livewire:operations.personnel-review module="qualifications" :embedded="true" :initial-record-id="$recordId" :key="$contentKey" />
        @elseif($view === 'training')
            <livewire:operations.workforce-accounts tab="training" :embedded="true" :initial-user-id="$userId ?: null" :initial-record-id="$recordId" :key="$contentKey" />
        @endif
    @elseif($page === 'personnel-processes')
        @if($view === 'tasks')
            <livewire:operations.workforce-accounts tab="tasks" :embedded="true" :initial-user-id="$userId ?: null" :initial-record-id="$recordId" :key="$contentKey" />
        @else
            <livewire:operations.personnel-enhancements :tab="$view" :embedded="true" :initial-user-id="$userId ?: null" :initial-record-id="$recordId" :initial-record-type="$context['record_type'] ?? ''" :key="$contentKey" />
        @endif
    @elseif($page === 'leave')
        @if(in_array($view, ['requests','calendar'], true))
            <livewire:operations.personnel-review module="absences" :embedded="true" :initial-absence-view="$view === 'calendar' ? 'calendar' : 'list'" :initial-record-id="$recordId" :key="$contentKey" />
        @else
            <livewire:operations.workforce-accounts tab="account" :embedded="true" :account-kind="$view === 'leave-accounts' ? 'vacation' : 'time'" :initial-user-id="$userId ?: null" :key="$contentKey" />
        @endif
    @elseif($page === 'time-review')
        @if($view === 'conflicts')
            <livewire:operations.work-time-capture-review :initial-record-id="$recordId" :key="$contentKey" />
        @else
            <livewire:operations.time-review :embedded="true" :initial-record-id="$recordId" :key="$contentKey" />
        @endif
    @elseif($page === 'payroll')
        @if($view === 'closing')
            <livewire:operations.operations-enhancements tab="payroll" :embedded="true" :initial-record-id="$recordId" :initial-user-id="$userId ?: null" :key="$contentKey" />
        @else
            <livewire:operations.time-review :exports="true" :embedded="true" :history-only="$view === 'history'" :initial-record-id="$recordId" :key="$contentKey" />
            @if($view === 'history' && \App\Support\Operations\OperationsEnhancementsSchema::ready())
                <livewire:operations.operations-enhancements tab="payroll" :embedded="true" :payroll-export-only="true" :key="$contentKey.'-closing-history'" />
            @endif
        @endif
    @endif
</div>
