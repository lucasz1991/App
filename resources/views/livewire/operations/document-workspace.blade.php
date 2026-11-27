<section class="ops-stack min-w-0" data-document-workspace>
    @if($view === 'files')
        <livewire:files.file-manager :embedded="true" :key="'documents-files'" />
    @elseif($view === 'personal')
        <livewire:user-files :embedded="true" :scope-links="$scopeLinks" :key="'documents-personal'" />
    @else
        <livewire:files.managed-documents :embedded="true" :key="'documents-managed'" />
    @endif
</section>
