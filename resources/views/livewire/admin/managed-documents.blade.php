<x-ui.page
    :title="__('app.managed_documents')"
    :eyebrow="__('app.file_management')"
    :description="__('app.managed_documents_intro')"
    :count="$documents->count()"
>
    <x-slot:actions>
        <x-ui.buttons.button-basic mode="primary" size="sm" wire:click="openCreate" can="files.manage">
            <i class="far fa-plus" aria-hidden="true"></i>
            {{ __('app.add_managed_document') }}
        </x-ui.buttons.button-basic>
    </x-slot:actions>

    <section class="rounded-2xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:p-6" data-anim="fade-up">
        <div class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                <i class="fad fa-file-check" aria-hidden="true"></i>
            </span>
            <div class="max-w-3xl">
                <h2 class="text-base font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.one_purpose_one_current_file') }}</h2>
                <p class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ __('app.managed_documents_explanation') }}</p>
            </div>
        </div>
    </section>

    @forelse ($documents as $document)
        @php
            $version = $document->currentVersion;
            $file = $version?->file;
        @endphp
        <article class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" wire:key="managed-document-{{ $document->id }}" data-anim="fade-up">
            <div class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center sm:p-6">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ $document->title }}</h2>
                        <span class="rounded-md px-2 py-1 text-[11px] font-semibold {{ $document->is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300' }}">
                            {{ $document->is_active ? __('app.active') : __('app.inactive') }}
                        </span>
                        @if($version)
                            <span class="rounded-md bg-rt-surface-muted px-2 py-1 font-mono text-[11px] font-semibold text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60">v{{ $version->version_number }}</span>
                        @endif
                    </div>
                    @if($document->description)
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $document->description }}</p>
                    @endif
                    <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-xs text-rt-muted dark:text-rt-dark-muted">
                        <span class="inline-flex items-center gap-1.5"><i class="far fa-clock text-rt-soft" aria-hidden="true"></i>{{ __('app.last_update') }}: {{ $document->content_updated_at?->format('d.m.Y H:i') ?? '—' }}</span>
                        <span class="inline-flex items-center gap-1.5"><i class="far fa-layer-group text-rt-soft" aria-hidden="true"></i>{{ trans_choice('app.versions_count', $document->versions_count, ['count' => $document->versions_count]) }}</span>
                        <span class="inline-flex items-center gap-1.5"><i class="far fa-users text-rt-soft" aria-hidden="true"></i>
                            {{ $document->audience_type === \App\Models\ManagedDocument::AUDIENCE_ALL ? __('app.all_employees') : $document->teams->pluck('name')->join(', ') }}
                        </span>
                        <span class="inline-flex items-center gap-1.5"><i class="far fa-bell text-rt-soft" aria-hidden="true"></i>{{ $document->notify_on_update ? __('app.automatic_notifications_on') : __('app.automatic_notifications_off') }}</span>
                    </div>
                    @if($file)
                        <p class="mt-3 truncate text-xs font-medium text-rt-text dark:text-rt-dark-text">{{ $file->name_with_extension }} · {{ $file->size_formatted }}</p>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2 lg:max-w-md lg:justify-end">
                    <button type="button" wire:click="openVersionUpload({{ $document->id }})" class="inline-flex items-center gap-2 rounded-lg bg-rt-red px-3.5 py-2 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40">
                        <i class="far fa-upload" aria-hidden="true"></i>{{ __('app.upload_new_version') }}
                    </button>
                    <button type="button" wire:click="openHistory({{ $document->id }})" class="inline-flex items-center gap-2 rounded-lg border border-rt-border bg-rt-surface px-3.5 py-2 text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted active:scale-[0.98] dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted">
                        <i class="far fa-history" aria-hidden="true"></i>{{ __('app.version_history') }}
                    </button>
                    <button type="button" wire:click="openEdit({{ $document->id }})" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-white" title="{{ __('app.edit') }}">
                        <i class="far fa-pen" aria-hidden="true"></i>
                    </button>
                    <button type="button" wire:click="toggleActive({{ $document->id }})" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-white" title="{{ $document->is_active ? __('app.deactivate') : __('app.activate') }}">
                        <i class="far {{ $document->is_active ? 'fa-eye-slash' : 'fa-eye' }}" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </article>
    @empty
        <section class="flex flex-col items-center rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/50 px-6 py-16 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/30">
            <i class="fad fa-file-plus text-3xl text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
            <h2 class="mt-4 font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.no_managed_documents') }}</h2>
            <p class="mt-1 max-w-md text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_managed_documents_hint') }}</p>
        </section>
    @endforelse

    <x-dialog-modal wire:model="formOpen">
        <x-slot:title>{{ $editingId ? __('app.edit_managed_document') : __('app.add_managed_document') }}</x-slot:title>
        <x-slot:content>
            <div class="space-y-5">
                <div>
                    <x-ui.forms.label for="managed-title" :value="__('app.title')" />
                    <x-ui.forms.input id="managed-title" type="text" wire:model="title" class="mt-1" />
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="managed-description" :value="__('app.description')" />
                    <textarea id="managed-description" wire:model="description" rows="3" class="mt-1 w-full rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-base leading-6 text-rt-text shadow-rt-xs outline-none transition-all duration-200 focus:border-rt-red focus:ring-4 focus:ring-rt-red/15 sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:focus:ring-rt-red/25"></textarea>
                    @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label :value="__('app.availability')" />
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-rt-border p-3 dark:border-rt-dark-border">
                            <input type="radio" wire:model.live="audienceType" value="all" class="mt-1 text-rt-red focus:ring-rt-red">
                            <span><span class="block text-sm font-semibold text-rt-text dark:text-white">{{ __('app.all_employees') }}</span><span class="block text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.all_employees_hint') }}</span></span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-rt-border p-3 dark:border-rt-dark-border">
                            <input type="radio" wire:model.live="audienceType" value="teams" class="mt-1 text-rt-red focus:ring-rt-red">
                            <span><span class="block text-sm font-semibold text-rt-text dark:text-white">{{ __('app.selected_teams') }}</span><span class="block text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.selected_teams_hint') }}</span></span>
                        </label>
                    </div>
                </div>
                @if($audienceType === 'teams')
                    <div class="grid gap-2 rounded-xl bg-rt-surface-muted p-3 sm:grid-cols-2 dark:bg-rt-dark-surface-muted">
                        @foreach($teams as $team)
                            <x-ui.forms.checkbox :id="'managed-team-'.$team->id" :value="$team->id" wire:model="teamIds" :label="$team->name" />
                        @endforeach
                    </div>
                    @error('teamIds') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.forms.toggle-button model="notifyOnUpdate" :label="__('app.notify_automatically')" />
                    <x-ui.forms.toggle-button model="isActive" :label="__('app.make_available')" />
                </div>
                @if(!$editingId)
                    <div class="rounded-xl bg-rt-surface-muted p-4 dark:bg-rt-dark-surface-muted">
                        <x-ui.forms.label for="managed-initial-file" :value="__('app.initial_file')" />
                        <input id="managed-initial-file" type="file" wire:model="upload" class="mt-2 block w-full text-base text-rt-muted sm:text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-rt-red file:px-3 file:py-2 file:font-semibold file:text-white">
                        @error('upload') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        <x-ui.forms.label for="managed-initial-notes" :value="__('app.change_notes')" class="mt-4" />
                        <x-ui.forms.input id="managed-initial-notes" type="text" wire:model="changeNotes" class="mt-1" />
                    </div>
                @endif
            </div>
        </x-slot:content>
        <x-slot:footer>
            <x-secondary-button wire:click="$set('formOpen', false)">{{ __('app.cancel') }}</x-secondary-button>
            <x-button class="ml-2" wire:click="save" wire:loading.attr="disabled" wire:target="save,upload">{{ __('app.save') }}</x-button>
        </x-slot:footer>
    </x-dialog-modal>

    <x-dialog-modal wire:model="versionUploadOpen">
        <x-slot:title>{{ __('app.upload_new_version') }}</x-slot:title>
        <x-slot:content>
            <div class="space-y-4">
                <div>
                    <x-ui.forms.label for="managed-version-file" :value="__('app.file')" />
                    <input id="managed-version-file" type="file" wire:model="upload" class="mt-2 block w-full text-base text-rt-muted sm:text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-rt-red file:px-3 file:py-2 file:font-semibold file:text-white">
                    @error('upload') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="managed-version-notes" :value="__('app.change_notes')" />
                    <textarea id="managed-version-notes" wire:model="changeNotes" rows="3" class="mt-1 w-full rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-base leading-6 text-rt-text shadow-rt-xs outline-none transition-all duration-200 focus:border-rt-red focus:ring-4 focus:ring-rt-red/15 sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:focus:ring-rt-red/25"></textarea>
                </div>
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ __('app.previous_versions_remain') }}</p>
            </div>
        </x-slot:content>
        <x-slot:footer>
            <x-secondary-button wire:click="$set('versionUploadOpen', false)">{{ __('app.cancel') }}</x-secondary-button>
            <x-button class="ml-2" wire:click="uploadVersion" wire:loading.attr="disabled" wire:target="uploadVersion,upload">{{ __('app.publish_version') }}</x-button>
        </x-slot:footer>
    </x-dialog-modal>

    <x-dialog-modal wire:model="historyOpen" maxWidth="4xl">
        <x-slot:title>{{ __('app.version_history') }}{{ $historyDocument ? ': '.$historyDocument->title : '' }}</x-slot:title>
        <x-slot:content>
            @if($historyDocument)
                <div class="divide-y divide-rt-border overflow-hidden rounded-xl border border-rt-border dark:divide-rt-dark-border dark:border-rt-dark-border">
                    @foreach($historyDocument->versions as $version)
                        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="managed-version-{{ $version->id }}">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-sm font-semibold text-rt-text dark:text-white">v{{ $version->version_number }}</span>
                                    @if($version->is_current)<span class="rounded-md bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ __('app.current') }}</span>@endif
                                </div>
                                <p class="mt-1 truncate text-sm text-rt-text dark:text-rt-dark-text">{{ $version->file?->name_with_extension }}</p>
                                <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $version->created_at?->format('d.m.Y H:i') }} · {{ $version->creator?->name ?? __('app.unknown') }} @if($version->change_notes) · {{ $version->change_notes }} @endif</p>
                            </div>
                            <div class="flex shrink-0 gap-2">
                                <button type="button" wire:click="downloadVersion({{ $version->id }})" class="rounded-lg border border-rt-border px-3 py-2 text-xs font-semibold text-rt-text dark:border-rt-dark-border dark:text-white"><i class="far fa-download mr-1"></i>{{ __('app.download') }}</button>
                                @if(!$version->is_current)
                                    <button type="button" wire:click="restoreVersion({{ $version->id }})" wire:confirm="{{ __('app.restore_version_confirm') }}" class="rounded-lg bg-rt-red px-3 py-2 text-xs font-semibold text-white"><i class="far fa-undo mr-1"></i>{{ __('app.restore') }}</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-slot:content>
        <x-slot:footer><x-secondary-button wire:click="$set('historyOpen', false)">{{ __('app.close') }}</x-secondary-button></x-slot:footer>
    </x-dialog-modal>
</x-ui.page>
