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
            <div class="text-sm text-gray-800 dark:text-slate-200 mb-1" title="{{ $file->name }}">
              {{ $file->name }}
            </div>
            <div class="text-xs text-gray-500 dark:text-slate-400 mb-1 truncate" title="{{ $file->getMimeTypeForHumans() }}">
              <span class="block truncate">{{ $file->getMimeTypeForHumans() }}</span>
            </div>
            <div class="text-xs text-gray-500 dark:text-slate-400">
              <span>{{ $file?->sizeFormatted ?? '' }}</span>
            </div>
          </div>

          {{-- Rechte Spalte: Actions, fixbreit, bricht auf Mobil ggf. um --}}
          <div class="shrink-0 mt-2 sm:mt-0 flex items-center gap-2">
            {{-- Download --}}
            <button
              wire:click="downloadFile({{ $file->id }})"
              class="inline-flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-500 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 rounded-full p-2 focus:outline-none focus:ring focus:ring-gray-300 dark:focus:ring-slate-600"
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
                class="inline-flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-500 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 rounded-full p-2 focus:outline-none focus:ring focus:ring-gray-300 dark:focus:ring-slate-600"
                title="{{ __('app.print') }}"
              >
                <i class="fas fa-print w-4 h-4 leading-none"></i>
                <span class="sr-only">{{ __('app.print') }}</span>
              </a>
            @endif

            {{-- Schliessen --}}
            <button
              wire:click="close"
              class="inline-flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-500 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 rounded-full p-2 focus:outline-none focus:ring focus:ring-gray-300 dark:focus:ring-slate-600"
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
              class="inline-flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 rounded-full p-2 focus:outline-none focus:ring focus:ring-gray-300 dark:focus:ring-slate-600"
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
        <div class="rounded-md border overflow-hidden bg-white dark:bg-slate-800 dark:border-slate-700">
          {{-- Bilder --}}
          @if($isImage)
            <div class="img-container flex justify-center items-center bg-gray-100 dark:bg-slate-900 min-h-[200px]">
                <img
                  class="block w-auto h-auto"
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
                  <div class="font-medium text-gray-900 dark:text-white truncate">
                    {{ $file->name_with_extension ?? $file->name }}
                  </div>
                  @if($mime)
                    <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ $mime }}</div>
                  @endif
                  <div class="text-xs text-gray-500 dark:text-slate-400">
                    {{ __('app.no_inline_preview') }}
                  </div>
                </div>
              </div>
            </div>
          @endif
        </div>
      @else
        <p class="text-sm text-gray-600 dark:text-slate-400">{{ __('app.no_file_selected') }}</p>
      @endif
    </x-slot>

    <x-slot name="footer">
      <div class="flex items-center gap-2">
          @if($file)
          <x-ui.buttons.button-basic
              :mode="'basic'"
              href="{{ $file->getEphemeralPublicUrl() }}"
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
