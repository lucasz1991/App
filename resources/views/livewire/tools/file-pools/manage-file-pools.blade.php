<div x-data="{ openFileForm: @entangle('openFileForm') }">
  <div class="flex items-center justify-between mb-4">
    <div class="flex items-stretch space-x-3">
        @if(!$readOnly)
          <button wire:click="$toggle('openFileForm')" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-semibold bg-rt-red text-white rounded-lg transition hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/40 focus:ring-offset-2 dark:focus:ring-offset-slate-900">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H6a1 1 0 110-2h4V4a1 1 0 011-1z"/></svg>
            {{ __('app.add') }}
          </button>
        @endif
    </div>
    <div>
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
  <div class="my-8 mx-2 flex flex-wrap">
    @forelse($poolFiles as $file)
      <div class="w-32 mb-4 mr-4">
        <x-ui.filepool.file-card :file="$file" :read-only="$readOnly" />
        @if($allowRoleSharing)
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
      <div class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_files_available') }}</div>
    @endforelse
  </div>

  @if(!$readOnly && $filePool)
    {{-- FileForm Modal --}}
    <x-dialog-modal wire:model="openFileForm">
      <x-slot name="title">{{ __('app.file_upload') }}</x-slot>
      <x-slot name="content">
        <x-ui.filepool.drop-zone :model="'fileUploads.'.$filePool->id" />
          @error('fileUploads.'.$filePool->id)
            <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
          @enderror
        <div class="mt-4">
          <x-label :value="__('app.expires_date')" />
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
          <x-label :value="__('app.file_name')" />
          <x-ui.forms.input type="text" wire:model="selectedFileName" class="mt-1 block" />
          @error('selectedFileName')
            <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
          @enderror
        </div>
        <div class="mt-4">
          <x-label :value="__('app.expires_date')" />
          <x-ui.forms.input type="date" wire:model="selectedFileExpiresDate" class="mt-1 block" />
          @error('selectedFileExpiresDate')
            <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
          @enderror
        </div>
        @if($allowRoleSharing)
          <div class="mt-4">
            <x-label :value="__('app.shared_for_roles')" />
            <div class="mt-2 space-y-2">
              @foreach(\App\Models\File::shareableRoles() as $roleKey => $roleLabel)
                <label class="flex items-center gap-2 text-sm text-rt-text dark:text-white">
                  <input type="checkbox" value="{{ $roleKey }}" wire:model="selectedFileShareRoles"
                         class="rounded border-slate-300 text-rt-red shadow-sm focus:ring-rt-red/40 dark:border-slate-600 dark:bg-slate-800">
                  {{ $roleLabel }}
                </label>
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
  @endif
</div>
