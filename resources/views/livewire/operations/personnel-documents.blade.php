<div class="rt-ops ops-stack"><x-operations.feedback />
    @if(!$ready)<p class="ops-empty">Unterlagen derzeit nicht verfügbar.</p>@else
        <x-tables.table :columns="[['label'=>'Unterlage','key'=>'type'],['label'=>'Aktueller Stand','key'=>'file'],['label'=>'Kenntnisnahme','key'=>'ack']]" :items="$requirements" row-view="components.tables.rows.operations.personnel-document-row" empty="Keine Unterlagen hinterlegt." table-key="personnel-documents" />
        <x-operations.modal wire:model="historyOpen" :title="\App\Models\EmployeeDocumentRequirement::TYPES[$type] ?? 'Versionen'"><x-tables.table :columns="[['label'=>'Version','key'=>'revision'],['label'=>'Datei','key'=>'file'],['label'=>'Status','key'=>'status']]" :items="$versions" row-view="components.tables.rows.operations.personnel-version-row" empty="Keine archivierten Versionen." table-key="personnel-document-versions" /></x-operations.modal>
    @endif
</div>
