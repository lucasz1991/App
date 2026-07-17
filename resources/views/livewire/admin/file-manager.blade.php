<div class="px-2 space-y-4">
    <x-ui.page-header :title="__('app.file_management')" eyebrow="Administration" />

    <livewire:tools.file-pools.manage-file-pools
        :pool-id="$companyPoolId"
        :read-only="false"
        :allow-role-sharing="true"
        :key="'company-file-pool'"
    />
</div>
