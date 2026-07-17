<div class="px-2 space-y-4">
    <div class="flex items-center">
        <h1 class="text-2xl font-bold text-gray-700 dark:text-white">{{ __('app.file_management') }}</h1>
    </div>

    <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 dark:bg-sky-500/10 dark:border-sky-500 dark:text-sky-300">
        <p class="text-sm">
            {{ __('app.file_management_hint') }}
        </p>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <livewire:tools.file-pools.manage-file-pools
            :pool-id="$companyPoolId"
            :read-only="false"
            :allow-role-sharing="true"
            :key="'company-file-pool'"
        />
    </div>
</div>
