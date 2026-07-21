<div
  x-data="{ bust: 0 }"
  x-on:filepool-preview.window="$wire.openWith($event.detail.id); bust = Date.now()"
>
  <x-dialog-modal wire:model="open" :maxWidth="'4xl'">

    <x-slot name="title">
      <div class="flex flex-wrap sm:flex-nowrap items-start sm:items-center justify-between gap-2">
        @if($file && $open)
          @php
                $mime    = $file->mime_type ?? '';
                $isImage = $mime && str_starts_with($mime, 'image/');
                $isVideo = $mime && str_starts_with($mime, 'video/');
                $isAudio = $mime && str_starts_with($mime, 'audio/');
                $isPdf   = $mime && str_contains($mime, 'pdf');
                $isText  = $mime && str_contains($mime, 'text');
                $tempUrl = $this->url;
                $printUrl = '';
                if($isPdf || $isText || $isImage){
                  $printUrl = isset($this->url) && $this->url ? $this->url : ($file->getEphemeralPublicUrl() ?? null);
                }
          @endphp
          {{-- Linke Spalte: Infos, darf schrumpfen & ellipsen --}}
          <div class="min-w-0 flex-1">
            <div class="mb-1 text-sm text-rt-text dark:text-white" title="{{ $file->name }}">
              {{ $file->name }}
            </div>
            <div class="mb-1 truncate text-xs text-rt-muted dark:text-rt-dark-muted" title="{{ $file->getMimeTypeForHumans() }}">
              <span class="block truncate">{{ $file->getMimeTypeForHumans() }}</span>
            </div>
            <div class="text-xs text-rt-muted dark:text-rt-dark-muted">
              <span>{{ $file?->sizeFormatted ?? '' }}</span>
            </div>
          </div>

          {{-- Rechte Spalte: Actions, fixbreit, bricht auf Mobil ggf. um --}}
          <div class="shrink-0 mt-2 sm:mt-0 flex items-center gap-2">
            {{-- Download --}}
            <button
              wire:click="downloadFile({{ $file->id }})"
              class="inline-flex items-center justify-center rounded-full bg-rt-surface-muted p-2 text-rt-muted transition-all duration-300 ease-rt-spring hover:text-rt-accent active:scale-[0.98] dark:bg-rt-dark-surface-muted dark:text-white dark:hover:text-rt-dark-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/40"
              title="{{ __('app.download') }}"
            >
              <i class="fas fa-download w-4 h-4 leading-none"></i>
              <span class="sr-only">{{ __('app.download') }}</span>
            </button>

            {{-- Drucken: oeffnet Vorschau in neuem Tab - von dort druckbar --}}
            @if($printUrl != '')
              <a
                href="{{ $printUrl }}"
                target="_blank" rel="noopener"
                class="inline-flex items-center justify-center rounded-full bg-rt-surface-muted p-2 text-rt-muted transition-all duration-300 ease-rt-spring hover:text-rt-accent active:scale-[0.98] dark:bg-rt-dark-surface-muted dark:text-white dark:hover:text-rt-dark-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/40"
                title="{{ __('app.print') }}"
              >
                <i class="fas fa-print w-4 h-4 leading-none"></i>
                <span class="sr-only">{{ __('app.print') }}</span>
              </a>
            @endif

            {{-- Schliessen --}}
            <button
              wire:click="close"
              class="inline-flex items-center justify-center rounded-full bg-rt-surface-muted p-2 text-rt-muted transition-all duration-300 ease-rt-spring hover:text-rt-accent active:scale-[0.98] dark:bg-rt-dark-surface-muted dark:text-white dark:hover:text-rt-dark-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/40"
              title="{{ __('app.close') }}"
            >
              <i class="fas fa-times w-4 h-4 leading-none"></i>
              <span class="sr-only">{{ __('app.close') }}</span>
            </button>
          </div>
        @else
          <div class="min-w-0 flex-1">
            <span class="font-semibold dark:text-white">{{ __('app.file_preview') }}</span>
          </div>
          <div class="shrink-0">
            <button
              wire:click="close"
              class="inline-flex items-center justify-center rounded-full bg-rt-surface-muted p-2 text-rt-muted transition-all duration-300 ease-rt-spring hover:text-rt-accent active:scale-[0.98] dark:bg-rt-dark-surface-muted dark:text-white dark:hover:text-rt-dark-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/40"
              title="{{ __('app.close') }}"
            >
              <i class="fas fa-times w-4 h-4 leading-none"></i>
              <span class="sr-only">{{ __('app.close') }}</span>
            </button>
          </div>
        @endif
      </div>
    </x-slot>

    <x-slot name="content">
      @if($file && $open)
        <div class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-lg ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
          {{-- Bilder --}}
          @if($isImage)
            <div class="img-container flex min-h-[200px] items-center justify-center bg-rt-surface-muted dark:bg-rt-dark-canvas">
                <img
                  class="block max-h-[72vh] w-auto max-w-full object-contain"
                  src="{{ $tempUrl }}"
                  alt="{{ $file->name_with_extension ?? $file->name }}"
                />
            </div>

          {{-- Videos --}}
          @elseif($isVideo)
            <div class="video-container">
              <video
                class="block w-full h-[75vh] min-h-[420px]"
                controls
                src="{{ $tempUrl }}"
              ></video>
            </div>

          {{-- Audio --}}
          @elseif($isAudio)
            <div class="audio-container p-4">
              <audio class="w-full" controls
                     src="{{ $tempUrl }}"></audio>
            </div>

          {{-- PDF & sonstiger Text/HTML --}}
          @elseif($isPdf || $isText)
            <div class="pdf-container">
              <iframe
                key="file-preview-{{ $file->id }}-{{ $file->updated_at?->timestamp ?? $file->id }}"
                class="w-full min-h-[60vh] max-h-[70vh]"
                src="{{ $tempUrl }}"
              ></iframe>
            </div>

          {{-- Fallback --}}
          @else
            <div class="p-6 flex items-center justify-between gap-4">
              <div class="flex items-center gap-3 min-w-0">
                <img class="w-10 h-10 object-contain"
                     src="{{ $file->icon_or_thumbnail }}"
                     alt="{{ __('app.file_preview') }}">
                <div class="min-w-0">
                  <div class="truncate font-medium text-rt-text dark:text-white">
                    {{ $file->name_with_extension ?? $file->name }}
                  </div>
                  @if($mime)
                    <div class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $mime }}</div>
                  @endif
                  <div class="text-xs text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.no_inline_preview') }}
                  </div>
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
      <div class="flex items-center gap-2">
          @if($file)
          <x-ui.buttons.button-basic
              :mode="'basic'"
              href="{{ $this->url }}"
              target="_blank"
              rel="noopener noreferrer"
              :size="'sm'"
          >
              <i class="fas fa-external-link-alt mr-2"></i>
              {{ __('app.open_in_new_tab') }}
          </x-ui.buttons.button-basic>
          @endif

          {{-- Schliessen-Button: Modal schliessen --}}
          <x-ui.buttons.button-basic
            :mode="'basic'"
            wire:click="close"
            :size="'sm'"
          >
          <i class="fas fa-times mr-2"></i>
          {{ __('app.close') }}
          </x-ui.buttons.button-basic>
      </div>
    </x-slot>

  </x-dialog-modal>
</div>
