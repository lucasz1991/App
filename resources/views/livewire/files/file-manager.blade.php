<x-ui.page :embedded="$embedded" :title="__('app.download_files')" :eyebrow="__('app.file_management')">
    <livewire:tools.file-pools.manage-file-pools
        :pool-id="$companyPoolId"
        :read-only="false"
        :allow-team-permissions="true"
        :scope-links="$scopeLinks"
        :key="'company-file-pool'"
    />
</x-ui.page>
