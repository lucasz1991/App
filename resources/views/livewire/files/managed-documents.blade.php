<x-ui.page :embedded="$embedded"
    :title="__('app.managed_documents')"
    :eyebrow="__('app.file_management')"
    :description="__('app.managed_documents_intro')"
>
    <x-ui.filepool.explorer-toolbar :layout="$layout" :file-type="$fileType" :file-types="$fileTypes"
        scope="required" :scope-links="$scopeLinks" :show-up="false">
        <x-slot:actions>
            <x-ui.dropdown.page-actions>
                <x-ui.dropdown.dropdown-link wire:click.prevent="openCreate" can="files.manage">
                    <i class="far fa-plus" aria-hidden="true"></i>
                    {{ __('app.add_managed_document') }}
                </x-ui.dropdown.dropdown-link>
            </x-ui.dropdown.page-actions>
        </x-slot:actions>
    </x-ui.filepool.explorer-toolbar>

    <div class="flex min-w-0 flex-wrap items-center justify-between gap-2 px-1 text-sm text-rt-muted dark:text-rt-dark-muted">
        <span class="font-medium text-rt-text dark:text-rt-dark-text">{{ __('file_explorer.scopes.required') }}</span>
        <span role="status">{{ trans_choice('app.files_count', $documents->count(), ['count' => $documents->count()]) }}</span>
    </div>

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

    @if ($layout === 'details' && $documents->isNotEmpty())
        <x-ui.filepool.explorer-table :sort-by="$sortBy" :sort-direction="$sortDirection">
            @foreach ($documents as $document)
                @php
                    $version = $document->currentVersion;
                    $file = $version?->file;
                @endphp
                <tr class="rt-explorer-file-row" wire:key="managed-document-detail-{{ $document->id }}">
                    <td class="rt-explorer-col-name">
                        <p class="rt-explorer-file-name">{{ $document->title }}</p>
                        <p class="rt-explorer-file-meta">
                            @if($version)
                                v{{ $version->version_number }} ·
                            @endif
                            {{ $document->is_active ? __('app.active') : __('app.inactive') }}
                        </p>
                    </td>
                    <td class="rt-explorer-col-date">{{ $document->content_updated_at?->format('d.m.Y') ?? '—' }}</td>
                    <td class="rt-explorer-col-type">{{ $file ? \App\Support\FileExplorer::typeLabel(\App\Support\FileExplorer::type($file)) : '—' }}</td>
                    <td class="rt-explorer-col-size">{{ $file?->size_formatted ?? '—' }}</td>
                    <td class="rt-explorer-col-actions">
                        <x-ui.dropdown.anchor-dropdown align="right" width="56">
                            <x-slot:trigger>
                                <button type="button" class="rt-explorer-control rt-explorer-row-menu"
                                    aria-label="{{ __('app.file_actions_label', ['name' => $document->title]) }}">
                                    <i class="far fa-ellipsis-v" aria-hidden="true"></i>
                                </button>
                            </x-slot:trigger>
                            <x-slot:content>
                                <x-ui.dropdown.dropdown-link wire:click="openVersionUpload({{ $document->id }})">
                                    <i class="far fa-upload" aria-hidden="true"></i>{{ __('app.upload_new_version') }}
                                </x-ui.dropdown.dropdown-link>
                                <x-ui.dropdown.dropdown-link wire:click="openHistory({{ $document->id }})">
                                    <i class="far fa-history" aria-hidden="true"></i>{{ __('app.version_history') }}
                                </x-ui.dropdown.dropdown-link>
                                <x-ui.dropdown.dropdown-link wire:click="openEdit({{ $document->id }})">
                                    <i class="far fa-pen" aria-hidden="true"></i>{{ __('app.edit') }}
                                </x-ui.dropdown.dropdown-link>
                                <x-ui.dropdown.dropdown-link wire:click="toggleActive({{ $document->id }})">
                                    <i class="far {{ $document->is_active ? 'fa-eye-slash' : 'fa-eye' }}" aria-hidden="true"></i>
                                    {{ $document->is_active ? __('app.deactivate') : __('app.activate') }}
                                </x-ui.dropdown.dropdown-link>
                            </x-slot:content>
                        </x-ui.dropdown.anchor-dropdown>
                    </td>
                </tr>
            @endforeach
        </x-ui.filepool.explorer-table>
    @else
      <div @class(['grid min-w-0 gap-3', 'lg:grid-cols-2' => $layout === 'grid'])>
      @forelse ($documents as $document)
        @php
            $version = $document->currentVersion;
            $file = $version?->file;
        @endphp
        <article class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" wire:key="managed-document-{{ $document->id }}" data-anim="fade-up">
            <div @class(['grid gap-5 p-4 sm:p-5', 'lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center' => $layout === 'list'])>
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
                    @if($trustReady && $version && $version->trustCertificates->isNotEmpty())
                        <p class="mt-1 inline-flex items-center gap-1.5 text-xs text-rt-muted dark:text-rt-dark-muted"><i class="far fa-file-certificate text-rt-soft" aria-hidden="true"></i>Signiert mit: {{ $version->trustCertificates->pluck('name')->join(', ') }}</p>
                    @endif
                    @if($fileSyncReady && ($document->device_release_version_id || $canReleaseToDevices))
                        @php
                            $releasedNumber = $document->device_release_version_id ? \App\Models\ManagedDocumentVersion::query()->whereKey($document->device_release_version_id)->value('version_number') : null;
                            $releaseBlocker = $canReleaseToDevices ? app(\App\Services\DeviceManagement\Desktop\DeviceFileSyncService::class)->blocker($document) : null;
                        @endphp
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs" data-managed-device-release>
                            <span class="inline-flex items-center gap-1.5 {{ $releasedNumber ? 'text-emerald-700 dark:text-emerald-300' : 'text-rt-muted dark:text-rt-dark-muted' }}"><i class="far fa-desktop" aria-hidden="true"></i>
                                @if($releasedNumber)
                                    Auf Firmen-PCs: Version {{ $releasedNumber }}{{ $version && $releasedNumber !== $version->version_number ? ' – neue Version '.$version->version_number.' noch nicht freigegeben' : '' }}
                                @else
                                    Nicht auf Firmen-PCs verteilt
                                @endif
                            </span>
                            @if($canReleaseToDevices)
                                @if($version && $releasedNumber !== $version->version_number)
                                    <button type="button" wire:click="releaseToDevices({{ $document->id }})" wire:confirm="Version {{ $version->version_number }} auf allen Firmen-PCs mit Office-Vertrauen im Ordner RailTime\Listen ablegen? Excel führt die Makros dort ohne Sperre aus." @disabled($releaseBlocker !== null) title="{{ $releaseBlocker ?? 'Nur Superadmins' }}" class="rounded-lg border border-rt-border px-2.5 py-1 font-semibold text-rt-text disabled:opacity-50 dark:border-rt-dark-border dark:text-white">Auf Firmen-PCs verteilen</button>
                                @endif
                                @if($releasedNumber)
                                    <button type="button" wire:click="withdrawFromDevices({{ $document->id }})" class="rounded-lg px-2 py-1 font-semibold text-rt-muted underline dark:text-rt-dark-muted">Verteilung beenden</button>
                                @endif
                                @if($releaseBlocker && (! $version || $releasedNumber !== $version->version_number))
                                    <span class="text-rt-muted dark:text-rt-dark-muted">{{ $releaseBlocker }}</span>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>

                <div @class(['flex flex-wrap items-center gap-2', 'lg:max-w-md lg:justify-end' => $layout === 'list'])>
                    <x-ui.buttons.button-basic mode="primary" type="button" wire:click="openVersionUpload({{ $document->id }})">
                        <i class="far fa-upload" aria-hidden="true"></i>{{ __('app.upload_new_version') }}
                    </x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic mode="secondary" type="button" wire:click="openHistory({{ $document->id }})">
                        <i class="far fa-history" aria-hidden="true"></i>{{ __('app.version_history') }}
                    </x-ui.buttons.button-basic>
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
          <section class="col-span-full flex flex-col items-center rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/50 px-6 py-12 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/30">
              @if(trim($search) !== '' || $fileType !== '')
                  <p class="font-medium text-rt-text dark:text-rt-dark-text">{{ __('file_explorer.no_matches') }}</p>
                  <x-ui.buttons.button-basic class="mt-3" mode="secondary" type="button" wire:click="clearExplorerFilters">
                      {{ __('file_explorer.clear') }}
                  </x-ui.buttons.button-basic>
              @else
                  <i class="fad fa-file-plus text-3xl text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                  <h2 class="mt-4 font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.no_managed_documents') }}</h2>
                  <p class="mt-1 max-w-md text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_managed_documents_hint') }}</p>
              @endif
          </section>
      @endforelse
      </div>
    @endif

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
                    <x-ui.forms.textarea id="managed-description" wire:model="description" rows="3" class="mt-1" />
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
                @if($trustReady && ($certificates->isNotEmpty() || $canManageTrust))
                    <div class="rounded-xl border border-rt-border p-3 dark:border-rt-dark-border">
                        <x-ui.forms.label for="managed-certificate-initial" value="Signiert mit (nur bei Listen mit Makros)" />
                        <select id="managed-certificate-initial" wire:model="certificateId" class="mt-1 block min-h-11 w-full rounded-xl border-rt-border bg-rt-surface text-base text-rt-text dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white sm:text-sm">
                            <option value="">Kein Zertifikat</option>
                            @foreach($certificates as $certificate)
                                <option value="{{ $certificate->public_id }}">{{ $certificate->name }} · gültig bis {{ $certificate->valid_until?->format('d.m.Y') }}</option>
                            @endforeach
                        </select>
                        @error('certificateId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        @if($canManageTrust)
                            <x-ui.forms.label for="managed-certificate-file-initial" value="Oder neues Zertifikat hochladen (.cer)" class="mt-3" />
                            <input id="managed-certificate-file-initial" type="file" wire:model="certificateFile" accept=".cer,.crt,.pem" class="mt-2 block w-full text-base text-rt-muted sm:text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-rt-red file:px-3 file:py-2 file:font-semibold file:text-white">
                            @error('certificateFile') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            @error('certificate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        @endif
                        <p class="mt-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Die RailTime-App richtet dieses Zertifikat auf den Firmengeräten als vertrauenswürdigen Herausgeber ein. Hochgeladen wird nur der öffentliche Teil, nie der private Schlüssel.</p>
                    </div>
                @endif
                @endif
            </div>
        </x-slot:content>
        <x-slot:footer>
            <x-ui.buttons.button-basic mode="secondary" type="button" wire:click="$set('formOpen', false)">{{ __('app.cancel') }}</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic mode="primary" type="button" class="ml-2" wire:click="save" wire:loading.attr="disabled" wire:target="save,upload,certificateFile">{{ __('app.save') }}</x-ui.buttons.button-basic>
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
                    <x-ui.forms.textarea id="managed-version-notes" wire:model="changeNotes" rows="3" class="mt-1" />
                </div>
                @if($trustReady && ($certificates->isNotEmpty() || $canManageTrust))
                    <div class="rounded-xl border border-rt-border p-3 dark:border-rt-dark-border">
                        <x-ui.forms.label for="managed-certificate-version" value="Signiert mit (nur bei Listen mit Makros)" />
                        <select id="managed-certificate-version" wire:model="certificateId" class="mt-1 block min-h-11 w-full rounded-xl border-rt-border bg-rt-surface text-base text-rt-text dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white sm:text-sm">
                            <option value="">Kein Zertifikat</option>
                            @foreach($certificates as $certificate)
                                <option value="{{ $certificate->public_id }}">{{ $certificate->name }} · gültig bis {{ $certificate->valid_until?->format('d.m.Y') }}</option>
                            @endforeach
                        </select>
                        @error('certificateId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        @if($canManageTrust)
                            <x-ui.forms.label for="managed-certificate-file-version" value="Oder neues Zertifikat hochladen (.cer)" class="mt-3" />
                            <input id="managed-certificate-file-version" type="file" wire:model="certificateFile" accept=".cer,.crt,.pem" class="mt-2 block w-full text-base text-rt-muted sm:text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-rt-red file:px-3 file:py-2 file:font-semibold file:text-white">
                            @error('certificateFile') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            @error('certificate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        @endif
                        <p class="mt-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Die RailTime-App richtet dieses Zertifikat auf den Firmengeräten als vertrauenswürdigen Herausgeber ein. Hochgeladen wird nur der öffentliche Teil, nie der private Schlüssel.</p>
                    </div>
                @endif
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ __('app.previous_versions_remain') }}</p>
            </div>
        </x-slot:content>
        <x-slot:footer>
            <x-ui.buttons.button-basic mode="secondary" type="button" wire:click="$set('versionUploadOpen', false)">{{ __('app.cancel') }}</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic mode="primary" type="button" class="ml-2" wire:click="uploadVersion" wire:loading.attr="disabled" wire:target="uploadVersion,upload,certificateFile">{{ __('app.publish_version') }}</x-ui.buttons.button-basic>
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
                                    <x-ui.buttons.button-basic
                                        mode="primary"
                                        size="sm"
                                        type="button"
                                        confirm-method="restoreVersion"
                                        :confirm-arguments="[$version->id]"
                                        :confirm-title="__('app.restore')"
                                        :confirm-message="__('app.restore_version_confirm')"
                                        confirm-variant="default"
                                        :confirm-label="__('app.restore')"
                                    >
                                        <i class="far fa-undo" aria-hidden="true"></i>{{ __('app.restore') }}
                                    </x-ui.buttons.button-basic>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-slot:content>
        <x-slot:footer><x-ui.buttons.button-basic mode="secondary" type="button" wire:click="$set('historyOpen', false)">{{ __('app.close') }}</x-ui.buttons.button-basic></x-slot:footer>
    </x-dialog-modal>
</x-ui.page>
