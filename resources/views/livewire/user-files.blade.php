<div class="space-y-6">
    {{-- Kopfbereich --}}
    <div>
        <h1 class="text-2xl font-semibold text-slate-900 dark:text-white">{{ __('app.my_files') }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('app.my_files_hint') }}</p>
    </div>

    {{-- Dateipool (nur lesen: Vorschau + Download, Uploads erfolgen durch Admins) --}}
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <livewire:tools.file-pools.manage-file-pools
            :model-type="\App\Models\User::class"
            :model-id="auth()->id()"
            :read-only="true"
            :key="'my-files-'.auth()->id()"
        />
    </div>

    {{-- Fuer die eigene Rolle freigegebene Firmendateien --}}
    <div>
        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">{{ __('app.shared_files') }}</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('app.shared_files_hint') }}</p>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <livewire:tools.file-pools.manage-file-pools
            :pool-id="\App\Models\FilePool::company()->id"
            :read-only="true"
            :role-filter="auth()->user()->role"
            :key="'shared-files-'.auth()->id()"
        />
    </div>
</div>
