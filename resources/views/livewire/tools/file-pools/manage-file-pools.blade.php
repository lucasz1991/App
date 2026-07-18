<div x-data="{ openFileForm: @entangle('openFileForm') }">
  {{-- Toolbar: Breadcrumbs + Aktionen --}}
  <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    {{-- Breadcrumbs (Explorer-Pfad) --}}
    <nav class="flex min-w-0 items-center gap-1 text-sm" aria-label="Breadcrumb">
      <button type="button" wire:click="enterFolder"
              class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 font-medium transition {{ $currentFolder ? 'text-rt-muted hover:bg-rt-surface-muted hover:text-rt-accent dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent' : 'text-rt-text dark:text-rt-dark-text' }}">
        <i class="fad fa-home fa-sm"></i>
        {{ __('app.root_folder') }}
      </button>
      @foreach($breadcrumb as $crumb)
        <i class="far fa-chevron-right text-[10px] text-rt-soft dark:text-rt-dark-soft"></i>
        @if($loop->last)
          <span class="truncate rounded-lg px-2 py-1 font-semibold text-rt-text dark:text-rt-dark-text">{{ $crumb->name }}</span>
        @else
          <button type="button" wire:click="enterFolder({{ $crumb->id }})"
                  class="truncate rounded-lg px-2 py-1 font-medium text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-accent dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent">
            {{ $crumb->name }}
          </button>
        @endif
      @endforeach
    </nav>

    <div class="flex items-center gap-2">
      @if(!$readOnly)
        <button wire:click="openCreateFolder" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-semibold rounded-lg border border-rt-border bg-rt-control text-rt-text transition hover:bg-rt-surface-muted focus:outline-none focus:ring-2 focus:ring-rt-accent/40 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:hover:bg-rt-dark-surface-muted">
          <i class="fad fa-folder-plus"></i>
          {{ __('app.new_folder') }}
        </button>
        <button wire:click="$toggle('openFileForm')" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-semibold bg-rt-red text-white rounded-lg transition hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/40 focus:ring-offset-2 dark:focus:ring-offset-slate-900">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H6a1 1 0 110-2h4V4a1 1 0 011-1z"/></svg>
          {{ __('app.add') }}
        </button>
      @endif
      @if($filePool && $poolFiles->count() > 0)
        <x-dropdown class="" :width="'w-max'">
          <x-slot name="trigger">
              <button type="button" class="inline-flex items-center px-2 py-2 rounded-lg border border-rt-border bg-rt-control text-rt-text shadow-sm transition hover:bg-rt-surface-muted focus:outline-none focus:ring-2 focus:ring-rt-accent/40 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:hover:bg-rt-dark-surface-muted">
                  <i class="fad fa-download fa-lg h-5 w-5"></i>
              </button>
          </x-slot>
          <x-slot name="content">
            <x-dropdown-link wire:click="downloadAll" class="flex items-center gap-2">
                <i class="fad fa-file-archive fa-lg"></i>&nbsp;&nbsp;{{ __('app.download_all_files') }}
            </x-dropdown-link>
          </x-slot>
        </x-dropdown>
      @endif
    </div>
  </div>

  {{-- Ordner-Raster --}}
  @if($folders->count() > 0)
    <div class="mb-2 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
      @foreach($folders as $folder)
        <div class="group relative rounded-xl border border-rt-border bg-rt-surface p-3 shadow-sm transition hover:border-rt-accent/40 hover:shadow dark:border-rt-dark-border dark:bg-rt-dark-surface" wire:key="folder-{{ $folder->id }}">
          <button type="button" wire:click="enterFolder({{ $folder->id }})" class="flex w-full flex-col items-center gap-1 text-center focus:outline-none">
            <i class="fad fa-folder text-4xl text-amber-400 transition group-hover:text-amber-500"></i>
            <span class="w-full truncate text-xs font-medium text-rt-text dark:text-rt-dark-text" title="{{ $folder->name }}">{{ $folder->name }}</span>
          </button>

          @if(!$readOnly)
            <div class="absolute right-1.5 top-1.5 opacity-0 transition group-hover:opacity-100">
              <x-dropdown align="right" width="48">
                <x-slot name="trigger">
                  <button type="button" class="rounded-lg px-1.5 py-0.5 text-sm text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-text">
                    &#x22EE;
                  </button>
                </x-slot>
                <x-slot name="content">
                  <x-dropdown-link wire:click.prevent="openRenameFolder({{ $folder->id }})">
                    <i class="far fa-pen mr-2"></i>{{ __('app.rename') }}
                  </x-dropdown-link>
                  @if($allowRoleSharing)
                    <x-dropdown-link wire:click.prevent="openPermissions({{ $folder->id }})">
                      <i class="far fa-shield-alt mr-2"></i>{{ __('app.permissions') }}
                    </x-dropdown-link>
                  @endif
                  <x-dropdown-link wire:click.prevent="deleteFolder({{ $folder->id }})"
                                   wire:confirm="{{ __('app.folder_delete_confirm') }}"
                                   class="!text-red-600 dark:!text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
                    <i class="far fa-trash-alt mr-2"></i>{{ __('app.delete') }}
                  </x-dropdown-link>
                </x-slot>
              </x-dropdown>
            </div>
          @endif
        </div>
      @endforeach
    </div>
  @endif

  {{-- Datei-Raster --}}
  <div class="my-6 mx-2 flex flex-wrap">
    @forelse($poolFiles as $file)
      <div class="w-32 mb-4 mr-4" wire:key="file-{{ $file->id }}">
        <x-ui.filepool.file-card :file="$file" :read-only="$readOnly" />
        @if($allowRoleSharing && ! $file->folder_id)
          <div class="mt-1 flex flex-wrap gap-1">
            @forelse($file->shared_roles ?? [] as $sharedRole)
              <span class="rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                {{ \App\Models\File::shareableRoles()[$sharedRole] ?? $sharedRole }}
              </span>
            @empty
              <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 dark:bg-slate-700 dark:text-slate-400">
                {{ __('app.not_shared') }}
              </span>
            @endforelse
          </div>
        @endif
      </div>
    @empty
      @if($folders->count() === 0)
        <div class="flex w-full flex-col items-center gap-2 rounded-xl border border-dashed border-rt-border py-10 text-center dark:border-rt-dark-border">
          <i class="fad fa-folder-open text-3xl text-rt-soft dark:text-rt-dark-soft"></i>
          <span class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_files_available') }}</span>
        </div>
      @endif
    @endforelse
  </div>

  @if(!$readOnly && $filePool)
    {{-- FileForm Modal --}}
    <x-dialog-modal wire:model="openFileForm">
      <x-slot name="title">
        {{ __('app.file_upload') }}
        @if($currentFolder)
          <span class="ml-1 text-sm font-normal text-rt-muted dark:text-rt-dark-muted">({{ $currentFolder->name }})</span>
        @endif
      </x-slot>
      <x-slot name="content">
        <x-ui.filepool.drop-zone :model="'fileUploads.'.$filePool->id" />
          @error('fileUploads.'.$filePool->id)
            <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
          @enderror
        <div class="mt-4">
          <x-ui.forms.label :value="__('app.expires_date')" />
          <x-ui.forms.input type="date" wire:model="expires.{{ $filePool->id }}" class="mt-1 block" />
          @error('expires.'.$filePool->id)
            <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
          @enderror
        </div>
      </x-slot>
      <x-slot name="footer">
          <div class="flex justify-end space-x-2">
              <x-ui.buttons.button-basic :mode="'primary'" :size="'sm'" wire:click="uploadFile({{ $filePool->id }})" wire:loading.attr="disabled">
                  {{ __('app.upload') }}
              </x-ui.buttons.button-basic>
              <x-ui.buttons.button-basic :mode="'basic'" :size="'sm'" wire:click="$toggle('openFileForm')">
                  {{ __('app.cancel') }}
              </x-ui.buttons.button-basic>
          </div>
      </x-slot>
    </x-dialog-modal>

    {{-- EditFileForm Modal --}}
    <x-dialog-modal wire:model="openEditFileForm">
      <x-slot name="title">{{ __('app.edit_file') }}</x-slot>
      <x-slot name="content">
        <div class="mt-4">
          <x-ui.forms.label :value="__('app.file_name')" />
          <x-ui.forms.input type="text" wire:model="selectedFileName" class="mt-1 block" />
          @error('selectedFileName')
            <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
          @enderror
        </div>
        <div class="mt-4">
          <x-ui.forms.label :value="__('app.expires_date')" />
          <x-ui.forms.input type="date" wire:model="selectedFileExpiresDate" class="mt-1 block" />
          @error('selectedFileExpiresDate')
            <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
          @enderror
        </div>
        @if($allowRoleSharing && ! $currentFolder)
          <div class="mt-4">
            <x-ui.forms.label :value="__('app.shared_for_roles')" />
            <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.shared_for_roles_hint') }}</p>
            <div class="mt-2 space-y-2">
              @foreach(\App\Models\File::shareableRoles() as $roleKey => $roleLabel)
                <x-ui.forms.checkbox
                  :id="'share-role-'.$roleKey"
                  value="{{ $roleKey }}"
                  wire:model="selectedFileShareRoles"
                  :label="$roleLabel"
                />
              @endforeach
            </div>
          </div>
        @endif
      </x-slot>
      <x-slot name="footer">
          <div class="flex justify-end space-x-2">
              <x-ui.buttons.button-basic :mode="'primary'" :size="'sm'" wire:click="safeFile()">
                  {{ __('app.save') }}
              </x-ui.buttons.button-basic>
              <x-ui.buttons.button-basic :mode="'basic'" :size="'sm'" wire:click="$toggle('openEditFileForm')">
                  {{ __('app.cancel') }}
              </x-ui.buttons.button-basic>
          </div>
      </x-slot>
    </x-dialog-modal>

    {{-- Ordner anlegen/umbenennen --}}
    <x-dialog-modal wire:model="openFolderForm" maxWidth="md">
      <x-slot name="title">{{ $editFolderId ? __('app.rename_folder') : __('app.new_folder') }}</x-slot>
      <x-slot name="content">
        <x-ui.forms.label :value="__('app.folder_name')" />
        <x-ui.forms.input type="text" wire:model="folderName" wire:keydown.enter="saveFolder" class="mt-1 block" />
        @error('folderName')
          <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
        @enderror
      </x-slot>
      <x-slot name="footer">
          <div class="flex justify-end space-x-2">
              <x-ui.buttons.button-basic :mode="'primary'" :size="'sm'" wire:click="saveFolder">
                  {{ __('app.save') }}
              </x-ui.buttons.button-basic>
              <x-ui.buttons.button-basic :mode="'basic'" :size="'sm'" wire:click="$toggle('openFolderForm')">
                  {{ __('app.cancel') }}
              </x-ui.buttons.button-basic>
          </div>
      </x-slot>
    </x-dialog-modal>

    {{-- Ordner-Rechte --}}
    @if($allowRoleSharing)
      <x-dialog-modal wire:model="openFolderPermissions" maxWidth="lg">
        <x-slot name="title">{{ __('app.folder_permissions') }}</x-slot>
        <x-slot name="content">
          <p class="mb-3 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.folder_permissions_hint') }}</p>
          <div class="overflow-x-auto rounded-xl border border-rt-border dark:border-rt-dark-border">
            <table class="w-full text-sm">
              <thead>
                <tr class="bg-rt-surface-muted text-left text-xs font-semibold uppercase tracking-wide text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                  <th class="px-3 py-2">{{ __('app.role') }}</th>
                  @foreach(\App\Models\FileFolder::permissionActions() as $actionKey => $actionLabel)
                    <th class="px-3 py-2 text-center">{{ $actionLabel }}</th>
                  @endforeach
                </tr>
              </thead>
              <tbody class="divide-y divide-rt-border dark:divide-rt-dark-border">
                @foreach(\App\Models\File::shareableRoles() as $roleKey => $roleLabel)
                  <tr>
                    <td class="px-3 py-2 font-medium text-rt-text dark:text-rt-dark-text">{{ $roleLabel }}</td>
                    @foreach(\App\Models\FileFolder::permissionActions() as $actionKey => $actionLabel)
                      <td class="px-3 py-2 text-center">
                        <input type="checkbox"
                               wire:model="folderPermissions.{{ $roleKey }}.{{ $actionKey }}"
                               class="rounded border-slate-300 text-rt-red focus:ring-rt-red/40 dark:border-slate-600 dark:bg-slate-800">
                      </td>
                    @endforeach
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </x-slot>
        <x-slot name="footer">
            <div class="flex justify-end space-x-2">
                <x-ui.buttons.button-basic :mode="'primary'" :size="'sm'" wire:click="savePermissions">
                    {{ __('app.save') }}
                </x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic :mode="'basic'" :size="'sm'" wire:click="$toggle('openFolderPermissions')">
                    {{ __('app.cancel') }}
                </x-ui.buttons.button-basic>
            </div>
        </x-slot>
      </x-dialog-modal>
    @endif
  @endif
</div>
