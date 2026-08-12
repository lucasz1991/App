@php
  $visibleDropPath = collect($breadcrumb)
    ->pluck('name')
    ->prepend(__('app.root_folder'))
    ->implode(' / ');
@endphp

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
  <div
    x-data="filePoolExternalDrop({
      writable: @js($canUploadFiles),
      model: @js($filePool ? 'fileUploads.'.$filePool->id : ''),
      targetPath: @js($visibleDropPath),
      openFileForm: @entangle('openFileForm'),
      directoryMessage: @js(app()->getLocale() === 'de' ? 'Ordner können nicht hochgeladen werden. Bitte wähle einzelne Dateien aus.' : 'Folders cannot be uploaded. Please select individual files.'),
      openErrorMessage: @js(app()->getLocale() === 'de' ? 'Der Datei-Upload konnte nicht geöffnet werden.' : 'The file upload could not be opened.'),
    })"
    data-filepool-external-drop
    data-target-path="{{ $visibleDropPath }}"
  >
  <div
    x-cloak
    x-show="externalDragActive"
    x-transition:enter="transition duration-200 ease-out"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition duration-150 ease-in"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="rt-filepool-window-drop"
    role="status"
    aria-live="polite"
    data-filepool-window-drop
  >
    <div class="rt-filepool-window-drop__backdrop" aria-hidden="true"></div>
    <div class="rt-filepool-window-drop__panel">
      <span class="rt-filepool-window-drop__icon" aria-hidden="true">
        <i class="fad fa-cloud-upload-alt"></i>
      </span>
      <span class="rt-filepool-window-drop__eyebrow">{{ __('app.file_upload') }}</span>
      <strong>{{ app()->getLocale() === 'de' ? 'Dateien hier ablegen' : 'Drop files here' }}</strong>
      <span class="rt-filepool-window-drop__path">
        <i class="far fa-folder-open" aria-hidden="true"></i>
        <span x-text="currentTargetPath()"></span>
      </span>
      <small>{{ app()->getLocale() === 'de' ? 'Danach kannst du Sichtbarkeit und Löschung festlegen.' : 'You can configure visibility and deletion before uploading.' }}</small>
    </div>
  </div>

  <p id="file-pool-drag-hint-{{ $filePoolId }}" class="sr-only">
    {{ __('app.file_drag_hint') }}
  </p>
  {{--
    Explorer-Kopf:
    - Aktionen als reine Icon-Knoepfe (Beschriftung via title/aria-label),
      IMMER in eigener Zeile ueber der Verzeichnisnavigation;
    - der Pfad bleibt das letzte Element vor dem Explorer.
  --}}
  <div
    class="mb-2 flex flex-col gap-2"
    data-file-explorer-header
  >
    <div
      class="flex items-center justify-end gap-2"
      data-file-explorer-actions
    >
      @if(!$readOnly)
        <button
          wire:click="openCreateFolder"
          title="{{ __('app.new_folder') }}"
          aria-label="{{ __('app.new_folder') }}"
          class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-rt-border bg-rt-control text-rt-text shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-accent/40 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:hover:bg-rt-dark-surface-muted"
        >
          <i class="fad fa-folder-plus fa-lg" aria-hidden="true"></i>
        </button>
      @endif
      @if($canUploadFiles)
        <button
          wire:click="openUploadForm"
          wire:loading.attr="disabled"
          wire:target="openUploadForm"
          title="{{ __('app.file_upload') }}"
          aria-label="{{ __('app.file_upload') }}"
          class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-rt-red text-white shadow-rt-xs transition-all duration-300 ease-rt-spring hover:-translate-y-px hover:bg-rt-red-dark hover:shadow-rt-glow active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:focus:ring-offset-slate-900"
        >
          <i class="fad fa-cloud-upload-alt text-lg" aria-hidden="true"></i>
        </button>
      @endif
      @if($filePool && $poolFiles->count() > 0)
        <x-dropdown class="" :width="'w-max'">
          <x-slot name="trigger">
              <button
                type="button"
                title="{{ __('app.download_all_files') }}"
                aria-label="{{ __('app.download_all_files') }}"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-rt-border bg-rt-control text-rt-text shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-accent/40 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:hover:bg-rt-dark-surface-muted"
              >
                  <i class="fad fa-download fa-lg" aria-hidden="true"></i>
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
      class="flex min-w-0 items-center gap-1 overflow-x-auto rounded-xl bg-rt-surface-muted px-2 py-1.5 text-sm dark:bg-rt-dark-surface-muted"
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

  {{-- Gemeinsames Explorer-Raster: zuerst Ordner, danach Dateien.

       Ladeverhalten: Beim Ordnerwechsel blendet das alte Raster kurz ab
       (is-busy) statt zu verschwinden — das haelt die Hoehe stabil und
       vermeidet den Sprung. Die neuen Kacheln tragen --rt-stagger und laufen
       per CSS gestaffelt ein; das greift auch beim Ordnerwechsel, waehrend
       die GSAP-Reveals nur bei echten Seitenwechseln feuern. --}}
  <div
    class="rt-file-explorer-grid mb-6 mt-0"
    data-anim-stagger
    wire:target="enterFolder"
    wire:loading.class="is-busy"
    @contextmenu.prevent="openCtx($event, null)"
  >
    @foreach($folders as $folder)
        <div
          class="rt-file-explorer-card rt-file-drop-folder group relative rounded-lg p-1.5 transition-all duration-300 ease-rt-spring hover:bg-rt-accent/5 hover:ring-1 hover:ring-rt-accent/30 dark:hover:bg-rt-dark-accent/10 dark:hover:ring-rt-dark-accent/30"
          style="--rt-stagger: {{ $loop->index }}"
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
                      confirm-method="deleteFolder"
                      :confirm-arguments="[(int) $folder->id]"
                      :confirm-title="__('app.delete')"
                      :confirm-message="__('app.folder_delete_confirm')"
                      :confirm-label="__('app.delete')"
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
      <div
        class="rt-file-explorer-card min-w-0"
        style="--rt-stagger: {{ $folders->count() + $loop->index }}"
        wire:key="file-{{ $file->id }}"
      >
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
    @endif
    @if($canUploadFiles)
      <button type="button" @click="$wire.openUploadForm(); ctx = false" class="{{ $ctxItem }} text-rt-text hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted">
        <i class="far fa-upload w-4 text-center"></i>{{ __('app.file_upload') }}
      </button>
    @endif
    @if($filePool && $poolFiles->count() > 0)
      <button type="button" @click="$wire.downloadAll(); ctx = false" class="{{ $ctxItem }} text-rt-text hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted">
        <i class="far fa-file-archive w-4 text-center"></i>{{ __('app.download_all_files') }}
      </button>
    @endif
  </div>

  @if($canUploadFiles && $filePool)
    {{-- FileForm Modal --}}
    <x-dialog-modal wire:model="openFileForm" maxWidth="4xl" data-filepool-upload-modal>
      <x-slot name="title">
        <span class="flex min-w-0 items-center gap-3">
          <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-red/10 text-rt-red dark:bg-rt-red/20 dark:text-rt-dark-accent" aria-hidden="true">
            <i class="fad fa-cloud-upload-alt"></i>
          </span>
          <span class="min-w-0">
            <span class="block">{{ __('app.file_upload') }}</span>
            @if($uploadTargetPath)
              <span class="mt-0.5 block truncate text-xs font-medium text-rt-muted dark:text-rt-dark-muted" title="{{ $uploadTargetPath }}">
                {{ $uploadTargetPath }}
              </span>
            @endif
          </span>
        </span>
      </x-slot>
      <x-slot name="content">
        <x-ui.accordion.tabs
          :tabs="[
            'uploadFiles' => ['label' => __('app.files'), 'icon' => 'fad fa-cloud-upload-alt'],
            'uploadVisibility' => ['label' => __('app.visibility'), 'icon' => 'fad fa-eye'],
            'uploadDeletion' => ['label' => __('app.automatic_deletion'), 'icon' => 'fad fa-clock'],
          ]"
          default="uploadFiles"
          :force-default="true"
          :persist="false"
          :reset-on-open="true"
          :aria-label="__('app.file_upload')"
          content-class="mt-4"
          class="rt-filepool-upload-tabs"
        >
          <x-ui.accordion.tab-panel for="uploadFiles" :order="0" panel-class="rt-filepool-upload-panel">
            <div class="rt-filepool-upload-panel__intro">
              <div>
                <h3>{{ __('app.files') }}</h3>
                <p>{{ app()->getLocale() === 'de' ? 'Wähle bis zu 20 Dateien aus. Gespeichert wird erst nach dem Klick auf Hochladen.' : 'Select up to 20 files. Files are only saved after you click Upload.' }}</p>
              </div>
              @if($uploadTargetPath)
                <span class="rt-filepool-upload-target" title="{{ $uploadTargetPath }}">
                  <i class="far fa-folder-open" aria-hidden="true"></i>
                  <span>{{ $uploadTargetPath }}</span>
                </span>
              @endif
            </div>

            <x-ui.filepool.drop-zone
              :model="'fileUploads.'.$filePool->id"
              :max-files="20"
              :max-filesize="100"
            />
          </x-ui.accordion.tab-panel>

          <x-ui.accordion.tab-panel for="uploadVisibility" :order="1" content-class="space-y-5" panel-class="rt-filepool-upload-panel rt-filepool-upload-panel--settings">
            <div class="rt-filepool-setting-card">
              <span class="rt-filepool-setting-card__icon" aria-hidden="true"><i class="fad fa-calendar-day"></i></span>
              <div class="min-w-0 flex-1">
                <x-ui.forms.label :value="__('app.visible_from')" />
                <x-ui.forms.input type="date" wire:model="uploadVisibleFrom" class="mt-2 block" />
                <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.visible_from_hint') }}</p>
                @error('uploadVisibleFrom')
                  <span class="mt-1 block text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
                @enderror
              </div>
            </div>

            <div class="rt-filepool-setting-card rt-filepool-setting-card--stacked">
              <div class="flex items-start gap-3">
                <span class="rt-filepool-setting-card__icon" aria-hidden="true"><i class="fad fa-users"></i></span>
                <div class="min-w-0 flex-1">
                  <x-ui.forms.label :value="__('app.team_visibility')" />
                  <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">
                    {{ $allowTeamPermissions && $uploadFolderId === null ? __('app.company_team_visibility_hint') : __('app.team_visibility_hint') }}
                  </p>
                </div>
              </div>
              @error('uploadVisibleTeams')
                <span class="block text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
              @enderror
              @if($teams->isEmpty())
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_teams_available') }}</p>
              @else
                <div class="rt-filepool-team-list">
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
          </x-ui.accordion.tab-panel>

          <x-ui.accordion.tab-panel for="uploadDeletion" :order="2" content-class="space-y-5" panel-class="rt-filepool-upload-panel rt-filepool-upload-panel--settings">
            <div class="rt-filepool-setting-card">
              <span class="rt-filepool-setting-card__icon" aria-hidden="true"><i class="fad fa-calendar-times"></i></span>
              <div class="min-w-0 flex-1">
                <x-ui.forms.label :value="__('app.expires_date')" />
                <x-ui.forms.input type="date" wire:model="expires.{{ $filePool->id }}" class="mt-2 block" />
                @error('expires.'.$filePool->id)
                  <span class="mt-1 block text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
                @enderror
              </div>
            </div>

            <div class="rt-filepool-setting-card">
              <span class="rt-filepool-setting-card__icon" aria-hidden="true"><i class="fad fa-trash-restore"></i></span>
              <div class="min-w-0 flex-1">
                <x-ui.forms.toggle-button model="uploadAutoDelete" :label="__('app.auto_delete')" />
                <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.auto_delete_hint') }}</p>
              </div>
            </div>
          </x-ui.accordion.tab-panel>
        </x-ui.accordion.tabs>
      </x-slot>
      <x-slot name="footer">
        <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <x-ui.buttons.button-basic
            :mode="'basic'"
            :size="'md'"
            x-on:click="cancelUploadForm()"
            class="min-h-11 min-w-[8.5rem]"
          >
            <i class="far fa-times" aria-hidden="true"></i>
            {{ __('app.cancel') }}
          </x-ui.buttons.button-basic>
          <x-ui.buttons.button-basic
            :mode="'primary'"
            :size="'md'"
            wire:click="uploadFile({{ $filePool->id }})"
            wire:loading.attr="disabled"
            wire:target="uploadFile"
            class="min-h-11 min-w-[9.5rem]"
          >
            <i wire:loading.remove wire:target="uploadFile" class="fad fa-cloud-upload-alt" aria-hidden="true"></i>
            <i wire:loading wire:target="uploadFile" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
            <span wire:loading.remove wire:target="uploadFile">{{ __('app.upload') }}</span>
            <span wire:loading wire:target="uploadFile">{{ __('app.uploading') }}</span>
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
</div>
