<div class="">
<div class="space-y-8">

{{-- Hinweisbox --}}
<div class="hidden">
    <div class="flex">
        <div class="flex-shrink-0">
            <svg class="h-6 w-6 text-blue-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 23.625 23.625" fill="currentColor" aria-hidden="true">
                <path d="M11.812 0C5.289 0 0 5.289 0 11.812s5.289 11.813 11.812 11.813 11.813-5.29 11.813-11.813S18.335 0 11.812 0zm2.459 18.307c-.608.24-1.092.422-1.455.548a3.838 3.838 0 0 1-1.262.189c-.736 0-1.309-.18-1.717-.539s-.611-.814-.611-1.367c0-.215.015-.435.045-.659a8.23 8.23 0 0 1 .147-.759l.761-2.688c.067-.258.125-.503.171-.731.046-.23.068-.441.068-.633 0-.342-.071-.582-.212-.717-.143-.135-.412-.201-.813-.201-.196 0-.398.029-.605.09-.205.063-.383.12-.529.176l.201-.828c.498-.203.975-.377 1.43-.521a4.225 4.225 0 0 1 1.29-.218c.731 0 1.295.178 1.692.53.395.353.594.812.594 1.376 0 .117-.014.323-.041.617a4.129 4.129 0 0 1-.152.811l-.757 2.68a7.582 7.582 0 0 0-.167.736 3.892 3.892 0 0 0-.073.626c0 .356.079.599.239.728.158.129.435.194.827.194.185 0 .392-.033.626-.097.232-.064.4-.121.506-.17l-.203.827zm-.134-10.878a1.807 1.807 0 0 1-1.275.492c-.496 0-.924-.164-1.28-.492a1.57 1.57 0 0 1-.533-1.193c0-.465.18-.865.533-1.196a1.812 1.812 0 0 1 1.28-.497c.497 0 .923.165 1.275.497.353.331.53.731.53 1.196 0 .467-.177.865-.53 1.193z"/>
            </svg>
        </div>

        <div class="ml-3 text-sm">
            <h2 class="text-lg font-semibold mb-1">
                Hinweis zur Berichtsheft-Prüfung
            </h2>

            <p>
                In dieser Übersicht sehen Sie alle
                <strong>Berichtshefte</strong>, die geprüft werden müssen –
                aufgeteilt in offen, in Bearbeitung und abgeschlossen.
            </p>

            <p class="mt-2">
                Um Doppelprüfungen zu vermeiden, müssen Sie ein Berichtsheft
                zunächst <strong>übernehmen</strong>. Erst danach ist es Ihnen
                exklusiv zugeordnet.
            </p>

            <p class="mt-2">
                Im Detail-Dialog können Sie das
                <strong>Berichtsheft vollständig einsehen</strong>,
                die Einträge prüfen und die Kontrolle abschließen.
                Nach erfolgreicher Prüfung markieren Sie das Berichtsheft als
                <strong>abgeschlossen</strong>.
            </p>

            <p class="mt-2 text-sm">
                Aktuell offene Berichtshefte:
                <strong>{{ $openCount }}</strong>
            </p>
        </div>
    </div>
</div>



    {{-- Kopfzeile + globaler Premium-Filter --}}
    <div>
        <h1 class="text-2xl font-bold text-rt-text dark:text-rt-dark-text">Job's</h1>
    </div>

    <x-tables.toolbar
        id="admin-task-filters"
        :filter-count="$this->activeFilterCount"
        title="Aufgaben filtern"
        reset-action="resetFilters"
        search-for="admin-task-search"
    >
        <x-slot:search>
            <x-tables.search-field
                id="admin-task-search"
                :results-count="$tasks->count()"
                wire:model.live="search"
            />
        </x-slot:search>

        <x-tables.filter-field label="Status" icon="far fa-signal-alt-3" for="admin-task-status-filter">
            <x-ui.forms.select id="admin-task-status-filter" wire:model.live="filterStatus" aria-label="Status" class="w-full">
                <option value="">Status: Alle</option>
                <option value="{{ \App\Models\AdminTask::STATUS_OPEN }}">Offen</option>
                <option value="{{ \App\Models\AdminTask::STATUS_IN_PROGRESS }}">In Bearbeitung</option>
                <option value="{{ \App\Models\AdminTask::STATUS_COMPLETED }}">Erledigt</option>
            </x-ui.forms.select>
        </x-tables.filter-field>

        <x-tables.filter-field label="Priorität" icon="far fa-flag" for="admin-task-priority-filter">
            <x-ui.forms.select id="admin-task-priority-filter" wire:model.live="filterPriority" aria-label="Priorität" class="w-full">
                <option value="">Prio: Alle</option>
                <option value="{{ \App\Models\AdminTask::PRIORITY_HIGH }}">Hoch</option>
                <option value="{{ \App\Models\AdminTask::PRIORITY_NORMAL }}">Normal</option>
                <option value="{{ \App\Models\AdminTask::PRIORITY_LOW }}">Niedrig</option>
            </x-ui.forms.select>
        </x-tables.filter-field>

        <x-tables.filter-field label="Zuständigkeit" icon="far fa-user-check">
            <div class="flex min-h-11 items-center">
                <x-ui.forms.toggle-button model="onlyMine" label="Nur meine" />
            </div>
        </x-tables.filter-field>

        <x-slot:chips>
            @if (trim((string) $search) !== '')
                <x-tables.filter-chip label="Suche" :value="$search" wire:click="$set('search', '')" />
            @endif
            @if ($filterStatus !== null && $filterStatus !== '')
                <x-tables.filter-chip
                    label="Status"
                    :value="match ((int) $filterStatus) {
                        \App\Models\AdminTask::STATUS_OPEN => 'Offen',
                        \App\Models\AdminTask::STATUS_IN_PROGRESS => 'In Bearbeitung',
                        \App\Models\AdminTask::STATUS_COMPLETED => 'Erledigt',
                        default => (string) $filterStatus,
                    }"
                    wire:click="$set('filterStatus', null)"
                />
            @endif
            @if ($filterPriority !== null && $filterPriority !== '')
                <x-tables.filter-chip
                    label="Priorität"
                    :value="match ((int) $filterPriority) {
                        \App\Models\AdminTask::PRIORITY_HIGH => 'Hoch',
                        \App\Models\AdminTask::PRIORITY_NORMAL => 'Normal',
                        \App\Models\AdminTask::PRIORITY_LOW => 'Niedrig',
                        default => (string) $filterPriority,
                    }"
                    wire:click="$set('filterPriority', null)"
                />
            @endif
            @if ($onlyMine)
                <x-tables.filter-chip label="Zuständigkeit" value="Nur meine" wire:click="$set('onlyMine', false)" />
            @endif
        </x-slot:chips>
    </x-tables.toolbar>

    {{-- Aufgaben-Tabelle --}}
    <x-tables.table
        :columns="[
            ['label' => 'ID',          'key' => 'id',               'width' => '5%',  'sortable' => false,  'hideOn' => 'md'],
            ['label' => 'Art',         'key' => 'task_type_text',   'width' => '40%', 'sortable' => false,  'hideOn' => 'none'],
            ['label' => 'Ersteller',   'key' => 'creator_name',     'width' => '40%', 'sortable' => false,  'hideOn' => 'lg'],
            ['label' => 'Status',      'key' => 'status',           'width' => '15%', 'sortable' => false,  'hideOn' => 'none'],
        ]"
        :items="$tasks"
        :selected-items="$selectedTasks"
        selection-action="toggleTaskSelection"
        detail-action="openTaskDetail"
        :sort-by="$sortBy ?? null"
        :sort-dir="$sortDir ?? 'asc'"
        row-view="components.tables.rows.admin-tasks.task-row"
        action-view=""
    />

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $tasks->links() }}
    </div>
</div>
<livewire:admin.tasks.admin-task-detail wire:key="admin-task-detail-global"  />
</div>
