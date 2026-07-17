<div class="space-y-6">
    {{-- Kopfbereich --}}
    <x-ui.page-header :title="__('app.my_files')" />

    {{-- Persoenlicher Standard-Downloadbereich --}}
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <div class="mb-5 flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300">
                <i data-feather="user" class="h-5 w-5"></i>
            </span>
            <div>
                <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('app.my_files') }}</h2>
                <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Eigene, direkt für Sie bereitgestellte Downloads.</p>
            </div>
        </div>
        <livewire:tools.file-pools.manage-file-pools
            :model-type="\App\Models\User::class"
            :model-id="auth()->id()"
            :read-only="true"
            :key="'my-files-'.auth()->id()"
        />
    </div>

    {{-- Fuer die eigene Rolle freigegebene Firmendateien --}}
    <h2 class="text-xl font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.shared_files') }}</h2>

    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <livewire:tools.file-pools.manage-file-pools
            :pool-id="\App\Models\FilePool::company()->id"
            :read-only="true"
            :role-filter="auth()->user()->role"
            :key="'shared-files-'.auth()->id()"
        />
    </div>

    {{-- Standard-Downloadbereiche der Teams --}}
    @if ($teams->isNotEmpty())
        <div>
            <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Team-Dateien</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Dokumente, die in Ihren Teams standardmäßig bereitgestellt werden.</p>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            @foreach ($teams as $team)
                <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                    <div class="mb-5 flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300">
                            <i data-feather="users" class="h-5 w-5"></i>
                        </span>
                        <div>
                            <h3 class="font-semibold text-slate-900 dark:text-white">{{ $team->name }}</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Standard-Downloads dieses Teams</p>
                        </div>
                    </div>
                    <livewire:tools.file-pools.manage-file-pools
                        :model-type="\App\Models\Team::class"
                        :model-id="$team->id"
                        :read-only="true"
                        :key="'team-files-'.$team->id.'-'.auth()->id()"
                    />
                </section>
            @endforeach
        </div>
    @endif
</div>
