<x-ui.page :title="__('app.file_management')" :eyebrow="__('app.administration')">
    <livewire:tools.file-pools.manage-file-pools
        :pool-id="$companyPoolId"
        :read-only="false"
        :allow-role-sharing="true"
        :key="'company-file-pool'"
    />
</x-ui.page>
