@foreach($folders as $folder)
    <{{ $folderTag }}
      @class([
        'rt-file-drop-folder group relative transition-colors hover:bg-rt-accent/5 dark:hover:bg-rt-dark-accent/10',
        'rt-file-explorer-card rounded-lg p-1.5 hover:ring-1 hover:ring-rt-accent/30 dark:hover:ring-rt-dark-accent/30' => $gridView,
        'flex min-w-0 items-center gap-2 rounded-lg border border-rt-border px-2 dark:border-rt-dark-border' => !$gridView && !$detailsView,
        'border-b border-rt-border/60 dark:border-rt-dark-border/60' => $detailsView,
      ])
      @if($gridView) style="--rt-stagger: {{ $loop->index }}" @endif
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
      @if($detailsView) <td class="px-3 py-1.5"> @endif
      <button type="button" wire:click="enterFolder({{ $folder->id }})"
              @class([
                'flex w-full min-w-0 items-center rounded-lg text-rt-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent dark:text-rt-dark-text',
                'flex-col gap-1 pt-2 text-center' => $gridView,
                'min-h-10 gap-2 px-1 text-left' => !$gridView,
              ])>
        <i @class(['fad fa-folder shrink-0 text-amber-500 dark:text-amber-400', 'text-4xl' => $gridView, 'text-xl' => !$gridView]) aria-hidden="true"></i>
        <span @class([
                'min-w-0 font-medium',
                'w-full line-clamp-2 break-words text-xs leading-snug' => $gridView,
                'truncate text-sm' => !$gridView,
              ]) title="{{ $folder->name }}">{{ $folder->name }}</span>
        @if($folder->auto_delete || $folder->visible_until)
          @php
            $visibilityLabel = $folder->visible_until
              ? __('app.visible_until').': '.$folder->visible_until->format('d.m.Y').($folder->auto_delete ? ' · '.__('app.auto_delete') : '')
              : __('app.auto_delete');
          @endphp
          <span @class(['shrink-0 text-rt-muted dark:text-rt-dark-muted', 'absolute left-1.5 top-1.5' => $gridView]) title="{{ $visibilityLabel }}">
            <i class="fad fa-clock text-[11px]" aria-hidden="true"></i>
            <span class="sr-only">{{ $visibilityLabel }}</span>
          </span>
        @endif
      </button>
      @if($detailsView)
        </td>
        <td class="whitespace-nowrap px-3 py-1.5 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $folder->updated_at?->format('d.m.Y') ?? '–' }}</td>
        <td class="whitespace-nowrap px-3 py-1.5 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('file_explorer.folder') }}</td>
        <td class="whitespace-nowrap px-3 py-1.5 text-right text-sm text-rt-muted dark:text-rt-dark-muted">–</td>
        <td class="px-3 py-1.5 text-right">
      @endif

      @if(!$readOnly)
        <div @class(['shrink-0', 'absolute right-1.5 top-1.5' => $gridView])>
          <x-ui.dropdown.anchor-dropdown align="right" width="48">
            <x-slot name="trigger">
              <button type="button" aria-label="{{ __('file_explorer.folder_actions', ['name' => $folder->name]) }}"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rt-surface/80 text-sm text-rt-muted shadow-rt-xs ring-1 ring-rt-border/60 transition hover:bg-rt-surface-muted hover:text-rt-text focus-visible:ring-2 focus-visible:ring-rt-accent dark:bg-rt-dark-surface/80 dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                <i class="far fa-ellipsis-v" aria-hidden="true"></i>
              </button>
            </x-slot>
            <x-slot name="content">
              <x-ui.dropdown.dropdown-link wire:click.prevent="openRenameFolder({{ $folder->id }})">
                <i class="far fa-cog mr-2"></i>{{ __('app.folder_settings') }}
              </x-ui.dropdown.dropdown-link>
              @if($allowTeamPermissions)
                <x-ui.dropdown.dropdown-link wire:click.prevent="openPermissions({{ $folder->id }})">
                  <i class="far fa-shield-alt mr-2"></i>{{ __('app.permissions') }}
                </x-ui.dropdown.dropdown-link>
              @endif
              <x-ui.dropdown.dropdown-link
                  confirm-method="deleteFolder"
                  :confirm-arguments="[(int) $folder->id]"
                  :confirm-title="__('app.delete')"
                  :confirm-message="__('app.folder_delete_confirm')"
                  :confirm-label="__('app.delete')"
                  class="!text-red-600 dark:!text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10"
              >
                <i class="far fa-trash-alt mr-2"></i>{{ __('app.delete') }}
              </x-ui.dropdown.dropdown-link>
            </x-slot>
          </x-ui.dropdown.anchor-dropdown>
        </div>
      @endif
      @if($detailsView) </td> @endif
    </{{ $folderTag }}>
@endforeach

@foreach($poolFiles as $file)
  @if($gridView)
    <div class="rt-file-explorer-card min-w-0" style="--rt-stagger: {{ $folders->count() + $loop->index }}" wire:key="file-{{ $file->id }}">
  @endif
    <x-ui.filepool.file-card
      :file="$file"
      :layout="$layout"
      :can-download="$downloadableIds->has($file->id)"
      :read-only="$readOnly"
      :can-move="$canMoveFiles && (
        $file->is_owned_by_auth_user
        || auth()->user()?->isAdmin()
        || auth()->user()?->can('files.manage')
        || auth()->user()?->can('users.edit')
      )"
      :drag-hint-id="'file-pool-drag-hint-'.$filePoolId"
      wire:key="file-item-{{ $file->id }}"
    />
  @if($gridView)
    </div>
  @endif
@endforeach
