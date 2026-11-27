<div class="relative" wire:loading.class="cursor-wait">
    <x-ui.page
        :embedded="$embedded"
        :title="__('app.download_center')"
        :eyebrow="__('app.downloads')"
        :description="__('app.downloads_intro')"
    >
        <x-ui.filepool.explorer-toolbar
            :layout="$layout"
            :file-type="$fileType"
            :file-types="$fileTypes"
            :scope="$embedded && $scopeLinks === [] ? null : $currentScope"
            :scope-links="$scopeLinks"
            :include-all-scopes="!$embedded"
            :show-up="false"
        />

        <div class="flex min-w-0 flex-wrap items-center justify-between gap-x-3 gap-y-1 px-1 text-sm text-rt-muted dark:text-rt-dark-muted">
            <span class="min-w-0 break-words font-medium text-rt-text dark:text-rt-dark-text">{{ __('file_explorer.scopes.'.$currentScope) }}</span>
            <span role="status" class="tabular-nums" wire:loading.class="opacity-60" wire:target="search,fileType,setExplorerScope">
                {{ trans_choice('app.files_count', $total, ['count' => number_format($total, 0, ',', '.')]) }}
            </span>
        </div>

        @if($managedDocuments->isNotEmpty())
            <section class="min-w-0 overflow-hidden rounded-xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <header class="flex items-start gap-3 border-b border-rt-border/60 bg-rt-surface-muted px-4 py-4 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted sm:px-6">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                        <i class="fad fa-file-check" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-rt-accent dark:text-rt-dark-accent">{{ __('app.always_current') }}</p>
                        <h2 class="mt-0.5 font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.managed_documents') }}</h2>
                        <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __('app.managed_documents_user_hint') }}</p>
                    </div>
                </header>

                @if($layout === 'details')
                    <x-ui.filepool.explorer-table :sort-by="$sortBy" :sort-direction="$sortDirection">
                        @foreach($managedDocuments as $document)
                            @php($version = $document->currentVersion)
                            @php($file = $version->file)
                            <tr class="rt-explorer-file-row" wire:key="managed-document-detail-{{ $document->id }}">
                                <td class="rt-explorer-col-name">
                                    <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="break-words font-semibold [overflow-wrap:anywhere]">{{ $document->title }}</span>
                                        <span class="rounded-md bg-emerald-50 px-2 py-0.5 font-mono text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">v{{ $version->version_number }}</span>
                                    </div>
                                    @if($document->description)
                                        <p class="mt-1 break-words text-xs text-rt-muted [overflow-wrap:anywhere] dark:text-rt-dark-muted">{{ $document->description }}</p>
                                    @endif
                                </td>
                                <td class="rt-explorer-col-date">{{ $document->content_updated_at?->format('d.m.Y H:i') ?? '–' }}</td>
                                <td class="rt-explorer-col-type">{{ \App\Support\FileExplorer::typeLabel(\App\Support\FileExplorer::type($file)) }}</td>
                                <td class="rt-explorer-col-size">{{ $file->size_formatted }}</td>
                                <td class="rt-explorer-col-actions">
                                    <a href="{{ route('managed-documents.download', $document) }}" class="inline-flex h-11 w-8 items-center justify-center rounded-lg text-rt-accent hover:bg-rt-accent-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent dark:text-rt-dark-accent dark:hover:bg-rt-dark-accent-soft" title="{{ __('app.download_current_version') }}">
                                        <i class="far fa-download" aria-hidden="true"></i>
                                        <span class="sr-only">{{ __('app.download_current_version') }} {{ $document->title }}</span>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.filepool.explorer-table>
                @else
                    <div @class(['grid gap-px bg-rt-border/60 dark:bg-rt-dark-border/60', 'lg:grid-cols-2' => $layout === 'grid'])>
                        @foreach($managedDocuments as $document)
                            @php($version = $document->currentVersion)
                            @php($file = $version->file)
                            <article @class(['flex min-w-0 flex-col gap-3 bg-rt-surface p-4 dark:bg-rt-dark-surface', 'sm:p-6' => $layout === 'grid', 'sm:flex-row sm:items-center sm:justify-between sm:px-6' => $layout === 'list']) wire:key="user-managed-document-{{ $document->id }}">
                                <div class="min-w-0">
                                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                                        <h3 class="break-words font-semibold text-rt-text [overflow-wrap:anywhere] dark:text-rt-dark-text">{{ $document->title }}</h3>
                                        <span class="rounded-md bg-emerald-50 px-2 py-0.5 font-mono text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">v{{ $version->version_number }}</span>
                                    </div>
                                    @if($document->description)
                                        <p class="mt-1 break-words text-sm leading-6 text-rt-muted [overflow-wrap:anywhere] dark:text-rt-dark-muted">{{ $document->description }}</p>
                                    @endif
                                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-rt-muted dark:text-rt-dark-muted">
                                        <span>{{ __('app.last_update') }}: {{ $document->content_updated_at?->format('d.m.Y H:i') ?? '–' }}</span>
                                        <span>{{ \App\Support\FileExplorer::typeLabel(\App\Support\FileExplorer::type($file)) }}</span>
                                        <span>{{ $file->size_formatted }}</span>
                                    </div>
                                </div>
                                <div @class(['shrink-0', 'mt-auto' => $layout === 'grid'])>
                                    <a href="{{ route('managed-documents.download', $document) }}" class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-rt-red px-4 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40">
                                        <i class="far fa-download" aria-hidden="true"></i>{{ __('app.download_current_version') }}
                                        <span class="sr-only">{{ $document->title }}</span>
                                    </a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        @if ($total === 0)
            {{-- Zwei verschiedene Leerzustaende: "nichts da" ist eine andere
                 Aussage als "nichts gefunden" und braucht einen anderen Ausweg. --}}
            <div class="flex w-full min-w-0 flex-col items-center gap-3 rounded-xl border border-dashed border-rt-border bg-rt-surface-muted/60 px-4 py-12 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/40">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-rt-surface text-rt-soft shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-soft dark:ring-rt-dark-border/60">
                    <i class="fad {{ $isFiltered ? 'fa-search' : 'fa-folder-open' }} text-2xl" aria-hidden="true"></i>
                </span>

                @if ($isFiltered)
                    <p class="min-w-0 break-words text-sm text-rt-muted [overflow-wrap:anywhere] dark:text-rt-dark-muted">
                        {{ trim($search) !== '' && $fileType === '' ? __('app.no_files_for_search', ['term' => $search]) : __('file_explorer.no_matches') }}
                    </p>
                    <x-ui.buttons.button-basic mode="secondary"
                        type="button"
                        wire:click="clearExplorerFilters"
                    >
                        <i class="far fa-undo" aria-hidden="true"></i>
                        {{ __('file_explorer.clear') }}
                    </x-ui.buttons.button-basic>
                @else
                    <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_downloads_available') }}</p>
                @endif
            </div>
        @else
            {{-- Fuer Sie bereitgestellt (persoenlicher Pool) --}}
            @if ($personal->isNotEmpty())
                <x-ui.filepool.download-group
                    :title="__('app.provided_for_you')"
                    icon="fad fa-user-check"
                    :files="$personal"
                    :layout="$layout"
                    :sort-by="$sortBy"
                    :sort-direction="$sortDirection"
                />
            @endif

            {{-- Firmen-Freigaben (Teams) --}}
            @if ($company->isNotEmpty())
                <x-ui.filepool.download-group
                    :title="__('app.company_files')"
                    icon="fad fa-building"
                    :files="$company"
                    :layout="$layout"
                    :sort-by="$sortBy"
                    :sort-direction="$sortDirection"
                    delay="0.05"
                />
            @endif

            {{-- Team-Downloads --}}
            @foreach ($teams as $entry)
                <x-ui.filepool.download-group
                    :title="$entry['team']->name"
                    :eyebrow="__('app.my_teams')"
                    icon="fad fa-users"
                    :files="$entry['files']"
                    :layout="$layout"
                    :sort-by="$sortBy"
                    :sort-direction="$sortDirection"
                    delay="0.05"
                    wire:key="user-file-group-{{ $entry['team']->id }}"
                />
            @endforeach
        @endif
    </x-ui.page>
</div>
