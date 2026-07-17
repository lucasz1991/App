<div class="px-2 space-y-4">
    <div class="flex items-center">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">{{ __('app.file_management') }}</h1>
    </div>

    <div class="border-l-4 border-rt-red bg-slate-50 text-slate-600 p-4 dark:bg-slate-800 dark:text-slate-300">
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
