<div
    x-data="filePreview({ open: @entangle('open') })"
    x-on:filepool-preview.window="prepareOpen($event); if ($event.detail?.id) $wire.openWith($event.detail.id)"
    x-on:resize.window.debounce.100ms="handleResize()"
    data-file-preview-modal
>
    @php
        $mime = strtolower((string) ($file && $open ? ($file->mime_type ?? '') : ''));
        $isImage = $mime !== '' && str_starts_with($mime, 'image/');
        $isVideo = $mime !== '' && str_starts_with($mime, 'video/');
        $isAudio = $mime !== '' && str_starts_with($mime, 'audio/');
        $isPdf = $mime === 'application/pdf' || str_contains($mime, '/pdf');
        $isText = $mime !== '' && (
            str_starts_with($mime, 'text/')
            || in_array($mime, [
                'application/json',
                'application/ld+json',
                'application/xml',
                'application/javascript',
                'application/sql',
                'application/x-yaml',
            ], true)
        );
        $tempUrl = $file && $open ? $this->url : null;
        $printUrl = ($isPdf || $isText || $isImage) ? $tempUrl : null;
        $previewKey = $file && $open ? $this->versionKey : null;
    @endphp

    <x-ui.fullscreen-modal
        state="open"
        close-action="closePreview()"
        escape-action="closePreview(); $event.stopImmediatePropagation()"
        labelledby="file-preview-title"
        :show-default-close="false"
        body-class="min-h-0 flex-1 overflow-hidden p-0"
        content-class="h-full min-h-0 w-full max-w-none"
        header-class="rt-file-preview__header"
        class="rt-file-preview-modal"
        data-file-preview-dialog
    >
        <x-slot:header>
            <div class="rt-file-preview__identity min-w-0 flex-1">
                <button
                    type="button"
                    x-on:click="closePreview()"
                    class="rt-file-preview__glass-button"
                    aria-label="{{ __('app.close') }}"
                    title="{{ __('app.close') }}"
                    data-file-preview-close
                >
                    <i class="far fa-arrow-left" aria-hidden="true"></i>
                </button>

                <div class="min-w-0">
                    <p class="rt-file-preview__eyebrow">{{ __('app.file_preview') }}</p>
                    <h2
                        id="file-preview-title"
                        tabindex="-1"
                        class="rt-file-preview__title"
                        title="{{ $file?->name_with_extension ?? $file?->name }}"
                        data-file-preview-heading
                    >
                        {{ $file?->name_with_extension ?? $file?->name ?? __('app.file_preview') }}
                    </h2>

                    @if($file && $open)
                        <p class="rt-file-preview__metadata">
                            <span class="truncate">{{ $file->getMimeTypeForHumans() }}</span>
                            @if($file->sizeFormatted)
                                <span class="rt-file-preview__metadata-dot" aria-hidden="true"></span>
                                <span class="shrink-0 tabular-nums">{{ $file->sizeFormatted }}</span>
                            @endif
                        </p>
                    @endif
                </div>
            </div>
        </x-slot:header>

        <x-slot:actions>
            @if($file && $open)
                <div class="hidden items-center gap-2 sm:flex" role="toolbar" aria-label="{{ __('app.file_preview') }}">
                    @if($this->canDownload)
                        <button
                            type="button"
                            wire:click="downloadFile({{ $file->id }})"
                            wire:loading.attr="disabled"
                            wire:target="downloadFile({{ $file->id }})"
                            class="rt-file-preview__glass-button"
                            title="{{ __('app.download') }}"
                            aria-label="{{ __('app.download') }}"
                            data-file-preview-action="download"
                        >
                            <i class="far fa-download" wire:loading.remove wire:target="downloadFile({{ $file->id }})" aria-hidden="true"></i>
                            <i class="far fa-spinner fa-spin" wire:loading wire:target="downloadFile({{ $file->id }})" aria-hidden="true"></i>
                        </button>
                    @endif

                    @if($printUrl)
                        <a
                            href="{{ $printUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            data-no-navigate
                            class="rt-file-preview__glass-button"
                            title="{{ __('app.print') }}"
                            aria-label="{{ __('app.print') }}"
                            data-file-preview-action="print"
                        >
                            <i class="far fa-print" aria-hidden="true"></i>
                        </a>
                    @endif

                    @if($tempUrl)
                        <a
                            href="{{ $tempUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            data-no-navigate
                            class="rt-file-preview__glass-button"
                            title="{{ __('app.open_in_new_tab') }}"
                            aria-label="{{ __('app.open_in_new_tab') }}"
                            data-file-preview-action="open"
                        >
                            <i class="far fa-external-link-alt" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>

                <div class="relative sm:hidden">
                    <button
                        type="button"
                        class="rt-file-preview__glass-button"
                        x-on:click="overflowOpen = ! overflowOpen"
                        x-bind:aria-expanded="overflowOpen.toString()"
                        aria-controls="file-preview-mobile-actions"
                        aria-label="Weitere Aktionen"
                        title="Weitere Aktionen"
                        data-file-preview-mobile-menu-trigger
                    >
                        <i class="far fa-ellipsis-v" aria-hidden="true"></i>
                    </button>

                    <div
                        id="file-preview-mobile-actions"
                        x-show="overflowOpen"
                        x-cloak
                        x-on:click.outside="overflowOpen = false"
                        x-on:keydown.escape.stop.prevent="overflowOpen = false; $nextTick(() => $el.previousElementSibling?.focus())"
                        x-transition.opacity.duration.150ms
                        class="rt-file-preview__mobile-menu"
                        role="menu"
                        data-file-preview-mobile-menu
                    >
                        @if($this->canDownload)
                            <button
                                type="button"
                                wire:click="downloadFile({{ $file->id }})"
                                wire:loading.attr="disabled"
                                wire:target="downloadFile({{ $file->id }})"
                                x-on:click="overflowOpen = false"
                                class="rt-file-preview__mobile-action"
                                role="menuitem"
                                data-file-preview-action="download"
                            >
                                <i class="far fa-download" aria-hidden="true"></i>
                                <span>{{ __('app.download') }}</span>
                            </button>
                        @endif

                        @if($printUrl)
                            <a
                                href="{{ $printUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                data-no-navigate
                                x-on:click="overflowOpen = false"
                                class="rt-file-preview__mobile-action"
                                role="menuitem"
                                data-file-preview-action="print"
                            >
                                <i class="far fa-print" aria-hidden="true"></i>
                                <span>{{ __('app.print') }}</span>
                            </a>
                        @endif

                        @if($tempUrl)
                            <a
                                href="{{ $tempUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                data-no-navigate
                                x-on:click="overflowOpen = false"
                                class="rt-file-preview__mobile-action"
                                role="menuitem"
                                data-file-preview-action="open"
                            >
                                <i class="far fa-external-link-alt" aria-hidden="true"></i>
                                <span>{{ __('app.open_in_new_tab') }}</span>
                            </a>
                        @endif
                    </div>
                </div>
            @endif
        </x-slot:actions>

        @if($file && $open && $previewKey)
            <div
                wire:key="file-preview-stage-{{ $previewKey }}"
                x-init="activateFile(@js($previewKey), @js($isImage ? 'image' : ($isVideo ? 'video' : ($isAudio ? 'audio' : ($isPdf ? 'pdf' : ($isText ? 'text' : 'fallback'))))))"
                class="rt-file-preview__stage"
                x-bind:aria-busy="mediaLoading.toString()"
                data-file-preview-content
                data-file-preview-stage
            >
                @if($isImage && $tempUrl)
                    <div
                        class="rt-file-preview__image-canvas"
                        x-bind:class="{ 'is-pannable': canPan(), 'is-panning': pointerActive }"
                        tabindex="0"
                        role="group"
                        aria-label="Bildvorschau. Mit Mausrad oder den Schaltflächen zoomen; vergrößerte Bilder können gezogen werden."
                        x-on:wheel.prevent="wheelZoom($event)"
                        x-on:dblclick.prevent="toggleActualAt($event)"
                        x-on:pointerdown="pointerDown($event)"
                        x-on:pointermove="pointerMove($event)"
                        x-on:pointerup="pointerUp($event)"
                        x-on:pointercancel="pointerCancel($event)"
                        x-on:keydown="handleCanvasKeydown($event)"
                        data-file-preview-image-stage
                    >
                        <div
                            class="rt-file-preview__image-pan"
                            x-bind:style="'transform: translate3d(calc(-50% + ' + panX + 'px), calc(-50% + ' + panY + 'px), 0)'"
                        >
                            <img
                                src="{{ $tempUrl }}"
                                alt="{{ $file->name_with_extension ?? $file->name }}"
                                draggable="false"
                                class="rt-file-preview__image"
                                x-bind:style="'transform: scale(' + zoom + ')'"
                                x-on:load="imageLoaded($event)"
                                x-on:error="mediaFailed()"
                                data-file-preview-image
                            />
                        </div>
                    </div>

                    <div class="rt-file-preview__zoom-toolbar" role="toolbar" aria-label="Bildgröße">
                        <button
                            type="button"
                            class="rt-file-preview__zoom-button"
                            x-on:click="fitImage()"
                            x-bind:aria-pressed="isFitted().toString()"
                            title="Bild einpassen"
                            aria-label="Bild einpassen"
                        >
                            <i class="far fa-expand-arrows-alt" aria-hidden="true"></i>
                        </button>
                        <span class="rt-file-preview__zoom-divider" aria-hidden="true"></span>
                        <button
                            type="button"
                            class="rt-file-preview__zoom-button"
                            x-on:click="zoomOut()"
                            x-bind:disabled="zoom <= minZoom + 0.001"
                            title="Verkleinern"
                            aria-label="Verkleinern"
                        >
                            <i class="far fa-minus" aria-hidden="true"></i>
                        </button>
                        <button
                            type="button"
                            class="rt-file-preview__zoom-value"
                            x-on:click="actualSize()"
                            x-text="zoomLabel()"
                            title="Originalgröße (100 %)"
                            aria-label="Originalgröße anzeigen"
                        ></button>
                        <button
                            type="button"
                            class="rt-file-preview__zoom-button"
                            x-on:click="zoomIn()"
                            x-bind:disabled="zoom >= maxZoom - 0.001"
                            title="Vergrößern"
                            aria-label="Vergrößern"
                        >
                            <i class="far fa-plus" aria-hidden="true"></i>
                        </button>
                    </div>
                @elseif($isVideo && $tempUrl)
                    <div class="rt-file-preview__video-wrap">
                        <video
                            class="rt-file-preview__video"
                            controls
                            playsinline
                            preload="metadata"
                            src="{{ $tempUrl }}"
                            x-on:loadedmetadata="mediaLoaded()"
                            x-on:error="mediaFailed()"
                            data-file-preview-media
                        ></video>
                    </div>
                @elseif($isAudio && $tempUrl)
                    <div class="rt-file-preview__audio-stage">
                        <div class="rt-file-preview__audio-card">
                            <span class="rt-file-preview__audio-icon" aria-hidden="true">
                                <i class="far fa-waveform"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-white">{{ $file->name_with_extension ?? $file->name }}</p>
                                <p class="mt-1 text-xs text-white/55">{{ $file->getMimeTypeForHumans() }}</p>
                            </div>
                            <audio
                                class="mt-5 block w-full"
                                controls
                                preload="metadata"
                                src="{{ $tempUrl }}"
                                x-on:loadedmetadata="mediaLoaded()"
                                x-on:error="mediaFailed()"
                                data-file-preview-media
                            ></audio>
                        </div>
                    </div>
                @elseif($isPdf && $tempUrl)
                    <div class="rt-file-preview__document-stage">
                        <iframe
                            class="rt-file-preview__document"
                            src="{{ $tempUrl }}"
                            title="{{ $file->name_with_extension ?? $file->name }}"
                            referrerpolicy="no-referrer"
                            x-on:load="mediaLoaded()"
                            x-on:error="mediaFailed()"
                            data-file-preview-pdf
                        ></iframe>
                    </div>
                @elseif($isText && $tempUrl)
                    <div class="rt-file-preview__document-stage rt-file-preview__document-stage--text">
                        <iframe
                            class="rt-file-preview__document"
                            src="{{ $tempUrl }}"
                            title="{{ $file->name_with_extension ?? $file->name }}"
                            sandbox=""
                            referrerpolicy="no-referrer"
                            loading="eager"
                            x-on:load="mediaLoaded()"
                            x-on:error="mediaFailed()"
                            data-file-preview-sandboxed
                        ></iframe>
                    </div>
                @else
                    <div class="rt-file-preview__fallback" x-init="mediaLoaded()" data-file-preview-fallback>
                        <img
                            class="h-16 w-16 object-contain sm:h-20 sm:w-20"
                            src="{{ $file->icon_or_thumbnail }}"
                            alt=""
                        />
                        <div class="min-w-0 text-center">
                            <p class="truncate text-base font-semibold text-white sm:text-lg">
                                {{ $file->name_with_extension ?? $file->name }}
                            </p>
                            @if($mime)
                                <p class="mt-1 truncate text-xs text-white/55">{{ $mime }}</p>
                            @endif
                            <p class="mt-3 text-sm text-white/70">{{ __('app.no_inline_preview') }}</p>
                        </div>
                    </div>
                @endif

                <div
                    x-show="mediaLoading && ! mediaError"
                    x-cloak
                    class="rt-file-preview__status"
                    role="status"
                    aria-live="polite"
                    data-file-preview-loading
                >
                    <span class="rt-file-preview__spinner" aria-hidden="true"></span>
                    <span>{{ ucfirst(__('app.loading')) }}</span>
                </div>

                <div
                    x-show="mediaError"
                    x-cloak
                    class="rt-file-preview__status"
                    role="alert"
                    data-file-preview-error
                >
                    <span class="rt-file-preview__error-icon" aria-hidden="true">
                        <i class="far fa-exclamation-triangle"></i>
                    </span>
                    <strong>Vorschau konnte nicht geladen werden</strong>
                    <span class="max-w-md text-center text-sm text-white/60">{{ __('app.no_inline_preview') }}</span>
                    @if($tempUrl)
                        <a
                            href="{{ $tempUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            data-no-navigate
                            class="rt-file-preview__error-action"
                        >
                            <i class="far fa-external-link-alt" aria-hidden="true"></i>
                            {{ __('app.open_in_new_tab') }}
                        </a>
                    @endif
                </div>
            </div>
        @else
            <div class="rt-file-preview__fallback">
                <p class="text-sm text-white/60">{{ __('app.no_file_selected') }}</p>
            </div>
        @endif
    </x-ui.fullscreen-modal>
</div>
