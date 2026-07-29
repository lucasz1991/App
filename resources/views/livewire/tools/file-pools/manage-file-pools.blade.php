<div
  x-data="{
    openFileForm: @entangle('openFileForm'),
    ctx: false,
    cx: 0,
    cy: 0,
    cf: null,
    canMoveFiles: @js($canMoveFiles),
    draggedFileId: null,
    dropTarget: null,
    movingFileId: null,
    openCtx(e, id) {
      this.cf = id;
      this.cx = e.clientX;
      this.cy = e.clientY;
      this.ctx = true;
    },
    fileIdFromDrag(event) {
      const transferId = event.dataTransfer?.getData('application/x-railtime-file')
        || event.dataTransfer?.getData('text/plain');
      const fileId = Number(transferId || this.draggedFileId);

      return Number.isInteger(fileId) && fileId > 0 ? fileId : null;
    },
    startFileDrag(event, fileId) {
      if (!this.canMoveFiles) {
        event.preventDefault();
        return;
      }

      this.draggedFileId = Number(fileId);
      event.currentTarget.setAttribute('aria-grabbed', 'true');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('application/x-railtime-file', String(fileId));
      event.dataTransfer.setData('text/plain', String(fileId));
    },
    endFileDrag(event) {
      event.currentTarget.setAttribute('aria-grabbed', 'false');
      this.draggedFileId = null;
      this.dropTarget = null;
    },
    activateDropTarget(event, target) {
      if (!this.draggedFileId) {
        return;
      }

      event.dataTransfer.dropEffect = 'move';
      this.dropTarget = target;
    },
    leaveDropTarget(event, target) {
      if (this.dropTarget === target && !event.currentTarget.contains(event.relatedTarget)) {
        this.dropTarget = null;
      }
    },
    async dropFile(event, targetFolderId, target) {
      const fileId = this.fileIdFromDrag(event);

      if (!fileId || this.movingFileId) {
        return;
      }

      this.dropTarget = null;
      this.movingFileId = fileId;

      try {
        await this.$wire.moveFile(fileId, targetFolderId);
      } catch (error) {
        window.dispatchEvent(new CustomEvent('swal:toast', {
          detail: { type: 'error', text: @js(__('app.file_move_failed')) },
        }));
      } finally {
        this.draggedFileId = null;
        this.movingFileId = null;
      }
    },
  }"
>
  <p id="file-pool-drag-hint-{{ $filePoolId }}" class="sr-only">
    {{ __('app.file_drag_hint') }}
  </p>
  {{--
    Explorer-Kopf:
    - mobil stehen die Aktionen bewusst vor dem Pfad;
    - auf breiten Ansichten teilen sich Pfad und Aktionen eine Zeile;
    - der Pfad bleibt in beiden Fällen das letzte Element vor dem Explorer.
  --}}
  <div
    class="mb-2 grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center"
    data-file-explorer-header
  >
    <div
      class="order-1 flex flex-wrap items-center justify-end gap-2 lg:order-2 lg:flex-nowrap"
      data-file-explorer-actions
    >
      @if(!$readOnly)
        <button wire:click="openCreateFolder" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-semibold rounded-lg border border-rt-border bg-rt-control text-rt-text shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-accent/40 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:hover:bg-rt-dark-surface-muted">
          <i class="fad fa-folder-plus"></i>
          {{ __('app.new_folder') }}
        </button>
        <button wire:click="$toggle('openFileForm')" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-semibold bg-rt-red text-white rounded-lg shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-red-dark hover:shadow-rt-glow active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40 focus:ring-offset-2 dark:focus:ring-offset-slate-900">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H6a1 1 0 110-2h4V4a1 1 0 011-1z"/></svg>
          {{ __('app.add') }}
        </button>
      @endif
      @if($filePool && $poolFiles->count() > 0)
        <x-dropdown class="" :width="'w-max'">
          <x-slot name="trigger">
              <button type="button" class="inline-flex items-center px-2 py-2 rounded-lg border border-rt-border bg-rt-control text-rt-text shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-accent/40 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:hover:bg-rt-dark-surface-muted">
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

    {{-- Breadcrumbs (Explorer-Pfad) --}}
    <nav
      class="order-2 flex min-w-0 items-center gap-1 overflow-x-auto rounded-xl bg-rt-surface-muted px-2 py-1.5 text-sm dark:bg-rt-dark-surface-muted lg:order-1"
      aria-label="Breadcrumb"
      data-file-explorer-breadcrumb
    >
      <button type="button" wire:click="enterFolder"
              class="rt-file-drop-breadcrumb inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2 py-1 font-medium transition-all duration-300 ease-rt-spring {{ $currentFolder ? 'text-rt-muted hover:bg-rt-surface hover:text-rt-accent hover:shadow-rt-xs dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface dark:hover:text-rt-dark-accent' : 'text-rt-text dark:text-rt-dark-text' }}"
              @if($canMoveFiles)
                :data-drop-active="dropTarget === 'breadcrumb-root' ? 'true' : 'false'"
                @dragenter.prevent="activateDropTarget($event, 'breadcrumb-root')"
                @dragover.prevent="activateDropTarget($event, 'breadcrumb-root')"
                @dragleave="leaveDropTarget($event, 'breadcrumb-root')"
                @drop.prevent.stop="dropFile($event, null, 'breadcrumb-root')"
              @endif>
        <i class="fad fa-home fa-sm"></i>
        {{ __('app.root_folder') }}
      </button>
      @foreach($breadcrumb as $crumb)
        <i class="far fa-chevron-right text-[10px] text-rt-soft dark:text-rt-dark-soft"></i>
        @if($loop->last)
          <span class="truncate rounded-lg px-2 py-1 font-semibold text-rt-text dark:text-rt-dark-text">{{ $crumb->name }}</span>
        @else
          <button type="button" wire:click="enterFolder({{ $crumb->id }})"
                  class="rt-file-drop-breadcrumb shrink-0 truncate rounded-lg px-2 py-1 font-medium text-rt-muted transition-all duration-300 ease-rt-spring hover:bg-rt-surface hover:text-rt-accent hover:shadow-rt-xs dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface dark:hover:text-rt-dark-accent"
                  @if($canMoveFiles)
                    :data-drop-active="dropTarget === 'breadcrumb-{{ $crumb->id }}' ? 'true' : 'false'"
                    @dragenter.prevent="activateDropTarget($event, 'breadcrumb-{{ $crumb->id }}')"
                    @dragover.prevent="activateDropTarget($event, 'breadcrumb-{{ $crumb->id }}')"
                    @dragleave="leaveDropTarget($event, 'breadcrumb-{{ $crumb->id }}')"
                    @drop.prevent.stop="dropFile($event, {{ $crumb->id }}, 'breadcrumb-{{ $crumb->id }}')"
                  @endif>
            {{ $crumb->name }}
          </button>
        @endif
      @endforeach
    </nav>
  </div>

  {{-- Gemeinsames Explorer-Raster: zuerst Ordner, danach Dateien --}}
  <div class="rt-file-explorer-grid mb-6 mt-0" data-anim-stagger @contextmenu.prevent="openCtx($event, null)">
    @foreach($folders as $folder)
        <div
          class="rt-file-explorer-card rt-file-drop-folder group relative rounded-lg p-1.5 transition-all duration-300 ease-rt-spring hover:bg-rt-accent/5 hover:ring-1 hover:ring-rt-accent/30 dark:hover:bg-rt-dark-accent/10 dark:hover:ring-rt-dark-accent/30"
          wire:key="folder-{{ $folder->id }}"
          @contextmenu.prevent.stop="openCtx($event, {{ $folder->id }})"
          @if($canMoveFiles)
            :data-drop-active="dropTarget === 'folder-{{ $folder->id }}' ? 'true' : 'false'"
            @dragenter.prevent="activateDropTarget($event, 'folder-{{ $folder->id }}')"
            @dragover.prevent="activateDropTarget($event, 'folder-{{ $folder->id }}')"
            @dragleave="leaveDropTarget($event, 'folder-{{ $folder->id }}')"
            @drop.prevent.stop="dropFile($event, {{ $folder->id }}, 'folder-{{ $folder->id }}')"
          @endif
        >
          @if($folder->auto_delete || $folder->visible_until)
            <div class="absolute left-1.5 top-1.5 text-rt-muted dark:text-rt-dark-muted" title="{{ $folder->visible_until ? __('app.visible_until').': '.$folder->visible_until->format('d.m.Y').($folder->auto_delete ? ' · '.__('app.auto_delete') : '') : __('app.auto_delete') }}">
              <i class="fad fa-clock text-[11px]"></i>
            </div>
          @endif
          <button type="button" wire:click="enterFolder({{ $folder->id }})" class="flex w-full flex-col items-center gap-1 pt-2 text-center focus:outline-none">
            <i class="fad fa-folder text-4xl text-amber-400 transition group-hover:text-amber-500 dark:text-amber-400 dark:group-hover:text-amber-300"></i>
            <span class="w-full line-clamp-2 break-words text-xs font-medium leading-snug text-rt-text dark:text-rt-dark-text" title="{{ $folder->name }}">{{ $folder->name }}</span>
          </button>

          @if(!$readOnly)
            <div class="absolute right-1.5 top-1.5">
              <x-dropdown align="right" width="48">
                <x-slot name="trigger">
                  <button type="button" class="rounded-lg bg-rt-surface/80 px-1.5 py-0.5 text-sm text-rt-muted shadow-rt-xs ring-1 ring-rt-border/60 transition hover:bg-rt-surface-muted hover:text-rt-text dark:bg-rt-dark-surface/80 dark:text-rt-dark-muted dark:ring-rt-dark-border/60 dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-text">
                    &#x22EE;
                  </button>
                </x-slot>
                <x-slot name="content">
                  <x-dropdown-link wire:click.prevent="openRenameFolder({{ $folder->id }})">
                    <i class="far fa-cog mr-2"></i>{{ __('app.folder_settings') }}
                  </x-dropdown-link>
                  @if($allowTeamPermissions)
                    <x-dropdown-link wire:click.prevent="openPermissions({{ $folder->id }})">
                      <i class="far fa-shield-alt mr-2"></i>{{ __('app.permissions') }}
                    </x-dropdown-link>
                  @endif
                  <x-dropdown-link
                      x-on:click.prevent='$dispatch("rt-confirm", {
                          title: @js(__("app.delete")),
                          message: @js(__("app.folder_delete_confirm")),
                          variant: "destructive",
                          confirmLabel: @js(__("app.delete")),
                          action: () => $wire.deleteFolder({{ $folder->id }})
                      })'
                      class="!text-red-600 dark:!text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10"
                  >
                    <i class="far fa-trash-alt mr-2"></i>{{ __('app.delete') }}
                  </x-dropdown-link>
                </x-slot>
              </x-dropdown>
            </div>
          @endif
        </div>
    @endforeach

    @foreach($poolFiles as $file)
      <div class="rt-file-explorer-card min-w-0" wire:key="file-{{ $file->id }}">
        <x-ui.filepool.file-card
          :file="$file"
          :read-only="$readOnly"
          :can-move="$canMoveFiles && (
            $file->is_owned_by_auth_user
            || auth()->user()?->isAdmin()
            || auth()->user()?->can('files.manage')
            || auth()->user()?->can('users.edit')
          )"
          :drag-hint-id="'file-pool-drag-hint-'.$filePoolId"
        />
      </div>
    @endforeach

    @if($folders->isEmpty() && $poolFiles->isEmpty())
      <div class="col-span-full flex w-full flex-col items-center gap-2 rounded-xl border border-dashed border-rt-border bg-rt-surface-muted/60 py-12 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/40">
        <i class="fad fa-folder-open text-3xl text-rt-soft dark:text-rt-dark-soft"></i>
        <span class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_files_available') }}</span>
      </div>
    @endif
  </div>

  {{-- Rechtsklick-Kontextmenue (Explorer) --}}
  @php $ctxItem = 'flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium transition-all duration-300 ease-rt-spring'; @endphp
  <div x-show="ctx" x-cloak
       @click.outside="ctx = false"
       @keydown.escape.window="ctx = false"
       :style="'left:' + cx + 'px; top:' + cy + 'px'"
       class="fixed z-[300] w-56 rounded-xl bg-rt-surface p-1.5 shadow-rt-md ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
    {{-- Ordner-spezifisch (nur bei Rechtsklick auf einen Ordner) --}}
    <template x-if="cf !== null">
      <div class="space-y-0.5">
        <button type="button" @click="$wire.enterFolder(cf); ctx = false" class="{{ $ctxItem }} text-rt-text hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted">
          <i class="far fa-folder-open w-4 text-center"></i>{{ __('app.open') }}
        </button>
        @if(!$readOnly)
          <button type="button" @click="$wire.openRenameFolder(cf); ctx = false" class="{{ $ctxItem }} text-rt-text hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted">
            <i class="far fa-cog w-4 text-center"></i>{{ __('app.folder_settings') }}
          </button>
          @if($allowTeamPermissions)
            <button type="button" @click="$wire.openPermissions(cf); ctx = false" class="{{ $ctxItem }} text-rt-text hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted">
              <i class="far fa-shield-alt w-4 text-center"></i>{{ __('app.permissions') }}
            </button>
          @endif
          <button
              type="button"
              x-on:click='
                  $dispatch("rt-confirm", {
                      title: @js(__("app.delete")),
                      message: @js(__("app.folder_delete_confirm")),
                      variant: "destructive",
                      confirmLabel: @js(__("app.delete")),
                      action: () => $wire.deleteFolder(cf)
                  });
                  ctx = false;
              '
              class="{{ $ctxItem }} text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
          >
            <i class="far fa-trash-alt w-4 text-center"></i>{{ __('app.delete') }}
          </button>
        @endif
        <div class="my-1 border-t border-rt-border/60 dark:border-rt-dark-border/60"></div>
      </div>
    </template>

    {{-- Allgemeine Aktionen --}}
    @if(!$readOnly)
      <button type="button" @click="$wire.openCreateFolder(); ctx = false" class="{{ $ctxItem }} text-rt-text hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted">
        <i class="far fa-folder-plus w-4 text-center"></i>{{ __('app.new_folder') }}
      </button>
      <button type="button" @click="openFileForm = true; ctx = false" class="{{ $ctxItem }} text-rt-text hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted">
        <i class="far fa-upload w-4 text-center"></i>{{ __('app.file_upload') }}
      </button>
    @endif
    @if($filePool && $poolFiles->count() > 0)
      <button type="button" @click="$wire.downloadAll(); ctx = false" class="{{ $ctxItem }} text-rt-text hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted">
        <i class="far fa-file-archive w-4 text-center"></i>{{ __('app.download_all_files') }}
      </button>
    @endif
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
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <x-ui.forms.label :value="__('app.expires_date')" />
            <x-ui.forms.input type="date" wire:model="expires.{{ $filePool->id }}" class="mt-1 block" />
            @error('expires.'.$filePool->id)
              <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
            @enderror
          </div>
          <div>
            <x-ui.forms.label :value="__('app.visible_from')" />
            <x-ui.forms.input type="date" wire:model="uploadVisibleFrom" class="mt-1 block" />
          </div>
        </div>

        {{-- Sichtbarkeit / Team-Freigabe --}}
        <div class="mt-4 space-y-4 rounded-xl border border-rt-border bg-rt-surface-muted/40 p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/30">
          <div>
            <x-ui.forms.toggle-button model="uploadAutoDelete" :label="__('app.auto_delete')" />
            <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.auto_delete_hint') }}</p>
          </div>

          <div>
            <x-ui.forms.label :value="__('app.team_visibility')" />
            <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">
              {{ $allowTeamPermissions && ! $currentFolder ? __('app.company_team_visibility_hint') : __('app.team_visibility_hint') }}
            </p>
            @error('uploadVisibleTeams')
              <span class="mt-1 block text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
            @enderror
            @if($teams->isEmpty())
              <p class="mt-2 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_teams_available') }}</p>
            @else
              <div class="mt-2 max-h-40 space-y-2 overflow-y-auto rounded-lg border border-rt-border bg-rt-surface p-3 dark:border-rt-dark-border dark:bg-rt-dark-surface">
                @foreach($teams as $team)
                  <x-ui.forms.toggle-button
                    :id="'upload-team-'.$team->id"
                    model="uploadVisibleTeams"
                    :value="$team->id"
                    :label="$team->name"
                  />
                @endforeach
              </div>
            @endif
          </div>
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
        <x-ui.accordion.tabs
          :tabs="[
            'fileName' => ['label' => __('app.name'), 'icon' => 'fad fa-pen'],
            'fileVisibility' => ['label' => __('app.visibility'), 'icon' => 'fad fa-eye'],
            'fileDeletion' => ['label' => __('app.automatic_deletion'), 'icon' => 'fad fa-clock'],
          ]"
          default="fileName"
          :force-default="true"
          persist-key="file-settings.tabs"
          content-class="mt-4"
        >
          <x-ui.accordion.tab-panel for="fileName" panel-class="rounded-xl border border-rt-border bg-rt-surface-muted/40 p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/30">
            <x-ui.forms.label :value="__('app.file_name')" />
            <x-ui.forms.input type="text" wire:model="selectedFileName" class="mt-1 block" required />
            @error('selectedFileName')
              <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
            @enderror
          </x-ui.accordion.tab-panel>

          <x-ui.accordion.tab-panel for="fileVisibility" content-class="space-y-4" panel-class="rounded-xl border border-rt-border bg-rt-surface-muted/40 p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/30">
            <div>
              <x-ui.forms.label :value="__('app.visible_from')" />
              <x-ui.forms.input type="date" wire:model="selectedFileVisibleFrom" class="mt-1 block" />
              <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.visible_from_hint') }}</p>
              @error('selectedFileVisibleFrom')
                <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
              @enderror
            </div>

            <div>
              <x-ui.forms.label :value="__('app.team_visibility')" />
              <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">
                {{ $allowTeamPermissions && ! $file?->folder_id ? __('app.company_team_visibility_hint') : __('app.team_visibility_hint') }}
              </p>
              @error('selectedFileVisibleTeams')
                <span class="mt-1 block text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
              @enderror
              @if($teams->isEmpty())
                <p class="mt-2 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_teams_available') }}</p>
              @else
                <div class="mt-2 max-h-40 space-y-2 overflow-y-auto rounded-lg border border-rt-border bg-rt-surface p-3 dark:border-rt-dark-border dark:bg-rt-dark-surface">
                  @foreach($teams as $team)
                    <x-ui.forms.toggle-button
                      :id="'file-team-'.$team->id"
                      model="selectedFileVisibleTeams"
                      :value="$team->id"
                      :label="$team->name"
                    />
                  @endforeach
                </div>
              @endif
            </div>

          </x-ui.accordion.tab-panel>

          <x-ui.accordion.tab-panel for="fileDeletion" content-class="space-y-4" panel-class="rounded-xl border border-rt-border bg-rt-surface-muted/40 p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/30">
            <div>
              <x-ui.forms.label :value="__('app.expires_date')" />
              <x-ui.forms.input type="date" wire:model="selectedFileExpiresDate" class="mt-1 block" />
              @error('selectedFileExpiresDate')
                <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
              @enderror
            </div>
            <div>
              <x-ui.forms.toggle-button model="selectedFileAutoDelete" :label="__('app.auto_delete')" />
              <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.auto_delete_hint') }}</p>
            </div>
          </x-ui.accordion.tab-panel>
        </x-ui.accordion.tabs>
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
    <x-dialog-modal wire:model="openFolderForm" maxWidth="lg">
      <x-slot name="title">{{ $editFolderId ? __('app.folder_settings') : __('app.new_folder') }}</x-slot>
      <x-slot name="content">
        <x-ui.accordion.tabs
          :tabs="[
            'folderName' => ['label' => __('app.name'), 'icon' => 'fad fa-pen'],
            'folderVisibility' => ['label' => __('app.visibility'), 'icon' => 'fad fa-eye'],
            'folderDeletion' => ['label' => __('app.automatic_deletion'), 'icon' => 'fad fa-clock'],
          ]"
          default="folderName"
          :force-default="true"
          persist-key="folder-settings.tabs"
          content-class="mt-4"
        >
          <x-ui.accordion.tab-panel for="folderName" panel-class="rounded-xl border border-rt-border bg-rt-surface-muted/40 p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/30">
            <x-ui.forms.label :value="__('app.folder_name')" />
            <x-ui.forms.input type="text" wire:model="folderName" wire:keydown.enter="saveFolder" class="mt-1 block" required />
            @error('folderName')
              <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
            @enderror
          </x-ui.accordion.tab-panel>

          <x-ui.accordion.tab-panel for="folderVisibility" content-class="space-y-4" panel-class="rounded-xl border border-rt-border bg-rt-surface-muted/40 p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/30">
            <div>
              <x-ui.forms.label :value="__('app.visible_from')" />
              <x-ui.forms.input type="date" wire:model="folderVisibleFrom" class="mt-1 block" />
              <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.visible_from_hint') }}</p>
              @error('folderVisibleFrom')
                <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
              @enderror
            </div>

            <div>
              <x-ui.forms.label :value="__('app.team_visibility')" />
              <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.team_visibility_hint') }}</p>
              @if($teams->isEmpty())
                <p class="mt-2 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_teams_available') }}</p>
              @else
                <div class="mt-2 max-h-40 space-y-2 overflow-y-auto rounded-lg border border-rt-border bg-rt-surface p-3 dark:border-rt-dark-border dark:bg-rt-dark-surface">
                  @foreach($teams as $team)
                    <x-ui.forms.toggle-button
                      :id="'folder-team-'.$team->id"
                      model="folderVisibleTeams"
                      :value="$team->id"
                      :label="$team->name"
                    />
                  @endforeach
                </div>
              @endif
            </div>
          </x-ui.accordion.tab-panel>

          <x-ui.accordion.tab-panel for="folderDeletion" content-class="space-y-4" panel-class="rounded-xl border border-rt-border bg-rt-surface-muted/40 p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/30">
            <div>
              <x-ui.forms.label :value="__('app.visible_until')" />
              <x-ui.forms.input type="date" wire:model="folderVisibleUntil" class="mt-1 block" />
              @error('folderVisibleUntil')
                <span class="text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
              @enderror
            </div>
            <div>
              <x-ui.forms.toggle-button model="folderAutoDelete" :label="__('app.auto_delete')" />
              <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.auto_delete_hint') }}</p>
            </div>
          </x-ui.accordion.tab-panel>
        </x-ui.accordion.tabs>
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

    {{-- Team-Rechte für Ordner --}}
    @if($allowTeamPermissions)
      <x-dialog-modal wire:model="openFolderPermissions" maxWidth="lg">
        <x-slot name="title">{{ __('app.folder_permissions') }}</x-slot>
        <x-slot name="content">
          <p class="mb-3 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.folder_permissions_hint') }}</p>
          <div class="rt-table-scroll rounded-xl border border-rt-border dark:border-rt-dark-border">
            <table class="min-w-[34rem] w-full text-sm">
              <thead>
                <tr class="bg-rt-surface-muted text-left text-xs font-semibold uppercase tracking-wide text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                  <th class="sticky left-0 z-[1] bg-rt-surface-muted px-3 py-2 dark:bg-rt-dark-surface-muted">{{ __('app.team') }}</th>
                  @foreach(\App\Models\FileFolder::permissionActions() as $actionKey => $actionLabel)
                    <th class="px-3 py-2 text-center">{{ $actionLabel }}</th>
                  @endforeach
                </tr>
              </thead>
              <tbody class="divide-y divide-rt-border dark:divide-rt-dark-border">
                @foreach($teams as $team)
                  <tr>
                    <td class="sticky left-0 bg-rt-surface px-3 py-2 font-medium text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">{{ $team->name }}</td>
                    @foreach(\App\Models\FileFolder::permissionActions() as $actionKey => $actionLabel)
                      <td class="px-3 py-2 text-center">
                        <x-ui.forms.toggle-button
                          :id="'folder-permission-'.$team->id.'-'.$actionKey"
                          :model="'folderPermissions.'.$team->id.'.'.$actionKey"
                          aria-label="{{ $team->name }}: {{ $actionLabel }}"
                          class="justify-center"
                        />
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
