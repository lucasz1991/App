<x-ui.page :title="__('app.download_files')" :eyebrow="__('app.file_management')">
    <livewire:tools.file-pools.manage-file-pools
        :pool-id="$companyPoolId"
        :read-only="false"
        :allow-role-sharing="true"
        :key="'company-file-pool'"
    />
</x-ui.page>
