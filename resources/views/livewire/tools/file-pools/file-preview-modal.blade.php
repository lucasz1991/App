<div
  x-data="{ bust: 0 }"
  x-on:filepool-preview.window="$wire.openWith($event.detail.id); bust = Date.now()"
  data-file-preview-modal
>
  @php
    $mime = $file && $open ? ($file->mime_type ?? '') : '';
    $isImage = $mime !== '' && str_starts_with($mime, 'image/');
    $isVideo = $mime !== '' && str_starts_with($mime, 'video/');
    $isAudio = $mime !== '' && str_starts_with($mime, 'audio/');
    $isPdf = $mime !== '' && str_contains($mime, 'pdf');
    $isText = $mime !== '' && str_contains($mime, 'text');
    $tempUrl = $file && $open ? $this->url : null;
    $printUrl = ($isPdf || $isText || $isImage) ? $tempUrl : null;
  @endphp

  <x-dialog-modal wire:model="open" :maxWidth="'4xl'">

    <x-slot name="title">
      @if($file && $open)
        <span class="block min-w-0">
          <span class="block text-[0.65rem] font-bold uppercase tracking-[0.13em] text-rt-accent dark:text-rt-dark-accent">
            {{ __('app.file_preview') }}
          </span>
          <span class="mt-1 block truncate text-base font-semibold text-rt-text dark:text-rt-dark-text" title="{{ $file->name }}">
            {{ $file->name }}
          </span>
          <span class="mt-1 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs font-normal text-rt-muted dark:text-rt-dark-muted">
            <span class="max-w-full truncate" title="{{ $file->getMimeTypeForHumans() }}">
              {{ $file->getMimeTypeForHumans() }}
            </span>
            @if($file?->sizeFormatted)
              <span aria-hidden="true">&middot;</span>
              <span class="tabular-nums">{{ $file->sizeFormatted }}</span>
            @endif
          </span>
        </span>
      @else
        <span class="font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.file_preview') }}</span>
      @endif
    </x-slot>

    <x-slot name="headerActions">
      @if($file && $open)
        <x-ui.buttons.button-basic
          type="button"
          mode="basic"
          size="sm"
          wire:click="downloadFile({{ $file->id }})"
          wire:loading.attr="disabled"
          wire:target="downloadFile"
          class="!h-9 !min-h-9 !w-9 !p-0"
          title="{{ __('app.download') }}"
          aria-label="{{ __('app.download') }}"
          data-file-preview-action="download"
        >
          <i class="fas fa-download" aria-hidden="true"></i>
          <span class="sr-only">{{ __('app.download') }}</span>
        </x-ui.buttons.button-basic>

        @if($printUrl)
          <x-ui.buttons.button-basic
            mode="basic"
            size="sm"
            href="{{ $printUrl }}"
            target="_blank"
            rel="noopener noreferrer"
            data-no-navigate
            class="!h-9 !min-h-9 !w-9 !p-0"
            title="{{ __('app.print') }}"
            aria-label="{{ __('app.print') }}"
            data-file-preview-action="print"
          >
            <i class="fas fa-print" aria-hidden="true"></i>
            <span class="sr-only">{{ __('app.print') }}</span>
          </x-ui.buttons.button-basic>
        @endif
      @endif
    </x-slot>

    <x-slot name="content">
      @if($file && $open)
        <div class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-file-preview-content>
          {{-- Bilder --}}
          @if($isImage)
            <div class="img-container flex min-h-[12rem] items-center justify-center bg-rt-surface-muted/70 dark:bg-rt-dark-canvas">
              <img
                class="block max-h-[68dvh] w-auto max-w-full object-contain"
                src="{{ $tempUrl }}"
                alt="{{ $file->name_with_extension ?? $file->name }}"
              />
            </div>

          {{-- Videos --}}
          @elseif($isVideo)
            <div class="video-container bg-black">
              <video
                class="block aspect-video max-h-[68dvh] w-full bg-black object-contain"
                controls
                playsinline
                preload="metadata"
                src="{{ $tempUrl }}"
              ></video>
            </div>

          {{-- Audio --}}
          @elseif($isAudio)
            <div class="audio-container bg-rt-surface-muted/55 p-4 dark:bg-rt-dark-surface-muted/35 sm:p-5">
              <audio class="block w-full" controls preload="metadata" src="{{ $tempUrl }}"></audio>
            </div>

          {{-- PDF & sonstiger Text/HTML --}}
          @elseif($isPdf || $isText)
            <div class="pdf-container">
              <iframe
                key="file-preview-{{ $file->id }}-{{ $file->updated_at?->timestamp ?? $file->id }}"
                class="block min-h-[60dvh] w-full"
                src="{{ $tempUrl }}"
                title="{{ $file->name_with_extension ?? $file->name }}"
              ></iframe>
            </div>

          {{-- Fallback --}}
          @else
            <div class="flex items-center gap-4 p-5 sm:p-6">
              <img class="h-10 w-10 shrink-0 object-contain"
                   src="{{ $file->icon_or_thumbnail }}"
                   alt="">
              <div class="min-w-0">
                <div class="truncate font-medium text-rt-text dark:text-white">
                  {{ $file->name_with_extension ?? $file->name }}
                </div>
                @if($mime)
                  <div class="mt-0.5 truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $mime }}</div>
                @endif
                <div class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">
                  {{ __('app.no_inline_preview') }}
                </div>
              </div>
            </div>
          @endif
        </div>
      @else
        <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_file_selected') }}</p>
      @endif
    </x-slot>

    <x-slot name="footer">
      <div class="flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:justify-end" data-file-preview-footer-actions>
        @if($file && $open)
          <x-ui.buttons.button-basic
            mode="basic"
            href="{{ $this->url }}"
            target="_blank"
            rel="noopener noreferrer"
            data-no-navigate
            size="sm"
            class="w-full sm:w-auto"
          >
            <i class="fas fa-external-link-alt" aria-hidden="true"></i>
            {{ __('app.open_in_new_tab') }}
          </x-ui.buttons.button-basic>
        @endif
      </div>
    </x-slot>

  </x-dialog-modal>
</div>
