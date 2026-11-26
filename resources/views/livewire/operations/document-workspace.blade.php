<section class="ops-stack min-w-0" data-document-workspace>
    <header class="ops-toolbar">
        <x-ui.buttons.multi-toggle :options="[['value'=>'files','label'=>'Dateien','icon'=>'fa-folder'],['value'=>'managed','label'=>'Verbindliche Unterlagen','icon'=>'fa-file-signature']]" :value="$view" action="setView" label="Dokumentbereich wählen" />
    </header>
    @if($view === 'files')
        <livewire:files.file-manager :embedded="true" :key="'documents-files'" />
    @else
        <livewire:files.managed-documents :embedded="true" :key="'documents-managed'" />
    @endif
</section>
