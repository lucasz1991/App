<div class="">
<div class="space-y-8">

{{-- Hinweisbox --}}
<div class="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 rounded-md shadow-sm">
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
                In dieser Übersicht siehst du alle
                <strong>Berichtshefte</strong>, die geprüft werden müssen –
                aufgeteilt in offen, in Bearbeitung und abgeschlossen.
            </p>

            <p class="mt-2">
                Um Doppelprüfungen zu vermeiden, musst du ein Berichtsheft
                zunächst <strong>übernehmen</strong>. Erst danach ist es dir
                exklusiv zugeordnet.
            </p>

            <p class="mt-2">
                Im Detail-Dialog kannst du das
                <strong>Berichtsheft vollständig einsehen</strong>,
                die Einträge prüfen und die Kontrolle abschließen.
                Nach erfolgreicher Prüfung markierst du das Berichtsheft als
                <strong>abgeschlossen</strong>.
            </p>

            <p class="mt-2 text-sm">
                Aktuell offene Berichtshefte:
                <strong>{{ $openCount }}</strong>
            </p>
        </div>
    </div>
</div>



    {{-- Kopfzeile + Filter --}}
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Job's</h1>
        </div>

        <div class="flex flex-wrap items-center gap-3 text-sm">
            {{-- Suchfeld --}}
            <x-tables.search-field 
                resultsCount="{{ $tasks->count() }}"
                wire:model.live="search"
            />
            {{-- Status-Filter --}}
            <select wire:model.live="filterStatus"
                    class="text-base border border-gray-300 rounded-lg px-2 py-1.5 bg-white shadow-sm focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
                <option value="">Status: Alle</option>
                <option value="{{ \App\Models\AdminTask::STATUS_OPEN }}">Offen</option>
                <option value="{{ \App\Models\AdminTask::STATUS_IN_PROGRESS }}">In Bearbeitung</option>
                <option value="{{ \App\Models\AdminTask::STATUS_COMPLETED }}">Erledigt</option>
            </select>

            {{-- Priority-Filter --}}
            <select wire:model.live="filterPriority"
                    class="text-base border border-gray-300 rounded-lg px-2 py-1.5 bg-white shadow-sm focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
                <option value="">Prio: Alle</option>
                <option value="{{ \App\Models\AdminTask::PRIORITY_HIGH }}">Hoch</option>
                <option value="{{ \App\Models\AdminTask::PRIORITY_NORMAL }}">Normal</option>
                <option value="{{ \App\Models\AdminTask::PRIORITY_LOW }}">Niedrig</option>
            </select>

            {{-- Nur meine Aufgaben --}}
            <label class="inline-flex items-center gap-1">

                <x-ui.forms.toggle-button 
                    model="onlyMine"
                    label="Nur meine"
                />
            </label>
        </div>
    </div>

    {{-- Aufgaben-Tabelle --}}
    <x-tables.table
        :columns="[
            ['label' => 'ID',          'key' => 'id',               'width' => '5%',  'sortable' => false,  'hideOn' => 'md'],
            ['label' => 'Art',         'key' => 'task_type_text',   'width' => '40%', 'sortable' => false,  'hideOn' => 'none'],
            ['label' => 'Ersteller',   'key' => 'creator_name',     'width' => '40%', 'sortable' => false,  'hideOn' => 'lg'],
            ['label' => 'Status',      'key' => 'status',           'width' => '15%', 'sortable' => false,  'hideOn' => 'none'],
        ]"
        :items="$tasks"
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
