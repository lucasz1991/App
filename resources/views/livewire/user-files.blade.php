<x-ui.page :title="__('app.my_files')" :eyebrow="__('app.personal_data')">
    {{-- Persoenlicher Standard-Downloadbereich --}}
    <div class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
        <div class="mb-5 flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                <i data-feather="user" class="h-5 w-5"></i>
            </span>
            <div>
                <h2 class="font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.my_files') }}</h2>
                <p class="mt-0.5 text-sm text-rt-muted dark:text-rt-dark-muted">Eigene, direkt für Sie bereitgestellte Downloads.</p>
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
    <div data-anim="fade-up">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rt-accent dark:text-rt-dark-accent">Freigaben</p>
        <h2 class="mt-1 text-xl font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.shared_files') }}</h2>
    </div>

    <div class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
        <livewire:tools.file-pools.manage-file-pools
            :pool-id="\App\Models\FilePool::company()->id"
            :read-only="true"
            :role-filter="auth()->user()->role"
            :key="'shared-files-'.auth()->id()"
        />
    </div>

    {{-- Standard-Downloadbereiche der Teams --}}
    @if ($teams->isNotEmpty())
        <div data-anim="fade-up">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rt-accent dark:text-rt-dark-accent">Teams</p>
            <h2 class="mt-1 text-xl font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">Team-Dateien</h2>
            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">Dokumente, die in Ihren Teams standardmäßig bereitgestellt werden.</p>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            @foreach ($teams as $team)
                <section class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                    <div class="mb-5 flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                            <i data-feather="users" class="h-5 w-5"></i>
                        </span>
                        <div>
                            <h3 class="font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ $team->name }}</h3>
                            <p class="text-sm text-rt-muted dark:text-rt-dark-muted">Standard-Downloads dieses Teams</p>
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
</x-ui.page>
